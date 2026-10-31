<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Services\Ai\AgentOrchestrator;
use App\Services\Ai\GeminiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AiMessageController extends Controller
{
    public function stream(Request $request, AiConversation $conversation, AgentOrchestrator $orchestrator): StreamedResponse
    {
        abort_unless($conversation->user_id === $request->user('staff')->id, 403);
        $data = $this->validated($request);
        $orchestrator->guard($request->user('staff'));

        return response()->stream(function () use ($request, $conversation, $orchestrator, $data): void {
            $emit = static function (string $event, array $payload): void {
                echo 'event: '.$event."\n".'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };
            try {
                $reply = $orchestrator->handle($request->user('staff'), $conversation, $data, fn ($status) => $emit('status', ['message' => $status]));
                $emit('reply', ['text' => $reply->text, 'ui_blocks' => $reply->blocks, 'message_id' => $reply->messageId]);
            } catch (\Throwable $e) {
                // L-9: أخطاء التفويض والتحقق ليست HttpExceptionInterface —
                // بدون تصنيف كانت تظهر كـ 502 مضللة.
                $status = match (true) {
                    $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                    $e instanceof AuthorizationException => 403,
                    $e instanceof ValidationException => 422,
                    default => 502,
                };
                if ($status >= 500) {
                    report($e);
                }
                $emit('error', ['message' => $status < 500 ? $e->getMessage() : 'تعذر إتمام طلب المساعد. حاول مرة تانية.', 'status' => $status]);
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-store', 'X-Accel-Buffering' => 'no']);
    }

    public function store(Request $request, AiConversation $conversation, AgentOrchestrator $orchestrator): JsonResponse
    {
        abort_unless($conversation->user_id === $request->user('staff')->id, 403);
        $data = $this->validated($request);
        try {
            $reply = $orchestrator->handle($request->user('staff'), $conversation, $data);
        } catch (GeminiException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => ['text' => $reply->text, 'ui_blocks' => $reply->blocks, 'message_id' => $reply->messageId]]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'text' => ['nullable', 'string', 'max:8000'],
            'selection' => ['nullable', 'array:entity,id,label'], 'selection.entity' => ['required_with:selection', 'in:branch,user,role,permission,location'], 'selection.id' => ['required_with:selection', Rule::when($request->input('selection.entity') !== 'permission', ['integer', 'min:1'], ['string', 'max:255'])], 'selection.label' => ['nullable', 'string', 'max:255'],
            'mentions' => ['nullable', 'array', 'max:25'], 'mentions.*.entity' => ['required', 'in:branch,user,role,location'], 'mentions.*.id' => ['required', 'integer', 'min:1'],
        ]);
    }
}
