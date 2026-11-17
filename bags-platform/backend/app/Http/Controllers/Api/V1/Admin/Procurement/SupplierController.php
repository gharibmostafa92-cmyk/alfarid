<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Procurement;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Procurement\StoreSupplierUserRequest;
use App\Http\Requests\Admin\Procurement\SupplierRequest;
use App\Http\Requests\Admin\Procurement\UpdateSupplierUserRequest;
use App\Http\Resources\Admin\Procurement\SupplierResource;
use App\Http\Resources\Admin\Procurement\SupplierUserResource;
use App\Models\Supplier;
use App\Models\SupplierUser;
use App\Services\Inventory\BranchAccess;
use App\Services\Procurement\SupplierFinanceService;
use App\Services\Procurement\SupplierService;
use App\Services\Shared\SearchTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/**
 * Supplier profiles and portal staff administration with ledger balance tracking.
 */
class SupplierController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:suppliers.view', only: ['index', 'show', 'users', 'statement']),
            new Middleware('can:suppliers.create', only: ['store']),
            new Middleware('can:suppliers.update', only: ['update', 'storeUser', 'updateUser']),
            new Middleware('can:suppliers.delete', only: ['destroy', 'destroyUser']),
        ];
    }

    public function __construct(
        protected SupplierService $suppliers,
        protected SupplierFinanceService $finance,
        protected BranchAccess $branchAccess,
    ) {}

    /**
     * List paginated suppliers with case-insensitive search and status filtering.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $suppliers = Supplier::query()
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', (bool) $filters['is_active']))
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search): void {
                $pattern = SearchTerm::pattern($search);
                $w->where('name', 'ilike', $pattern)
                    ->orWhere('code', 'ilike', $pattern)
                    ->orWhere('company_name', 'ilike', $pattern);
            }))
            ->latest('id')
            ->paginate(20);

        return SupplierResource::collection($suppliers);
    }

    /**
     * Create a new supplier profile and initialize ledger balance.
     */
    public function store(SupplierRequest $request): JsonResponse
    {
        $this->branchAccess->assertCentralAccess($request->user());
        $supplier = $this->suppliers->create($request->validated());

        return SupplierResource::make($supplier)->response()->setStatusCode(201);
    }

    /**
     * Retrieve supplier profile details and portal users.
     */
    public function show(Supplier $supplier): SupplierResource
    {
        return SupplierResource::make($supplier->loadMissing('users'));
    }

    /**
     * Administrative chronological statement with internal void reasons included.
     */
    public function statement(Request $request, Supplier $supplier): JsonResponse
    {
        $this->branchAccess->assertCentralAccess($request->user());
        $filters = $request->validate([
            'from_date' => ['nullable', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ]);

        return response()->json(['data' => $this->finance->statement(
            $supplier,
            $filters['from_date'] ?? null,
            $filters['to_date'] ?? null,
            internal: true,
        )]);
    }

    /**
     * Update supplier profile details.
     */
    public function update(SupplierRequest $request, Supplier $supplier): SupplierResource
    {
        $this->branchAccess->assertCentralAccess($request->user());

        return SupplierResource::make($this->suppliers->update($supplier, $request->validated()));
    }

    /**
     * Delete an unused supplier profile.
     */
    public function destroy(Request $request, Supplier $supplier): JsonResponse
    {
        $this->branchAccess->assertCentralAccess($request->user());
        DB::transaction(function () use ($supplier): void {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            if ($supplier->purchaseOrders()->exists() || $supplier->invoices()->exists()
                || $supplier->payments()->exists() || $supplier->users()->exists()
                || $supplier->deliveries()->exists()) {
                throw new BusinessException('لا يمكن حذف مورد له أوامر شراء أو فواتير أو دفعات أو حسابات مستخدمين؛ عطّله بدلًا من حذفه.');
            }
            $supplier->delete();
        });

        return response()->json(['message' => 'تم حذف المورد.']);
    }

    // --- Supplier Portal User Accounts ---

    /**
     * List portal user accounts associated with the supplier.
     */
    public function users(Request $request, Supplier $supplier): AnonymousResourceCollection
    {
        $this->branchAccess->assertCentralAccess($request->user());

        return SupplierUserResource::collection($supplier->users()->latest('id')->get());
    }

    /**
     * Create a new portal user account for the supplier.
     */
    public function storeUser(StoreSupplierUserRequest $request, Supplier $supplier): JsonResponse
    {
        $this->branchAccess->assertCentralAccess($request->user());
        $data = $request->validated();
        $user = DB::transaction(function () use ($supplier, $data): SupplierUser {
            $lockedSupplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            if (! $lockedSupplier->is_active && ($data['is_active'] ?? true)) {
                throw new BusinessException('لا يمكن إنشاء أو تفعيل مستخدم لمورد معطل.');
            }

            return $lockedSupplier->users()->create($data);
        });

        return SupplierUserResource::make($user)->response()->setStatusCode(201);
    }

    /**
     * Update supplier portal user account and invalidate tokens on credential changes.
     */
    public function updateUser(UpdateSupplierUserRequest $request, Supplier $supplier, SupplierUser $user): SupplierUserResource
    {
        $this->branchAccess->assertCentralAccess($request->user());
        $this->assertBelongsToSupplier($supplier, $user);
        $data = $request->validated();
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user = DB::transaction(function () use ($supplier, $user, $data): SupplierUser {
            $lockedSupplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            $user = $lockedSupplier->users()->lockForUpdate()->findOrFail($user->id);
            if (! $lockedSupplier->is_active && ($data['is_active'] ?? $user->is_active)) {
                throw new BusinessException('لا يمكن تفعيل مستخدم لمورد معطل.');
            }
            $emailChanged = isset($data['email']) && mb_strtolower(trim((string) $data['email'])) !== mb_strtolower((string) $user->email);
            $user->update($data);
            if (isset($data['password']) || $emailChanged || (isset($data['is_active']) && ! $data['is_active'])) {
                $user->tokens()->delete();
            }

            return $user;
        });

        return SupplierUserResource::make($user->fresh());
    }

    /**
     * Revoke tokens and delete a supplier portal user account.
     */
    public function destroyUser(Request $request, Supplier $supplier, SupplierUser $user): JsonResponse
    {
        $this->branchAccess->assertCentralAccess($request->user());
        $this->assertBelongsToSupplier($supplier, $user);
        DB::transaction(function () use ($supplier, $user): void {
            // Serialize with AuthController::issueToken(), which locks the same row.
            $locked = $supplier->users()->lockForUpdate()->findOrFail($user->id);
            $locked->tokens()->delete();
            $locked->delete();
        });

        return response()->json(['message' => 'تم حذف حساب موظف المورد.']);
    }

    /**
     * Verifies that the portal user belongs to the specified supplier, aborting 404 otherwise.
     *
     * @param  Supplier  $supplier  Parent supplier
     * @param  SupplierUser  $user  Target portal user
     */
    private function assertBelongsToSupplier(Supplier $supplier, SupplierUser $user): void
    {
        abort_if((int) $user->supplier_id !== (int) $supplier->id, 404);
    }
}
