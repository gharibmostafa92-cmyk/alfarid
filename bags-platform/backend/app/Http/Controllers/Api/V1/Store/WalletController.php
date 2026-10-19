<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Models\CustomerWalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $entries = CustomerWalletTransaction::query()->where('customer_id', $request->user()->id)
            ->latest('id')->paginate(20, ['id', 'type', 'amount', 'balance_after', 'reference_type', 'reference_id', 'created_at']);

        return response()->json(['balance' => $request->user()->wallet_balance, 'transactions' => $entries]);
    }
}
