<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Pricing;

use App\Enums\PriceListType;
use App\Enums\ProductStatus;
use App\Models\AiAttachment;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\GeminiClient;
use App\Services\Ai\ToolRegistry;
use App\Services\Shared\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PricingVisionImportTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        DB::table('settings')->whereIn('key', ['ai_enabled', 'ai_pricing_tools_enabled', 'ai_promotions_tools_enabled'])->update(['value' => '1']);
        foreach (['ai_pricing_tools_enabled' => '1', 'ai_promotions_tools_enabled' => '1', 'ai_pricing_max_items_per_action' => '200', 'ai_coupon_high_risk_percent' => '30', 'ai_price_below_cost_policy' => 'block', 'ai_pricing_vision_enabled' => '1'] as $key => $value) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['group' => 'ai', 'value' => $value]);
        }
        app(SettingService::class)->clearCache();
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        foreach (['ai_agent.use', 'ai_agent.execute', 'ai_agent.catalog.vision', 'price_lists.view', 'price_lists.create', 'price_lists.update', 'customers.view', 'inventory.all_branches'] as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function variant(float $retail = 100): ProductVariant
    {
        $category = Category::query()->firstOrCreate(['slug' => 'test-cat'], ['name_ar' => 'قسم', 'name_en' => 'Cat', 'sort_order' => 1, 'is_active' => true]);
        $product = Product::query()->create([
            'code' => 'P'.uniqid(), 'category_id' => $category->id, 'name_ar' => 'منتج', 'name_en' => 'P',
            'slug' => 'p-'.uniqid(), 'status' => ProductStatus::Published, 'sales_channel' => 'both',
        ]);

        return ProductVariant::query()->create([
            'product_id' => $product->id, 'sku' => 'VSHEET-'.uniqid(), 'retail_price' => $retail,
            'wholesale_price' => 80, 'is_active' => true,
        ]);
    }

    private function attach(AiConversation $conv, User $u, string $path, string $mime): AiAttachment
    {
        return AiAttachment::create([
            'conversation_id' => $conv->id, 'user_id' => $u->id,
            'path' => $path, 'mime' => $mime, 'size' => 12, 'sha256' => hash('sha256', $path),
        ]);
    }

    public function test_vision_gate_closed_denies_without_model_call(): void
    {
        $admin = $this->admin();
        DB::table('settings')->where('key', 'ai_pricing_vision_enabled')->update(['value' => '0']);
        app(SettingService::class)->clearCache();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);

        $this->mock(GeminiClient::class, function ($mock): void {
            $mock->shouldReceive('generateStructured')->never();
        });

        $out = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'parse_price_sheet',
            'args' => ['price_list_id' => 1, 'attachment_ids' => [1]],
        ]);
        $this->assertSame('denied', $out->type);
        $this->assertStringContainsString('مقفولة من الإعدادات', $out->message);
    }

    public function test_parse_image_sheet_produces_single_upsert_proposal(): void
    {
        $admin = $this->admin();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $variant = $this->variant();
        $list = PriceList::query()->create(['name' => 'List', 'type' => PriceListType::Retail]);
        Storage::fake('ai_private');
        Storage::disk('ai_private')->put('sheet.jpg', 'fake-bytes');
        $att = $this->attach($conv, $admin, 'sheet.jpg', 'image/jpeg');

        $this->mock(GeminiClient::class, function ($mock) use ($variant): void {
            $mock->shouldReceive('generateStructured')->once()->andReturn([
                'data' => ['rows' => [
                    ['sku' => $variant->sku, 'price' => 90, 'min_qty' => 1, 'confidence' => 0.95],
                    ['sku' => 'لاشيء', 'price' => 10, 'min_qty' => 1, 'confidence' => 0.9],
                    ['sku' => $variant->sku, 'price' => 0, 'min_qty' => 1, 'confidence' => 0.99],
                    ['sku' => $variant->sku, 'price' => 70, 'min_qty' => 2, 'confidence' => 0.6],
                ]],
                'model' => 'gemini-test',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]);
        });

        $out = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'parse_price_sheet',
            'args' => ['price_list_id' => $list->id, 'attachment_ids' => [$att->id]],
        ]);
        $this->assertSame('proposal', $out->type);
        $this->assertSame('price_diff', $out->uiBlock['type']);
        $action = AiPendingAction::findOrFail($out->data['pending_action_id']);
        $this->assertSame('upsert_price_list_items', $action->tool);
        $this->assertCount(1, $action->args['rows']);
        $this->assertSame($variant->id, $action->args['rows'][0]['variant_id']);
        $this->assertSame(90.0, (float) $action->args['rows'][0]['price']);
        $this->assertNotEmpty($action->args['rows'][0]['source_row']);
        $this->assertNotEmpty($action->preview['warnings']);
        $this->assertSame(1, AiMessage::query()->where('conversation_id', $conv->id)->where('role', 'event')->count());
        $this->assertDatabaseMissing('price_list_items', ['price_list_id' => $list->id]);
    }

    public function test_import_csv_produces_single_upsert_proposal_and_confirms(): void
    {
        $admin = $this->admin();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $variant = $this->variant();
        $list = PriceList::query()->create(['name' => 'List', 'type' => PriceListType::Retail]);
        Storage::fake('ai_private');
        Storage::disk('ai_private')->put('import.csv', "\xEF\xBB\xBF"."variant_id,min_qty,price\n".$variant->id.",1,95\n99999999,2,50\n");
        $att = $this->attach($conv, $admin, 'import.csv', 'text/csv');

        $out = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'import_price_list_items',
            'args' => ['price_list_id' => $list->id, 'attachment_ids' => [$att->id]],
        ]);
        $this->assertSame('proposal', $out->type);
        $this->assertSame('price_diff', $out->uiBlock['type']);
        $action = AiPendingAction::findOrFail($out->data['pending_action_id']);
        $this->assertSame('upsert_price_list_items', $action->tool);
        $this->assertCount(1, $action->args['rows']);
        $this->assertStringContainsString('import.csv', $action->args['rows'][0]['source_row']);
        $this->assertCount(1, $out->uiBlock['blocked']);

        $res = app(\App\Services\Ai\PendingActionService::class)->confirm($admin, $action);
        $this->assertSame('result', $res->type);
        $this->assertSame(95.0, (float) $list->items()->firstOrFail()->price);
    }

    public function test_import_rejects_oversized_file_and_huge_sheets(): void
    {
        $admin = $this->admin();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $list = PriceList::query()->create(['name' => 'List', 'type' => PriceListType::Retail]);
        Storage::fake('ai_private');
        Storage::disk('ai_private')->put('big.csv', str_repeat('x', 11 * 1024 * 1024));
        $big = $this->attach($conv, $admin, 'big.csv', 'text/csv');

        $denied = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'import_price_list_items',
            'args' => ['price_list_id' => $list->id, 'attachment_ids' => [$big->id]],
        ]);
        $this->assertSame('denied', $denied->type);
        $this->assertStringContainsString('10MB', $denied->message);

        $lines = implode("\n", array_map(fn ($i) => "SKU-{$i},10,1", range(1, 201)));
        Storage::disk('ai_private')->put('huge.csv', $lines);
        $huge = $this->attach($conv, $admin, 'huge.csv', 'text/csv');

        $split = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'import_price_list_items',
            'args' => ['price_list_id' => $list->id, 'attachment_ids' => [$huge->id]],
        ]);
        $this->assertSame('denied', $split->type);
        $this->assertStringContainsString('قسّم الملف', $split->message);
    }

    public function test_parse_csv_attachment_without_model_call(): void
    {
        $admin = $this->admin();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $variant = $this->variant();
        $list = PriceList::query()->create(['name' => 'List', 'type' => PriceListType::Retail]);
        Storage::fake('ai_private');
        Storage::disk('ai_private')->put('sheet.csv', $variant->sku.",95,1\nUNKNOWN-SKU,50,2\n");

        $this->mock(GeminiClient::class, function ($mock): void {
            $mock->shouldReceive('generateStructured')->never();
        });
        $att = $this->attach($conv, $admin, 'sheet.csv', 'text/csv');

        $out = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'parse_price_sheet',
            'args' => ['price_list_id' => $list->id, 'attachment_ids' => [$att->id]],
        ]);
        $this->assertSame('proposal', $out->type);
        $action = AiPendingAction::findOrFail($out->data['pending_action_id']);
        $this->assertCount(1, $action->args['rows']);
        $this->assertSame(95.0, (float) $action->args['rows'][0]['price']);
        $this->assertNotEmpty($action->preview['warnings']);
    }
}
