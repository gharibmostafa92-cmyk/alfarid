<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AiConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user('staff');
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'all' => ['sometimes', 'boolean']]);
        $query = AiConversation::query()->withCount('messages')->when(! empty($data['q']), fn ($q) => $q->where('title', 'ilike', '%'.$data['q'].'%'))->orderByDesc('is_pinned')->orderByDesc('last_message_at')->orderByDesc('id');
        if (! $request->query('all') || ! $actor->can('ai_agent.logs.view')) {
            $query->where('user_id', $actor->id);
        }
        $perPage = min(100, max(1, (int) $request->query('per_page', 15)));

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $conv = AiConversation::create(['user_id' => $request->user('staff')->id, 'model' => (string) config('services.gemini.model')]);

        return response()->json(['data' => $conv], 201);
    }

    public function show(Request $request, AiConversation $conversation): JsonResponse
    {
        $this->authorizeView($request, $conversation);

        return response()->json(['data' => $conversation->load(['messages' => fn ($q) => $q->where('role', '!=', 'tool')->where(fn ($m) => $m->whereNotNull('content')->orWhereNotNull('ui_blocks'))->orderBy('id'), 'pendingActions' => fn ($q) => $q->select('id', 'conversation_id', 'status', 'expires_at')])]);
    }

    public function destroy(Request $request, AiConversation $conversation): Response
    {
        abort_unless($conversation->user_id === $request->user('staff')->id, 403);
        $conversation->delete();

        return response()->noContent();
    }

    public function pin(Request $request, AiConversation $conversation): JsonResponse
    {
        abort_unless($conversation->user_id === $request->user('staff')->id, 403);
        $data = $request->validate(['is_pinned' => ['required', 'boolean']]);
        $conversation->forceFill($data)->save();

        return response()->json(['data' => $conversation]);
    }

    private function authorizeView(Request $request, AiConversation $c): void
    {
        $actor = $request->user('staff');
        abort_unless($c->user_id === $actor->id || $actor->can('ai_agent.logs.view'), 403);
    }
}
