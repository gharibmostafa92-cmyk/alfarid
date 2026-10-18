<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WebhookLog;
use Illuminate\Console\Command;

/** حذف سجلات الويب هوك القديمة — سياسة احتفاظ بدل نمو بلا حد (M4). */
class PruneWebhookLogs extends Command
{
    protected $signature = 'webhooks:prune-logs {--days=90 : الاحتفاظ بعدد الأيام هذا}';

    protected $description = 'Delete webhook log rows older than the retention window';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $deleted = 0;

        // الحذف على دفعات (F-29): عبارة DELETE واحدة على جدول ضخم تُطيل
        // القفل وتفشل عند تراكم ملايين السجلات
        do {
            $batch = WebhookLog::query()
                ->where('created_at', '<', $cutoff)
                ->limit(1000)
                ->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("Pruned {$deleted} webhook log rows older than {$days} days.");

        return self::SUCCESS;
    }
}
