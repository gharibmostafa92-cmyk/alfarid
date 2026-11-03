<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * task.txt §2 tasks 9-12: create_key idempotency, publish conditions,
 * published-product guards, optimistic locking.
 */
class TaskTxtIdempotencyPublishLockTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
    }

    private function staff(array $perms): User
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function fullAdmin(): User
    {
        return $this->staff([
            'products.view', 'products.create', 'products.update', 'products.delete', 'products.publish',
            'categories.view', 'categories.create', 'categories.update', 'categories.delete',
        ]);
    }

    private function token(User $u): string
    {
        Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    private function createProduct(string $token, array $overrides = []): array
    {
        $category = Category::factory()->create(['is_active' => true]);
        $res = $this->withToken($token)->postJson('/api/v1/admin/products', array_merge([
            'category_id' => $category->id,
            'name_ar' => 'منتج',
            'name_en' => 'Some Product '.uniqid(),
            'sales_channel' => 'retail',
        ], $overrides));

        return [$res, $category];
    }

    // ── task 9: create_key idempotency ──
    public function test_task9_create_key_replay_and_conflicts(): void
    {
        $admin = $this->fullAdmin();
        $token = $this->token($admin);
        $category = Category::factory()->create(['is_active' => true]);
        $base = [
            'category_id' => $category->id,
            'name_ar' => 'منتج مكرر',
            'name_en' => 'Dup Product',
            'sales_channel' => 'retail',
            'create_key' => '11111111-1111-4111-8111-111111111111',
        ];

        $first = $this->withToken($token)->postJson('/api/v1/admin/products', $base)->assertCreated();
        $second = $this->withToken($token)->postJson('/api/v1/admin/products', $base)->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Product::where('create_key', '11111111-1111-4111-8111-111111111111')->count());

        // Same key, different data → 409 with product_id.
        $conflict = $this->withToken($token)->postJson('/api/v1/admin/products', array_merge($base, ['name_en' => 'Different Name']));
        $conflict->assertStatus(409);

        // Delete then reuse → 409 (trashed replay refuses).
        $this->withToken($token)->deleteJson('/api/v1/admin/products/'.$first->json('data.id'))->assertOk();
        $this->withToken($token)->postJson('/api/v1/admin/products', $base)->assertStatus(409);
    }

    private function publishableProduct(string $token, Category $category, string $channel = 'retail'): int
    {
        $this->fakeUploads();
        [$res] = [$this->withToken($token)->postJson('/api/v1/admin/products', [
            'category_id' => $category->id,
            'name_ar' => 'منتج للنشر',
            'name_en' => 'Publishable '.uniqid(),
            'sales_channel' => $channel,
            'variants' => [[
                'color_name_en' => 'Red',
                'retail_price' => 500,
                'wholesale_price' => $channel === 'retail' ? null : 400,
            ]],
        ])->assertCreated()];
        $id = $res->json('data.id');
        $this->withToken($token)->post("/api/v1/admin/products/{$id}/media", [
            'collection' => 'images',
            'files' => [UploadedFile::fake()->image('product.jpg')],
        ])->assertCreated();

        return $id;
    }

    // ── task 10: publish conditions ──
    public function test_task10_publish_conditions(): void
    {
        $admin = $this->fullAdmin();
        $token = $this->token($admin);

        // No active variants → error with status labels.
        $cat = Category::factory()->create(['is_active' => true]);
        [$res] = $this->createProduct($token, ['category_id' => $cat->id]);
        $id = $res->json('data.id');
        $put = $this->withToken($token)->putJson("/api/v1/admin/products/{$id}", [
            'status' => 'published',
            'expected_version' => Product::find($id)->lock_version,
        ]);
        $put->assertUnprocessable();
        $this->assertNotEmpty($put->json('errors.status') ?? []);

        // Retail channel with zero retail price → rejected.
        $cat2 = Category::factory()->create(['is_active' => true]);
        [$res2] = $this->createProduct($token, [
            'category_id' => $cat2->id,
            'variants' => [['color_name_en' => 'Blue', 'retail_price' => 0]],
        ]);
        $id2 = $res2->json('data.id');
        $put2 = $this->withToken($token)->putJson("/api/v1/admin/products/{$id2}", [
            'status' => 'published',
            'expected_version' => Product::find($id2)->lock_version,
        ]);
        $this->assertContains($put2->status(), [422, 409]);

        // Happy path publishes once: published_at set and stable across re-publish.
        $cat3 = Category::factory()->create(['is_active' => true]);
        $id3 = $this->publishableProduct($token, $cat3);
        $this->withToken($token)->putJson("/api/v1/admin/products/{$id3}", [
            'status' => 'published',
            'expected_version' => Product::find($id3)->lock_version,
        ])->assertOk()->assertJsonPath('data.status', 'published');
        $firstPublishedAt = Product::find($id3)->published_at;
        $this->assertNotNull($firstPublishedAt);

        // Archive then re-publish keeps the original published_at.
        $this->withToken($token)->putJson("/api/v1/admin/products/{$id3}", [
            'status' => 'archived',
            'expected_version' => Product::find($id3)->lock_version,
        ])->assertOk();
        $this->withToken($token)->putJson("/api/v1/admin/products/{$id3}", [
            'status' => 'published',
            'expected_version' => Product::find($id3)->lock_version,
        ])->assertOk();
        $this->assertTrue(Product::find($id3)->published_at->equalTo($firstPublishedAt));

        // Without products.publish → 403.
        $editor = $this->staff(['products.view', 'products.update']);
        $this->withToken($this->token($editor))->putJson("/api/v1/admin/products/{$id3}", [
            'status' => 'archived',
            'expected_version' => Product::find($id3)->lock_version,
        ])->assertStatus(403);
    }

    // ── task 11: edits that would unpublish are rejected atomically ──
    public function test_task11_breaking_edits_on_published_rejected(): void
    {
        $admin = $this->fullAdmin();
        $token = $this->token($admin);
        $cat = Category::factory()->create(['is_active' => true]);
        $id = $this->publishableProduct($token, $cat);
        $this->withToken($token)->putJson("/api/v1/admin/products/{$id}", [
            'status' => 'published',
            'expected_version' => Product::find($id)->lock_version,
        ])->assertOk();
        $versionAfterPublish = Product::find($id)->lock_version;
        $variant = Product::find($id)->variants()->firstOrFail();

        // Switch to both without wholesale prices → rejected, nothing changed.
        $bad = $this->withToken($token)->putJson("/api/v1/admin/products/{$id}", [
            'sales_channel' => 'both',
            'expected_version' => $versionAfterPublish,
        ]);
        $this->assertContains($bad->status(), [422, 409]);
        $this->assertSame('retail', Product::find($id)->sales_channel->value);
        $this->assertSame($versionAfterPublish, Product::find($id)->lock_version);

        // Zero the last variant's retail price → rejected.
        $vbad = $this->withToken($token)->putJson(
            "/api/v1/admin/products/{$id}/variants/{$variant->id}",
            ['retail_price' => 0, 'expected_version' => $variant->lock_version]
        );
        $this->assertContains($vbad->status(), [422, 409]);
        $this->assertSame(500.0, (float) $variant->fresh()->retail_price);
    }

    // ── task 12: optimistic locking ──
    public function test_task12_expected_version_enforced_for_product_and_variant(): void
    {
        $admin = $this->fullAdmin();
        $token = $this->token($admin);
        [$res, $cat] = $this->createProduct($token, [
            'variants' => [['color_name_en' => 'Green', 'retail_price' => 100]],
        ]);
        $id = $res->json('data.id');
        $n = Product::find($id)->lock_version;

        // Fresh version succeeds → N+1.
        $this->withToken($token)->putJson("/api/v1/admin/products/{$id}", [
            'name_en' => 'Renamed Once',
            'expected_version' => $n,
        ])->assertOk();
        $this->assertSame($n + 1, Product::find($id)->lock_version);

        // Stale version → 409.
        $this->withToken($token)->putJson("/api/v1/admin/products/{$id}", [
            'name_en' => 'Renamed Twice',
            'expected_version' => $n,
        ])->assertStatus(409);

        // Missing version → 422.
        $this->withToken($token)->putJson("/api/v1/admin/products/{$id}", [
            'name_en' => 'Renamed Thrice',
        ])->assertStatus(422);

        // Variant: stale → 409, missing → 422.
        $variant = Product::find($id)->variants()->firstOrFail();
        $vn = $variant->lock_version;
        $this->withToken($token)->putJson("/api/v1/admin/products/{$id}/variants/{$variant->id}", [
            'retail_price' => 150,
            'expected_version' => $vn,
        ])->assertOk();
        $this->withToken($token)->putJson("/api/v1/admin/products/{$id}/variants/{$variant->id}", [
            'retail_price' => 160,
            'expected_version' => $vn,
        ])->assertStatus(409);
        $this->withToken($token)->putJson("/api/v1/admin/products/{$id}/variants/{$variant->id}", [
            'retail_price' => 170,
        ])->assertStatus(422);
    }

    // ── task 12b: category currently ignores expected_version (owner decision) ──
    public function test_task12b_category_overwrites_on_stale_version(): void
    {
        $admin = $this->fullAdmin();
        $token = $this->token($admin);
        $cat = Category::factory()->create(['is_active' => true, 'name_en' => 'Original']);

        // Stale expected_version is silently dropped by validation → overwrites.
        // task.txt §2-12 flags this as needing an owner decision (409 like products?).
        $this->withToken($token)->putJson("/api/v1/admin/categories/{$cat->id}", [
            'name_en' => 'First Write',
        ])->assertOk();
        $res = $this->withToken($token)->putJson("/api/v1/admin/categories/{$cat->id}", [
            'name_en' => 'Stale Overwrite',
            'expected_version' => 1,
        ]);
        $res->assertOk();
        $this->assertSame('Stale Overwrite', $cat->fresh()->name_en);
    }
}
