<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiMessage;
use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\User;
use App\Services\SettingService;
use App\Services\StaffAdministration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\RoleAlreadyExists;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class PendingActionService
{
    public function __construct(private ToolRegistry $registry, private SettingService $settings) {}

    public function confirm(User $actor, AiPendingAction $action, ?string $typed = null, ?array $selectedItems = null): ToolOutcome
    {
        if ($action->tool === 'bulk_actions') {
            return $this->confirmBulk($actor, $action, $typed, $selectedItems);
        }
        try {
            $result = DB::transaction(function () use ($actor, $action, $typed) {
                StaffAdministration::lock();
                $locked = AiPendingAction::query()->lockForUpdate()->findOrFail($action->id);
                try {
                    return $this->confirmLocked($actor, $locked, $typed);
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
            // M-4: المختارون فقط يتحولون لـ pending، والباقون يُلغون في نفس
            // الـ transaction — فلا نافذة سباق لتأكيد مستبعد من تبويب آخر،
            // ولا أيتام pending لو انهارت العملية بعد الـ claim.
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
                $outcome = $this->confirm($actor, $child);
                $results[] = ['action_id' => $id, 'ok' => $outcome->type === 'result', 'message' => $outcome->message];
            } catch (QueryException $e) {
                if (! $this->isRetryableLockFailure($e)) {
                    report($e);
                    AiPendingAction::whereKey($id)->where('status', 'pending')->update(['status' => 'failed']);
                    $results[] = ['action_id' => $id, 'ok' => false, 'message' => 'تعذّر تنفيذ العنصر ده ضمن الدفعة؛ راجع التفاصيل أو جرّبه لوحده.'];

                    continue;
                }
                AiPendingAction::whereKey($action->id)->where('status', 'confirmed')->update(['status' => 'pending']);
                $message = 'السجل مشغول بتعديل تاني؛ مفيش حاجة اتأكدت. جرّب تأكيد الدفعة تاني بعد لحظة.';
                $this->event($action, 'result', $message, [['type' => 'result', 'ok' => false, 'message' => $message]]);
                abort(409, $message);
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
        $action->refresh()->forceFill(['status' => 'executed', 'executed_at' => now(), 'result' => ['ok' => $ok, 'message' => $message, 'items' => $results]])->save();
        $block = ['type' => 'result', 'ok' => $ok, 'message' => $message, 'items' => $results];
        $this->event($action, 'result', $message, [$block]);

        return ToolOutcome::result($ok, $message, ['items' => $results], $block);
    }

    private function authorizeTool(User $actor, AiPendingAction $action): void
    {
        $actor->refresh();
        abort_unless($this->settings->getBool('ai_enabled', false), 403, 'المساعد مقفول.');
        $disabled = $this->settings->get('ai_disabled_tools', []);
        abort_if(is_array($disabled) && in_array($action->tool, $disabled, true), 403, 'الأداة مقفولة من الإعدادات.');
        $def = $this->registry->definitions()[$action->tool] ?? null;
        abort_unless($def && $actor->is_active && $actor->can('ai_agent.use') && $actor->can('ai_agent.execute') && $actor->canAny($def['permissions']), 403);
        abort_unless($action->conversation()->exists(), 409, 'المحادثة اتحذفت.');
        abort_unless(hash_equals($action->args_hash, $this->registry->hashArgs($action->args)), 409, 'بيانات الإجراء اتغيرت.');
    }

    private function confirmLocked(User $actor, AiPendingAction $action, ?string $typed): ToolOutcome
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
        abort_unless($def && $actor->can('ai_agent.execute') && $actor->canAny($def['permissions']), 403);
        if ($action->risk === 'high') {
            $preview = $action->preview;
            $title = $preview['title'] ?? '';
            // typed confirmation enforced when preview carries typed_confirmation via block reconstruction: check args
            $expectedTyped = null;
            if ($action->tool === 'set_branch_status') {
                $b = Branch::find($action->args['branch_id'] ?? null);
                $expectedTyped = $b?->code;
            }
            if ($action->tool === 'delete_role') {
                $r = Role::find($action->args['role_id'] ?? null);
                $expectedTyped = $r?->name;
            }
            // L-9: مطابقة متسامحة (trim + توحيد حالة) بدل الحرفية الصارمة.
            $norm = fn (?string $v): ?string => $v === null ? null : mb_strtolower(trim($v));
            if ($expectedTyped !== null && $typed !== null && $norm($typed) !== $norm($expectedTyped)) {
                abort(422, 'نص التأكيد مش مطابق.');
            }
            if ($expectedTyped !== null && ($typed === null || trim($typed) === '')) {
                abort(422, 'اكتب نص التأكيد المطلوب: '.$expectedTyped);
            }
        }
        $via = ['channel' => 'ai', 'conversation_id' => $action->conversation_id, 'action_id' => $action->id];
        try {
            $outcome = DB::transaction(function () use ($actor, $action, $via) {
                $this->registry->lockTargets($action);

                return $this->registry->executeWrite($actor, $action, $via);
            });
        } catch (UniqueConstraintViolationException) {
            // M14: 23505 بتصل برا الـ savepoint — الإجراء يتاخد failed برسالة
            // مفهومة بدل 500 والإجراء يتعلق pending للأبد.
            $message = 'قيمة مشتركة (كود فرع أو موقع/بريد) اتحجزت لحظة الحفظ. اطلب اقتراح جديد.';
            $action->forceFill(['status' => 'failed', 'result' => ['message' => $message]])->save();
            $this->event($action, 'result', $message, [['type' => 'result', 'ok' => false, 'message' => $message]]);
            abort(409, $message);
        } catch (BusinessException|ValidationException $e) {
            // M-5: خطأ عمل مفهوم (تعطيل مرفوض/تحقق) يُحوَّل لفشل مسجَّل بدل
            // إلغاء الـ transaction الخارجية وبقاء الإجراء pending للأبد.
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
            $action->forceFill(['status' => 'failed', 'result' => ['message' => $message]])->save();
            $this->event($action, 'result', $message, [['type' => 'result', 'ok' => false, 'message' => $message]]);

            return ToolOutcome::result(false, $message);
        } catch (QueryException $e) {
            if (! $this->isRetryableLockFailure($e)) {
                throw $e;
            }
            abort(409, 'السجل مشغول بتعديل تاني؛ مفيش حاجة اتنفذت. جرّب التأكيد تاني بعد لحظة.');
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() === 409) {
                $action->forceFill(['status' => 'failed', 'result' => ['message' => 'حد عدّل السجل ده بعد الاقتراح. تحب أجهّز اقتراح جديد؟']])->save();
                $this->event($action, 'result', 'حد عدّل السجل ده بعد الاقتراح.', [['type' => 'result', 'ok' => false, 'message' => 'حد عدّل السجل ده بعد الاقتراح.']]);
                throw $e;
            }
            throw $e;
        }
        $ok = $outcome->type === 'result';
        $storedResult = $outcome->data;
        unset($storedResult['generated_password']);
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
        }
        $this->event($action, 'result', $outcome->message ?? '', $blocks, stripPassword: $action->tool === 'create_staff_user');

        return ToolOutcome::wrap($outcome->type, $outcome->data, $blocks[0] ?? null, $outcome->message);
    }

    public function cancel(User $actor, AiPendingAction $action): AiPendingAction
    {
        abort_unless($action->user_id === $actor->id, 403);

        return DB::transaction(function () use ($actor, $action): AiPendingAction {
            StaffAdministration::lock();
            $locked = AiPendingAction::query()->lockForUpdate()->findOrFail($action->id);
            if ($locked->status === 'pending') {
                $locked->forceFill(['status' => 'cancelled'])->save();
                if ($locked->tool === 'bulk_actions') {
                    // M10: الإجراءات الفرعية بتنقفل مع الخبّة الجماعية.
                    AiPendingAction::query()->where('user_id', $actor->id)->where('conversation_id', $locked->conversation_id)->whereIn('id', $locked->args['action_ids'])->whereIn('status', ['pending', 'bulk_child'])->update(['status' => 'cancelled']);
                }
                $this->event($locked, 'result', 'تم إلغاء الإجراء '.$locked->id, [['type' => 'result', 'ok' => true, 'message' => 'تم إلغاء الإجراء.']]);
            }

            return $locked;
        });
    }

    public function undo(User $actor, AiPendingAction $action): ToolOutcome
    {
        // L-6: فحص الملكية قبل قفل الصفوف — لا قفل لصفوف إجراء لا يملكه المستخدم.
        abort_unless($action->user_id === $actor->id, 403);

        return DB::transaction(function () use ($actor, $action) {
            StaffAdministration::lock();
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
        $via = ['channel' => 'ai', 'conversation_id' => $action->conversation_id, 'action_id' => $action->id];
        try {
            $outcome = $this->registry->executeUndo($actor, $action, $via);
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

    private function event(AiPendingAction $action, string $kind, string $text, array $blocks, bool $stripPassword = false): void
    {
        if ($stripPassword) {
            // L8: لا تعتمد على regex فوق صياغة عربية قد تتغير — المؤقتة
            // تُعرض مرة واحدة في رد التأكيد فقط، والمخزّن نص ثابت.
            $text = 'تم إنشاء الموظف (الباسورد ظهر للأدمن مرة واحدة).';
            $blocks = array_map(function ($b) {
                if (isset($b['message'])) {
                    $b['message'] = 'تم إنشاء الموظف.';
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
