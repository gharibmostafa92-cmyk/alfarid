<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Services\Ai\Finance\FinancePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function index(Request $request, FinancePresenter $presenter): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'direction' => ['nullable', 'string', 'in:credit,debit'],
            'type' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $customerId = $request->user()->id;
        $query = CustomerWalletTransaction::query()->where('customer_id', $customerId);
        if (($validated['page'] ?? 1) <= 1) {
            $query->useWritePdo();
        }
        if (isset($validated['direction'])) {
            $query->where('amount', $validated['direction'] === 'credit' ? '>' : '<', 0);
        }
        if (isset($validated['type']) && $validated['type'] !== '') {
            $query->where('type', $validated['type']);
        }

        $businessTz = (string) config('app.business_timezone', 'Africa/Cairo');
        if (! empty($validated['from'])) {
            $fromUtc = CarbonImmutable::createFromFormat('!Y-m-d', $validated['from'], $businessTz)?->startOfDay()->utc()->toDateTimeString();
            if ($fromUtc !== null) {
                $query->where('created_at', '>=', $fromUtc);
            }
        }
        if (! empty($validated['to'])) {
            $toUtc = CarbonImmutable::createFromFormat('!Y-m-d', $validated['to'], $businessTz)?->addDay()->startOfDay()->utc()->toDateTimeString();
            if ($toUtc !== null) {
                $query->where('created_at', '<', $toUtc);
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

        $balance = Customer::query()->useWritePdo()->whereKey($customerId)->firstOrFail(['wallet_balance'])->wallet_balance;

        return response()->json(['balance' => $balance, 'transactions' => $entries]);
    }
}
