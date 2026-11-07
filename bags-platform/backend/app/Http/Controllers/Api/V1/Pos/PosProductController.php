<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\PosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POS product barcode and SKU lookup with branch stock availability and tiered customer pricing.
 */
class PosProductController extends Controller
{
    public function __construct(private readonly PosService $pos) {}

    /**
     * Search products by barcode, SKU, or name for the cashier's active branch.
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'barcode'     => ['nullable', 'string', 'max:100'],
            'q'           => ['nullable', 'string', 'max:100'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $data['barcode'] ?? $data['q'] ?? '';

        if (empty($query)) {
            return response()->json(['data' => []]);
        }

        /** @var \App\Models\User $user */
        $user     = $request->user('staff');
        $customer = isset($data['customer_id'])
            ? Customer::find($data['customer_id'])
            : null;

        $results = $this->pos->lookupProducts($query, (int) $user->branch_id, $customer);

        return response()->json(['data' => $results]);
    }
}
