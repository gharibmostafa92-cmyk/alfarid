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
        AiConversation::withTrashed()->whereRaw('COALESCE(last_message_at, created_at) < ?', [now()->subDays($days)])->chunkById(100, function ($conversations): void {
            foreach ($conversations as $conversation) {
                $conversation->forceDelete();
            }
        });
        AiPendingAction::query()->where('status', 'pending')->where('expires_at', '<', now())->update(['status' => 'expired']);
        // M10: كارت جماعي اتحجز confirmed وما اتنفذش (العملية اتقطعت) —
        // يتقفل برسالة مفهومة بدل ما يفضل عالق للأبد.
        AiPendingAction::query()->where('status', 'confirmed')->where('updated_at', '<', now()->subMinutes(5))->update(['status' => 'failed', 'result' => ['message' => 'العملية الجماعية انتهت من غير تنفيذ كامل. اطلب اقتراح جديد.']]);
    }
}
