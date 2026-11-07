<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\BusinessException;
use App\Exceptions\RetryableConflict;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\User;
use App\Services\Ai\Contracts\ExecutesOutsideTransaction;
use App\Services\Ai\Contracts\ToolProvider;
use App\Services\Ai\WhatsApp\WhatsAppActorContext;
use App\Services\SettingService;
use App\Services\StaffAdministration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\RoleAlreadyExists;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Coordinates two-phase confirmation, execution, cancellation, and rollback of AI-proposed actions.
 */
final class PendingActionService
{
    public function __construct(private ToolRegistry $registry, private SettingService $settings) {}

    /**
     * Confirms and executes an AI proposed action after verifying permissions and constraints.
     *
     * @param  User  $actor  Authenticated staff user confirming the action.
     * @param  AiPendingAction  $action  Target pending action model.
     * @param  string|null  $typed  Required typed confirmation string for high-risk actions.
     * @param  array<string, mixed>|null  $selectedItems  Item selection for partial bulk execution.
     * @return ToolOutcome Execution result outcome.
     */
    public function confirm(User $actor, AiPendingAction $action, ?string $typed = null, ?array $selectedItems = null): ToolOutcome
    {
        if ($action->tool === 'bulk_actions') {
            return $this->confirmBulk($actor, $action, $typed, $selectedItems);
        }
        $toolInstance = $this->registry->toolInstance($action->tool);
        if ($toolInstance instanceof ExecutesOutsideTransaction && $toolInstance->executesOutsideTransaction($action)) {
            return $this->confirmOutsideTransaction($actor, $action, $typed, $toolInstance);
        }
        try {
            $result = DB::transaction(function () use ($actor, $action, $typed, $selectedItems) {
                $this->lockForAction($action->tool);
                $locked = AiPendingAction::query()->lockForUpdate()->findOrFail($action->id);
                try {
                    return $this->confirmLocked($actor, $locked, $typed, $selectedItems);
                } catch (HttpExceptionInterface $e) {
                    return $e;
                }
            });
        } catch (\Throwable $e) {
            // M-3: unexpected errors must not leave the proposal stuck in
            // pending — record the failure outside the rolled-back transaction.
            if (! $e instanceof HttpExceptionInterface) {
                AiPendingAction::whereKey($action->id)->where('status', 'pending')
                    ->update(['status' => 'failed', 'result' => ['message' => 'تعذّر التنفيذ بسبب خطأ غير متوقع؛ اطلب اقتراحًا جديدًا.']]);
            }
            throw $e;
        }
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }

    private function confirmOutsideTransaction(User $actor, AiPendingAction $action, ?string $typed, WriteTool $tool): ToolOutcome
    {
        $claim = DB::transaction(function () use ($actor, $action, $typed) {
            $this->lockForAction($action->tool);
            $locked = AiPendingAction::query()->lockForUpdate()->findOrFail($action->id);
            try {
                return $this->confirmLocked($actor, $locked, $typed);
            } catch (HttpExceptionInterface $e) {
                return $e;
            }
        });
        if ($claim instanceof \Throwable) {
            throw $claim;
        }
        if ($claim instanceof ToolOutcome && $claim->type !== 'claimed') {
            return $claim;
        }

        try {
            $outcome = $tool->execute($actor, $action->fresh() ?? $action);
        } catch (\Throwable $e) {
            DB::transaction(function () use ($action, $e): void {
                $locked = AiPendingAction::query()->lockForUpdate()->find($action->id);
                if ($locked && $locked->status === 'confirmed') {
                    $message = ($e instanceof BusinessException || $e instanceof ValidationException)
                        ? ($e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage())
                        : 'تعذّر التنفيذ؛ راجع البيانات أو اطلب اقتراحًا جديدًا.';
                    $this->markFailed($locked, $message ?: 'تعذّر التنفيذ؛ راجع البيانات أو اطلب اقتراحًا جديدًا.');
                }
            });
            if ($e instanceof BusinessException && $e->status === 409) {
                abort(409, $e->getMessage());
            }
            if ($e instanceof HttpExceptionInterface) {
                throw $e;
            }
            if ($e instanceof BusinessException || $e instanceof ValidationException) {
                $msg = $e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage();

                return ToolOutcome::result(false, $msg ?: 'تعذّر التنفيذ.');
            }
            throw $e;
        }

        return DB::transaction(function () use ($action, $outcome): ToolOutcome {
            $locked = AiPendingAction::query()->lockForUpdate()->findOrFail($action->id);
            if ($locked->status === 'confirmed') {
                return $this->finalizeAction($locked, $outcome);
            }

            return ToolOutcome::wrap($outcome->type, $outcome->data, $outcome->uiBlock, $outcome->message);
        });
    }

    private function confirmBulk(User $actor, AiPendingAction $action, ?string $typed, ?array $selectedItems): ToolOutcome
    {
        $claim = DB::transaction(function () use ($actor, $action, $typed, $selectedItems) {
            StaffAdministration::lock();
            $locked = AiPendingAction::query()->lockForUpdate()->findOrFail($action->id);
            abort_unless($locked->user_id === $actor->id, 403);
            $this->authorizeTool($actor, $locked);
            if ($locked->status === 'executed') {
                return ToolOutcome::result((bool) ($locked->result['ok'] ?? false), $locked->result['message'], $locked->result);
            }
            abort_unless($locked->status === 'pending' && $locked->expires_at->isFuture(), 409, 'العملية الجماعية مش متاحة للتنفيذ.');
            abort_unless(trim((string) $typed) === 'تنفيذ', 422, 'اكتب تنفيذ لتأكيد المجموعة.');
            $ids = $locked->args['action_ids'];
            $selected = $selectedItems ?? $ids;
            abort_if(! $selected || array_diff($selected, $ids) !== [] || count(array_unique($selected)) !== count($selected), 422);
            $locked->forceFill(['status' => 'confirmed'])->save();
            // Transition selected items to pending while cancelling unselected ones in the same transaction to prevent orphans and race conditions.
            AiPendingAction::query()->whereIn('id', $selected)->where('status', 'bulk_child')->update(['status' => 'pending']);
            AiPendingAction::query()->whereIn('id', array_diff($ids, $selected))->whereIn('status', ['bulk_child', 'pending'])->update(['status' => 'cancelled']);

            return ['selected' => $selected, 'all' => $ids];
        });
        if ($claim instanceof ToolOutcome) {
            return $claim;
        }
        $results = [];
        foreach ($claim['selected'] as $id) {
            $child = AiPendingAction::query()->where('conversation_id', $action->conversation_id)->where('user_id', $actor->id)->findOrFail($id);
            try {
                // Do not auto-fill typed confirmation: high-risk actions requiring typed input must fail if unconfirmed.
                $outcome = $this->confirm($actor, $child, null);
                $results[] = ['action_id' => $id, 'ok' => $outcome->type === 'result', 'message' => $outcome->message];
            } catch (QueryException $e) {
                if (! $this->isRetryableLockFailure($e)) {
                    report($e);
                    AiPendingAction::whereKey($id)->where('status', 'pending')->update(['status' => 'failed']);
                    $results[] = ['action_id' => $id, 'ok' => false, 'message' => 'تعذّر تنفيذ العنصر ده ضمن الدفعة؛ راجع التفاصيل أو جرّبه لوحده.'];

                    continue;
                }
                $this->retryBulkConflict($action, $claim, $results);
            } catch (RetryableConflict $e) {
                // Transient lock timeouts in batch actions trigger retryable conflict rather than marking action as failed.
                $this->retryBulkConflict($action, $claim, $results);
            } catch (\Throwable $e) {
                if (! $e instanceof HttpExceptionInterface && ! $e instanceof ValidationException) {
                    report($e);
                }
                AiPendingAction::whereKey($id)->where('status', 'pending')->update(['status' => 'failed']);
                $results[] = ['action_id' => $id, 'ok' => false, 'message' => 'تعذّر تنفيذ العنصر ده ضمن الدفعة؛ راجع التفاصيل أو جرّبه لوحده.'];
            }
        }
        $successes = count(array_filter($results, fn ($item) => $item['ok']));
        $ok = $successes === count($results);
        $message = 'تم تنفيذ '.$successes.' من '.count($results).' عمليات.';
        // Mark batch parent action as failed if zero items succeeded to prevent incorrect audit reporting.
        $parentStatus = $successes === 0 ? 'failed' : 'executed';
        $action->refresh()->forceFill(['status' => $parentStatus, 'executed_at' => now(), 'result' => ['ok' => $ok, 'message' => $message, 'items' => $results]])->save();
        $block = ['type' => 'result', 'ok' => $ok, 'message' => $message, 'items' => $results];
        $this->event($action, 'result', $message, [$block]);

        return ToolOutcome::result($ok, $message, ['items' => $results], $block);
    }

    /**
     * Handles transient lock conflict during batch item confirmation, leaving parent pending for safe retry.
     *
     * @param  array{selected:list<string>,all:list<string>}  $claim
     * @param  list<array{action_id:string,ok:bool,message:string}>  $results
     */
    private function retryBulkConflict(AiPendingAction $action, array $claim, array $results): never
    {
        AiPendingAction::whereKey($action->id)->where('status', 'confirmed')->update(['status' => 'pending']);
        $done = count(array_filter($results, fn ($item) => $item['ok']));
        $message = $done === 0
            ? 'السجل مشغول بتعديل تاني؛ مفيش حاجة اتنفذت. جرّب تأكيد الدفعة تاني بعد لحظة.'
            : 'السجل مشغول بتعديل تاني؛ العناصر المنفذة: '.$done.' من '.count($claim['selected']).'. الباقي ما اتنفذش — جرّب تأكيد الدفعة تاني بعد لحظة.';
        $this->event($action, 'result', $message, [['type' => 'result', 'ok' => false, 'message' => $message]]);
        abort(409, $message);
    }

    /**
     * Acquire global admin lock only for tool domains that do not manage fine-grained locks.
     */
    private function lockForAction(string $tool): void
    {
        $def = $this->registry->definitions()[$tool] ?? null;
        $provider = $def['provider'] ?? null;
        if ($provider instanceof ToolProvider && ! $provider->usesGlobalAdminLock()) {
            return;
        }
        StaffAdministration::lock();
    }

    private function authorizeTool(User $actor, AiPendingAction $action): void
    {
        $actor->refresh();
        abort_unless($this->settings->getBool('ai_enabled', false), 403, 'المساعد مقفول.');
        $disabled = $this->settings->get('ai_disabled_tools', []);
        abort_if(is_array($disabled) && in_array($action->tool, $disabled, true), 403, 'الأداة مقفولة من الإعدادات.');
        abort_if($this->registry->isCustomerTool($action->tool) && ! $this->registry->customerToolsEnabled(), 403, 'أدوات العملاء مقفولة من الإعدادات.');
        abort_if($this->registry->toolHiddenByFlags($action->tool), 403, 'الأداة مقفولة من الإعدادات.');
        $def = $this->registry->definitions()[$action->tool] ?? null;
        abort_unless($def && $actor->is_active && $actor->can('ai_agent.use') && $actor->can('ai_agent.execute') && $this->registry->actorHasToolPermissions($actor, $action->tool, $def), 403);
        abort_unless($action->conversation()->exists(), 409, 'المحادثة اتحذفت.');
        abort_unless(hash_equals($action->args_hash, $this->registry->hashArgs($action->args)), 409, 'بيانات الإجراء اتغيرت.');
        $mode = app(WhatsAppActorContext::class)->mode();
        if (in_array($mode, ['confirm', 'undo'], true) && $def) {
            $risk = (string) ($action->risk ?: $def['risk']);
            abort_if($this->registry->blockedOnWhatsapp($action->tool, $def['kind'], $risk), 403, 'الأداة دي مش متاحة من واتساب.');
        }
    }

    private function confirmLocked(User $actor, AiPendingAction $action, ?string $typed, ?array $selectedItems = null): ToolOutcome
    {
        abort_unless($action->user_id === $actor->id, 403);
        $this->authorizeTool($actor, $action);
        if ($action->status === 'executed') {
            return ToolOutcome::result(true, 'اتنفذ قبل كده.', $action->result ?? []);
        }
        abort_unless($action->status === 'pending', 409, 'الإجراء مش في حالة انتظار.');
        if ($action->expires_at->isPast()) {
            $action->forceFill(['status' => 'expired'])->save();
            abort(409, 'انتهت صلاحية كارت التأكيد.');
        }
        if (! hash_equals($action->args_hash, $this->registry->hashArgs($action->args))) {
            abort(409, 'بيانات الإجراء اتغيرت.');
        }
        abort_unless($this->settings->getBool('ai_enabled', false), 403, 'المساعد مقفول.');
        // re-check execute + original permission
        $defs = $this->registry->definitions();
        $def = $defs[$action->tool] ?? null;
        abort_unless($def && $actor->can('ai_agent.execute') && $this->registry->actorHasToolPermissions($actor, $action->tool, $def), 403);
        if ($action->risk === 'high') {
            $preview = $action->preview ?? [];
            [$expectedTyped, $typedHint] = ProposalBuilder::resolveTypedConfirmation($action->tool, $action->args ?? [], $preview);
            $typedInstruction = null;
            if (in_array($action->tool, ['approve_dealer_application', 'reject_dealer_application', 'suspend_customer', 'set_credit_limit'], true)) {
                $typedInstruction = 'اكتب آخر 4 أرقام من موبايل التاجر.';
            } elseif ($action->tool === 'adjust_customer_wallet') {
                $typedInstruction = 'اكتب آخر 4 أرقام من موبايل العميل.';
            } elseif ($typedHint !== null && $typedHint !== '') {
                $typedInstruction = 'اكتب '.$typedHint.': '.$expectedTyped;
            }
            // Tolerant comparison (trimmed, case-insensitive) for user-entered confirmation.
            $norm = fn (?string $v): ?string => $v === null ? null : mb_strtolower(trim($v));
            if ($expectedTyped !== null && $typed !== null && $norm($typed) !== $norm($expectedTyped)) {
                abort(422, 'نص التأكيد مش مطابق.');
            }
            if ($expectedTyped !== null && ($typed === null || trim($typed) === '')) {
                abort(422, $typedInstruction ?? 'اكتب نص التأكيد المطلوب: '.$expectedTyped);
            }
        }
        $tool = $this->registry->toolInstance($action->tool);
        if ($tool instanceof ExecutesOutsideTransaction && $tool->executesOutsideTransaction($action)) {
            $action->forceFill(['status' => 'confirmed'])->save();

            return ToolOutcome::wrap('claimed');
        }
        $via = $this->via($action);
        try {
            $outcome = DB::transaction(function () use ($actor, $action, $via, $selectedItems) {
                $this->registry->lockTargets($action);

                return $this->registry->executeWrite($actor, $action, $via, $selectedItems);
            });
        } catch (UniqueConstraintViolationException) {
            // Convert unique constraint violations outside savepoint to descriptive 409 errors instead of dangling in pending.
            $message = 'قيمة مشتركة (كود فرع أو موقع/بريد) اتحجزت لحظة الحفظ. اطلب اقتراح جديد.';
            $action->forceFill(['status' => 'failed', 'result' => ['message' => $message, 'conflict_409' => true]])->save();
            $this->event($action, 'result', $message, [['type' => 'result', 'ok' => false, 'message' => $message]]);
            abort(409, $message);
        } catch (BusinessException|ValidationException $e) {
            if ($e instanceof BusinessException && $e->status === 409) {
                $message = $e->getMessage() ?: 'حد عدّل السجل ده بعد الاقتراح. تحب أجهّز اقتراح جديد؟';
                $action->forceFill(['status' => 'failed', 'result' => ['message' => $message, 'conflict_409' => true]])->save();
                $this->event($action, 'result', $message, [['type' => 'result', 'ok' => false, 'message' => $message]]);
                abort(409, $message);
            }
            if ($e instanceof BusinessException && $e->status === 403) {
                $message = $e->getMessage() ?: 'صلاحيتك على السجل ده اتغيرت بعد الاقتراح؛ اطلب اقتراحًا جديدًا.';
                $this->markFailed($action, $message);

                return ToolOutcome::result(false, $message);
            }
            // Record expected domain validation errors as action failures to prevent hanging actions in pending state.
            $message = $e instanceof ValidationException
                ? collect($e->errors())->flatten()->implode(' ')
                : $e->getMessage();
            $message = trim((string) $message) !== '' ? $message : 'تعذّر التنفيذ؛ راجع البيانات أو اطلب اقتراحًا جديدًا.';
            $action->forceFill(['status' => 'failed', 'result' => ['message' => $message]])->save();
            $this->event($action, 'result', $message, [['type' => 'result', 'ok' => false, 'message' => $message]]);

            return ToolOutcome::result(false, $message);
        } catch (AuthorizationException|ModelNotFoundException|RoleAlreadyExists $e) {
            $message = match (true) {
                $e instanceof AuthorizationException => 'صلاحيتك على السجل ده اتغيرت بعد الاقتراح؛ اطلب اقتراحًا جديدًا.',
                $e instanceof ModelNotFoundException => 'السجل المطلوب لم يعد موجودًا؛ اطلب اقتراحًا جديدًا.',
                default => trim((string) $e->getMessage()) !== '' ? $e->getMessage() : 'القيمة المطلوبة موجودة بالفعل؛ اطلب اقتراحًا جديدًا.',
            };
            $this->markFailed($action, $message);

            return ToolOutcome::result(false, $message);
        } catch (QueryException $e) {
            // Convert database lock timeouts (55P03, 40P01, 40001) to RetryableConflict without marking the action failed.
            if (in_array($e->errorInfo[0] ?? null, ['55P03', '40P01', '40001'], true) || $this->isRetryableLockFailure($e)) {
                throw new RetryableConflict('السجل مشغول بتعديل تاني؛ مفيش حاجة اتنفذت. جرّب التأكيد تاني بعد لحظة.', $e);
            }
            report($e);
            $message = match ($e->errorInfo[0] ?? null) {
                '23514' => 'البيانات مش مستوفية لشروط الحفظ (مثلاً بيانات قانونية ناقصة)؛ كمّلها واطلب اقتراح جديد.',
                '23503' => 'سجل مرتبط بالإجراء اتغير أو اتحذف؛ اطلب اقتراح جديد.',
                default => 'تعذّر التنفيذ بسبب خطأ في قاعدة البيانات؛ اطلب اقتراح جديد.',
            };
            $this->markFailed($action, $message);

            return ToolOutcome::result(false, $message);
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() === 409) {
                // Do not mark retryable 409 conflicts as failed since underlying state is unchanged and can be retried.
                if (! $e instanceof RetryableConflict) {
                    $action->forceFill(['status' => 'failed', 'result' => ['message' => $e->getMessage() ?: 'حد عدّل السجل ده بعد الاقتراح. تحب أجهّز اقتراح جديد؟', 'conflict_409' => true]])->save();
                    $this->event($action, 'result', $e->getMessage() ?: 'حد عدّل السجل ده بعد الاقتراح.', [['type' => 'result', 'ok' => false, 'message' => $e->getMessage() ?: 'حد عدّل السجل ده بعد الاقتراح.']]);
                }
            }
            throw $e;
        }

        return $this->finalizeAction($action, $outcome);
    }

    private function finalizeAction(AiPendingAction $action, ToolOutcome $outcome): ToolOutcome
    {
        $ok = $outcome->type === 'result';
        $storedResult = $outcome->data;
        unset($storedResult['generated_password']);
        // Refresh model instance so newly generated IDs in expected_versions are available for undo state hashing.
        $action->refresh();
        if ($ok && $this->registry->canUndo($action)) {
            $storedResult['undo_state'] = $this->registry->targetState($action);
        }
        $action->forceFill(['status' => $ok ? 'executed' : 'failed', 'result' => array_merge($storedResult, ['message' => $outcome->message]), 'executed_at' => $ok ? now() : null])->save();
        // Strip generated password from stored result? Keep only in response block, remove from result json
        $block = $outcome->uiBlock;
        if ($block) {
            $blocks = [$block];
            if ($ok && $this->registry->canUndo($action)) {
                $blocks[0]['undo_available_until'] = now()->addMinutes(15)->toIso8601String();
                $blocks[0]['undo_action_id'] = $action->id;
            }
        } else {
            $blocks = [['type' => 'result', 'ok' => $ok, 'message' => $outcome->message ?? '']];
            if ($ok && $this->registry->canUndo($action)) {
                $blocks[0]['undo_available_until'] = now()->addMinutes(15)->toIso8601String();
                $blocks[0]['undo_action_id'] = $action->id;
            }
        }
        $this->event($action, 'result', $outcome->message ?? '', $blocks, stripPassword: in_array($action->tool, ['create_staff_user', 'create_customer'], true));

        return ToolOutcome::wrap($outcome->type, $outcome->data, $blocks[0] ?? null, $outcome->message);
    }

    /**
     * Cancels a pending AI action and its associated bulk children.
     *
     * @param  User  $actor  Staff user initiating cancellation.
     * @param  AiPendingAction  $action  Target pending action to cancel.
     * @return AiPendingAction Cancelled action instance.
     */
    public function cancel(User $actor, AiPendingAction $action): AiPendingAction
    {
        abort_unless($action->user_id === $actor->id, 403);

        return DB::transaction(function () use ($actor, $action): AiPendingAction {
            $this->lockForAction($action->tool);
            $locked = AiPendingAction::query()->lockForUpdate()->findOrFail($action->id);
            if ($locked->status === 'pending') {
                $locked->forceFill(['status' => 'cancelled'])->save();
                if ($locked->tool === 'bulk_actions') {
                    // Cancel child actions in lockstep when parent bulk action is cancelled.
                    AiPendingAction::query()->where('user_id', $actor->id)->where('conversation_id', $locked->conversation_id)->whereIn('id', $locked->args['action_ids'])->whereIn('status', ['pending', 'bulk_child'])->update(['status' => 'cancelled']);
                }
                $this->event($locked, 'result', 'تم إلغاء الإجراء '.$locked->id, [['type' => 'result', 'ok' => true, 'message' => 'تم إلغاء الإجراء.']]);
            }

            return $locked;
        });
    }

    /**
     * Reverses an executed AI action if within the allowable 15-minute undo window.
     *
     * @param  User  $actor  Staff user initiating the undo.
     * @param  AiPendingAction  $action  Executed action to revert.
     * @return ToolOutcome Undo result outcome.
     */
    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        // Verify action ownership before acquiring row locks to prevent unauthorized lock contention.
        abort_unless($action->user_id === $actor->id, 403);

        return DB::transaction(function () use ($actor, $action) {
            $this->lockForAction($action->tool);
            $locked = AiPendingAction::query()->lockForUpdate()->findOrFail($action->id);
            abort_unless($locked->user_id === $actor->id, 403);
            $this->registry->lockTargets($locked);

            return $this->undoLocked($actor, $locked);
        });
    }

    private function undoLocked(User $actor, AiPendingAction $action): ToolOutcome
    {
        abort_unless($action->user_id === $actor->id, 403);
        $this->authorizeTool($actor, $action);
        abort_unless($this->registry->canUndo($action), 409);
        abort_unless(isset($action->result['undo_state']) && hash_equals($action->result['undo_state'], $this->registry->targetState($action)), 409, 'السجل اتعدل بعد التنفيذ؛ التراجع مش آمن.');
        abort_unless($action->status === 'executed' && $action->executed_at && $action->executed_at->copy()->addMinutes(15)->isFuture(), 409, 'التراجع مش متاح.');
        abort_unless($actor->can('ai_agent.execute'), 403);
        $via = $this->via($action);
        try {
            $outcome = DB::transaction(fn () => $this->registry->executeUndo($actor, $action, $via));
        } catch (BusinessException $e) {
            if ($e->status === 409) {
                $this->event($action, 'result', 'التراجع اترفض عشان السجل اتعدل.', [['type' => 'result', 'ok' => false, 'message' => 'التراجع اترفض: السجل اتعدل بعد التنفيذ.']]);
                abort(409, $e->getMessage());
            }

            return ToolOutcome::result(false, $e->getMessage() ?: 'فشل التراجع.');
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() === 409) {
                $this->event($action, 'result', 'التراجع اترفض عشان السجل اتعدل.', [['type' => 'result', 'ok' => false, 'message' => 'التراجع اترفض: السجل اتعدل بعد التنفيذ.']]);
                throw $e;
            } throw $e;
        }
        if ($outcome->type === 'result') {
            $action->forceFill(['status' => 'undone'])->save();
            $this->event($action, 'result', 'تم التراجع.', [['type' => 'result', 'ok' => true, 'message' => 'تم التراجع.']]);
        }

        return $outcome;
    }

    private function isRetryableLockFailure(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['55P03', '40P01'], true);
    }

    /**
     * Marks an action as failed with an explanatory message and records the corresponding result event.
     */
    private function markFailed(AiPendingAction $action, string $message): void
    {
        $action->forceFill(['status' => 'failed', 'result' => ['message' => $message]])->save();
        $this->event($action, 'result', $message, [['type' => 'result', 'ok' => false, 'message' => $message]]);
    }

    /** @return array{channel:string,conversation_id:mixed,action_id:mixed,source:string} */
    private function via(AiPendingAction $action): array
    {
        return [
            'channel' => 'ai',
            'conversation_id' => $action->conversation_id,
            'action_id' => $action->id,
            'source' => (string) ($action->conversation()->value('channel') ?: 'web'),
        ];
    }

    private function event(AiPendingAction $action, string $kind, string $text, array $blocks, bool $stripPassword = false): void
    {
        if ($stripPassword) {
            // Strip generated passwords from stored conversation events to ensure credentials are shown only once.
            $created = $action->tool === 'create_customer' ? 'العميل' : 'الموظف';
            $text = "تم إنشاء {$created} (الباسورد ظهر للأدمن مرة واحدة).";
            $blocks = array_map(function ($b) use ($created) {
                if (isset($b['message'])) {
                    $b['message'] = "تم إنشاء {$created}.";
                }
                if (isset($b['generated_password'])) {
                    unset($b['generated_password']);
                }

                return $b;
            }, $blocks);
        }
        AiMessage::create(['conversation_id' => $action->conversation_id, 'role' => 'event', 'content' => $text, 'ui_blocks' => $blocks]);
    }
}
