<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Enums\SalesChannel;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * task.txt §2 task 21: product media via REST (ownership, ordering,
 * validation, video rule, idempotent retry, media lock, no orphans).
 */
class TaskTxtProductMediaTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        Storage::fake('r2_public');
        Storage::fake('r2');
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

    private function admin(): array
    {
        $u = $this->staff(['products.view', 'products.create', 'products.update', 'products.delete']);

        return [$u, $this->token($u)];
    }

    private static int $seq = 0;

    private function product(string $code): Product
    {
        $cat = Category::factory()->create(['is_active' => true]);

        return Product::factory()->create([
            'category_id' => $cat->id, 'code' => $code,
            'status' => ProductStatus::Draft, 'sales_channel' => SalesChannel::Retail,
        ]);
    }

    private function img(): UploadedFile
    {
        return UploadedFile::fake()->image('p-'.(++self::$seq).'.jpg');
    }

    private function uploadImages(string $token, Product $product, int $count, array $extra = []): array
    {
        $files = [];
        for ($i = 0; $i < $count; $i++) {
            $files[] = $this->img();
        }
        $res = $this->withToken($token)->post(
            "/api/v1/admin/products/{$product->id}/media",
            array_merge(['collection' => 'images', 'files' => $files], $extra)
        );

        return [$res, $files];
    }

    // ── deleting another product's media → 404 ──
    public function test_task21a_foreign_media_delete_returns_404(): void
    {
        [$u, $token] = $this->admin();
        $a = $this->product('P71111');
        $b = $this->product('P71222');

        [$res] = $this->uploadImages($token, $b, 1);
        $res->assertCreated();
        $foreignId = $res->json('data.0.id');

        $this->withToken($token)->deleteJson("/api/v1/admin/products/{$a->id}/media/{$foreignId}")
            ->assertStatus(404);
        $this->assertDatabaseHas('media', ['id' => $foreignId]);
    }

    // ── incomplete / mixed-owner reorder list → business error, order kept ──
    public function test_task21b_partial_or_mixed_reorder_rejected(): void
    {
        [$u, $token] = $this->admin();
        $product = $this->product('P71333');
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id, 'sku' => 'P71333-BLK',
            'retail_price' => 100, 'wholesale_price' => null, 'cost_price' => 10, 'is_active' => true,
        ]);

        [$res] = $this->uploadImages($token, $product, 2);
        $res->assertCreated();
        $ids = [$res->json('data.0.id'), $res->json('data.1.id')];
        [$vres] = $this->uploadImages($token, $product, 1, ['variant_id' => $variant->id]);
        $vres->assertCreated();
        $variantMediaId = $vres->json('data.0.id');

        // Incomplete list.
        $this->withToken($token)->putJson("/api/v1/admin/products/{$product->id}/media/reorder", ['ids' => [$ids[0]]])
            ->assertStatus(422);

        // Mixed product + variant images.
        $this->withToken($token)->putJson("/api/v1/admin/products/{$product->id}/media/reorder", ['ids' => [$ids[0], $variantMediaId]])
            ->assertStatus(422);

        // Complete list still works afterwards.
        $this->withToken($token)->putJson("/api/v1/admin/products/{$product->id}/media/reorder", ['ids' => [$ids[1], $ids[0]]])
            ->assertOk();
    }

    // ── 5 files / mismatched client_upload_ids → 422 ──
    public function test_task21c_upload_count_and_id_list_validation(): void
    {
        [$u, $token] = $this->admin();
        $product = $this->product('P71444');

        $files = [$this->img(), $this->img(), $this->img(), $this->img(), $this->img()];
        $this->withToken($token)->post("/api/v1/admin/products/{$product->id}/media", [
            'collection' => 'images', 'files' => $files,
        ])->assertStatus(422);

        $this->withToken($token)->post("/api/v1/admin/products/{$product->id}/media", [
            'collection' => 'images',
            'files' => [$this->img(), $this->img()],
            'client_upload_ids' => ['11111111-1111-4111-8111-111111111111'],
        ])->assertStatus(422);

        $this->assertSame(0, Media::where('model_type', Product::class)->where('model_id', $product->id)->count());
    }

    // ── video on a variant → "الفيديو للمنتج فقط" ──
    public function test_task21d_variant_video_rejected(): void
    {
        [$u, $token] = $this->admin();
        $product = $this->product('P71555');
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id, 'sku' => 'P71555-BLK',
            'retail_price' => 100, 'wholesale_price' => null, 'cost_price' => 10, 'is_active' => true,
        ]);

        $video = UploadedFile::fake()->create('v.mp4', 100, 'video/mp4');
        $res = $this->withToken($token)->post("/api/v1/admin/products/{$product->id}/media", [
            'collection' => 'video', 'variant_id' => $variant->id, 'file' => $video,
        ]);
        $this->assertContains($res->status(), [409, 422]);
        $this->assertStringContainsString('الفيديو للمنتج فقط', (string) $res->json('message'));
    }

    // ── same client_upload_id twice → one image (idempotent retry) ──
    public function test_task21e_duplicate_client_upload_id_replays_winner(): void
    {
        [$u, $token] = $this->admin();
        $product = $this->product('P71666');
        $uuid = '22222222-2222-4222-8222-222222222222';

        [$first] = $this->uploadImages($token, $product, 1, ['client_upload_ids' => [$uuid]]);
        $first->assertCreated();
        [$second] = $this->uploadImages($token, $product, 1, ['client_upload_ids' => [$uuid]]);
        $second->assertCreated();

        $this->assertSame($first->json('data.0.id'), $second->json('data.0.id'));
        $this->assertSame(1, Media::where('model_type', Product::class)->where('model_id', $product->id)
            ->where('collection_name', 'images')->count());
    }

    // ── upload while reorder lock held → 409 "جاري رفع أو ترتيب" ──
    public function test_task21f_concurrent_upload_and_reorder_conflicts_409(): void
    {
        [$u, $token] = $this->admin();
        $product = $this->product('P71777');
        [$res] = $this->uploadImages($token, $product, 1);
        $res->assertCreated();

        $lock = Cache::lock('catalog-media:'.$product->id, 30);
        $this->assertTrue($lock->acquire());
        try {
            $conflict = $this->withToken($token)->putJson(
                "/api/v1/admin/products/{$product->id}/media/reorder",
                ['ids' => [$res->json('data.0.id')]]
            );
            $this->assertSame(409, $conflict->status());
            $this->assertStringContainsString('جاري رفع أو ترتيب', (string) $conflict->json('message'));
        } finally {
            $lock->release();
        }

        // After release the same reorder passes.
        $this->withToken($token)->putJson("/api/v1/admin/products/{$product->id}/media/reorder", ['ids' => [$res->json('data.0.id')]])
            ->assertOk();
    }

    // ── no orphaned images_staging rows after failures ──
    public function test_task21g_no_orphan_staging_rows(): void
    {
        [$u, $token] = $this->admin();
        $product = $this->product('P71888');
        [$res] = $this->uploadImages($token, $product, 1);
        $res->assertCreated();

        // Failed reorder + failed delete leave no staging rows behind.
        $this->withToken($token)->putJson("/api/v1/admin/products/{$product->id}/media/reorder", ['ids' => [999999999]]);
        $this->withToken($token)->deleteJson("/api/v1/admin/products/{$product->id}/media/999999999");

        $this->assertSame(0, Media::where('collection_name', 'images_staging')->count());
        $this->assertSame(0, Media::where('collection_name', 'video_staging')->count());
    }
}
