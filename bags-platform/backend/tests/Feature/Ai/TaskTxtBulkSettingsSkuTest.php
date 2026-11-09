<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Orders\SalesChannel;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\PendingActionService;
use App\Services\Ai\ToolRegistry;
use App\Services\Catalog\BarcodeGenerator;
use App\Services\Catalog\VariantService;
use App\Services\Shared\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * task.txt §2 tasks 15/23/26: bulk_actions validation, SKU/barcode rules,
 * assistant settings guards.
 */
class TaskTxtBulkSettingsSkuTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        DB::table('settings')->whereIn('key', [
            'ai_enabled',
            'ai_catalog_tools_enabled',
        ])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
    }

    private function staff(array $perms): User
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function token(User $u): string
    {
        Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    private static int $seq = 0;

    private function twoVariants(): array
    {
        $admin = $this->staff(['ai_agent.use', 'ai_agent.execute', 'products.view', 'products.update']);
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $cat = Category::factory()->create(['is_active' => true]);
        $vs = [];
        for ($i = 0; $i < 2; $i++) {
            $n = 97000 + (++self::$seq);
            $p = Product::factory()->create([
                'category_id' => $cat->id, 'code' => 'P'.$n,
                'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Both,
            ]);
            $vs[] = ProductVariant::factory()->create([
                'product_id' => $p->id, 'sku' => 'P'.$n.'-BLK',
                'retail_price' => 400, 'wholesale_price' => 300, 'cost_price' => 50, 'is_active' => true,
            ]);
        }

        return [$admin, $conv, $vs];
    }

    private function bulkParent(User $admin, $conv, array $vs): AiPendingAction
    {
        $prop = app(ToolRegistry::class)->dispatch($admin, $conv, ['name' => 'bulk_actions', 'args' => ['items' => [
            ['tool' => 'update_variant', 'args' => ['variant_id' => $vs[0]->id, 'retail_price' => 410]],
            ['tool' => 'update_variant', 'args' => ['variant_id' => $vs[1]->id, 'retail_price' => 420]],
        ]]]);
        $this->assertSame('proposal', $prop->type);

        return AiPendingAction::findOrFail($prop->data['pending_action_id']);
    }

    // ── task 15: bulk confirmation text + selection validation ──
    public function test_task15_bulk_confirmation_validation(): void
    {
        [$admin, $conv, $vs] = $this->twoVariants();
        $token = $this->token($admin);
        $svc = app(PendingActionService::class);

        // Wrong confirmation text → 422.
        $p1 = $this->bulkParent($admin, $conv, $vs);
        try {
            $svc->confirm($admin, $p1->fresh(), 'تأكيد');
            $this->fail('expected 422');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // Unknown selected id → 422.
        $p2 = $this->bulkParent($admin, $conv, $vs);
        try {
            $svc->confirm($admin, $p2->fresh(), 'تنفيذ', ['01J0000000000000000000000']);
            $this->fail('expected 422');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // Empty selection → 422.
        $p3 = $this->bulkParent($admin, $conv, $vs);
        try {
            $svc->confirm($admin, $p3->fresh(), 'تنفيذ', []);
            $this->fail('expected 422');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // Correct partial selection executes chosen, cancels the rest.
        $children = AiPendingAction::where('conversation_id', $conv->id)->where('status', 'bulk_child')->orderBy('id')->pluck('id')->all();
        $this->assertCount(6, $children); // 3 parents × 2 children
        $p4 = $this->bulkParent($admin, $conv, $vs);
        $kids = AiPendingAction::where('conversation_id', $conv->id)->where('status', 'bulk_child')->orderBy('id')->get()->take(-2)->values();
        $svc->confirm($admin, $p4->fresh(), 'تنفيذ', [$kids[0]->id]);
        $this->assertSame('executed', $kids[0]->fresh()->status);
        $this->assertSame('cancelled', $kids[1]->fresh()->status);
    }

    // ── task 23: SKU/barcode rules ──
    public function test_task23_sku_and_barcode_rules(): void
    {
        $admin = $this->staff(['products.view', 'products.create', 'products.update']);
        $token = $this->token($admin);
        $cat = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->create([
            'category_id' => $cat->id, 'code' => 'P98111',
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Retail,
        ]);
        $svc = app(VariantService::class);

        // Two sequential auto-SKUs are consecutive, no 500.
        $v1 = $svc->create($product->fresh(), ['color_name_en' => 'Red', 'retail_price' => 100]);
        $v2 = $svc->create($product->fresh(), ['color_name_en' => 'Blue', 'retail_price' => 100]);
        $this->assertSame('P98111-01', $v1->sku);
        $this->assertSame('P98111-02', $v2->sku);

        // Lowercase duplicate SKU → normalized + 409/422.
        $dup = $this->withToken($token)->postJson("/api/v1/admin/products/{$product->id}/variants", [
            'color_name_en' => 'Green', 'retail_price' => 100, 'sku' => strtolower($v1->sku),
        ]);
        $this->assertContains($dup->status(), [409, 422]);

        // Reserved 2+12 barcode shape → "صيغة محجوزة".
        $reserved = $this->withToken($token)->postJson("/api/v1/admin/products/{$product->id}/variants", [
            'color_name_en' => 'Yellow', 'retail_price' => 100, 'barcode' => '2001234567890',
        ]);
        $this->assertContains($reserved->status(), [409, 422]);
        $this->assertStringContainsString('محجوز', (string) $reserved->json('message'));

        // Duplicate color/size/capacity combination → clear message.
        $same = $this->withToken($token)->postJson("/api/v1/admin/products/{$product->id}/variants", [
            'color_name_en' => 'RED', 'retail_price' => 120,
        ]);
        $this->assertContains($same->status(), [409, 422]);
        $this->assertStringContainsString('نفس اللون', json_encode($same->json(), JSON_UNESCAPED_UNICODE));

        // BarcodeGenerator: 13 digits, starts with 2, valid check digit, unique per id.
        foreach ([1, 7, 123456, 987654321] as $id) {
            $code = BarcodeGenerator::fromId($id);
            $this->assertMatchesRegularExpression('/^2\d{12}$/', $code);
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += ((int) $code[$i]) * ($i % 2 === 0 ? 1 : 3);
            }
            $this->assertSame((string) ((10 - ($sum % 10)) % 10), $code[12]);
        }
        $this->assertNotSame(BarcodeGenerator::fromId(5), BarcodeGenerator::fromId(6));
    }

    private function settingsStaff(bool $restricted = false): array
    {
        $perms = ['ai_agent.use', 'ai_agent.settings'];
        if (! $restricted) {
            $perms[] = 'inventory.all_branches';
        }
        $u = $this->staff($perms);
        if ($restricted) {
            $u->forceFill(['branch_id' => Branch::factory()->create()->id])->save();
        }

        return [$u, $this->token($u)];
    }

    // ── task 26: assistant settings guards ──
    public function test_task26_settings_guards(): void
    {
        [$staff, $token] = $this->settingsStaff();
        $current = fn (string $key) => DB::table('settings')->where('key', $key)->value('value');

        // Unknown key → 422.
        $this->withToken($token)->putJson('/api/v1/admin/ai/settings', [
            'ai_made_up_key' => true, 'expected' => ['ai_made_up_key' => $current('ai_made_up_key')],
        ])->assertStatus(422);

        // Missing expected entirely → 422, never 500.
        $this->withToken($token)->putJson('/api/v1/admin/ai/settings', [
            'ai_enabled' => true,
        ])->assertStatus(422);

        // Missing expected for one key → 422.
        $this->withToken($token)->putJson('/api/v1/admin/ai/settings', [
            'ai_enabled' => true, 'expected' => [],
        ])->assertStatus(422);

        // Unknown tool inside ai_disabled_tools → 422.
        $this->withToken($token)->putJson('/api/v1/admin/ai/settings', [
            'ai_disabled_tools' => ['tool_that_does_not_exist'],
            'expected' => ['ai_disabled_tools' => json_decode((string) $current('ai_disabled_tools'), true)],
        ])->assertStatus(422);

        // Stale expected value after another staffer saved → 409.
        $fresh = $current('ai_action_ttl_minutes');
        $this->withToken($token)->putJson('/api/v1/admin/ai/settings', [
            'ai_action_ttl_minutes' => 20, 'expected' => ['ai_action_ttl_minutes' => (int) $fresh],
        ])->assertOk();
        $this->withToken($token)->putJson('/api/v1/admin/ai/settings', [
            'ai_action_ttl_minutes' => 25, 'expected' => ['ai_action_ttl_minutes' => (int) $fresh],
        ])->assertStatus(409);

        // Branch-restricted staffer → 403.
        [, $restToken] = $this->settingsStaff(true);
        $this->withToken($restToken)->putJson('/api/v1/admin/ai/settings', [
            'ai_enabled' => true, 'expected' => ['ai_enabled' => true],
        ])->assertStatus(403);
    }
}
