<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Services\SettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Periodic maintenance job to purge expired AI conversations, delete orphaned storage attachments, and reap abandoned pending actions.
 */
class PruneAiConversations implements ShouldQueue
{
    use Queueable;

    /**
     * Execute the pruning job.
     */
    public function handle(SettingService $settings): void
    {
        $days = max(1, (int) $settings->get('ai_retention_days', 180));
        // Force-deleting conversations cascades ai_attachments rows; remove the physical directory from the storage disk after deletion.
        $disk = \Illuminate\Support\Facades\Storage::disk('ai_private');
        AiConversation::withTrashed()->whereRaw('COALESCE(last_message_at, created_at) < ?', [now()->subDays($days)])->chunkById(100, function ($conversations) use ($disk): void {
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
        AiPendingAction::query()->where('status', 'pending')->where('expires_at', '<', now())->update(['status' => 'expired']);
        AiPendingAction::query()->where('status', 'bulk_child')->where('expires_at', '<', now())->update(['status' => 'expired']);
        // Cancel abandoned confirmed bulk actions and their unexecuted child actions if worker was terminated mid-flight.
        AiPendingAction::query()->where('status', 'confirmed')->where('tool', 'bulk_actions')->where('updated_at', '<', now()->subMinutes(5))
            ->chunkById(100, function ($parents): void {
                foreach ($parents as $parent) {
                    $childIds = $parent->args['action_ids'] ?? [];
                    if (is_array($childIds) && $childIds !== []) {
                        AiPendingAction::query()->whereIn('id', $childIds)->whereIn('status', ['pending', 'bulk_child'])->update(['status' => 'cancelled']);
                    }
                    $parent->forceFill(['status' => 'failed', 'result' => ['message' => 'العملية الجماعية انتهت من غير تنفيذ كامل. اطلب اقتراح جديد.']])->save();
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
        if (\Illuminate\Support\Facades\Schema::hasTable('ai_attachments')) {
            \App\Models\AiAttachment::query()->whereNull('consumed_at')->where('created_at', '<', now()->subHours(24))
                ->chunkById(100, function ($attachments) use ($disk): void {
                    foreach ($attachments as $attachment) {
                        $disk->delete($attachment->path);
                        $attachment->delete();
                    }
                });
        }
    }
}
