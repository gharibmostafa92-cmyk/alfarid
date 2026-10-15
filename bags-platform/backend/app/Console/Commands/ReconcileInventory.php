<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\StockLevel;
use App\Models\StockMovement;
use Illuminate\Console\Command;

/**
 * مطابقة دورية بين الدفتر والأرصدة (M-8): القاعدة تضمن صحة كل حركة على حدة،
 * لكن أي كتابة مباشرة على stock_levels (والـ fillable يسمح بها) لا يكشفها
 * شيء آخر. الفحص يتحقق لكل وعاء من أمرين:
 * 1. رصيد stock_levels يساوي آخر balance_after في الدفتر لنفس الوعاء.
 * 2. تسلسل الدفتر متصل: balance_before لكل حركة يساوي balance_after لسابقتها.
 * يُشغَّل دوريًا (جدولة ليلية) ويُرجع كود فشل عند وجود أي فرق للتنبيه.
 */
class ReconcileInventory extends Command
{
    protected $signature = 'inventory:reconcile {--branch_id= : قصر الفحص على فرع واحد}';

    protected $description = 'مطابقة أرصدة المخزون مع دفتر الحركات وكشف أي كتابة مباشرة';

    /** @var list<string> */
    private array $buckets = ['on_hand', 'reserved', 'damaged', 'in_transit'];

    public function handle(): int
    {
        $problems = [];
        $checked = 0;

        $levels = StockLevel::query()
            ->when($this->option('branch_id'), fn ($q, $v) => $q->where('branch_id', (int) $v))
            ->orderBy('branch_id')->orderBy('id')
            ->lazy(200);

        foreach ($levels as $level) {
            $checked++;
            $movements = StockMovement::query()
                ->where('product_variant_id', $level->product_variant_id)
                ->where('branch_id', $level->branch_id)
                ->where('location_id', $level->location_id)
                ->orderBy('id')
                ->get(['id', 'affected_bucket', 'balance_before', 'balance_after']);

            $expected = array_fill_keys($this->buckets, 0);
            foreach ($movements as $movement) {
                $bucket = $movement->affected_bucket instanceof \BackedEnum
                    ? $movement->affected_bucket->value
                    : (string) $movement->affected_bucket;

                if ((int) round((float) $movement->balance_before * 10000) !== (int) round($expected[$bucket] * 10000)) {
                    $problems[] = "حركة #{$movement->id}: balance_before ({$movement->balance_before}) لا يساوي الرصيد السابق ({$expected[$bucket]}) لوعاء {$bucket} — متغير {$level->product_variant_id} فرع {$level->branch_id}";
                }
                $expected[$bucket] = (float) $movement->balance_after;
            }

            foreach ($this->buckets as $bucket) {
                if ((int) round((float) $level->{$bucket} * 10000) !== (int) round($expected[$bucket] * 10000)) {
                    $problems[] = "متغير {$level->product_variant_id} فرع {$level->branch_id} موقع ".($level->location_id ?? '—').": رصيد {$bucket} في stock_levels ({$level->{$bucket}}) لا يطابق الدفتر ({$expected[$bucket]})";
                }
            }
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        if ($problems !== []) {
            $this->error('المطابقة فشلت: '.count($problems).' فرق في '.$checked.' صف رصيد.');

            return self::FAILURE;
        }

        $this->info("المطابقة سليمة: {$checked} صف رصيد مطابق للدفتر.");

        return self::SUCCESS;
    }
}
