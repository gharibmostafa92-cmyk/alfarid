<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Orders\OrderStatus;
use App\Enums\Orders\ReturnStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosHeldSale;
use App\Models\PosShift;
use App\Models\ReturnRequestItem;
use App\Models\User;
use App\Services\Inventory\BranchAccess;
use App\Services\Shared\OperationIdempotencyService;
use Illuminate\Auth\Access\AuthorizationException;
use App\Services\Pos\PosService;
use App\Services\Shared\SearchTerm;
use App\Services\Shared\SettingService;
use App\Support\ArabicDigits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * POS terminal sales operations: direct checkout, cart hold/resume, returns, and item exchanges.
 */
class PosSaleController extends Controller
{
    public function __construct(private readonly PosService $pos) {}

    /**
     * Create and record a direct POS retail sale within the active shift.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'items.*.override_reason' => ['nullable', 'string', 'max:255'],
            'customer_id' => ['nullable', 'integer', 'min:1', Rule::exists('customers', 'id')
                ->whereNull('deleted_at')
                ->whereNotIn('status', [CustomerStatus::Suspended->value, CustomerStatus::Rejected->value])],
            'payment_method' => ['nullable', 'string', 'in:cash,card,mixed,credit'],
            'cash_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'card_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'override_price' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            // A hold consumed by resumeHeld no longer exists; the service
            // tolerates the missing row, so the rule must not reject it.
            'held_sale_id' => ['nullable', 'integer', 'min:1'],
            'held_sale_ids' => ['nullable', 'array', 'max:20'],
            'held_sale_ids.*' => ['integer', 'min:1'],
        ]);

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');

        // Authorize manual unit price overrides via pos.override_price permission.
        if (! empty($data['override_price'])) {
            $user = $request->user('staff');
            if (! $user->can('pos.override_price')) {
                return response()->json(['message' => 'لا تملك صلاحية تجاوز سعر البيع.'], 403);
            }
        }

        // Mirror quote(): manual line discounts need pos.discount before the
        // idempotency run, so a rejected attempt never burns an idempotency key.
        if (collect($data['items'])->contains(fn ($item) => (float) ($item['discount_amount'] ?? 0) > 0)
            && ! $request->user('staff')->can('pos.discount')) {
            return response()->json(['message' => 'لا تملك صلاحية تطبيق خصم يدوي.'], 403);
        }

        $order = app(OperationIdempotencyService::class)->run(
            'pos_sale', $request->user('staff'), $this->requiredIdempotencyKey($request),
            ['shift_id' => $shift->id, ...$data], fn () => $this->pos->createSale($shift, $data),
        );
        // Idempotent replays resolve via find() without relations; reload for a complete response.
        $order->loadMissing(['items.variant', 'customer']);

        return response()->json([
            'message' => 'تم تسجيل البيع بنجاح.',
            'data' => $this->formatOrder($order),
        ], 201);
    }

    /**
     * Temporarily hold an uncompleted sale for later retrieval.
     */
    public function hold(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            // F-03: explicit manual flag round-trips through items_payload.
            'items.*.manual_price' => ['nullable', 'boolean'],
            // F-04: hold-time customer gate mirrors store() — suspended,
            // rejected, and soft-deleted customers cannot park a held sale.
            'customer_id' => ['nullable', 'integer', 'min:1', Rule::exists('customers', 'id')
                ->whereNull('deleted_at')
                ->whereNotIn('status', [CustomerStatus::Suspended->value, CustomerStatus::Rejected->value])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');
        /** @var User $user */
        $user = $request->user('staff');

        $hasManualPrice = collect($data['items'])->some(
            fn (array $item): bool => ! empty($item['manual_price'])
        );
        if ($hasManualPrice && ! $user->can('pos.override_price')) {
            throw new AuthorizationException('لا تملك صلاحية تجاوز سعر البيع.');
        }

        $hasDiscount = collect($data['items'])->some(
            fn (array $item): bool => (float) ($item['discount_amount'] ?? 0) > 0
        );
        if ($hasDiscount && ! $user->can('pos.discount')) {
            throw new AuthorizationException('لا تملك صلاحية تطبيق خصم يدوي.');
        }

        $idempotencyKey = $this->idempotencyKey($request);
        $held = $idempotencyKey !== null
            ? app(OperationIdempotencyService::class)->run(
                'pos_hold',
                $user,
                $idempotencyKey,
                ['shift_id' => $shift->id, ...$data],
                fn (): PosHeldSale => $this->pos->holdSale($shift, $data),
            )
            : $this->pos->holdSale($shift, $data);

        return response()->json([
            'message' => 'تم تعليق الفاتورة بنجاح.',
            'hold_reference' => $held->hold_reference,
            'data' => $held->only(['id', 'hold_reference', 'items_payload', 'notes', 'created_at']),
        ], 201);
    }

    /**
     * List all held sales currently pending in the cashier's branch.
     */
    public function held(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');
        if ($user->branch_id === null) {
            throw new AuthorizationException('حسابك غير مرتبط بأي فرع.');
        }

        $branchId = (int) $user->branch_id;
        app(BranchAccess::class)->assertBranch($user->id, $branchId);

        $query = PosHeldSale::query()
            ->where('branch_id', $branchId)
            ->with(['customer:id,name,phone,type,status'])
            ->orderByDesc('created_at')
            ->limit(100);

        if (! $user->can('pos.held.view_all')) {
            $query->where('user_id', $user->id);
        }

        $held = $query->get()->map(fn (PosHeldSale $h): array => [
            'id' => $h->id,
            'hold_reference' => $h->hold_reference,
            'branch_id' => $h->branch_id,
            'user_id' => $h->user_id,
            'customer_id' => $h->customer_id,
            'customer' => $this->formatHeldCustomer($h->customer),
            'items_payload' => $h->items_payload,
            'notes' => $h->notes,
            'resumed_by' => $h->resumed_by,
            'resumed_at' => $h->resumed_at,
            'created_at' => $h->created_at,
        ])->values()->all();

        return response()->json(['data' => $held]);
    }

    /**
     * Calculate live cart pricing, discounts, available stock, and totals without locks or DB writes.
     */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required', 'integer', 'distinct'],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:9999'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'items.*.override_reason' => ['nullable', 'string', 'max:255'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')
                ->whereNull('deleted_at')
                ->whereNotIn('status', [CustomerStatus::Suspended->value, CustomerStatus::Rejected->value])],
            'override_price' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');
        /** @var User $user */
        $user = $request->user('staff');

        $override = ! empty($data['override_price']);
        if ($override && ! $user->can('pos.override_price')) {
            return response()->json(['message' => 'لا تملك صلاحية تجاوز سعر البيع.'], 403);
        }

        if (collect($data['items'])->contains(fn ($item) => (float) ($item['discount_amount'] ?? 0) > 0) && ! $user->can('pos.discount')) {
            return response()->json(['message' => 'لا تملك صلاحية تطبيق خصم يدوي.'], 403);
        }

        $customer = isset($data['customer_id']) ? Customer::find($data['customer_id']) : null;

        if ($override && filled($data['override_reason'] ?? null)) {
            $data['items'] = array_map(
                fn (array $item): array => [...$item, 'override_reason' => $item['override_reason'] ?? $data['override_reason']],
                $data['items'],
            );
        }

        return response()->json([
            'data' => $this->pos->quote(
                branchId: (int) $shift->branch_id,
                items: $data['items'],
                c: $customer,
                override: $override,
                actor: $user,
            ),
        ]);
    }

    /**
     * List completed POS sales and delivered branch orders (store/wholesale) for return lookup.
     */
    public function history(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'channel' => ['nullable', Rule::in(['all', 'pos', 'store', 'wholesale'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'only_returnable' => ['nullable', 'boolean'],
            'cursor' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');
        /** @var User $user */
        $user = $request->user('staff');
        $branchId = (int) $shift->branch_id;
        app(BranchAccess::class)->assertBranch($user->id, $branchId);

        $channel = $filters['channel'] ?? 'all';
        $perPage = (int) ($filters['per_page'] ?? 20);
        $rawQuery = trim((string) ($filters['q'] ?? ''));
        $normalizedQuery = ArabicDigits::normalize($rawQuery);
        $tz = (string) config('app.business_timezone', 'Africa/Cairo');

        $query = Order::query()
            ->where('status', OrderStatus::Delivered)
            ->whereNull('return_of_order_id')
            ->where(function ($scope) use ($branchId, $channel): void {
                $applyPosScope = fn ($pos) => $pos
                    ->where('channel', 'pos')
                    ->where(fn ($q) => $q->where('allocated_branch_id', $branchId)
                        ->orWhereHas('posShift', fn ($s) => $s->where('branch_id', $branchId)));

                $applyRemoteScope = fn ($remote) => $remote
                    ->whereIn('channel', $channel === 'all' ? ['store', 'wholesale'] : [$channel])
                    ->where(fn ($b) => $b->whereNull('allocated_branch_id')->orWhere('allocated_branch_id', $branchId))
                    ->whereHas('items', fn ($items) => $items->where('allocated_branch_id', $branchId))
                    ->whereDoesntHave('items', fn ($items) => $items
                        ->whereNull('allocated_branch_id')
                        ->orWhere('allocated_branch_id', '!=', $branchId));

                if ($channel === 'pos') {
                    $applyPosScope($scope);
                } elseif (in_array($channel, ['store', 'wholesale'], true)) {
                    $applyRemoteScope($scope);
                } else {
                    $scope->where($applyPosScope)->orWhere($applyRemoteScope);
                }
            })
            ->with(['customer:id,name,phone', 'posShift.cashier:id,name'])
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', \Illuminate\Support\Carbon::parse((string) $d, $tz)->startOfDay()->utc()))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->where('created_at', '<', \Illuminate\Support\Carbon::parse((string) $d, $tz)->addDay()->startOfDay()->utc()))
            ->when($filters['only_returnable'] ?? false, fn ($q) => $q->whereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('order_items')
                ->whereColumn('order_items.order_id', 'orders.id')
                ->whereRaw('order_items.qty > order_items.returned_qty')))
            ->when($normalizedQuery !== '', function ($q) use ($normalizedQuery): void {
                $pattern = SearchTerm::pattern($normalizedQuery);
                $prefix = SearchTerm::prefix($normalizedQuery);
                $upperPrefix = SearchTerm::prefix(strtoupper($normalizedQuery));
                $phoneNormalized = Customer::normalizePhone($normalizedQuery);
                $phonePrefix = SearchTerm::prefix($phoneNormalized);

                $q->where(function ($inner) use ($normalizedQuery, $pattern, $prefix, $upperPrefix, $phoneNormalized, $phonePrefix): void {
                    $inner->whereLike('order_number', $prefix, caseSensitive: false)
                        ->orWhereLike('order_number', $upperPrefix, caseSensitive: false)
                        ->orWhereLike('order_number', $pattern, caseSensitive: false)
                        ->orWhereLike('guest_name', $pattern, caseSensitive: false)
                        ->orWhere('guest_phone', 'like', $prefix)
                        ->orWhere('guest_phone_normalized', 'like', $prefix)
                        ->orWhereHas('customer', function ($cq) use ($pattern, $prefix, $phoneNormalized, $phonePrefix): void {
                            $cq->whereLike('name', $pattern, caseSensitive: false)
                                ->orWhere('phone', 'like', $prefix);
                            if ($phoneNormalized !== '' && $phonePrefix !== $prefix) {
                                $cq->orWhere('phone', 'like', $phonePrefix);
                            }
                        })
                        ->orWhereExists(function ($sub) use ($pattern, $prefix): void {
                            $sub->selectRaw('1')
                                ->from('order_items')
                                ->whereColumn('order_items.order_id', 'orders.id')
                                ->where(function ($itemsQ) use ($pattern, $prefix): void {
                                    $itemsQ->whereLike('order_items.sku_snapshot', $prefix, caseSensitive: false)
                                        ->orWhereLike('order_items.product_name_snapshot', $pattern, caseSensitive: false);
                                });
                        });
                    if ($phoneNormalized !== '' && $phonePrefix !== $prefix) {
                        $inner->orWhere('guest_phone', 'like', $phonePrefix)
                            ->orWhere('guest_phone_normalized', 'like', $phonePrefix);
                    }
                    if (ctype_digit($normalizedQuery)) {
                        $inner->orWhere('order_number', 'like', '%-'.str_pad((string) ((int) $normalizedQuery), 6, '0', STR_PAD_LEFT));
                    }
                });
            })
            ->when(isset($filters['cursor']), fn ($q) => $q->where('id', '<', (int) $filters['cursor']))
            ->orderByDesc('id')
            ->limit($perPage + 1);

        $rows = $query->get();
        $hasMore = $rows->count() > $perPage;
        $page = $rows->take($perPage);

        $itemStats = $page->isEmpty()
            ? collect()
            : DB::table('order_items')
                ->whereIn('order_id', $page->pluck('id')->all())
                ->groupBy('order_id')
                ->selectRaw('order_id, COUNT(*) AS items_count, COALESCE(SUM(qty), 0) AS total_qty, COALESCE(SUM(GREATEST(qty - returned_qty, 0)), 0) AS returnable_qty')
                ->get()
                ->keyBy('order_id');

        $reservedByOrder = $page->isEmpty()
            ? collect()
            : ReturnRequestItem::query()
                ->join('return_requests', 'return_requests.id', '=', 'return_request_items.return_request_id')
                ->whereIn('return_requests.order_id', $page->pluck('id')->all())
                ->whereNotIn('return_requests.status', [ReturnStatus::Rejected->value, ReturnStatus::Cancelled->value, ReturnStatus::Received->value, ReturnStatus::Refunded->value])
                ->selectRaw("return_requests.order_id, COALESCE(SUM(CASE WHEN return_requests.status = 'approved' THEN approved_qty ELSE requested_qty END), 0) AS total")
                ->groupBy('return_requests.order_id')
                ->pluck('total', 'order_id');

        $returnWindowDays = (int) (app(SettingService::class)->get('pos.return_window_days')
            ?? config('inventory.pos_return_window_days', 30));

        $data = $page->map(function (Order $order) use ($itemStats, $reservedByOrder, $returnWindowDays): array {
            $stats = $itemStats->get($order->id);
            $totalQty = (float) ($stats->total_qty ?? 0);
            $baseReturnable = (float) ($stats->returnable_qty ?? 0);
            $pendingReserved = $order->channel !== 'pos' ? (float) ($reservedByOrder[$order->id] ?? 0) : 0.0;
            $returnableQty = max(0.0, $baseReturnable - $pendingReserved);
            $withinWindow = $returnWindowDays <= 0
                || ($order->created_at !== null && $order->created_at->gte(now()->subDays($returnWindowDays)));

            $customerData = $order->customer !== null
                ? ['id' => $order->customer->id, 'name' => $order->customer->name, 'phone' => $order->customer->phone]
                : ($order->guest_name !== null ? ['id' => null, 'name' => $order->guest_name, 'phone' => $order->guest_phone] : null);

            return [
                'id' => $order->id,
                'channel' => $order->channel,
                'order_number' => $order->order_number,
                'created_at' => $order->created_at,
                'payment_method' => $order->payment_method,
                'grand_total' => number_format((float) $order->grand_total, 2, '.', ''),
                'customer' => $customerData,
                'cashier' => $order->posShift?->cashier !== null ? [
                    'id' => $order->posShift->cashier->id,
                    'name' => $order->posShift->cashier->name,
                ] : null,
                'items_count' => (int) ($stats->items_count ?? 0),
                'total_qty' => floor($totalQty) === $totalQty ? (int) $totalQty : $totalQty,
                'returnable_qty' => floor($returnableQty) === $returnableQty ? (int) $returnableQty : $returnableQty,
                'is_within_return_window' => $withinWindow,
            ];
        })->values()->all();

        return response()->json([
            'data' => $data,
            'next_cursor' => $hasMore ? $page->last()?->id : null,
        ]);
    }

    /**
     * Look up a delivered POS sale order by number for return or exchange processing.
     */
    public function lookupForReturn(Request $request): JsonResponse
    {
        $data = $request->validate([
            // F-08: bound the input so oversized payloads are rejected before
            // any query building, padding, or LIKE compilation happens.
            'number' => ['required', 'string', 'max:64'],
        ]);

        /** @var User $user */
        $user = $request->user('staff');

        $normalized = strtoupper(trim(ArabicDigits::normalize($data['number'])));
        if ($normalized === '') {
            abort(404, 'الفاتورة غير موجودة.');
        }

        try {
            $scopedBranch = app(BranchAccess::class)->restrictedBranch($user);
        } catch (AuthorizationException) {
            $scopedBranch = false;
        }

        $candidates = [$normalized];
        if (! str_starts_with($normalized, 'POS-')) {
            $candidates[] = 'POS-'.$normalized;
        }
        if (! str_starts_with($normalized, 'ORD-')) {
            $candidates[] = 'ORD-'.$normalized;
        }

        $stripped = preg_replace('/^(?:POS|ORD)-/', '', $normalized) ?? $normalized;
        $digitForms = [];
        if (ctype_digit($stripped)) {
            $intVal = (string) ((int) $stripped);
            $pad6 = str_pad($intVal, 6, '0', STR_PAD_LEFT);
            $pad4 = str_pad($intVal, 4, '0', STR_PAD_LEFT);
            foreach (['POS-', 'ORD-'] as $p) {
                $candidates[] = $p.$stripped;
                $candidates[] = $p.$intVal;
                $candidates[] = $p.$pad6;
                $candidates[] = $p.$pad4;
            }
            $orderPrefix = (string) app(SettingService::class)->get('order_prefix', 'ORD-');
            $orderPadding = (int) app(SettingService::class)->get('order_padding', 6);
            if ($orderPrefix !== '') {
                $candidates[] = strtoupper($orderPrefix).str_pad($intVal, max(0, $orderPadding), '0', STR_PAD_LEFT);
                $candidates[] = strtoupper($orderPrefix).$stripped;
            }
            $digitForms = [$stripped, $intVal, $pad6, $pad4];
        }
        // F-08: custom branch prefixes (e.g. BR9-000123) become exact index
        // candidates scoped to the cashier's own branch instead of relying
        // solely on the open LIKE '%-...' fallback below.
        if (is_int($scopedBranch)) {
            $branchCode = strtoupper((string) (Branch::whereKey($scopedBranch)->value('code') ?? ''));
            if ($branchCode !== '') {
                foreach (array_unique(array_merge([$stripped], $digitForms)) as $form) {
                    if ($form !== '') {
                        $candidates[] = $branchCode.'-'.$form;
                    }
                }
            }
        }
        $candidates = array_values(array_unique($candidates));
        $literalSuffix = strtr($stripped, ['!' => '!!', '%' => '!%', '_' => '!_']);

        // Hot path first: an exact index lookup on order_number (O(log N)).
        // The LIKE '%-...' fallback below runs only on a miss, so the common
        // case never forces a sequential scan over the orders table.
        $baseQuery = fn () => Order::query()
            ->whereIn('channel', ['pos', 'store', 'wholesale'])
            ->where('status', OrderStatus::Delivered)
            ->whereNull('return_of_order_id')
            ->with(['items.variant.product', 'customer', 'posShift.cashier']);

        $order = $baseQuery()->whereIn('order_number', $candidates)->latest('id')->first();

        // F-08: digit suffixes keep their zero-flex full-segment matching
        // (existing F16 contract: '123' still finds BR9-000123); free-text
        // suffixes shorter than 4 chars never reach the open LIKE fallback.
        $allowSuffixFallback = $stripped !== '' && (ctype_digit($stripped) || strlen($stripped) >= 4);
        if ($order === null && $allowSuffixFallback) {
            // Fallback for custom branch prefixes not covered by $candidates,
            // scoped to the cashier's branch and the return window so it stays
            // narrow instead of scanning the whole orders history.
            $returnWindowDays = (int) (app(SettingService::class)->get('pos.return_window_days')
                ?? config('inventory.pos_return_window_days', 30));
            $fallback = $baseQuery()
                ->where(function ($q) use ($literalSuffix, $stripped): void {
                    $q->whereRaw("order_number LIKE ? ESCAPE '!'", ['%-'.$literalSuffix]);
                    if (ctype_digit($stripped)) {
                        $intVal = (string) ((int) $stripped);
                        $q->orWhere('order_number', 'like', '%-'.str_pad($intVal, 6, '0', STR_PAD_LEFT));
                    }
                });
            if ($returnWindowDays > 0) {
                $fallback->where('created_at', '>=', now()->subDays($returnWindowDays + 1));
            }
            if (is_int($scopedBranch)) {
                $fallback->where(function ($q) use ($scopedBranch): void {
                    $q->where('allocated_branch_id', $scopedBranch)
                        ->orWhereHas('posShift', fn ($s) => $s->where('branch_id', $scopedBranch));
                });
            }

            $order = $fallback->latest('id')->first();
        }

        if ($order === null) {
            abort(404, 'الفاتورة غير موجودة.');
        }

        // Prefer an invoice from the cashier's own branch before falling back to
        // the globally latest match, so a branch-restricted cashier finds their
        // own invoice instead of failing on another branch's newer invoice.
        // ($scopedBranch resolved once above; reused here.)
        if (is_int($scopedBranch)) {
            $orderBranchId = (int) ($order->allocated_branch_id ?? $order->posShift?->branch_id ?? 0);
            if ($orderBranchId !== $scopedBranch) {
                $scoped = $baseQuery()
                    ->whereIn('order_number', $candidates)
                    ->where(function ($q) use ($scopedBranch): void {
                        $q->where('allocated_branch_id', $scopedBranch)
                            ->orWhereHas('posShift', fn ($s) => $s->where('branch_id', $scopedBranch));
                    })
                    ->latest('id')
                    ->first();
                if ($scoped !== null) {
                    $order = $scoped;
                }
            }
        }

        $activeBranchId = (int) ($request->attributes->get('pos_shift')?->branch_id ?? 0);
        if (in_array($order->channel, ['store', 'wholesale'], true)) {
            $ownsBranchOrder = $activeBranchId > 0
                && ($order->allocated_branch_id === null || (int) $order->allocated_branch_id === $activeBranchId)
                && $order->items()->where('allocated_branch_id', $activeBranchId)->exists()
                && ! $order->items()->where(fn ($items) => $items
                    ->whereNull('allocated_branch_id')
                    ->orWhere('allocated_branch_id', '!=', $activeBranchId))->exists();
            if (! $ownsBranchOrder) {
                abort(404, 'الفاتورة غير موجودة.');
            }
            $branchId = $activeBranchId;
        } else {
            $branchId = (int) ($order->allocated_branch_id ?? $order->posShift?->branch_id ?? 0);
            // F-08: fail closed with 404 when the invoice is detached from every
            // branch instead of leaking a misleading 403 from assertBranch(id, 0).
            if ($branchId <= 0) {
                abort(404, 'الفاتورة غير موجودة.');
            }
        }
        app(BranchAccess::class)->assertBranch($user->id, $branchId);

        // F-07: per-variant refund history so the client can reuse the
        // server's proportional-cent math instead of unit-price multiplication.
        $refundedByVariant = DB::table('order_items as ri')
            ->join('orders as ro', 'ro.id', '=', 'ri.order_id')
            ->where('ro.return_of_order_id', $order->id)
            ->whereNull('ro.deleted_at')
            ->where('ro.status', OrderStatus::Returned)
            ->selectRaw('ri.product_variant_id, SUM(ri.qty) AS qty, SUM(ri.line_total) AS amount')
            ->groupBy('ri.product_variant_id')->get()->keyBy('product_variant_id');

        $reservedReturnByItem = $order->channel !== 'pos' && $order->items->isNotEmpty()
            ? ReturnRequestItem::query()
                ->whereIn('order_item_id', $order->items->pluck('id')->all())
                ->join('return_requests', 'return_requests.id', '=', 'return_request_items.return_request_id')
                ->whereNotIn('return_requests.status', [ReturnStatus::Rejected->value, ReturnStatus::Cancelled->value])
                ->selectRaw("order_item_id, COALESCE(SUM(CASE WHEN return_requests.status IN ('received', 'refunded') THEN received_good_qty + received_damaged_qty WHEN return_requests.status = 'approved' THEN approved_qty ELSE requested_qty END), 0) AS total")
                ->groupBy('order_item_id')
                ->pluck('total', 'order_item_id')
            : collect();

        $customerPayload = $order->customer !== null
            ? ['id' => $order->customer->id, 'name' => $order->customer->name, 'phone' => $order->customer->phone]
            : ($order->guest_name !== null ? ['id' => null, 'name' => $order->guest_name, 'phone' => $order->guest_phone] : null);

        return response()->json([
            'data' => [
                'id' => $order->id,
                'channel' => $order->channel,
                'order_number' => $order->order_number,
                'created_at' => $order->created_at,
                'payment_method' => $order->payment_method,
                'cash_amount' => number_format((float) $order->cash_amount, 2, '.', ''),
                'card_amount' => number_format((float) $order->card_amount, 2, '.', ''),
                'grand_total' => number_format((float) $order->grand_total, 2, '.', ''),
                'customer' => $customerPayload,
                'cashier' => $order->posShift?->cashier !== null ? [
                    'id' => $order->posShift->cashier->id,
                    'name' => $order->posShift->cashier->name,
                ] : null,
                'items' => $order->items->map(function (OrderItem $item) use ($order, $refundedByVariant, $reservedReturnByItem): array {
                    $qty = (float) $item->qty;
                    $returnedQty = (float) ($item->returned_qty ?? 0);
                    $effectiveReturned = $order->channel === 'pos'
                        ? $returnedQty
                        : max($returnedQty, (float) ($reservedReturnByItem[$item->id] ?? 0));
                    $returnableQty = max(0.0, round($qty - $effectiveReturned, 4));
                    $unitPrice = (float) $item->unit_price;
                    $discountAmount = (float) $item->discount_amount;
                    $netUnitPrice = $qty > 0 ? ($qty * $unitPrice - $discountAmount) / $qty : 0.0;
                    $previousRefund = $refundedByVariant->get($item->product_variant_id);

                    return [
                        'id' => $item->id,
                        'product_variant_id' => $item->product_variant_id,
                        // F-07: full line value + already-refunded value so the
                        // client computes proportional cents like the server.
                        'line_total' => number_format((float) $item->line_total, 2, '.', ''),
                        'previous_refunded_amount' => number_format((float) ($previousRefund?->amount ?? 0), 2, '.', ''),
                        'product_name' => $item->product_name_snapshot
                            ?? $item->variant?->product?->name_ar
                            ?? $item->variant?->product?->name_en,
                        'variant_name' => $item->variant?->color_name_ar ?? $item->variant?->color_name_en,
                        'sku' => $item->sku_snapshot ?? $item->variant?->sku,
                        'qty' => floor($qty) === $qty ? (int) $qty : $qty,
                        'returned_qty' => floor($returnedQty) === $returnedQty ? (int) $returnedQty : $returnedQty,
                        'returnable_qty' => floor($returnableQty) === $returnableQty ? (int) $returnableQty : $returnableQty,
                        'unit_price' => number_format($unitPrice, 2, '.', ''),
                        'discount_amount' => number_format($discountAmount, 2, '.', ''),
                        'net_unit_price' => number_format($netUnitPrice, 2, '.', ''),
                    ];
                })->values()->all(),
            ],
        ]);
    }

    /**
     * Atomically lock and lease a held sale for resumption without destroying the row until checkout or explicit deletion.
     */
    public function resumeHeld(Request $request, int $held_sale): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');

        $acquireLease = function () use ($user, $held_sale): PosHeldSale {
            $heldSale = PosHeldSale::query()
                ->whereKey($held_sale)
                ->lockForUpdate()
                ->with('customer:id,name,phone,type,status')
                ->first();

            if ($heldSale === null) {
                abort(404, 'الفاتورة المعلقة غير موجودة.');
            }

            app(BranchAccess::class)->assertBranch($user->id, (int) $heldSale->branch_id);

            if ($heldSale->user_id !== $user->id && ! $user->can('pos.held.view_all')) {
                abort(403, 'لا تملك صلاحية استئناف فاتورة معلقة لمصرفي آخر.');
            }

            if ($heldSale->resumed_at !== null
                && $heldSale->resumed_at->gt(now()->subMinutes(5))
                && (int) $heldSale->resumed_by !== (int) $user->id) {
                throw new BusinessException('الفاتورة المعلقة قيد الاستئناف حالياً من قبل مستخدم آخر.', [], 409);
            }

            $heldSale->update([
                'resumed_by' => $user->id,
                'resumed_at' => now(),
            ]);

            return $heldSale->fresh(['customer:id,name,phone,type,status']);
        };

        $idempotencyKey = $this->idempotencyKey($request);
        /** @var PosHeldSale $heldSale */
        $heldSale = $idempotencyKey !== null
            ? app(OperationIdempotencyService::class)->run(
                'pos_resume_held',
                $user,
                $idempotencyKey,
                ['held_sale_id' => $held_sale],
                $acquireLease,
            )
            : DB::transaction($acquireLease);

        $heldSale->loadMissing('customer:id,name,phone,type,status');

        return response()->json([
            'message' => 'تم استعادة الفاتورة المعلقة بنجاح.',
            'data' => [
                'held_sale_id' => $heldSale->id,
                'id' => $heldSale->id,
                'hold_reference' => $heldSale->hold_reference,
                'customer_id' => $heldSale->customer_id,
                'customer' => $this->formatHeldCustomer($heldSale->customer),
                'notes' => $heldSale->notes,
                'items_payload' => $heldSale->items_payload,
                'resumed_by' => $heldSale->resumed_by,
                'resumed_at' => $heldSale->resumed_at,
            ],
        ]);
    }

    /**
     * @return array{id: int, name: string, phone: string|null, type: string, status: string, loyalty_points: int}|null
     */
    private function formatHeldCustomer(?\App\Models\Customer $customer): ?array
    {
        if ($customer === null) {
            return null;
        }

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'type' => $customer->type instanceof CustomerType ? $customer->type->value : (string) $customer->type,
            'status' => $customer->status instanceof CustomerStatus ? $customer->status->value : (string) $customer->status,
            'loyalty_points' => (int) ($customer->getAttribute('loyalty_points') ?? 0),
        ];
    }

    /**
     * Delete a parked held sale belonging to the cashier's branch.
     */
    public function deleteHeld(Request $request, int $held_sale): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('staff');

        DB::transaction(function () use ($user, $held_sale): void {
            $heldSale = PosHeldSale::query()->lockForUpdate()->find($held_sale);
            if ($heldSale === null) {
                abort(404, 'الفاتورة المعلقة غير موجودة.');
            }

            app(BranchAccess::class)->assertBranch($user->id, (int) $heldSale->branch_id);

            if ($heldSale->user_id !== $user->id && ! $user->can('pos.held.delete_any') && ! $user->can('pos.held.view_all')) {
                abort(403, 'لا تملك صلاحية حذف فاتورة معلقة لمصرفي آخر.');
            }

            if ($heldSale->resumed_at !== null
                && $heldSale->resumed_at->gt(now()->subMinutes(5))
                && $heldSale->resumed_by !== $user->id
            ) {
                throw new BusinessException('الفاتورة المعلقة قيد الاستئناف والدفع من مستخدم آخر حالياً.', status: 409);
            }

            $heldSale->delete();
        });

        return response()->json([
            'message' => 'تم حذف الفاتورة المعلقة بنجاح.',
        ]);
    }

    /**
     * Process item return against an existing POS sale order.
     */
    public function processReturn(Request $request, Order $sale): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_variant_id' => ['required', 'integer', 'min:1'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.return_to' => ['nullable', 'string', 'in:on_hand,damaged'],
        ]);

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');

        $sale->load('items');

        /** @var User $user */
        $user = $request->user('staff');

        /** @var Order $returnOrder */
        $returnOrder = app(OperationIdempotencyService::class)->run(
            'pos_return',
            $user,
            $this->requiredIdempotencyKey($request),
            ['sale_id' => $sale->id, 'items' => $data['items']],
            function () use ($shift, $sale, $data): Order {
                $result = $this->pos->processReturn($shift, $sale, $data['items']);

                return Order::findOrFail($result['return_order_id']);
            },
        );

        return response()->json([
            'message' => 'تم تسجيل المرتجع بنجاح.',
            'data' => [
                'return_order_id' => $returnOrder->id,
                'exchange_order_id' => null,
                'refund_amount' => (float) $returnOrder->grand_total,
                'cash_refund_amount' => (float) $returnOrder->cash_amount,
                'card_refund_amount' => (float) $returnOrder->card_amount,
                'account_credit_amount' => $returnOrder->payment_method === 'credit' ? (float) $returnOrder->grand_total : 0.0,
                'exchange_credit' => (float) $returnOrder->exchange_credit,
            ],
        ]);
    }

    /**
     * Process exchange: atomically return items and create new replacement sale order.
     */
    public function processExchange(Request $request, Order $sale): JsonResponse
    {
        $data = $request->validate([
            'returned_items' => ['required', 'array', 'min:1', 'max:100'],
            'returned_items.*.product_variant_id' => ['required', 'integer', 'min:1'],
            'returned_items.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'returned_items.*.return_to' => ['nullable', 'string', 'in:on_hand,damaged'],
            'exchange_items' => ['required', 'array', 'min:1', 'max:100'],
            'exchange_items.*.product_variant_id' => ['required', 'integer', 'min:1', 'distinct'],
            'exchange_items.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'exchange_items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'exchange_items.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'cash_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'card_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'override_price' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');
        /** @var User $user */
        $user = $request->user('staff');

        $hasDiscounts = collect($data['exchange_items'])->some(
            fn (array $item): bool => isset($item['discount_amount']) && (float) $item['discount_amount'] > 0
        );

        if ($hasDiscounts && ! $user->can('pos.discount')) {
            throw new AuthorizationException('لا تملك صلاحية تطبيق خصم على أصناف الاستبدال.');
        }

        $hasOverrides = $data['override_price'] ?? collect($data['exchange_items'])->some(
            fn (array $item): bool => isset($item['unit_price'])
        );
        if ($hasOverrides && ! $user->can('pos.override_price')) {
            throw new AuthorizationException('لا تملك صلاحية تجاوز أسعار أصناف الاستبدال.');
        }

        /** @var Order $returnOrder */
        $returnOrder = app(OperationIdempotencyService::class)->run(
            'pos_exchange',
            $user,
            $this->requiredIdempotencyKey($request),
            ['sale_id' => $sale->id, ...$data],
            fn (): Order => $this->pos->processExchange($shift, $sale, $user, $data),
        );

        $netRefund = round((float) $returnOrder->grand_total - (float) $returnOrder->exchange_credit, 2);

        return response()->json([
            'message' => 'تم تسجيل الاستبدال بنجاح.',
            'data' => [
                'return_order_id' => $returnOrder->id,
                'exchange_order_id' => $returnOrder->exchange_order_id,
                'refund_amount' => $netRefund,
                'cash_refund_amount' => (float) $returnOrder->cash_amount,
                'card_refund_amount' => (float) $returnOrder->card_amount,
                'exchange_credit' => (float) $returnOrder->exchange_credit,
            ],
        ]);
    }

    /**
     * List cash expenses recorded within the active POS shift.
     */
    public function listExpenses(Request $request): JsonResponse
    {
        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');
        /** @var User $user */
        $user = $request->user('staff');

        return response()->json([
            'data' => $this->pos->listShiftExpenses($shift, $user),
        ]);
    }

    /**
     * Record an operating cash expense directly from the active POS drawer.
     */
    public function recordExpense(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99', 'decimal:0,2'],
            'category' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');
        /** @var User $user */
        $user = $request->user('staff');

        $expense = app(OperationIdempotencyService::class)->run(
            'pos_expense',
            $user,
            $this->requiredIdempotencyKey($request),
            ['shift_id' => $shift->id, ...$data],
            fn (): Expense => $this->pos->recordExpense($shift, $data, (int) $user->id),
        );

        return response()->json([
            'message' => 'تم تسجيل المصروف بنجاح.',
            'data' => $this->formatExpense($expense),
        ], 201);
    }

    /**
     * Update an expense recorded in the active POS shift (amount changes atomically void and replace).
     */
    public function updateExpense(Request $request, int $expense): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['sometimes', 'numeric', 'min:0.01', 'max:999999.99', 'decimal:0,2'],
            'category' => ['sometimes', 'required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        if ($data === []) {
            throw new BusinessException('حدد حقلًا واحدًا على الأقل لتعديل المصروف.');
        }

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');
        /** @var User $user */
        $user = $request->user('staff');

        $updated = app(OperationIdempotencyService::class)->run(
            'pos_expense_update',
            $user,
            $this->requiredIdempotencyKey($request),
            ['shift_id' => $shift->id, 'expense_id' => $expense, ...$data],
            fn (): Expense => $this->pos->updateShiftExpense($shift, $expense, $data, $user),
        );

        return response()->json([
            'message' => 'تم تحديث المصروف بنجاح.',
            'data' => $this->formatExpense($updated),
        ]);
    }

    /**
     * Void an expense recorded in the active POS shift and reverse its drawer debit.
     */
    public function voidExpense(Request $request, int $expense): JsonResponse
    {
        $data = $request->validate([
            'void_reason' => ['required', 'string', 'min:2', 'max:500'],
        ]);

        /** @var PosShift $shift */
        $shift = $request->attributes->get('pos_shift');
        /** @var User $user */
        $user = $request->user('staff');
        $reason = trim($data['void_reason']);

        $voided = app(OperationIdempotencyService::class)->run(
            'pos_expense_void',
            $user,
            $this->requiredIdempotencyKey($request),
            ['shift_id' => $shift->id, 'expense_id' => $expense, 'void_reason' => $reason],
            fn (): Expense => $this->pos->voidShiftExpense($shift, $expense, $reason, $user),
        );

        return response()->json([
            'message' => 'تم إلغاء المصروف وإعادة المبلغ إلى الدرج.',
            'data' => $this->formatExpense($voided),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatExpense(Expense $expense): array
    {
        $expense->loadMissing('creator:id,name');
        $isActive = $expense->voided_at === null;

        return [
            'id' => $expense->id,
            'shift_id' => $expense->shift_id,
            'expense_number' => $expense->expense_number,
            'amount' => (float) $expense->amount,
            'category' => $expense->category,
            'notes' => $expense->notes,
            'status' => $isActive ? 'posted' : 'voided',
            'voided_at' => $expense->voided_at,
            'void_reason' => $expense->void_reason,
            'can_edit' => $isActive,
            'can_void' => $isActive,
            'created_by' => $expense->creator
                ? ['id' => $expense->creator->id, 'name' => $expense->creator->name]
                : null,
            'created_at' => $expense->created_at,
        ];
    }

    // ===================================================================
    //  Helper
    // ===================================================================

    /**
     * Formats order and line items for POS receipt printer and client payload.
     *
     * @param  Order  $order  Target order model
     * @return array<string, mixed> Formatted POS order data
     */
    private function formatOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'channel' => $order->channel,
            'status' => $order->status->value,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'grand_total' => $order->grand_total,
            'cash_amount' => $order->cash_amount,
            'card_amount' => $order->card_amount,
            'customer' => $order->relationLoaded('customer') ? [
                'id' => $order->customer?->id,
                'name' => $order->customer?->name,
            ] : null,
            'items' => $order->relationLoaded('items') ? $order->items->map(fn ($i) => [
                'product_variant_id' => $i->product_variant_id,
                'sku' => $i->relationLoaded('variant') ? $i->variant?->sku : null,
                'qty' => $i->qty,
                'unit_price' => $i->unit_price,
                'discount_amount' => $i->discount_amount,
                'line_total' => $i->line_total,
            ])->all() : [],
        ];
    }
}
