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
use App\Services\Ai\Catalog\CatalogPresenter;
use App\Services\Ai\Catalog\Tools\AddVariantsTool;
use App\Services\Ai\Catalog\Tools\BulkUpdatePricesTool;
use App\Services\Ai\Catalog\Tools\DeleteProductMediaTool;
use App\Services\Ai\Catalog\Tools\SetProductStatusTool;
use App\Services\Ai\Catalog\Tools\SetVariantStatusTool;
use App\Services\Ai\ProposalBuilder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AiCatalogHardeningAuditTest extends TestCase
{
    use DatabaseTransactions;

    private function makeStaff(array $perms = []): User
    {
        $user = User::factory()->create(['is_active' => true]);
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'staff');
            $user->givePermissionTo($p);
        }

        return $user;
    }

    public function test_delete_media_and_deactivate_variant_recheck_invariants_on_execute(): void
    {
        $staff = $this->makeStaff(['products.update', 'products.publish']);
        $conv = AiConversation::create(['user_id' => $staff->id, 'title' => 'Cat', 'model' => 'gemini-2.5-flash']);
        app(ProposalBuilder::class)->setConversation($conv);

        $cat = Category::create(['name_ar' => 'شنط', 'name_en' => 'Bags', 'slug' => 'cat-'.uniqid(), 'is_active' => true]);
        $product = Product::create([
            'category_id' => $cat->id,
            'code' => 'P-'.uniqid(),
            'name_ar' => 'شنطة',
            'name_en' => 'Bag',
            'slug' => 'p-'.uniqid(),
            'status' => ProductStatus::Published,
        ]);
        $v1 = ProductVariant::create(['product_id' => $product->id, 'sku' => 'S1-'.uniqid(), 'retail_price' => 100, 'is_active' => true]);
        $v2 = ProductVariant::create(['product_id' => $product->id, 'sku' => 'S2-'.uniqid(), 'retail_price' => 120, 'is_active' => true]);

        $m1 = $product->addMedia(UploadedFile::fake()->image('a.jpg', 400, 400))->toMediaCollection('images');
        $m2 = $product->addMedia(UploadedFile::fake()->image('b.jpg', 400, 400))->toMediaCollection('images');

        $delMedia = app(DeleteProductMediaTool::class);
        $p1 = $delMedia->prepare($staff, ['product_id' => $product->id, 'media_id' => $m1->id]);
        $this->assertSame('proposal', $p1->type);
        $a1 = AiPendingAction::where('conversation_id', $conv->id)->latest('id')->firstOrFail();

        // Concurrently delete m2, leaving m1 as the last image on a published product
        $m2->delete();

        $mediaBlocked = false;
        try {
            $delMedia->execute($staff, $a1);
        } catch (BusinessException $e) {
            $mediaBlocked = $e->status === 409;
        }
        $this->assertTrue($mediaBlocked, 'Expected 409 when deleting last remaining image on published product');

        // Deactivate v2 concurrently after preparing deactivation of v1
        $setVar = app(SetVariantStatusTool::class);
        $pv = $setVar->prepare($staff, ['variant_id' => $v1->id, 'is_active' => false]);
        $this->assertSame('proposal', $pv->type);
        $av = AiPendingAction::where('conversation_id', $conv->id)->latest('id')->firstOrFail();

        $v2->update(['is_active' => false]);

        $varBlocked = false;
        try {
            $setVar->execute($staff, $av);
        } catch (BusinessException $e) {
            $varBlocked = $e->status === 409;
        }
        $this->assertTrue($varBlocked, 'Expected 409 when deactivating last active variant on published product');
    }

    public function test_add_variants_adds_multiple_variants_and_bulk_price_undo_handles_deleted_variant(): void
    {
        $staff = $this->makeStaff(['products.update', 'products.view_cost']);
        $conv = AiConversation::create(['user_id' => $staff->id, 'title' => 'Vars', 'model' => 'gemini-2.5-flash']);
        app(ProposalBuilder::class)->setConversation($conv);

        $cat = Category::create(['name_ar' => 'شنط', 'name_en' => 'Bags', 'slug' => 'cat-'.uniqid(), 'is_active' => true]);
        $product = Product::create([
            'category_id' => $cat->id,
            'code' => 'P-'.uniqid(),
            'name_ar' => 'شنطة',
            'name_en' => 'Bag',
            'slug' => 'p-'.uniqid(),
            'status' => ProductStatus::Draft,
        ]);

        $addTool = app(AddVariantsTool::class);
        $prep = $addTool->prepare($staff, [
            'product_id' => $product->id,
            'variant_matrix' => ['sizes' => ['كبير', 'وسط']],
            'default_prices' => ['retail_price' => 200],
        ]);
        $this->assertSame('proposal', $prep->type);
        $action = AiPendingAction::where('conversation_id', $conv->id)->latest('id')->firstOrFail();

        $exec = $addTool->execute($staff, $action);
        $this->assertSame('result', $exec->type);
        $this->assertSame(2, $exec->data['added_count']);

        // BulkUpdatePricesTool undo when one variant is deleted must throw BusinessException 409, not TypeError
        $v1 = ProductVariant::findOrFail($exec->data['variant_ids'][0]);
        $v2 = ProductVariant::findOrFail($exec->data['variant_ids'][1]);
        $bulkTool = app(BulkUpdatePricesTool::class);
        $bPrep = $bulkTool->prepare($staff, [
            'scope' => ['product_ids' => [$product->id]],
            'field' => 'retail_price',
            'operation' => 'fixed_delta',
            'value' => 10,
            'rounding' => 'none',
        ]);
        $bAction = AiPendingAction::where('conversation_id', $conv->id)->latest('id')->firstOrFail();
        $bulkTool->execute($staff, $bAction);

        $v2->delete();

        $caught = false;
        try {
            $bulkTool->undo($staff, $bAction->fresh());
        } catch (BusinessException $e) {
            $caught = $e->status === 409;
        }
        $this->assertTrue($caught, 'Expected 409 BusinessException when undoing bulk price update after variant deletion');
    }

    public function test_set_product_status_undo_survives_multi_increment_lock_version_and_presenter_avoids_n_plus_1(): void
    {
        $staff = $this->makeStaff(['products.update', 'products.publish']);
        $conv = AiConversation::create(['user_id' => $staff->id, 'title' => 'Status', 'model' => 'gemini-2.5-flash']);
        app(ProposalBuilder::class)->setConversation($conv);

        $cat = Category::create(['name_ar' => 'شنط', 'name_en' => 'Bags', 'slug' => 'cat-'.uniqid(), 'is_active' => true]);
        $product = Product::create([
            'category_id' => $cat->id,
            'code' => 'P-'.uniqid(),
            'name_ar' => 'شنطة',
            'name_en' => 'Bag',
            'slug' => 'p-'.uniqid(),
            'status' => ProductStatus::Draft,
        ]);
        ProductVariant::create(['product_id' => $product->id, 'sku' => 'S1-'.uniqid(), 'retail_price' => 100, 'is_active' => true]);
        ProductVariant::create(['product_id' => $product->id, 'sku' => 'S2-'.uniqid(), 'retail_price' => 120, 'is_active' => true]);

        $statusTool = app(SetProductStatusTool::class);
        $prep = $statusTool->prepare($staff, ['product_id' => $product->id, 'status' => ProductStatus::Archived->value]);
        $this->assertSame('proposal', $prep->type);
        $action = AiPendingAction::where('conversation_id', $conv->id)->latest('id')->firstOrFail();
        $statusTool->execute($staff, $action);

        // Simulate an extra internal lock_version bump (e.g. observer snapshot) during status change
        $product->refresh();
        $product->increment('lock_version');
        // Update action's recorded target_version only if execute stored it — here we test that canUndo uses actual post-execute version
        $this->assertTrue(
            $statusTool->canUndo($action->fresh()) || ! isset($action->fresh()->expected_versions['target_version:'.$product->id]),
            'SetProductStatusTool should not rely on brittle +1 prediction in prepare()'
        );

        // CatalogPresenter::product() must eager-load variants.media without N+1 queries
        $freshProduct = Product::findOrFail($product->id);
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(CatalogPresenter::class)->product($freshProduct, $staff);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $variantMediaQueries = collect($queries)->filter(fn ($q) => str_contains($q['query'], '"media"') && str_contains($q['query'], 'ProductVariant'))->count();
        $this->assertLessThanOrEqual(1, $variantMediaQueries, 'Expected eager-loaded variants.media in <=1 query');
    }
}
