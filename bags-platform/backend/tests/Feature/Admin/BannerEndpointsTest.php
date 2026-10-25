<?php

namespace Tests\Feature\Admin;

use App\Enums\ProductStatus;
use App\Models\Banner;
use App\Models\Product;
use App\Models\User;
use App\Services\BannerService;
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
}
