<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\PosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * البحث عن المنتجات في شاشة الـ POS بالباركود أو SKU أو اسم المنتج.
 */
class PosProductController extends Controller
{
    public function __construct(private readonly PosService $pos) {}

    /**
     * GET /pos/products/lookup?barcode=&q=&customer_id=
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
