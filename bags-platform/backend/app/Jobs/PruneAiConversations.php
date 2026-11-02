<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Services\SettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PruneAiConversations implements ShouldQueue
{
    use Queueable;

    public function handle(SettingService $settings): void
    {
        $days = max(1, (int) $settings->get('ai_retention_days', 180));
        // H3/M4: المرفقات على القرص المشترك الخاص، وforceDelete يحذف صفوف
        // ai_attachments عبر cascade لكن ليس الملفات — نحذف مجلد المحادثة
        // بعد نجاح الحذف حتى لا تتسرب صور بعد فترة الاحتفاظ.
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
        // M10: كارت جماعي اتحجز confirmed وما اتنفذش (العملية اتقطعت) —
        // يتقفل برسالة مفهومة بدل ما يفضل عالق للأبد.
        // M-4: الأبناء غير المنفذين (pending/bulk_child) يُلغون مع الأب حتى
        // لا يبقوا قابلين للتأكيد الفردي بلا سجل يربطهم بالعملية الأصلية.
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
        // 0.3/M2: أي action فضل confirmed أكتر من 10 دقايق (تنفيذ خارج
        // المعاملة انقطع قبل finalize) يتحوّل لـ failed. الإعادة idempotent
        // بفضل client_upload_id الحتمي، فالرسالة توجّه لمراجعة الحالة
        // بدل إعادة عمياء.
        AiPendingAction::query()->where('status', 'confirmed')->where('tool', '!=', 'bulk_actions')->where('updated_at', '<', now()->subMinutes(10))
            ->chunkById(100, function ($actions): void {
                foreach ($actions as $action) {
                    $action->forceFill(['status' => 'failed', 'result' => ['message' => 'الحالة غير مؤكدة؛ راجع صور المنتج']])->save();
                }
            });
        // 4.1: مسح الملفات اللي ما اتستخدمتش بعد 24 ساعة
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
