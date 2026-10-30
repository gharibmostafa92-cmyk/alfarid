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
        $scrubbed = 0;

        // (L1) صفوف «processed» هي مفاتيح منع التكرار (provider + event_id):
        // حذفها كان يسمح بإعادة معالجة نفس الحدث عند وصوله متأخرًا. نُبقي عليها
        // لكن نُفرّغ حمولتها (المفتاح لا يحتاج الحمولة) — لا حذف لمفتاح التكرار.
        // L6: cursor pagination (orderBy id + where id > lastId) — دون ترتيب
        // كانت كل دفعة تمسح نفس الصفوف المفرّغة، والتكلفة تتزايد مع الزمن.
        $lastScrubbedId = 0;
        do {
            $ids = WebhookLog::query()
                ->where('status', 'processed')
                ->where('created_at', '<', $cutoff)
                ->whereNotNull('payload')
                ->where('id', '>', $lastScrubbedId)
                ->orderBy('id')
                ->limit(1000)
                ->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            WebhookLog::query()->whereIn('id', $ids->all())->update(['payload' => null]);
            $scrubbed += $ids->count();
            $lastScrubbedId = $ids->max();
        } while ($ids->count() === 1000);

        // بقية الحالات (received/duplicate/ignored/failed) لا تحمل دلالة منع
        // تكرار، فتُحذف. الحذف على دفعات (F-29): عبارة DELETE واحدة على جدول
        // ضخم تُطيل القفل وتفشل عند تراكم ملايين السجلات.
        $lastDeletedId = 0;
        do {
            $ids = WebhookLog::query()
                ->where('created_at', '<', $cutoff)
                ->where('status', '!=', 'processed')
                ->where('id', '>', $lastDeletedId)
                ->orderBy('id')
                ->limit(1000)
                ->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += WebhookLog::query()->whereIn('id', $ids->all())->delete();
            $lastDeletedId = $ids->max();
        } while ($ids->count() === 1000);

        $this->info("Pruned {$deleted} webhook log rows and scrubbed {$scrubbed} processed rows older than {$days} days.");

        return self::SUCCESS;
    }
}
