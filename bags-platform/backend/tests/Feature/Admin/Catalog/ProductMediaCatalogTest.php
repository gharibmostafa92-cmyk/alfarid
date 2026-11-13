<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ProductMediaService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductMediaCatalogTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ALL_PERMISSIONS = [
        'products.view', 'products.create', 'products.update', 'products.delete',
        'products.publish', 'products.view_cost',
    ];

    public function test_deleting_media_of_another_product_returns_404(): void
    {
        $this->fakeUploads();
        $staff = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $token = $staff->createToken('test')->plainTextToken;

        $product = Product::factory()->create();
        $other = Product::factory()->create();

        Auth::forgetGuards();
        $uploaded = $this->withToken($token)->post("/api/v1/admin/products/{$other->id}/media", [
            'collection' => 'images',
            'files' => [UploadedFile::fake()->image('other.jpg')],
        ])->assertCreated();
        $mediaId = $uploaded->json('data.0.id');
        $this->assertNotEmpty($mediaId);

        Auth::forgetGuards();
        $this->withToken($token)->deleteJson("/api/v1/admin/products/{$product->id}/media/{$mediaId}")
            ->assertNotFound();

        Auth::forgetGuards();
        $this->withToken($token)->deleteJson("/api/v1/admin/products/{$other->id}/media/{$mediaId}")
            ->assertOk()
            ->assertJsonPath('message', 'تم الحذف');
    }

    public function test_media_lock_exceeds_client_timeout_and_guards_delete_and_video_upload(): void
    {
        $this->fakeUploads();
        $product = Product::factory()->create();
        $media = $product->addMedia(UploadedFile::fake()->image('item.jpg'))->toMediaCollection('images');
        $service = app(ProductMediaService::class);

        $seen = [];
        Cache::shouldReceive('lock')->times(3)->andReturnUsing(function (string $key, int $ttl) use (&$seen) {
            $seen[] = [$key, $ttl];

            return new class
            {
                public function block(int $seconds, ?\Closure $callback = null): never
                {
                    throw new LockTimeoutException('Busy');
                }
            };
        });

        try {
            $service->upload($product, ['collection' => 'images'], [UploadedFile::fake()->image('new.jpg')]);
            $this->fail('Expected image upload lock timeout');
        } catch (BusinessException $e) {
            $this->assertSame(409, $e->status);
        }

        try {
            $mp4 = UploadedFile::fake()->createWithContent('clip.mp4', hex2bin('00000018667479706d703432000000006d70343269736f6d'));
            $service->upload($product, ['collection' => 'video'], [], $mp4);
            $this->fail('Expected video upload lock timeout');
        } catch (BusinessException $e) {
            $this->assertSame(409, $e->status);
        }

        try {
            $service->delete($product, $media);
            $this->fail('Expected delete lock timeout');
        } catch (BusinessException $e) {
            $this->assertSame(409, $e->status);
        }

        $this->assertCount(3, $seen);
        foreach ($seen as [$key, $ttl]) {
            $this->assertSame('catalog-media:'.$product->id, $key);
            $this->assertGreaterThanOrEqual(240, $ttl);
        }
    }

    /** @param list<string> $permissions */
    private function staffWithPermissions(array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'staff');
        }
        $staff = User::factory()->create();
        $staff->givePermissionTo($permissions);

        return $staff;
    }
}
