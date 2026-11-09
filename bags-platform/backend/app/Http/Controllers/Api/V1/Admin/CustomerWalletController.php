<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\WalletAdjustment;
use App\Services\Ai\Finance\FinancePresenter;
use App\Services\Finance\WalletAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Admin wallet inspection and manual adjustments.
 */
class CustomerWalletController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:customers.wallet.view', only: ['show', 'summary', 'adjustments']),
            new Middleware('can:customers.wallet.adjust', only: ['storeAdjustment', 'reverseAdjustment']),
        ];
    }

    public function __construct(
        private readonly WalletAdjustmentService $adjustments,
        private readonly FinancePresenter $presenter,
    ) {}

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $entries = CustomerWalletTransaction::query()->where('customer_id', $customer->id)
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->latest('id')->paginate($filters['per_page'] ?? 20);
        $entries->getCollection()->transform(fn (CustomerWalletTransaction $tx) => [
            'id' => $tx->id,
            'type' => $tx->type,
            'type_label' => $this->presenter->walletTypeLabel((string) $tx->type),
            'amount' => $tx->amount,
            'balance_after' => $tx->balance_after,
            'reference_type' => $tx->reference_type,
            'reference_id' => $tx->reference_id,
            'reference_label' => $this->presenter->referenceLabel($tx->reference_type, $tx->reference_id !== null ? (int) $tx->reference_id : null),
            'created_at' => $tx->created_at,
        ]);

        return response()->json(['balance' => $customer->wallet_balance, 'transactions' => $entries]);
    }

    public function summary(Customer $customer): JsonResponse
    {
        $totals = CustomerWalletTransaction::query()->where('customer_id', $customer->id)
            ->selectRaw('type, SUM(amount) AS total, COUNT(*) AS cnt')
            ->groupBy('type')->get();
        $bucket = ['topups' => 0.0, 'refunds' => 0.0, 'payments' => 0.0, 'adjustments' => 0.0];
        foreach ($totals as $row) {
            $total = (float) $row->total;
            match ((string) $row->type) {
                'topup', 'opening' => $bucket['topups'] += $total,
                'return_refund', 'refund', 'checkout_refund', 'order_reversal' => $bucket['refunds'] += $total,
                'checkout_payment' => $bucket['payments'] += $total,
                default => $bucket['adjustments'] += $total,
            };
        }
        $adjustments = WalletAdjustment::query()->where('customer_id', $customer->id)
            ->with('creator:id,name')->latest('id')->limit(20)->get();

        return response()->json([
            'balance' => $customer->wallet_balance,
            'breakdown' => $bucket,
            'adjustments' => $adjustments,
        ]);
    }

    public function adjustments(Request $request, Customer $customer): JsonResponse
    {
        $filters = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $rows = WalletAdjustment::query()->where('customer_id', $customer->id)
            ->with(['creator:id,name'])->latest('id')->paginate($filters['per_page'] ?? 20);

        return response()->json($rows);
    }

    public function storeAdjustment(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:disbursement,refund,adjustment'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999', 'decimal:0,2'],
            'direction' => ['nullable', 'string', 'in:credit,debit'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $adjustment = $this->adjustments->adjust(
            ['customer_id' => $customer->id, ...$data, 'source' => 'admin'],
            $request->user(),
            $request->header('Idempotency-Key')
        );

        return response()->json(['message' => 'تم تسجيل تعديل المحفظة.', 'data' => $adjustment->load('creator')], 201);
    }

    public function reverseAdjustment(Request $request, WalletAdjustment $adjustment): JsonResponse
    {
        $reversal = $this->adjustments->reverse($adjustment, $request->user(), $request->header('Idempotency-Key'));

        return response()->json(['message' => 'تم عكس التعديل بقيد مقابل.', 'data' => $reversal->load('creator')], 201);
    }
}
