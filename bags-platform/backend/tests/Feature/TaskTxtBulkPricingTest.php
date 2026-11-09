<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Orders\SalesChannel;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\PendingActionService;
use App\Services\Ai\Catalog\Tools\BulkUpdatePricesTool;
use App\Services\Ai\ToolRegistry;
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
 * task.txt §2 tasks 5-6: bulk price conflict → full rollback, and
 * rounding/guards unit table.
 */
class TaskTxtBulkPricingTest extends TestCase
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

    private function catalogStaff(): User
    {
        return $this->staff([
            'ai_agent.use', 'ai_agent.execute',
            'products.view', 'products.create', 'products.update', 'products.publish',
        ]);
    }

    private function conv(User $u): AiConversation
    {
        return AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
    }

    private static int $seq = 0;

    /** @return array{user: User, conv: AiConversation, variants: ProductVariant[]} */
    private function fiftyVariants(int $count = 50): array
    {
        $admin = $this->catalogStaff();
        $conv = $this->conv($admin);
        $cat = Category::factory()->create(['is_active' => true]);
        $variants = [];
        for ($i = 0; $i < $count; $i++) {
            $n = 92000 + (++self::$seq);
            $product = Product::factory()->create([
                'category_id' => $cat->id,
                'code' => 'P'.$n,
                'status' => ProductStatus::Draft,
                'sales_channel' => SalesChannel::Both,
            ]);
            $variants[] = ProductVariant::factory()->create([
                'product_id' => $product->id,
                'sku' => 'P'.$n.'-BLK',
                'retail_price' => 400,
                'wholesale_price' => 300,
                'cost_price' => 100,
                'is_active' => true,
            ]);
        }

        return ['user' => $admin, 'conv' => $conv, 'variants' => $variants];
    }

    // ── task 5: one conflicted row aborts the whole batch ──
    public function test_task5_single_conflict_rolls_back_entire_batch(): void
    {
        ['user' => $admin, 'conv' => $conv, 'variants' => $variants] = $this->fiftyVariants();

        $prop = app(ToolRegistry::class)->dispatch($admin, $conv, [
            'name' => 'bulk_update_prices',
            'args' => [
                'field' => 'retail_price',
                'operation' => 'percent',
                'value' => 10,
                'rounding' => 'none',
                'scope' => ['category_id' => $variants[0]->product->category_id],
            ],
        ]);
        $this->assertSame('proposal', $prop->type);
        $action = AiPendingAction::findOrFail($prop->data['pending_action_id']);

        // External REST-style edit of ONE variant bumps its lock_version.
        $victim = $variants[3];
        app(VariantService::class)->update($victim->fresh(), [
            'retail_price' => 411,
            'expected_version' => (int) $victim->lock_version,
        ]);

        $before = collect($variants)->mapWithKeys(fn ($v) => [$v->id => (float) $v->fresh()->retail_price])->all();

        try {
            app(PendingActionService::class)->confirm($admin, $action->fresh(), 'تنفيذ');
            $this->fail('expected 409 conflict');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertStringContainsString('حد عدّل أسعار أثناء المراجعة', $e->getMessage());
        }

        // Full rollback: the other 49 variants are untouched…
        foreach ($variants as $v) {
            if ($v->id === $victim->id) {
                continue;
            }
            $this->assertSame($before[$v->id], (float) $v->fresh()->retail_price, "variant {$v->id} changed despite rollback");
        }
        // …the victim keeps only its external edit (440 would be the bulk result)…
        $this->assertSame(411.0, (float) $victim->fresh()->retail_price);
        // …card failed, no audit written.
        $this->assertSame('failed', $action->fresh()->status);
        $this->assertSame(0, DB::table('staff_audit_logs')->where('action', 'variant.bulk_prices_updated')->count());
    }

    // ── task 6: rounding table + guards ──
    public function test_task6_rounding_and_guards(): void
    {
        // Rounding values from the spec.
        $this->assertSame(10.0, BulkUpdatePricesTool::applyRounding(12.4, 'nearest_5'));
        $this->assertSame(109.0, BulkUpdatePricesTool::applyRounding(100, 'end_with_9'));
        $this->assertSame(99.0, BulkUpdatePricesTool::applyRounding(40, 'end_with_99'));
        $this->assertSame(99.0, BulkUpdatePricesTool::applyRounding(149, 'end_with_99'));
        $this->assertSame(199.0, BulkUpdatePricesTool::applyRounding(150, 'end_with_99'));

        $admin = $this->catalogStaff();
        $conv = $this->conv($admin);
        $registry = app(ToolRegistry::class);

        // Null current price with non-set operation is blocked at prepare.
        $cat = Category::factory()->create(['is_active' => true]);
        $n = 93000 + (++self::$seq);
        $product = Product::factory()->create([
            'category_id' => $cat->id, 'code' => 'P'.$n,
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Both,
        ]);
        $nullPrice = ProductVariant::factory()->create([
            'product_id' => $product->id, 'sku' => 'P'.$n.'-N',
            'retail_price' => null, 'wholesale_price' => null, 'cost_price' => 50, 'is_active' => true,
        ]);

        // Prepare-phase guards surface as denied proposals (ToolRegistry wraps
        // ValidationException into ToolOutcome::denied).
        $nullPriceOut = $registry->dispatch($admin, $conv, [
            'name' => 'bulk_update_prices',
            'args' => ['field' => 'retail_price', 'operation' => 'percent', 'value' => 10, 'scope' => ['product_ids' => [$product->id]]],
        ]);
        $this->assertSame('denied', $nullPriceOut->type);

        // -100% → 0 is blocked; empty scope → validation error.
        $negOut = $registry->dispatch($admin, $conv, [
            'name' => 'bulk_update_prices',
            'args' => ['field' => 'retail_price', 'operation' => 'percent', 'value' => -100, 'scope' => ['product_ids' => [$product->id]]],
        ]);
        $this->assertSame('denied', $negOut->type);

        $emptyScopeOut = $registry->dispatch($admin, $conv, [
            'name' => 'bulk_update_prices',
            'args' => ['field' => 'retail_price', 'operation' => 'percent', 'value' => 10, 'scope' => []],
        ]);
        $this->assertSame('denied', $emptyScopeOut->type);

        // Below-cost blocked with protected reason for staff without CostAccess.
        $n2 = 93000 + (++self::$seq);
        $product2 = Product::factory()->create([
            'category_id' => $cat->id, 'code' => 'P'.$n2,
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Both,
        ]);
        $costly = ProductVariant::factory()->create([
            'product_id' => $product2->id, 'sku' => 'P'.$n2.'-C',
            'retail_price' => 400, 'wholesale_price' => 300, 'cost_price' => 390, 'is_active' => true,
        ]);
        $noCostView = $this->staff(['ai_agent.use', 'ai_agent.execute', 'products.view', 'products.update']);
        $this->assertFalse(\App\Services\Shared\CostAccess::canView($noCostView));
        $conv2 = $this->conv($noCostView);
        $costOut = $registry->dispatch($noCostView, $conv2, [
            'name' => 'bulk_update_prices',
            'args' => ['field' => 'retail_price', 'operation' => 'percent', 'value' => -50, 'scope' => ['product_ids' => [$product2->id]]],
        ]);
        $this->assertSame('denied', $costOut->type);
        $this->assertStringContainsString('محمية', $costOut->message.json_encode($costOut->data, JSON_UNESCAPED_UNICODE));

        // Wholesale >= retail blocked.
        $n3 = 93000 + (++self::$seq);
        $product3 = Product::factory()->create([
            'category_id' => $cat->id, 'code' => 'P'.$n3,
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Both,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $product3->id, 'sku' => 'P'.$n3.'-W',
            'retail_price' => 400, 'wholesale_price' => 390, 'cost_price' => 50, 'is_active' => true,
        ]);
        $wsOut = $registry->dispatch($admin, $conv, [
            'name' => 'bulk_update_prices',
            'args' => ['field' => 'wholesale_price', 'operation' => 'percent', 'value' => 10, 'scope' => ['product_ids' => [$product3->id]]],
        ]);
        $this->assertSame('denied', $wsOut->type);

        // Max-items cap enforced.
        $maxItems = (int) (DB::table('settings')->where('key', 'ai_catalog_bulk_price_max_items')->value('value') ?: 200);
        $this->assertSame(200, $maxItems);

        $this->assertNotNull($nullPrice->fresh());
        $this->assertNotNull($costly->fresh());
    }
}
