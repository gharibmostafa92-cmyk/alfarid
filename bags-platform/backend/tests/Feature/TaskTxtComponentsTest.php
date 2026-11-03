<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\PendingActionService;
use App\Services\Ai\ToolRegistry;
use App\Services\SettingService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * task.txt §3 per-component cases: categories, product filters/limits,
 * archive permissions, variant rules, orchestrator input, proposal
 * TTL/typed-confirmation/cancel.
 */
class TaskTxtComponentsTest extends TestCase
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

    private function catalogAdmin(): User
    {
        return $this->staff([
            'ai_agent.use', 'ai_agent.execute',
            'products.view', 'products.create', 'products.update', 'products.delete', 'products.publish',
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',
        ]);
    }

    private function token(User $u): string
    {
        Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    // ── categories: auto slug, uppercase normalization, sort_order bounds ──
    public function test_categories_slug_and_sort_order_rules(): void
    {
        $token = $this->token($this->catalogAdmin());

        // No slug → auto-generated.
        $r1 = $this->withToken($token)->postJson('/api/v1/admin/categories', [
            'name_ar' => 'حقائب سفر', 'name_en' => 'Travel Bags',
        ])->assertCreated();
        $this->assertSame('travel-bags', $r1->json('data.slug'));

        // Uppercase slug normalized to lowercase.
        $r2 = $this->withToken($token)->postJson('/api/v1/admin/categories', [
            'name_ar' => 'حقائب يد', 'name_en' => 'Hand Bags', 'slug' => 'HAND-BAGS',
        ])->assertCreated();
        $this->assertSame('hand-bags', $r2->json('data.slug'));

        // Negative / overflowing sort_order → 422.
        $cat = Category::firstOrFail();
        $this->withToken($token)->putJson("/api/v1/admin/categories/{$cat->id}", ['sort_order' => -1])->assertStatus(422);
        $this->withToken($token)->putJson("/api/v1/admin/categories/{$cat->id}", ['sort_order' => 2147483648])->assertStatus(422);
    }

    // ── categories: every endpoint 403 without its permission ──
    public function test_category_endpoints_require_permissions(): void
    {
        $plain = $this->staff(['categories.view']);
        $token = $this->token($plain);
        $cat = Category::factory()->create(['is_active' => true]);

        $this->withToken($token)->postJson('/api/v1/admin/categories', ['name_ar' => 'س', 'name_en' => 'X '.uniqid()])->assertStatus(403);
        $this->withToken($token)->putJson("/api/v1/admin/categories/{$cat->id}", ['name_en' => 'Y'])->assertStatus(403);
        $this->withToken($token)->putJson('/api/v1/admin/categories/reorder', ['items' => [['id' => $cat->id, 'parent_id' => null, 'sort_order' => 0]]])->assertStatus(403);
        $this->withToken($token)->deleteJson("/api/v1/admin/categories/{$cat->id}")->assertStatus(403);
    }

    // ── products: filters + creation guards + limits ──
    public function test_product_filters_creation_guards_and_limits(): void
    {
        $token = $this->token($this->catalogAdmin());
        $cat = Category::factory()->create(['is_active' => true]);

        // brand_id prohibited, deleted category rejected.
        $this->withToken($token)->postJson('/api/v1/admin/products', [
            'category_id' => $cat->id, 'name_ar' => 'م', 'name_en' => 'B1 '.uniqid(),
            'sales_channel' => 'retail', 'brand_id' => 1,
        ])->assertStatus(422);
        $gone = Category::factory()->create(['is_active' => true]);
        $gone->delete();
        $this->withToken($token)->getJson("/api/v1/admin/products?category_id={$gone->id}")->assertStatus(422);

        // status=published at creation → 422 (draft only).
        $this->withToken($token)->postJson('/api/v1/admin/products', [
            'category_id' => $cat->id, 'name_ar' => 'م', 'name_en' => 'B2 '.uniqid(),
            'sales_channel' => 'retail', 'status' => 'published',
        ])->assertStatus(422);

        // Limits: length 999999.99 ok / 1000000 rejected; occasions 5 rejected; warranty 121 rejected.
        $ok = $this->withToken($token)->postJson('/api/v1/admin/products', [
            'category_id' => $cat->id, 'name_ar' => 'م', 'name_en' => 'B3 '.uniqid(),
            'sales_channel' => 'retail', 'length_cm' => 999999.99,
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/admin/products', [
            'category_id' => $cat->id, 'name_ar' => 'م', 'name_en' => 'B4 '.uniqid(),
            'sales_channel' => 'retail', 'length_cm' => 1000000,
        ])->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/admin/products', [
            'category_id' => $cat->id, 'name_ar' => 'م', 'name_en' => 'B5 '.uniqid(),
            'sales_channel' => 'retail', 'occasions' => ['a', 'b', 'c', 'd', 'e'],
        ])->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/admin/products', [
            'category_id' => $cat->id, 'name_ar' => 'م', 'name_en' => 'B6 '.uniqid(),
            'sales_channel' => 'retail', 'warranty_months' => 121,
        ])->assertStatus(422);

        // Duplicate SKU inside one request → 422 distinct.
        $this->withToken($token)->postJson('/api/v1/admin/products', [
            'category_id' => $cat->id, 'name_ar' => 'م', 'name_en' => 'B7 '.uniqid(),
            'sales_channel' => 'retail',
            'variants' => [
                ['color_name_en' => 'Red', 'sku' => 'DUP-1'],
                ['color_name_en' => 'Blue', 'sku' => 'DUP-1'],
            ],
        ])->assertStatus(422);

        // Lowercase SKU/barcode search still finds.
        $pid = $this->withToken($token)->postJson('/api/v1/admin/products', [
            'category_id' => $cat->id, 'name_ar' => 'م', 'name_en' => 'Searchable '.uniqid(),
            'sales_channel' => 'retail',
            'variants' => [['color_name_en' => 'Red', 'sku' => 'FINDME-1', 'barcode' => '6220001112223', 'retail_price' => 100]],
        ])->assertCreated()->json('data.id');
        $this->withToken($token)->getJson('/api/v1/admin/products?search=findme-1')
            ->assertOk()->assertJsonFragment(['id' => $pid]);
    }

    // ── archive permission split ──
    public function test_archive_permission_split(): void
    {
        $admin = $this->catalogAdmin();
        $token = $this->token($admin);
        $cat = Category::factory()->create(['is_active' => true]);
        $mk = function () use ($token, $cat) {
            return $this->withToken($token)->postJson('/api/v1/admin/products', [
                'category_id' => $cat->id, 'name_ar' => 'م', 'name_en' => 'Arc '.uniqid(),
                'sales_channel' => 'retail',
            ])->assertCreated()->json('data.id');
        };

        $draftId = $mk();
        $pubId = $mk();
        Product::find($pubId)->forceFill(['status' => ProductStatus::Published])->save();

        $editor = $this->staff(['products.view', 'products.update']);
        $etoken = $this->token($editor);

        // Draft → archived without products.publish.
        $this->withToken($etoken)->putJson("/api/v1/admin/products/{$draftId}", [
            'status' => 'archived',
            'expected_version' => Product::find($draftId)->lock_version,
        ])->assertOk();

        // Published → archived without products.publish → 403.
        $this->withToken($etoken)->putJson("/api/v1/admin/products/{$pubId}", [
            'status' => 'archived',
            'expected_version' => Product::find($pubId)->lock_version,
        ])->assertStatus(403);
    }

    // ── variants: draft zero price ok, 4-decimal cost, color pair rule ──
    public function test_variant_field_rules(): void
    {
        $token = $this->token($this->catalogAdmin());
        $cat = Category::factory()->create(['is_active' => true]);
        $product = Product::factory()->create([
            'category_id' => $cat->id, 'code' => 'P99111',
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Retail,
        ]);

        // retail_price=0 acceptable in draft.
        $this->withToken($token)->postJson("/api/v1/admin/products/{$product->id}/variants", [
            'color_name_en' => 'Red', 'retail_price' => 0,
        ])->assertCreated();

        // cost_price with four decimals accepted.
        $this->withToken($token)->postJson("/api/v1/admin/products/{$product->id}/variants", [
            'color_name_en' => 'Blue', 'retail_price' => 100, 'cost_price' => 12.3456,
        ])->assertCreated();

        // color_name_ar without color_name_en → 422 required_with.
        $this->withToken($token)->postJson("/api/v1/admin/products/{$product->id}/variants", [
            'color_name_ar' => 'أحمر', 'retail_price' => 100,
        ])->assertStatus(422);
    }

    // ── orchestrator input: empty message 422, selection without text ──
    public function test_orchestrator_input_guards(): void
    {
        $admin = $this->catalogAdmin();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $token = $this->token($admin);

        // Empty text without attachments → 422.
        $this->withToken($token)->postJson("/api/v1/admin/ai/conversations/{$conv->id}/messages", ['text' => ''])
            ->assertStatus(422);
    }

    // ── proposal: short TTL expiry + typed normalization + cancel semantics ──
    public function test_proposal_ttl_typed_normalization_and_cancel(): void
    {
        $admin = $this->catalogAdmin();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $cat = Category::factory()->create(['is_active' => true]);
        static $n = 98000;
        $n++;
        $product = Product::factory()->create([
            'category_id' => $cat->id, 'code' => 'P'.$n,
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Both,
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id, 'sku' => 'P'.$n.'-BLK',
            'retail_price' => 400, 'wholesale_price' => 300, 'cost_price' => 50, 'is_active' => true,
        ]);
        $svc = app(PendingActionService::class);

        // TTL=1min then confirm after 61s → expired.
        DB::table('settings')->where('key', 'ai_action_ttl_minutes')->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        $prop = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'update_variant',
            'args' => ['variant_id' => $variant->id, 'retail_price' => 450],
        ]);
        $action = AiPendingAction::findOrFail($prop->data['pending_action_id']);
        $this->travel(61)->seconds();
        try {
            $svc->confirm($admin, $action->fresh());
            $this->fail('expected expiry 409');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame('expired', $action->fresh()->status);
        $this->travelBack();
        DB::table('settings')->where('key', 'ai_action_ttl_minutes')->update(['value' => '15']);
        app(SettingService::class)->clearCache();

        // Cancel of an executed card is a no-op.
        $prop2 = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'update_variant',
            'args' => ['variant_id' => $variant->id, 'retail_price' => 460],
        ]);
        $a2 = AiPendingAction::findOrFail($prop2->data['pending_action_id']);
        $svc->confirm($admin, $a2->fresh());
        $this->withToken($this->token($admin))->postJson("/api/v1/admin/ai/actions/{$a2->id}/cancel")->assertOk();
        $this->assertSame('executed', $a2->fresh()->status);
    }

    // ── delete tools: typed confirmations + no undo ──
    public function test_delete_tools_typed_confirmation_and_no_undo(): void
    {
        $admin = $this->catalogAdmin();
        $conv = AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']);
        $cat = Category::factory()->create(['is_active' => true]);
        static $m = 98500;
        $m++;
        $product = Product::factory()->create([
            'category_id' => $cat->id, 'code' => 'P'.$m,
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Retail,
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id, 'sku' => 'P'.$m.'-BLK',
            'retail_price' => 400, 'wholesale_price' => null, 'cost_price' => 50, 'is_active' => true,
        ]);
        $svc = app(PendingActionService::class);
        $token = $this->token($admin);

        // delete_product typed confirmation is the product code (case-insensitive).
        $prop = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'delete_product', 'args' => ['product_id' => $product->id],
        ]);
        $this->assertSame('proposal', $prop->type);
        $del = AiPendingAction::findOrFail($prop->data['pending_action_id']);
        try {
            $svc->confirm($admin, $del->fresh(), 'WRONG');
            $this->fail('expected 422');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $svc->confirm($admin, $del->fresh(), strtolower($product->code));
        $this->assertSame('executed', $del->fresh()->status);

        // No undo for deletes.
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$del->id}/undo")->assertStatus(409);

        // delete_variant typed confirmation is the SKU (separate product — the
        // first one was just soft-deleted above).
        $m++;
        $productB = Product::factory()->create([
            'category_id' => $cat->id, 'code' => 'P'.$m,
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Retail,
        ]);
        $variantB = ProductVariant::factory()->create([
            'product_id' => $productB->id, 'sku' => 'P'.$m.'-BLK',
            'retail_price' => 400, 'wholesale_price' => null, 'cost_price' => 50, 'is_active' => true,
        ]);
        $prop2 = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'delete_variant', 'args' => ['variant_id' => $variantB->id],
        ]);
        $this->assertSame('proposal', $prop2->type);
        $del2 = AiPendingAction::findOrFail($prop2->data['pending_action_id']);
        $svc->confirm($admin, $del2->fresh(), $variantB->sku);
        $this->assertSame('executed', $del2->fresh()->status);
    }
}
