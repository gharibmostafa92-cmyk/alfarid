<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\Account\StatementRequest;
use App\Models\SupplierUser;
use App\Services\SupplierFinanceService;
use Illuminate\Http\JsonResponse;

/**
 * Supplier portal ledger statement: opening balance, approved invoices, and debit payments with running balances.
 */
class AccountController extends Controller
{
    public function __construct(protected SupplierFinanceService $finance) {}

    /**
     * Retrieve chronological account statement for the authenticated supplier within a date range.
     */
    public function statement(StatementRequest $request): JsonResponse
    {
        /** @var SupplierUser $user */
        $user = $request->user();
        $supplier = $user->supplier()->firstOrFail();

        return response()->json(['data' => $this->finance->statement(
            $supplier,
            $request->validated('from_date'),
            $request->validated('to_date'),
        )]);
    }
}
