<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Services\Shared\SettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Periodic maintenance job to purge expired AI conversations, delete orphaned storage attachments, and reap abandoned pending actions.
 */
class PruneAiConversations implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    /**
     * Execute the pruning job.
     */
    public function handle(SettingService $settings): void
    {
        $days = max(1, (int) $settings->get('ai_retention_days', 180));
        // Force-deleting conversations cascades ai_attachments rows; remove the physical directory from the storage disk after deletion.
        $disk = \Illuminate\Support\Facades\Storage::disk('ai_private');
        AiConversation::withTrashed()
            ->where(fn ($q) => $q->whereNotNull('deleted_at')->orWhere('is_pinned', false))
            ->whereRaw('COALESCE(last_message_at, created_at) < ?', [now()->subDays($days)])
            ->chunkById(100, function ($conversations) use ($disk): void {
            foreach ($conversations as $conversation) {
                $conversationId = $conversation->id;
                $conversation->forceDelete();
                try {
                    $disk->deleteDirectory("ai-attachments/{$conversationId}");
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });
        AiPendingAction::query()->where('status', 'pending')->where('tool', '!=', 'bulk_actions')->where('expires_at', '<', now())->update(['status' => 'expired']);
        AiPendingAction::query()->where('status', 'pending')->where('tool', 'bulk_actions')->where('expires_at', '<', now())
            ->chunkById(100, fn ($parents) => $parents->each(fn (AiPendingAction $parent) => $this->finishExpiredBulk($parent, 'expired')));
        AiPendingAction::query()->where('status', 'bulk_child')->where('expires_at', '<', now())->update(['status' => 'expired']);
        // Cancel abandoned confirmed bulk actions and their unexecuted child actions if worker was terminated mid-flight.
        AiPendingAction::query()->where('status', 'confirmed')->where('tool', 'bulk_actions')->where('updated_at', '<', now()->subMinutes(5))
            ->chunkById(100, function ($parents): void {
                foreach ($parents as $parent) {
                    $this->finishExpiredBulk($parent, 'failed');
                }
            });
        // Mark long-lingering confirmed actions as failed if external transaction was interrupted before completion.
        AiPendingAction::query()->where('status', 'confirmed')->where('tool', '!=', 'bulk_actions')->where('updated_at', '<', now()->subMinutes(10))
            ->chunkById(100, function ($actions): void {
                foreach ($actions as $action) {
                    $action->forceFill(['status' => 'failed', 'result' => ['message' => 'الحالة غير مؤكدة؛ راجع صور المنتج']])->save();
                }
            });
        // Prune unconsumed attachment uploads after 24 hours.
        if (Schema::hasTable('ai_attachments')) {
            \App\Models\AiAttachment::query()->whereNull('consumed_at')->where('created_at', '<', now()->subHours(24))
                ->chunkById(100, function ($attachments) use ($disk): void {
                    foreach ($attachments as $attachment) {
                        $disk->delete($attachment->path);
                        $attachment->delete();
                    }
                });
        }

        $this->pruneOperationalTables($days);
    }

    private function pruneOperationalTables(int $retentionDays): void
    {
        $weekAgo = now()->subDays(7);
        $inboundCutoff = now()->subDays(min($retentionDays, 90));

        if (Schema::hasTable('ai_whatsapp_link_tokens')) {
            $this->deleteInBatches(fn () => DB::table('ai_whatsapp_link_tokens')
                ->whereIn('id', DB::table('ai_whatsapp_link_tokens')
                    ->where(fn ($q) => $q->where('expires_at', '<', $weekAgo)->orWhere('consumed_at', '<', $weekAgo))
                    ->limit(1000)
                    ->pluck('id'))
                ->delete());
        }

        if (Schema::hasTable('ai_whatsapp_inbound')) {
            $this->deleteInBatches(fn () => DB::table('ai_whatsapp_inbound')
                ->whereIn('id', DB::table('ai_whatsapp_inbound')
                    ->where('created_at', '<', $inboundCutoff)
                    ->where('status', '!=', 'queued')
                    ->limit(1000)
                    ->pluck('id'))
                ->delete());
        }

        if (Schema::hasTable('ai_whatsapp_outbound')) {
            $this->deleteInBatches(fn () => DB::table('ai_whatsapp_outbound')
                ->whereIn('id', DB::table('ai_whatsapp_outbound')
                    ->whereNotNull('picker')
                    ->where('created_at', '<', $weekAgo)
                    ->limit(1000)
                    ->pluck('id'))
                ->update(['picker' => null]));
        }

        if (Schema::hasTable('customer_message_budgets')) {
            $budgetCutoff = now()->subDays(7)->toDateString();
            $this->deleteInBatches(fn () => DB::table('customer_message_budgets')
                ->where('date', '<', $budgetCutoff)
                ->limit(1000)
                ->delete());
        }
    }

    private function deleteInBatches(callable $step): void
    {
        do {
            $affected = (int) $step();
        } while ($affected >= 1000);
    }

    private function finishExpiredBulk(AiPendingAction $parent, string $emptyStatus): void
    {
        DB::transaction(function () use ($parent, $emptyStatus): void {
            $locked = AiPendingAction::query()->lockForUpdate()->find($parent->id);
            if ($locked === null || ! in_array($locked->status, ['pending', 'confirmed'], true)) {
                return;
            }
            $childIds = $locked->args['action_ids'] ?? [];
            $childIds = is_array($childIds) ? $childIds : [];
            $children = $childIds !== []
                ? AiPendingAction::query()->whereIn('id', $childIds)->lockForUpdate()->get()
                : collect();
            $executed = $children->where('status', 'executed')->values();
            $unfinishedStatus = $emptyStatus === 'expired' ? 'expired' : 'cancelled';
            AiPendingAction::query()->whereIn('id', $childIds)->whereIn('status', ['pending', 'bulk_child'])->update(['status' => $unfinishedStatus]);

            if ($executed->isNotEmpty()) {
                $items = $children->map(fn (AiPendingAction $child) => [
                    'action_id' => $child->id,
                    'ok' => $child->status === 'executed',
                    'status' => $child->status === 'executed' ? 'executed' : $unfinishedStatus,
                    'message' => (string) ($child->result['message'] ?? ($child->status === 'executed' ? 'تم التنفيذ.' : 'لم يتم تنفيذ العنصر.')),
                ])->all();
                $message = 'تم تنفيذ '.$executed->count().' من '.count($childIds).' عناصر قبل انتهاء الدفعة؛ راجع العناصر المتبقية.';
                $locked->forceFill(['status' => 'executed', 'executed_at' => now(), 'result' => ['ok' => false, 'message' => $message, 'items' => $items]])->save();

                return;
            }

            $locked->forceFill(['status' => $emptyStatus, 'result' => ['ok' => false, 'message' => 'العملية الجماعية انتهت من غير تنفيذ كامل. اطلب اقتراح جديد.']])->save();
        });
    }
}
