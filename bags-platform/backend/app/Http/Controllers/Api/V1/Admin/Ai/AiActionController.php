<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiPendingAction;
use App\Services\Ai\PendingActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiActionController extends Controller
{
    public function confirm(Request $request, AiPendingAction $action, PendingActionService $service): JsonResponse
    {
        $data = $request->validate(['typed_confirmation' => ['nullable', 'string', 'max:255'], 'selected_items' => ['nullable', 'array', 'min:1', 'max:100'], 'selected_items.*' => ['string', 'ulid', 'distinct']]);
        $outcome = $service->confirm($request->user('staff'), $action, $data['typed_confirmation'] ?? null, $data['selected_items'] ?? null);

        // generated_password is returned once to confirming admin, never persisted in messages
        return response()->json(['data' => ['ok' => $outcome->type === 'result', 'message' => $outcome->message, 'result' => $outcome->data, 'ui_block' => $outcome->uiBlock, 'status' => $action->fresh()->status]]);
    }

    public function cancel(Request $request, AiPendingAction $action, PendingActionService $service): JsonResponse
    {
        $service->cancel($request->user('staff'), $action);

        return response()->json(['data' => ['status' => $action->fresh()->status]]);
    }

    public function undo(Request $request, AiPendingAction $action, PendingActionService $service): JsonResponse
    {
        $outcome = $service->undo($request->user('staff'), $action);

        return response()->json(['data' => ['ok' => $outcome->type === 'result', 'message' => $outcome->message, 'status' => $action->fresh()->status]]);
    }
}
