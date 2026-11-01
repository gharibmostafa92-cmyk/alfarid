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
    public function search(Request $request, EntityResolver $resolver, ToolRegistry $registry): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'entity' => ['nullable', 'in:branch,user,role,customer']]);
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

        // العملاء بلا branch scoping عمدًا — زي REST (CustomerController مبيستخدمش BranchAccess).
        if ((! $entity || $entity === 'customer') && $registry->canReadEntity($actor, 'customer')) {
            $out['customers'] = $resolver->customers($actor, $q);
        }

        return response()->json(['data' => $out]);
    }
}
