<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
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
        Storage::fake('public');
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
