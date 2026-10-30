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
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'entity' => ['nullable', 'in:branch,user,role']]);
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

        return response()->json(['data' => $out]);
    }
}
