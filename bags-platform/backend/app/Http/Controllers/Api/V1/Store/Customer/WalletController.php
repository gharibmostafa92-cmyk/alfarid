<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerWalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function index(Request $request, \App\Services\Ai\Finance\FinancePresenter $presenter): JsonResponse
    {
        $query = CustomerWalletTransaction::query()->where('customer_id', $request->user()->id);
        if (in_array($request->query('direction'), ['credit', 'debit'], true)) {
            $query->where('amount', $request->query('direction') === 'credit' ? '>' : '<', 0);
        }
        if (is_string($request->query('type')) && $request->query('type') !== '') {
            $query->where('type', $request->query('type'));
        }
        foreach (['from' => '>=', 'to' => '<='] as $param => $op) {
            $value = $request->query($param);
            if (is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1) {
                $query->whereDate('created_at', $op, $value);
            }
        }
        $entries = $query->latest('id')->paginate(20, ['id', 'type', 'amount', 'balance_after', 'reference_type', 'reference_id', 'created_at']);
        $entries->getCollection()->transform(fn (CustomerWalletTransaction $tx) => [
            'id' => $tx->id,
            'type' => $tx->type,
            'type_label' => $presenter->walletTypeLabel((string) $tx->type),
            'amount' => $tx->amount,
            'balance_after' => $tx->balance_after,
            'reference_type' => $tx->reference_type,
            'reference_id' => $tx->reference_id,
            'reference_label' => $presenter->referenceLabel($tx->reference_type, $tx->reference_id !== null ? (int) $tx->reference_id : null),
            'created_at' => $tx->created_at,
        ]);

        return response()->json(['balance' => $request->user()->wallet_balance, 'transactions' => $entries]);
    }
}
