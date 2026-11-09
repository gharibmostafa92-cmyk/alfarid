<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\Catalog\ProductStatus;
use App\Exceptions\BusinessException;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Ai\PendingActionService;
use App\Services\Ai\ToolRegistry;
use App\Services\Catalog\CategoryService;
use App\Services\Catalog\ProductMediaService;
use App\Services\Catalog\ProductService;
use App\Services\Catalog\VariantService;
use App\Services\Shared\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class AiCatalogConcurrencyGuardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_delete_services_and_variant_creation_reject_stale_versions(): void
    {
        $actor = User::factory()->create();
        $product = Product::factory()->create(['status' => 'draft']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $category = Category::factory()->create();
        $versions = [(int) $product->lock_version, (int) $variant->lock_version, (int) $category->lock_version];
        $product->update(['name_en' => 'New product edit']);
        $variant->update(['retail_price' => 777]);
        $category->update(['name_en' => 'New category edit']);
        $operations = [
            fn () => app(ProductService::class)->delete($product, $actor, $versions[0]),
            fn () => app(VariantService::class)->delete($variant, $versions[1]),
            fn () => app(CategoryService::class)->delete($category, $versions[2]),
            fn () => app(VariantService::class)->create($product, ['size' => 'NEW'], $versions[0]),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('A stale service write must fail.');
            } catch (BusinessException $exception) {
                $this->assertSame(409, $exception->status);
            }
        }
        $this->assertNotSoftDeleted($product);
        $this->assertNotSoftDeleted($variant);
        $this->assertNotSoftDeleted($category);
        $this->assertDatabaseCount('product_variants', 1);
    }

    public function test_failed_bulk_undo_rolls_back_every_earlier_restored_price(): void
    {
        $this->seed(SettingSeeder::class);
        DB::table('settings')->whereIn('key', ['ai_enabled', 'ai_catalog_tools_enabled'])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        $actor = User::factory()->create();
        foreach (['ai_agent.use', 'ai_agent.execute', 'products.update', 'products.publish'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }
        $product = Product::factory()->create(['status' => 'draft', 'sales_channel' => 'retail']);
        $first = ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 450, 'wholesale_price' => 100, 'cost_price' => 20, 'is_active' => true]);
        $second = ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => null, 'wholesale_price' => 100, 'cost_price' => 20, 'is_active' => true]);
        $product->addMedia(UploadedFile::fake()->image('publish.jpg'))->toMediaCollection('images');
        $conversation = AiConversation::create(['user_id' => $actor->id, 'model' => 'test']);
        $proposal = app(ToolRegistry::class)->dispatch($actor, $conversation, ['name' => 'bulk_update_prices', 'args' => ['scope' => ['product_ids' => [$product->id]], 'field' => 'retail_price', 'operation' => 'set', 'value' => 500]]);
        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        app(PendingActionService::class)->confirm($actor, $action, 'تنفيذ');
        app(ProductService::class)->changeStatus($product, ProductStatus::Published, $actor);
        $outcome = app(PendingActionService::class)->undo($actor, $action->fresh());
        $this->assertSame('failed', $outcome->type);
        $this->assertSame('500.00', $first->fresh()->retail_price);
        $this->assertSame('500.00', $second->fresh()->retail_price);
        $this->assertSame('executed', $action->fresh()->status);
    }

    public function test_media_reorder_checks_the_expected_order_inside_the_service_lock(): void
    {
        $product = Product::factory()->create();
        $first = $product->addMedia(UploadedFile::fake()->image('first.jpg'))->toMediaCollection('images');
        $second = $product->addMedia(UploadedFile::fake()->image('second.jpg'))->toMediaCollection('images');
        $service = app(ProductMediaService::class);
        $service->reorder($product, [$second->id, $first->id]);
        try {
            $service->reorder($product, [$first->id, $second->id], [$first->id, $second->id]);
            $this->fail('A stale image order must not overwrite a newer cover.');
        } catch (BusinessException $exception) {
            $this->assertSame(409, $exception->status);
        }
        $this->assertSame([$second->id, $first->id], $product->fresh()->getMedia('images')->pluck('id')->all());
    }

    public function test_status_service_rejects_a_stale_version_under_its_row_lock(): void
    {
        $actor = User::factory()->create();
        $product = Product::factory()->create(['status' => 'draft']);
        $version = (int) $product->lock_version;
        $product->update(['name_en' => 'A newer edit']);
        try {
            app(ProductService::class)->changeStatus($product, ProductStatus::Archived, $actor, $version);
            $this->fail('A stale status write must fail.');
        } catch (BusinessException $exception) {
            $this->assertSame(409, $exception->status);
        }
        $this->assertSame(ProductStatus::Draft, $product->fresh()->status);
    }

    public function test_category_reorder_rejects_stale_versions_without_partial_updates(): void
    {
        $first = Category::factory()->create(['sort_order' => 10]);
        $second = Category::factory()->create(['sort_order' => 20]);
        $staleVersion = (int) $second->lock_version;
        $second->update(['name_en' => 'A newer category edit']);
        try {
            app(CategoryService::class)->reorder([
                ['id' => $first->id, 'parent_id' => null, 'sort_order' => 20, 'expected_version' => (int) $first->lock_version],
                ['id' => $second->id, 'parent_id' => null, 'sort_order' => 10, 'expected_version' => $staleVersion],
            ]);
            $this->fail('A stale reorder must fail.');
        } catch (BusinessException $exception) {
            $this->assertSame(409, $exception->status);
        }
        $this->assertSame(10, $first->fresh()->sort_order);
        $this->assertSame(20, $second->fresh()->sort_order);
    }

    public function test_undo_rechecks_a_change_between_the_state_check_and_the_service_write(): void
    {
        $this->seed(SettingSeeder::class);
        DB::table('settings')->whereIn('key', ['ai_enabled', 'ai_catalog_tools_enabled'])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
        $actor = User::factory()->create();
        foreach (['ai_agent.use', 'ai_agent.execute', 'products.update'] as $permission) {
            $actor->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }
        $product = Product::factory()->create(['status' => 'draft']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 450]);
        $conversation = AiConversation::create(['user_id' => $actor->id, 'model' => 'test']);
        $proposal = app(ToolRegistry::class)->dispatch($actor, $conversation, ['name' => 'update_variant', 'args' => ['variant_id' => $variant->id, 'retail_price' => 495]]);
        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        app(PendingActionService::class)->confirm($actor, $action);

        // Interleave a versioned write after the target-state SELECT has read
        // its rows, before the undo tool enters the business service.
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed, $variant): void {
            if ($armed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "product_variants"')) {
                $armed = false;
                DB::table('product_variants')->where('id', $variant->id)->update(['retail_price' => 777, 'lock_version' => DB::raw('lock_version + 1')]);
            }
        });
        try {
            app(PendingActionService::class)->undo($actor, $action->fresh());
            $this->fail('The interleaved edit must prevent undo.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertFalse($armed, 'The interleaving hook must actually run.');
        $this->assertSame('executed', $action->fresh()->status);
        $this->assertNotSame('450.00', $variant->fresh()->retail_price);
    }
}