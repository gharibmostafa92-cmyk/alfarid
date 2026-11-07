<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\ToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiMentionController extends Controller
{
    /**
     * Search mentionable entities (users, branches, products, etc.) filtered by actor permissions.
     */
    public function search(Request $request, EntityResolver $resolver, ToolRegistry $registry): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'entity' => ['nullable', 'in:branch,user,role,customer,category,product,variant,transfer,stocktake,production_receipt,treasury,treasury_transfer,expense']]);
        $actor = $request->user('staff');
        $q = (string) ($data['q'] ?? '');
        $entity = $data['entity'] ?? null;
        $out = [];
        if ((! $entity || $entity === 'user') && $registry->canReadEntity($actor, 'user')) {
            $out['users'] = $resolver->users($actor, $q);
        }
        if ((! $entity || $entity === 'branch') && $registry->canReadEntity($actor, 'branch')) {
            $out['branches'] = $resolver->branches($actor, $q);
        }
        if ((! $entity || $entity === 'role') && $registry->canReadEntity($actor, 'role')) {
            $out['roles'] = $resolver->roles($q);
        }

        // Customers have no branch scoping, mirroring REST behavior in CustomerController.
        if ((! $entity || $entity === 'customer') && $registry->canReadEntity($actor, 'customer')) {
            $out['customers'] = $resolver->customers($actor, $q);
        }
        if ((! $entity || $entity === 'category') && $registry->canReadEntity($actor, 'category')) {
            $out['categories'] = $resolver->categories($actor, $q);
        }
        if ((! $entity || $entity === 'product') && $registry->canReadEntity($actor, 'product')) {
            $out['products'] = $resolver->products($actor, $q);
        }
        if ((! $entity || $entity === 'variant') && $registry->canReadEntity($actor, 'variant')) {
            $out['variants'] = $resolver->variants($actor, $q);
        }
        if ((! $entity || $entity === 'transfer') && $registry->canReadEntity($actor, 'transfer')) {
            $out['transfers'] = $resolver->transfers($actor, $q);
        }
        if ((! $entity || $entity === 'stocktake') && $registry->canReadEntity($actor, 'stocktake')) {
            $out['stocktakes'] = $resolver->stocktakes($actor, $q);
        }
        if ((! $entity || $entity === 'production_receipt') && $registry->canReadEntity($actor, 'production_receipt')) {
            $out['production_receipts'] = $resolver->productionReceipts($actor, $q);
        }
        if ((! $entity || $entity === 'treasury') && $registry->canReadEntity($actor, 'treasury')) {
            $out['treasuries'] = $resolver->treasuries($actor, $q);
        }
        if ((! $entity || $entity === 'treasury_transfer') && $registry->canReadEntity($actor, 'treasury_transfer')) {
            $out['treasury_transfers'] = $resolver->treasuryTransfers($actor, $q);
        }
        if ((! $entity || $entity === 'expense') && $registry->canReadEntity($actor, 'expense')) {
            $out['expenses'] = $resolver->expenses($actor, $q);
        }

        return response()->json(['data' => $out]);
    }
}
