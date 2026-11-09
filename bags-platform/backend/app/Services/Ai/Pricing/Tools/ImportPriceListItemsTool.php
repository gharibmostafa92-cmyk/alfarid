<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Enums\Catalog\PriceListType;
use App\Exceptions\BusinessException;
use App\Models\AiAttachment;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\Pricing\PricingUpsertPrep;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Inventory\BranchAccess;
use App\Services\Shared\CostAccess;
use App\Services\Pricing\PriceListService;
use App\Services\Shared\SettingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class ImportPriceListItemsTool implements AgentTool
{
    public function __construct(
        private EntityResolver $resolver,
        private PriceListService $lists,
        private ProposalBuilder $proposals,
        private PricingPresenter $presenter,
        private BranchAccess $branches,
        private SettingService $settings,
    ) {}

    public function name(): string
    {
        return 'import_price_list_items';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function permissions(): array
    {
        return ['price_lists.update', 'ai_agent.execute'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'استيراد بنود من ملف CSV مرفق (كود، سعر، حد كمية) إلى اقتراح واحد لـ upsert_price_list_items. الصفوف الغامضة تروح blocked والباقي يكمل. أكتر من 200 صف يعني قسّم الملف.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['price_list_id', 'attachment_ids'],
                'properties' => (object) [
                    'price_list_id' => ['type' => 'INTEGER'],
                    'attachment_ids' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        if (empty($args['price_list_id'])) {
            return ToolOutcome::denied('حدد قائمة الأسعار المستهدفة.', auditable: false);
        }
        try {
            $list = $this->lists->find((int) $args['price_list_id'], $actor);
        } catch (ModelNotFoundException) {
            return ToolOutcome::denied('قائمة الأسعار غير موجودة.', auditable: false);
        }

        $rawIds = array_values(array_unique(array_map('strval', (array) ($args['attachment_ids'] ?? []))));
        if ($rawIds === [] || count($rawIds) > 5) {
            return ToolOutcome::denied('ارفق ملف CSV واحد على الأقل (بحد أقصى 5).', auditable: false);
        }
        $conv = $this->proposals->conversation();
        if (! $conv) {
            return ToolOutcome::denied('المحادثة غير متاحة.', auditable: false);
        }
        $attachments = AiAttachment::query()
            ->where('conversation_id', $conv->id)
            ->whereIn('id', $rawIds)->get()->keyBy('id');
        foreach ($rawIds as $attId) {
            if (! $attachments->has($attId)) {
                return ToolOutcome::denied('الملف المرفق غير متاح؛ ارفعه من جديد.', auditable: false);
            }
        }

        $maxItems = max(1, (int) $this->settings->get('ai_pricing_max_items_per_action', 200));
        $disk = Storage::disk('ai_private');
        $canViewCost = CostAccess::canView($actor);
        $belowCostPolicy = (string) $this->settings->get('ai_price_below_cost_policy', 'block');
        $belowCostPolicy = in_array($belowCostPolicy, ['block', 'warn'], true) ? $belowCostPolicy : 'block';
        $listType = $list->type instanceof PriceListType ? $list->type->value : (string) $list->type;
        $isWholesaleList = in_array($listType, [PriceListType::Wholesale->value, PriceListType::Distributor->value], true);
        $current = PriceListItem::query()->where('price_list_id', $list->id)->get()
            ->keyBy(fn (PriceListItem $i) => $i->product_variant_id.':'.number_format((float) $i->min_qty, 4, '.', ''));

        $rows = [];
        $blocked = [];
        $warnings = [];
        $processed = 0;
        foreach ($rawIds as $attId) {
            /** @var AiAttachment $att */
            $att = $attachments->get($attId);
            $ext = strtolower(pathinfo((string) $att->path, PATHINFO_EXTENSION));
            if (! in_array($ext, ['csv', 'txt'], true)) {
                return ToolOutcome::denied('الاستيراد من CSV/TXT فقط (10MB للملف).', auditable: false);
            }
            if (! $disk->exists($att->path)) {
                return ToolOutcome::denied('ملف الشيت لم يعد متاحًا؛ ارفعه من جديد.', auditable: false);
            }
            if ($disk->size($att->path) > 10 * 1024 * 1024) {
                return ToolOutcome::denied('الملف أكبر من 10MB.', auditable: false);
            }
            $content = (string) $disk->get($att->path);
            $lines = preg_split('/\r\n|\r|\n/', $content);
            $header = str_getcsv((string) preg_replace('/^\xEF\xBB\xBF/', '', $lines[0] ?? ''), escape: '');
            $columns = array_map(fn ($column) => strtolower(trim((string) $column)), $header);
            if (in_array('price', $columns, true) && array_intersect(['sku', 'variant_id'], $columns) !== []) {
                try {
                    $preview = $this->previewCsv($list, $actor, $content, basename($att->path));
                } catch (BusinessException $exception) {
                    return ToolOutcome::denied($exception->getMessage(), auditable: false);
                }
                $processed += $preview['row_count'];
                if ($processed > $maxItems) {
                    return ToolOutcome::denied("الملف أكبر من الحد ({$maxItems} صف) — قسّم الملف.", auditable: false);
                }
                foreach ($preview['errors'] as $error) {
                    $blocked[] = ['sku' => '?', 'reason' => basename($att->path).' · سطر '.$error['line'].': '.$error['message']];
                }
                $variants = ProductVariant::query()->whereIn('id', array_column($preview['diff'], 'variant_id'))->get()->keyBy('id');
                foreach ($preview['diff'] as $row) {
                    $source = basename($att->path).' · سطر '.$row['line'];
                    $this->consumeLine($actor, [$row['sku'], $row['new_price'], $row['min_qty'], $row['starts_at'], $row['ends_at']],
                        $current, $rows, $blocked, $warnings, $canViewCost, $belowCostPolicy, $isWholesaleList, $source, $variants->get($row['variant_id']));
                }

                continue;
            }
            foreach ($lines as $lineIndex => $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $parts = str_getcsv($line, escape: '');
                if ($lineIndex === 0 && $this->looksLikeHeader($parts)) {
                    continue;
                }
                if (++$processed > $maxItems) {
                    return ToolOutcome::denied("الملف أكبر من الحد ({$maxItems} صف) — قسّم الملف.", auditable: false);
                }
                $this->consumeLine($actor, $parts, $current, $rows, $blocked, $warnings, $canViewCost, $belowCostPolicy, $isWholesaleList, basename($att->path).' · سطر '.($lineIndex + 1));
            }
        }

        if ($rows === []) {
            return ToolOutcome::denied('كل الصفوف تم استبعادها بواسطة الحراس — راجع blocked.', auditable: false);
        }
        $rows = collect($rows)->keyBy(fn ($row) => $row['variant_id'].':'.number_format($row['min_qty'], 4, '.', ''))->values()->all();

        $minPct = null;
        foreach ($rows as $row) {
            if ($row['old_price'] !== null && $row['old_price'] > 0) {
                $pct = (($row['price'] - $row['old_price']) / $row['old_price']) * 100;
                if ($minPct === null || $pct < $minPct) {
                    $minPct = $pct;
                }
            }
        }
        $risk = (count($rows) > 20 || ($minPct !== null && $minPct < -15)) ? 'high' : 'medium';
        $locking = PricingUpsertPrep::versionsAndInverse($list, $rows, $current);

        return $this->proposals->make(
            $actor,
            'upsert_price_list_items',
            ['price_list_id' => $list->id, 'rows' => array_map(fn ($r) => ['variant_id' => $r['variant_id'], 'min_qty' => $r['min_qty'], 'price' => $r['price'], 'starts_at' => $r['starts_at'], 'ends_at' => $r['ends_at'], 'source_row' => $r['source_row']], $rows)],
            risk: $risk,
            title: 'استيراد بنود إلى: '.$list->name.' ('.count($rows).' بند)',
            before: ['source' => 'ملفات CSV مرفقة'],
            after: ['items_count' => count($rows), 'blocked_count' => count($blocked)],
            warnings: $warnings,
            expectedVersions: $locking['expectedVersions'],
            inverse: $locking['inverse'],
            typedConfirmation: $risk === 'high' ? $list->name : null,
            typedConfirmationHint: 'اكتب اسم القائمة',
            extraPreview: [
                'block_type' => 'price_diff',
                'summary' => ['list_id' => $list->id, 'list_name' => $list->name, 'updated_count' => count(array_filter($rows, fn ($r) => $r['old_price'] !== null)), 'created_count' => count(array_filter($rows, fn ($r) => $r['old_price'] === null)), 'blocked_count' => count($blocked)],
                'rows' => array_map(fn ($r) => ['sku' => $r['sku'], 'min_qty' => $r['min_qty'], 'old_price' => $r['old_price'], 'new_price' => $r['price'], 'delta' => $r['old_price'] !== null ? round($r['price'] - $r['old_price'], 2) : $r['price'], 'delta_pct' => null, 'source_row' => $r['source_row']], $rows),
                'blocked' => $blocked,
            ],
        );
    }

    private function previewCsv(\App\Models\PriceList $list, User $actor, string $content, string $name): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ai-price-csv-');
        if ($path === false) {
            throw new BusinessException('تعذرت قراءة ملف الاستيراد.');
        }
        try {
            if (file_put_contents($path, $content) === false) {
                throw new BusinessException('تعذرت قراءة ملف الاستيراد.');
            }

            return $this->lists->importCsv($list, new UploadedFile($path, $name, test: true), $actor, dryRun: true);
        } finally {
            unlink($path);
        }
    }

    /** @param list<string|null> $parts */
    private function looksLikeHeader(array $parts): bool
    {
        $head = mb_strtolower(trim((string) ($parts[0] ?? '')));
        $second = mb_strtolower(trim((string) ($parts[1] ?? '')));

        return in_array($head, ['sku', 'code', 'الكود', 'كود'], true) || in_array($second, ['price', 'السعر', 'سعر'], true);
    }

    /**
     * @param  list<string|null>  $parts
     * @param  \Illuminate\Support\Collection<int, PriceListItem>  $current
     * @param  list<array<string,mixed>>  $rows
     * @param  list<array<string,mixed>>  $blocked
     * @param  list<string>  $warnings
     */
    private function consumeLine(User $actor, array $parts, $current, array &$rows, array &$blocked, array &$warnings, bool $canViewCost, string $belowCostPolicy, bool $isWholesaleList, string $sourceRow, ?ProductVariant $variant = null): void
    {
        $code = trim((string) ($parts[0] ?? ''));
        if ($code === '') {
            $blocked[] = ['sku' => '?', 'reason' => $sourceRow.': سطر من غير كود.'];
            return;
        }
        $variant ??= $this->resolver->exactVariantByCode($code);
        if (! $variant) {
            $options = $this->resolver->variants($actor, $code);
            $variant = count($options) === 1 ? \App\Models\ProductVariant::query()->find($options[0]['id']) : null;
        }
        if (! $variant) {
            $blocked[] = ['sku' => $code, 'reason' => $sourceRow.': الكود مش متطابق مع صنف — راجعه يدويًا.'];
            return;
        }
        if (! isset($parts[1]) || ! is_numeric(trim((string) $parts[1])) || (float) $parts[1] <= 0) {
            $blocked[] = ['sku' => $code, 'reason' => $sourceRow.': السعر لازم يكون رقم أكبر من صفر.'];
            return;
        }
        $price = (float) $parts[1];
        $minQty = isset($parts[2]) && is_numeric(trim((string) $parts[2])) && (float) $parts[2] > 0 ? (float) $parts[2] : 1.0;
        $startsAt = isset($parts[3]) && trim((string) $parts[3]) !== '' ? trim((string) $parts[3]) : null;
        $endsAt = isset($parts[4]) && trim((string) $parts[4]) !== '' ? trim((string) $parts[4]) : null;
        if (($startsAt !== null && strtotime($startsAt) === false) || ($endsAt !== null && strtotime($endsAt) === false)) {
            $blocked[] = ['sku' => $code, 'reason' => $sourceRow.': صيغة التاريخ غير صالحة.'];
            return;
        }
        if ($startsAt !== null && $endsAt !== null && strtotime($endsAt) < strtotime($startsAt)) {
            $blocked[] = ['sku' => $code, 'reason' => $sourceRow.': نهاية الصلاحية تسبق بدايتها.'];
            return;
        }

        if ($isWholesaleList && $variant->retail_price !== null && $price >= (float) $variant->retail_price) {
            $blocked[] = ['sku' => $code, 'reason' => $sourceRow.': سعر الجملة أكبر من أو يساوي القطاعي.'];
            return;
        }
        $cost = $variant->cost_price !== null ? (float) $variant->cost_price : null;
        if ($cost !== null && $cost > 0 && $price < $cost) {
            $message = $canViewCost ? "السعر ({$price}) أقل من التكلفة ({$cost})." : 'السعر أقل من التكلفة (محمي).';
            if ($belowCostPolicy === 'block') {
                $blocked[] = ['sku' => $code, 'reason' => $sourceRow.': '.$message];
                return;
            }
            $warnings[] = $sourceRow.": {$code} — {$message}";
        }

        $old = $current->get($variant->id.':'.number_format($minQty, 4, '.', ''));
        $rows[] = [
            'variant_id' => $variant->id,
            'sku' => $variant->sku,
            'min_qty' => $minQty,
            'price' => $price,
            'old_price' => $old ? (float) $old->price : null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'source_row' => $sourceRow,
        ];
    }
}
