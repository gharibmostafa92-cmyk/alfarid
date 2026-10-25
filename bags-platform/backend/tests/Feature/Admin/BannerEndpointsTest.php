<?php

namespace Tests\Feature\Admin;

use App\Enums\ProductStatus;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\BannerLinkResolver;
use App\Services\BannerService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BannerEndpointsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['filesystems.banners_disk' => 'public']);
    }

    private function staff(array $permissions): string
    {
        foreach ($permissions as $p) {
            Permission::findOrCreate($p, 'staff');
        }
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user->createToken('test')->plainTextToken;
    }

    private function admin(string $token)
    {
        Auth::forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }

    private function payload(array $over = []): array
    {
        return [
            'title' => 'Test Banner',
            'image' => UploadedFile::fake()->image('banner.jpg', 1200, 600),
            'position' => 'main_slider',
            'link_type' => 'none',
            'sort_order' => 1,
            'is_active' => 1,
            ...$over,
        ];
    }

    public function test_home_returns_empty_banners(): void
    {
        $this->getJson('/api/v1/store/home')->assertOk()->assertJsonPath('data.banners', []);
    }

    public function test_legacy_unsafe_external_banner_link_is_not_exposed(): void
    {
        $banner = Banner::factory()->create(['link_type' => 'external', 'link_value' => 'javascript:alert(document.cookie)']);
        $resolved = app(BannerLinkResolver::class)->attach(collect([$banner]));
        $this->assertNull($resolved->first()->getAttribute('resolved_href'));
    }

    public function test_https_banner_link_accepts_the_full_validated_length(): void
    {
        $url = 'https://example.com/?q='.str_repeat('a', 1500);
        $token = $this->staff(['store.manage']);
        $this->admin($token)->post('/api/v1/admin/banners', $this->payload(['link_type' => 'external', 'link_value' => $url]))->assertCreated();
        $this->assertDatabaseHas('banners', ['link_value' => $url]);
    }

    public function test_home_hides_scheduled_expired_and_disabled_banners(): void
    {
        Banner::factory()->create(['title' => 'future', 'starts_at' => now()->addDay()]);
        Banner::factory()->create(['title' => 'expired', 'ends_at' => now()->subDay()]);
        Banner::factory()->create(['title' => 'off', 'is_active' => false]);
        Banner::factory()->create(['title' => 'live']);
        $titles = collect($this->getJson('/api/v1/store/home')->assertOk()->json('data.banners'))->pluck('title');
        $this->assertSame(['live'], $titles->all());
    }

    public function test_admin_created_banner_appears_on_home_immediately(): void
    {
        $token = $this->staff(['store.manage']);
        // Warm the home cache first so a stale cache would hide the new banner.
        $this->getJson('/api/v1/store/home')->assertOk();
        $this->admin($token)
            ->post('/api/v1/admin/banners', $this->payload())
            ->assertCreated();
        $titles = collect($this->getJson('/api/v1/store/home')->json('data.banners'))->pluck('title');
        $this->assertContains('Test Banner', $titles->all());
    }

    public function test_draft_product_banner_has_null_href(): void
    {
        $draft = Product::factory()->create(['status' => ProductStatus::Draft]);
        Banner::factory()->create(['link_type' => 'product', 'link_value' => (string) $draft->id]);
        $banner = collect($this->getJson('/api/v1/store/home')->assertOk()->json('data.banners'))->first();
        $this->assertNotNull($banner);
        $this->assertNull($banner['href']);
    }

    public function test_invalid_upload_and_dates_return_422(): void
    {
        $token = $this->staff(['store.manage']);
        $headers = ['Authorization' => "Bearer {$token}"];
        $this->admin($token)->post('/api/v1/admin/banners', $this->payload([
            'image' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ]))->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->admin($token)->post('/api/v1/admin/banners', $this->payload([
            'starts_at' => '2030-01-02T00:00:00', 'ends_at' => '2030-01-01T00:00:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    }

    public function test_permissions_gate_admin_banners(): void
    {
        $viewer = $this->staff(['settings.view']);
        $this->admin($viewer)
            ->getJson('/api/v1/admin/banners')->assertOk();
        $this->admin($viewer)
            ->post('/api/v1/admin/banners', $this->payload())->assertForbidden();
        $banner = Banner::factory()->create();
        $this->admin($viewer)
            ->patchJson("/api/v1/admin/banners/{$banner->id}/toggle")->assertForbidden();
        $this->admin($viewer)
            ->deleteJson("/api/v1/admin/banners/{$banner->id}")->assertForbidden();
    }

    public function test_update_with_new_image_deletes_old_file(): void
    {
        $token = $this->staff(['store.manage']);
        $headers = ['Authorization' => "Bearer {$token}"];
        $created = $this->admin($token)->post('/api/v1/admin/banners', $this->payload())->assertCreated()->json('data');
        $oldPath = Banner::find($created['id'])->image_path;
        Storage::disk('public')->assertExists($oldPath);
        $this->admin($token)->post("/api/v1/admin/banners/{$created['id']}", $this->payload(['title' => 'Updated']))->assertOk();
        Storage::disk('public')->assertMissing($oldPath);
        $this->assertSame('Updated', Banner::find($created['id'])->title);
    }

    public function test_scheduled_banner_goes_live_without_waiting_for_home_cache_expiry(): void
    {
        $this->freezeTime();
        $banner = Banner::factory()->create(['starts_at' => now()->addMinute()]);
        $this->getJson('/api/v1/store/home')->assertOk()->assertJsonPath('data.banners', []);

        $this->travel(61)->seconds();

        $this->getJson('/api/v1/store/home')->assertOk()->assertJsonPath('data.banners.0.id', $banner->id);
    }

    public function test_large_image_is_rejected_with_422(): void
    {
        $token = $this->staff(['store.manage']);

        $this->admin($token)->post('/api/v1/admin/banners', $this->payload([
            'image' => UploadedFile::fake()->image('large.jpg', 1200, 600)->size(5121),
        ]))->assertUnprocessable()->assertJsonValidationErrors('image');

        Storage::disk('public')->assertDirectoryEmpty('banners');
    }

    public function test_update_without_image_keeps_existing_file_and_clears_optional_text(): void
    {
        $token = $this->staff(['store.manage']);
        $created = $this->admin($token)->post('/api/v1/admin/banners', $this->payload(['headline' => 'Original']))
            ->assertCreated()->json('data');
        $path = Banner::findOrFail($created['id'])->image_path;
        $data = $this->payload(['headline' => '', 'link_type' => 'none', 'link_value' => '123']);
        unset($data['image']);

        $this->admin($token)->post("/api/v1/admin/banners/{$created['id']}", $data)
            ->assertOk()->assertJsonPath('data.headline', null)->assertJsonPath('data.link_value', null);

        $this->assertDatabaseHas('banners', ['id' => $created['id'], 'image_path' => $path, 'headline' => null]);
        Storage::disk('public')->assertExists($path);
    }

    public function test_failed_image_replacement_keeps_old_file_and_removes_new_upload(): void
    {
        $banner = Banner::factory()->create(['image_path' => 'banners/original.jpg']);
        Storage::disk('public')->put($banner->image_path, 'original');
        $failed = false;

        try {
            app(BannerService::class)->update($banner, ['position' => 'invalid-position'], UploadedFile::fake()->image('new.jpg', 1200, 600));
        } catch (\Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed);
        $this->assertDatabaseHas('banners', ['id' => $banner->id, 'image_path' => 'banners/original.jpg']);
        $this->assertSame(['banners/original.jpg'], Storage::disk('public')->allFiles('banners'));
    }

    public function test_activation_and_sort_edits_are_visible_with_a_warm_product_cache(): void
    {
        $token = $this->staff(['store.manage']);
        $first = Banner::factory()->create(['sort_order' => 1]);
        $second = Banner::factory()->create(['is_active' => false, 'sort_order' => 2]);
        $this->getJson('/api/v1/store/home')->assertOk()->assertJsonCount(1, 'data.banners');

        $this->admin($token)->patchJson("/api/v1/admin/banners/{$second->id}/toggle", ['is_active' => true])->assertOk();
        $this->admin($token)->patchJson("/api/v1/admin/banners/{$second->id}/toggle", ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
        app(BannerService::class)->update($second, ['sort_order' => 0], null);

        $this->getJson('/api/v1/store/home')->assertOk()->assertJsonPath('data.banners.0.id', $second->id)->assertJsonPath('data.banners.1.id', $first->id);
    }

    public function test_shared_images_survive_until_the_last_banner_reference_is_removed(): void
    {
        $one = Banner::factory()->create(['image_path' => 'banners/shared.jpg']);
        $two = Banner::factory()->create(['image_path' => 'banners/shared.jpg']);
        Storage::disk('public')->put('banners/shared.jpg', 'image');
        $service = app(BannerService::class);

        $service->update($one, [], UploadedFile::fake()->image('replacement.jpg', 1200, 600));
        Storage::disk('public')->assertExists('banners/shared.jpg');
        $service->delete($two);

        Storage::disk('public')->assertMissing('banners/shared.jpg');
        Storage::disk('public')->assertExists($one->fresh()->image_path);
    }

    public function test_stale_model_updates_remove_the_actually_replaced_image(): void
    {
        $banner = Banner::factory()->create(['image_path' => 'banners/initial.jpg']);
        Storage::disk('public')->put($banner->image_path, 'original');
        $stale = $banner->fresh();
        $service = app(BannerService::class);
        $firstUpdate = $service->update($banner, [], UploadedFile::fake()->image('first.jpg', 1200, 600));

        $lastUpdate = $service->update($stale, [], UploadedFile::fake()->image('last.jpg', 1200, 600));

        Storage::disk('public')->assertMissing($firstUpdate->image_path);
        $this->assertSame([$lastUpdate->image_path], Storage::disk('public')->allFiles('banners'));
    }

    public function test_update_of_a_deleted_banner_removes_the_new_upload(): void
    {
        $banner = Banner::factory()->create();
        $banner->delete();
        try {
            app(BannerService::class)->update($banner, [], UploadedFile::fake()->image('orphan.jpg', 1200, 600));
            $this->fail('Updating a deleted banner must fail.');
        } catch (ModelNotFoundException) {
            Storage::disk('public')->assertDirectoryEmpty('banners');
        }
    }

    public function test_schedule_keeps_the_requested_instant_in_a_non_utc_application(): void
    {
        config(['app.timezone' => 'Africa/Cairo']);
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Africa/Cairo');
        try {
            $banner = Banner::factory()->create();
            $saved = app(BannerService::class)->update($banner, [
                'starts_at' => '2026-01-10T08:00:00Z', 'ends_at' => '2026-01-10T10:00:00Z',
            ], null);

            $this->assertSame('2026-01-10 10:00:00', $saved->getRawOriginal('starts_at'));
            $this->assertSame('2026-01-10T08:00:00+00:00', $saved->starts_at->utc()->toIso8601String());
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    public function test_reorder_rejects_duplicate_and_excessive_identifiers(): void
    {
        $token = $this->staff(['store.manage']);
        $this->admin($token)->putJson('/api/v1/admin/banners/reorder', ['position' => 'main_slider', 'ids' => [1, 1]])
            ->assertUnprocessable()->assertJsonValidationErrors('ids.0');
        $this->admin($token)->putJson('/api/v1/admin/banners/reorder', ['position' => 'main_slider', 'ids' => range(1, 501)])
            ->assertUnprocessable()->assertJsonValidationErrors('ids');
    }

    public function test_links_encode_arabic_slugs_and_reject_soft_deleted_targets(): void
    {
        $category = Category::factory()->create(['slug' => 'حقائب-يد']);
        Banner::factory()->create(['link_type' => 'category', 'link_value' => (string) $category->id]);
        $this->getJson('/api/v1/store/home')->assertOk()->assertJsonPath('data.banners.0.href', '/shop?category='.rawurlencode('حقائب-يد'));
        $deleted = Product::factory()->create();
        $deleted->delete();
        $token = $this->staff(['store.manage']);

        $this->admin($token)->post('/api/v1/admin/banners', $this->payload(['link_type' => 'product', 'link_value' => $deleted->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('link_value');
    }

    public function test_oversized_dimensions_and_new_legacy_brand_links_are_rejected(): void
    {
        $token = $this->staff(['store.manage']);
        $this->admin($token)->post('/api/v1/admin/banners', $this->payload(['image' => UploadedFile::fake()->image('wide.png', 4001, 300)]))
            ->assertUnprocessable()->assertJsonValidationErrors('image');
        $banner = Banner::factory()->create(['link_type' => 'none']);

        $this->admin($token)->post("/api/v1/admin/banners/{$banner->id}", $this->payload(['link_type' => 'brand', 'link_value' => 999]))
            ->assertUnprocessable()->assertJsonValidationErrors('link_type');
    }
}
