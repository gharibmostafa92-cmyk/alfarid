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
use Sentry\Laravel\Facade as Sentry;
use Sentry\Tracing\SpanContext;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AiMessageController extends Controller
{
    /**
     * Stream AI assistant responses via Server-Sent Events (SSE).
     */
    public function stream(Request $request, AiConversation $conversation, AgentOrchestrator $orchestrator): StreamedResponse
    {
        abort_unless($conversation->user_id === $request->user('staff')->id, 403);
        $data = $this->validated($request);
        $orchestrator->guard($request->user('staff'));

        return response()->stream(function () use ($request, $conversation, $orchestrator, $data): void {
            $startedAt = microtime(true);
            $streamSpan = Sentry::getSpan()?->startChild(
                SpanContext::make()
                    ->setOp('ai.stream')
                    ->setDescription('ai.stream')
                    ->setStartTimestamp($startedAt),
            );
            $firstOutputRecorded = false;
            $emit = static function (string $event, array $payload) use ($streamSpan, $startedAt, &$firstOutputRecorded): void {
                if ($event === 'reply'
                    && ! $firstOutputRecorded
                    && is_string($payload['text'] ?? null)
                    && $payload['text'] !== '') {
                    $firstOutputRecorded = true;
                    $streamSpan?->startChild(
                        SpanContext::make()
                            ->setOp('ai.stream.first_output')
                            ->setDescription('ai.stream.first_output')
                            ->setStartTimestamp($startedAt),
                    )->finish();
                }

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
                // Map AuthorizationException (403) and ValidationException (422) directly without reporting as 502 errors.
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
            } finally {
                $streamSpan?->finish();
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-store', 'X-Accel-Buffering' => 'no']);
    }

    /**
     * Submit a synchronous message turn to the AI assistant.
     */
    public function store(Request $request, AiConversation $conversation, AgentOrchestrator $orchestrator): JsonResponse
    {
        abort_unless($conversation->user_id === $request->user('staff')->id, 403);
        $data = $this->validated($request);
        $orchestrator->guard($request->user('staff'));
        try {
            $reply = $orchestrator->handle($request->user('staff'), $conversation, $data);
        } catch (\Illuminate\Validation\ValidationException|\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (GeminiException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'تعذر إتمام طلب المساعد. حاول مرة تانية.'], 502);
        }

        return response()->json(['data' => ['text' => $reply->text, 'ui_blocks' => $reply->blocks, 'message_id' => $reply->messageId]]);
    }

    private function validated(Request $request): array
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'text' => ['nullable', 'string', 'max:8000'],
            'selection' => ['nullable', 'array:entity,id,label'], 'selection.entity' => ['required_with:selection', 'in:branch,user,role,permission,location,customer,customer_group,category,product,variant,transfer,stocktake,production_receipt,treasury,treasury_transfer,expense,price_list,coupon,promotion,quotation,payment_notice'], 'selection.id' => ['required_with:selection', Rule::when($request->input('selection.entity') !== 'permission', ['integer', 'min:1'], ['string', 'max:255'])], 'selection.label' => ['nullable', 'string', 'max:255'],
            'mentions' => ['nullable', 'array', 'max:25'], 'mentions.*.entity' => ['required', 'in:branch,user,role,location,customer,category,product,variant,transfer,stocktake,production_receipt,treasury,treasury_transfer,expense,price_list,coupon,promotion,quotation,payment_notice'], 'mentions.*.id' => ['required', 'integer', 'min:1'],
            'attachments' => ['nullable', 'array', 'max:4'], 'attachments.*' => ['required', 'string', 'ulid', 'distinct'],
        ]);
        $validator->after(function ($v) use ($request): void {
            $hasText = trim((string) $request->input('text', '')) !== '';
            $hasSelection = ! empty($request->input('selection'));
            $hasAttachments = ! empty($request->input('attachments'));
            if (! $hasText && ! $hasSelection && ! $hasAttachments) {
                $v->errors()->add('text', 'أرسل نصًا أو تحديدًا أو مرفقًا واحدًا على الأقل.');
            }
        });

        return $validator->validate();
    }
}
