<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Services\Shared\CostAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AiItemDocumentDraftController extends Controller
{
    public function show(Request $request, string $draft): JsonResponse
    {
        $row = $this->authorizedDraft($request, $draft)->first();

        abort_if($row === null, 404);

        return response()->json(['version' => (int) $row->version, 'edits' => json_decode($row->edits),
            'receipt_id' => $row->receipt_id]);
    }

    public function update(Request $request, string $draft): JsonResponse
    {
        $query = $this->authorizedDraft($request, $draft);
        $row = $query->first();
        abort_if($row === null, 404);
        $validated = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'remember_supplier' => ['sometimes', 'boolean'],
            'edits' => ['present', 'array', 'max:250'],
            'edits.*' => ['array:matched_sku,quantity,manufacturing_unit_cost,excluded'],
            'edits.*.matched_sku' => ['nullable', 'string', 'max:128'],
            'edits.*.quantity' => ['nullable', 'string', 'max:32'],
            'edits.*.manufacturing_unit_cost' => ['nullable', 'string', 'max:32'],
            'edits.*.excluded' => ['sometimes', 'boolean'],
        ]);
        $rows = json_decode($row->rows, true);
        abort_unless(array_diff_key($validated['edits'], $rows) === [], 422);
        $edits = (object) array_map(fn (array $fields) => array_map(fn ($value) => $value ?? '', $fields), $validated['edits']);

        $version = (int) $validated['version'];
        DB::transaction(function () use ($query, $version, $edits, $rows, $row, $validated, $request): void {
            $updated = $query->where('version', $version)->whereNull('receipt_id')->update([
                'edits' => json_encode($edits, JSON_THROW_ON_ERROR),
                'version' => $version + 1,
            ]);
            abort_unless($updated === 1, 409, 'المراجعة اتعدلت في نافذة أخرى. افتح المحادثة من جديد.');
            if (! ($validated['remember_supplier'] ?? false)) {
                return;
            }
            abort_unless($row->supplier_id && $request->user('staff')->can('suppliers.update'), 403);
            foreach ((array) $edits as $index => $fields) {
                $code = trim($rows[$index]['sku'] ?? '');
                $sku = trim($fields['matched_sku'] ?? '');
                if (($fields['excluded'] ?? false) || $code === '' || $sku === '' || strlen($code) > 128) {
                    continue;
                }
                $variant = DB::table('product_variants')->where('sku', $sku)->where('is_active', true)->value('id');
                abort_if($variant === null, 422, 'كود الصنف غير متاح.');
                $key = ['supplier_id' => $row->supplier_id, 'supplier_code' => $code];
                DB::table('ai_supplier_variant_codes')->insertOrIgnore($key + ['product_variant_id' => $variant]);
                $current = DB::table('ai_supplier_variant_codes')->where($key)->value('product_variant_id');
                abort_unless((string) $current === (string) $variant, 409, 'كود المورد مرتبط بصنف آخر بالفعل.');
            }
        });

        return response()->json(['version' => $version + 1, 'edits' => $edits]);
    }

    private function authorizedDraft(Request $request, string $id): Builder
    {
        $actor = $request->user('staff');
        $query = DB::table('ai_item_document_drafts')->where('id', $id)
            ->whereIn('conversation_id', DB::table('ai_conversations')->select('id')->where('user_id', $actor->id));
        $mode = $query->value('mode');
        abort_if($mode === null, 404);
        abort_unless($mode === 'catalog'
            ? $actor->can('products.create') && $actor->can('ai_agent.catalog.vision')
            : $actor->can('inventory.adjust') && CostAccess::canView($actor), 403);

        return $query;
    }
}
