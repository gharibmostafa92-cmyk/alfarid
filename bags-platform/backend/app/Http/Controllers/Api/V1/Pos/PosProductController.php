<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Inventory\BranchAccess;
use App\Services\Pos\PosService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POS product barcode and SKU lookup with branch stock availability and tiered customer pricing.
 */
class PosProductController extends Controller
{
    public function __construct(private readonly PosService $pos) {}

    /**
     * Retrieve the full active branch catalog with aggregated stock, batch-resolved pricing, and ETag 304 caching.
     */
    public function catalog(Request $request): JsonResponse|Response
    {
        /** @var \App\Models\User $user */
        $user = $request->user('staff');
        if ($user->branch_id === null) {
            throw new AuthorizationException('حسابك غير مرتبط بأي فرع.');
        }

        $branchId = (int) $user->branch_id;
        app(BranchAccess::class)->assertBranch($user->id, $branchId);

        $catalogVersion = $this->pos->computeCatalogVersion($branchId);
        $etag = "\"{$catalogVersion}\"";

        $ifNoneMatch = $request->header('If-None-Match');
        if ($ifNoneMatch !== null) {
            $trimmed = trim($ifNoneMatch);
            if ($trimmed === $etag || $trimmed === $catalogVersion || trim($trimmed, '"') === $catalogVersion) {
                return response('', 304)->header('ETag', $etag);
            }
        }

        $cursor = $request->query('cursor');
        $payload = $this->pos->getBranchCatalog($branchId, $catalogVersion, is_string($cursor) ? $cursor : null);

        return response()->json($payload)->header('ETag', $etag);
    }

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
