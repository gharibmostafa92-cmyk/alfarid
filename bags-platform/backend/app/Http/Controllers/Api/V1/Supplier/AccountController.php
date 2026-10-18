<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Http\Requests\Supplier\Account\StatementRequest;
use App\Models\SupplierUser;
use App\Services\SupplierFinanceService;
use Illuminate\Http\JsonResponse;

/**
 * كشف حساب المورد (Supplier Ledger): رصيد افتتاحي + فواتير معتمدة
 * دائنة ودفعات مدينة برصيد تراكمي متحرك — من SupplierFinanceService.
 */
class AccountController extends Controller
{
    public function __construct(protected SupplierFinanceService $finance) {}

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
