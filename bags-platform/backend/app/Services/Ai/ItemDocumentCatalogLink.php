<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\BusinessException;
use App\Models\AiPendingAction;
use Illuminate\Support\Facades\DB;

final class ItemDocumentCatalogLink
{
    public static function properties(): array
    {
        return [
            'document_draft_id' => ['type' => 'STRING'],
            'document_variant_lines' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER'],
                'description' => 'أرقام سطور المستند من 1، بنفس ترتيب المتغيرات الجديدة في المعاينة.'],
        ];
    }

    public static function prepare(array $args, ?int $conversationId, int $count): array
    {
        if (! isset($args['document_draft_id'])) {
            return [];
        }
        $draft = DB::table('ai_item_document_drafts')->where('id', $args['document_draft_id'])
            ->where('conversation_id', $conversationId)->first();
        $lines = $args['document_variant_lines'] ?? [];
        if (! $draft || $draft->receipt_id || $draft->version < 1 || ! is_array($lines) || count($lines) !== $count
            || count(array_unique(array_filter($lines, 'is_int'))) !== $count) {
            throw new BusinessException('احفظ مراجعة المستند وحدّد سطرًا مختلفًا لكل متغير جديد.');
        }
        self::validateLines($draft, $lines);

        return ['document_draft_id' => $draft->id, 'document_variant_lines' => array_values($lines),
            'document_draft_version' => (int) $draft->version];
    }

    public static function lock(AiPendingAction $action): ?object
    {
        if (! isset($action->args['document_draft_id'])) {
            return null;
        }
        $draft = DB::table('ai_item_document_drafts')->where('id', $action->args['document_draft_id'])
            ->where('conversation_id', $action->conversation_id)->lockForUpdate()->first();
        if (! $draft || $draft->receipt_id || (int) $draft->version !== $action->args['document_draft_version']) {
            throw new BusinessException('المستند اتغير. راجع البنود واطلب اقتراحًا جديدًا.', [], 409);
        }
        self::validateLines($draft, $action->args['document_variant_lines']);

        return $draft;
    }

    public static function record(AiPendingAction $action, ?object $draft, array $skus): void
    {
        if (! $draft) {
            return;
        }
        $lines = $action->args['document_variant_lines'];
        if (count($lines) !== count($skus)) {
            throw new BusinessException('تعذّر ربط الأصناف الجديدة بسطور المستند.', [], 409);
        }
        $edits = json_decode($draft->edits, true);
        foreach ($lines as $index => $line) {
            $edits[$line - 1]['matched_sku'] = $skus[$index];
        }
        DB::table('ai_item_document_drafts')->where('id', $draft->id)->update([
            'edits' => json_encode((object) $edits, JSON_THROW_ON_ERROR),
            'version' => $draft->version + 1,
        ]);
    }

    private static function validateLines(object $draft, array $lines): void
    {
        $rows = json_decode($draft->rows, true);
        $edits = json_decode($draft->edits, true);
        foreach ($lines as $line) {
            $row = $rows[$line - 1] ?? null;
            $edit = $edits[$line - 1] ?? [];
            if (! $row || ($edit['excluded'] ?? false) || trim($edit['matched_sku'] ?? $row['matched_sku'] ?? '') !== '') {
                throw new BusinessException('أحد سطور المستند غير متاح لإنشاء صنف جديد.', [], 409);
            }
        }
    }
}
