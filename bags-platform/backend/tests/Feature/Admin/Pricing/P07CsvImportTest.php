<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Pricing;

use App\Enums\PriceListType;
use App\Models\PriceList;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * task.txt item 16: CSV validation, counters, and export→import roundtrip.
 */
class P07CsvImportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function staff(): User
    {
        foreach (['price_lists.view', 'price_lists.import', 'price_lists.export', 'inventory.all_branches'] as $p) {
            Permission::findOrCreate($p, 'staff');
        }
        $u = User::factory()->create();
        $u->givePermissionTo(['price_lists.view', 'price_lists.import', 'price_lists.export', 'inventory.all_branches']);

        return $u;
    }

    private function token(User $u): string
    {
        Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    private function list(): PriceList
    {
        return PriceList::query()->create(['name' => 'L'.uniqid(), 'type' => PriceListType::Retail]);
    }

    private function variant(array $overrides = []): ProductVariant
    {
        return ProductVariant::factory()->create($overrides);
    }

    /** @return array{created: int, updated: int, skipped: int, errors: list} */
    private function import(PriceList $list, string $csv): array
    {
        $response = $this->withToken($this->token($this->staff()))->post(
            "/api/v1/admin/price-lists/{$list->id}/import",
            ['file' => UploadedFile::fake()->createWithContent('prices.csv', $csv)]);
        $response->assertOk();

        return $response->json('data');
    }

    // ── BOM + uppercase headers are accepted ──
    public function test_bom_and_uppercase_headers_are_accepted(): void
    {
        $list = $this->list();
        $v = $this->variant();
        $csv = "\xEF\xBB\xBF"."SKU,VARIANT_ID,PRODUCT_NAME,MIN_QTY,PRICE,STARTS_AT,ENDS_AT\n{$v->sku},{$v->id},,1,88,,\n";
        $report = $this->import($list, $csv);
        $this->assertSame(1, $report['created']);
        $this->assertSame(0, $report['skipped']);
        $this->assertSame(88.0, (float) $list->items()->firstOrFail()->price);
    }

    public function test_preview_reports_changes_without_writing_prices(): void
    {
        $list = $this->list();
        $variant = $this->variant();
        $list->items()->create(['product_variant_id' => $variant->id, 'min_qty' => 1, 'price' => 55]);
        $csv = "variant_id,min_qty,price\n{$variant->id},1,88\n{$variant->id},2,70\n";

        $this->withToken($this->token($this->staff()))->post(
            "/api/v1/admin/price-lists/{$list->id}/import?dry_run=1",
            ['file' => UploadedFile::fake()->createWithContent('prices.csv', $csv)],
        )->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.diff.0.old_price', '55.0000')
            ->assertJsonPath('data.diff.0.new_price', '88')
            ->assertJsonPath('data.diff.1.old_price', null);

        $this->assertSame(1, $list->items()->count());
        $this->assertSame(55.0, (float) $list->items()->firstOrFail()->price);
    }

    public function test_percentage_update_is_atomic_when_one_price_overflows(): void
    {
        $list = $this->list();
        $first = $list->items()->create(['product_variant_id' => $this->variant()->id, 'min_qty' => 1, 'price' => 100]);
        $second = $list->items()->create(['product_variant_id' => $this->variant()->id, 'min_qty' => 1, 'price' => 90000000]);
        $staff = $this->staff();
        $staff->givePermissionTo(Permission::findOrCreate('price_lists.update', 'staff'));
        $url = "/api/v1/admin/price-lists/{$list->id}/apply-percentage";

        $this->withToken($this->token($staff))->postJson($url, ['percent' => 20])->assertUnprocessable();
        $this->assertSame(100.0, (float) $first->fresh()->price);
        $this->assertSame(90000000.0, (float) $second->fresh()->price);

        $this->withToken($this->token($staff))->postJson($url, ['percent' => 10])
            ->assertOk()->assertJsonPath('data.updated', 2);
        $this->assertSame(110.0, (float) $first->fresh()->price);
        $this->assertSame(99000000.0, (float) $second->fresh()->price);
    }

    // ── duplicate columns or missing price/identity reject the whole file ──
    public function test_bad_headers_reject_the_whole_file(): void
    {
        $list = $this->list();

        $dup = "sku,price,price\nA,1,2\n";
        $this->withToken($this->token($this->staff()))->post("/api/v1/admin/price-lists/{$list->id}/import",
            ['file' => UploadedFile::fake()->createWithContent('p.csv', $dup)])
            ->assertStatus(422)->assertJsonFragment(['message' => 'يجب أن يحتوي الملف على price وsku أو variant_id، دون أعمدة مكررة.']);

        $noPrice = "sku,variant_id\nA,1\n";
        $this->withToken($this->token($this->staff()))->post("/api/v1/admin/price-lists/{$list->id}/import",
            ['file' => UploadedFile::fake()->createWithContent('p.csv', $noPrice)])->assertStatus(422);

        $noIdentity = "price,min_qty\n10,1\n";
        $this->withToken($this->token($this->staff()))->post("/api/v1/admin/price-lists/{$list->id}/import",
            ['file' => UploadedFile::fake()->createWithContent('p.csv', $noIdentity)])->assertStatus(422);

        $empty = $this->withToken($this->token($this->staff()))->post("/api/v1/admin/price-lists/{$list->id}/import",
            ['file' => UploadedFile::fake()->createWithContent('p.csv', '')]);
        $this->assertContains($empty->getStatusCode(), [400, 409, 422]);
        $this->assertSame(0, $list->items()->count());
    }

    // ── size caps: 2000 synced rows, 10000 lines ──
    public function test_size_caps_reject_oversized_files(): void
    {
        $list = $this->list();
        $token = $this->token($this->staff());

        $rows = [];
        for ($i = 1; $i <= 2001; $i++) {
            $rows[] = "SKU-{$i},,,1,10,,";
        }
        $csv = "sku,variant_id,product_name,min_qty,price,starts_at,ends_at\n".implode("\n", $rows)."\n";
        $this->withToken($token)->post("/api/v1/admin/price-lists/{$list->id}/import",
            ['file' => UploadedFile::fake()->createWithContent('p.csv', $csv)])
            ->assertStatus(422)->assertJsonFragment(['message' => 'الحد الأقصى للاستيراد المتزامن 2000 صف؛ قسّم الملف إلى أجزاء أصغر.']);

        // Blank lines still count toward the 10k line cap even though they add no rows.
        $csv = "sku,variant_id,product_name,min_qty,price,starts_at,ends_at\n".str_repeat("\n", 10000);
        $this->withToken($token)->post("/api/v1/admin/price-lists/{$list->id}/import",
            ['file' => UploadedFile::fake()->createWithContent('p.csv', $csv)])
            ->assertStatus(422)->assertJsonFragment(['message' => 'عدد أسطر الملف يتجاوز الحد المسموح.']);
    }

    // ── row-level validation: casing, conflicts, inactive, numbers, dates ──
    public function test_row_level_validation_skips_with_line_numbers(): void
    {
        $list = $this->list();
        $lower = $this->variant(['sku' => 'lowcase-1']);
        $other = $this->variant(['sku' => 'other-9']);
        $inactive = ProductVariant::factory()->create(['is_active' => false]);

        $csv = "sku,variant_id,product_name,min_qty,price,starts_at,ends_at\n"
            ."LOWCASE-1,,,1,50,,\n"           // line 2: lowercase sku matches
            ."{$other->sku},999999,,1,60,,\n" // line 3: sku/variant conflict
            ."{$inactive->sku},,,1,70,,\n"    // line 4: inactive variant
            ."{$lower->sku},,,1,-5,,\n"        // line 5: negative price
            ."{$lower->sku},,,0,55,,\n"       // line 6: min_qty = 0
            ."{$lower->sku},,,1,55.12345,,\n" // line 7: five decimals
            ."{$lower->sku},,,1,55,2026/10/01,\n" // line 8: bad date format
            ."{$lower->sku},,,1,55,2026-11-05,2026-10-01\n"; // line 9: ends before starts
        $report = $this->import($list, $csv);

        $this->assertSame(1, $report['created']);
        $this->assertSame(50.0, (float) $list->items()->where('product_variant_id', $lower->id)->firstOrFail()->price);
        $lines = collect($report['errors'])->pluck('line')->all();
        foreach ([3, 4, 5, 6, 7, 8, 9] as $line) {
            $this->assertContains($line, $lines, "line {$line} must be reported");
        }
    }

    // ── duplicate (variant, min_qty) rows: last write wins ──
    public function test_duplicate_lines_last_write_wins(): void
    {
        $list = $this->list();
        $v = $this->variant();
        $csv = "sku,variant_id,product_name,min_qty,price,starts_at,ends_at\n"
            ."{$v->sku},{$v->id},,1,100,,\n"
            ."{$v->sku},{$v->id},,1,120,,\n";
        $report = $this->import($list, $csv);
        $this->assertSame(1, $list->items()->count());
        $this->assertSame(120.0, (float) $list->items()->firstOrFail()->price);
        $this->assertSame(0, $report['skipped']);
    }

    // ── ISO dates with offsets and ends<starts handling ──
    public function test_iso_dates_and_inverted_range(): void
    {
        $list = $this->list();
        $v = $this->variant();
        $csv = "sku,variant_id,product_name,min_qty,price,starts_at,ends_at\n"
            ."{$v->sku},{$v->id},,1,100,2026-10-01T00:00:00+02:00,2026-11-01T00:00:00+02:00\n";
        $report = $this->import($list, $csv);
        $this->assertSame(0, $report['skipped']);
        $item = $list->items()->firstOrFail();
        $this->assertSame('2026-09-30 22:00:00', $item->starts_at->format('Y-m-d H:i:s'));

        $list2 = $this->list();
        $v2 = $this->variant();
        $csv2 = "sku,variant_id,product_name,min_qty,price,starts_at,ends_at\n"
            ."{$v2->sku},{$v2->id},,1,100,2026-11-05,2026-10-01\n";
        $report2 = $this->import($list2, $csv2);
        $this->assertSame(1, $report2['skipped']);
    }

    // ── export → reimport is a no-op (incl. formula-escaped skus) ──
    public function test_export_reimport_roundtrip_is_stable(): void
    {
        Auth::forgetGuards();
        $token = $this->token($this->staff());
        $list = $this->list();
        $v = $this->variant(['sku' => '=X1Q']);
        $v2 = $this->variant();
        $list->items()->create(['product_variant_id' => $v->id, 'min_qty' => 1, 'price' => 90,
            'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-12-01 00:00:00']);
        $list->items()->create(['product_variant_id' => $v2->id, 'min_qty' => 5, 'price' => 70]);

        $export = $this->withToken($token)->get("/api/v1/admin/price-lists/{$list->id}/export")->assertOk();
        $csv = $export->streamedContent();
        $this->assertStringContainsString("'=X1Q", $csv);

        $report = $this->import($list, $csv);
        $this->assertSame(['created' => 0, 'updated' => 2, 'skipped' => 0], [
            'created' => $report['created'], 'updated' => $report['updated'], 'skipped' => $report['skipped'],
        ]);
        $this->assertSame(2, $list->items()->count());
        $this->assertSame(90.0, (float) $list->items()->where('product_variant_id', $v->id)->firstOrFail()->price);
        $this->assertSame(70.0, (float) $list->items()->where('product_variant_id', $v2->id)->firstOrFail()->price);
    }
}
