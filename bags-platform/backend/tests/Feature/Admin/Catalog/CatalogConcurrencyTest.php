<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Catalog;

use App\Enums\ProductStatus;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CatalogConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploads();
    }

    public function test_concurrent_deletes_cannot_remove_both_remaining_active_variants(): void
    {
        $product = $this->completeProduct();
        $first = $product->variants()->firstOrFail();
        $second = ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 100, 'wholesale_price' => 80]);
        $this->race([
            ['$variant = App\Models\ProductVariant::findOrFail('.$first->id.'); $variant->load("product");', 'app(App\Services\Catalog\VariantService::class)->delete($variant);'],
            ['$variant = App\Models\ProductVariant::findOrFail('.$second->id.'); $variant->load("product");', 'app(App\Services\Catalog\VariantService::class)->delete($variant);'],
        ]);
        $this->assertSame(1, $product->variants()->where('is_active', true)->count());
    }

    public function test_concurrent_deletes_cannot_remove_both_remaining_product_images(): void
    {
        $product = $this->completeProduct();
        $first = $product->getFirstMedia('images');
        $second = $product->addMedia(UploadedFile::fake()->image('second.jpg'))->toMediaCollection('images');
        $setup = '$product = App\Models\Product::findOrFail('.$product->id.'); $product->load("media"); ';
        $this->race([
            [$setup.'$media = Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail('.$first->id.');', 'app(App\Services\Catalog\ProductMediaService::class)->delete($product, $media);'],
            [$setup.'$media = Spatie\MediaLibrary\MediaCollections\Models\Media::findOrFail('.$second->id.');', 'app(App\Services\Catalog\ProductMediaService::class)->delete($product, $media);'],
        ]);
        $remaining = $product->fresh()->getMedia('images');
        $this->assertCount(1, $remaining);
        // H5: product media live on the public bucket.
        Storage::disk('r2_public')->assertExists($remaining->first()->getPathRelativeToRoot());
    }

    public function test_concurrent_opposite_reparents_cannot_create_a_cycle(): void
    {
        $a = Category::factory()->create();
        $b = Category::factory()->create();
        $this->race([
            ['$category = App\Models\Category::findOrFail('.$a->id.');', 'app(App\Services\Catalog\CategoryService::class)->update($category, ["parent_id" => '.$b->id.'], null);'],
            ['$category = App\Models\Category::findOrFail('.$b->id.');', 'app(App\Services\Catalog\CategoryService::class)->update($category, ["parent_id" => '.$a->id.'], null);'],
        ]);
        $this->assertEqualsCanonicalizing([1, 2], [$a->fresh()->depth(), $b->fresh()->depth()]);
    }

    public function test_publication_and_category_deactivation_are_serialized(): void
    {
        $product = $this->completeProduct();
        $product->update(['status' => ProductStatus::Draft, 'published_at' => null]);
        Permission::findOrCreate('products.publish', 'staff');
        $staff = User::factory()->create();
        $staff->givePermissionTo('products.publish');
        $this->race([
            ['$product = App\Models\Product::findOrFail('.$product->id.'); $user = App\Models\User::findOrFail('.$staff->id.');', 'app(App\Services\Catalog\ProductService::class)->changeStatus($product, App\Enums\ProductStatus::Published, $user);'],
            ['$category = App\Models\Category::findOrFail('.$product->category_id.');', 'app(App\Services\Catalog\CategoryService::class)->update($category, ["is_active" => false], null);'],
        ]);
        $product->refresh();
        $this->assertSame($product->status === ProductStatus::Published, $product->category->is_active);
    }

    public function test_product_creates_in_the_same_category_can_reach_insert_concurrently(): void
    {
        $category = Category::factory()->create();
        $user = User::factory()->create();
        $jobs = [];
        foreach (['left' => 'right', 'right' => 'left'] as $own => $other) {
            $setup = '$user = App\Models\User::findOrFail('.$user->id.');';
            $operation = 'App\Models\Product::creating(function () { $prefix = getenv("CATALOG_START_FILE"); touch($prefix.'.var_export('.'.$own, true).'); $deadline = microtime(true) + 5; while (!is_file($prefix.'.var_export('.'.$other, true).')) { if (microtime(true) > $deadline) { throw new RuntimeException("Product creates were serialized by a reference lock"); } usleep(10000); } });';
            $operation .= 'app(App\Services\Catalog\ProductService::class)->create(["category_id" => '.$category->id.', "name_ar" => "حقيبة", "name_en" => "Parallel '.$own.'", "sales_channel" => "retail"], $user);';
            $jobs[] = [$setup, $operation];
        }
        $this->race($jobs, ['OK', 'OK']);
        $this->assertSame(2, Product::where('category_id', $category->id)->count());
    }

    public function test_inflight_stock_increase_prevents_product_and_variant_deletion(): void
    {
        $this->seed(SettingSeeder::class);
        $user = User::factory()->create();
        $branch = Branch::factory()->create();
        foreach (['product', 'variant'] as $target) {
            $product = Product::factory()->create();
            $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
            $movement = var_export([
                'product_variant_id' => $variant->id, 'branch_id' => $branch->id,
                'type' => 'adjustment_in', 'affected_bucket' => 'on_hand', 'quantity' => 2, 'created_by' => $user->id,
            ], true);
            $increase = 'Illuminate\Support\Facades\DB::transaction(function () { app(App\Services\InventoryService::class)->recordMovement('.$movement.'); touch(getenv("CATALOG_START_FILE").".left"); usleep(300000); });';
            $wait = '$deadline = microtime(true) + 5; while (!is_file(getenv("CATALOG_START_FILE").".left")) { if (microtime(true) > $deadline) { throw new RuntimeException("Movement did not acquire lock"); } usleep(10000); }';
            $delete = $target === 'product'
                ? 'app(App\Services\Catalog\ProductService::class)->delete($product);'
                : 'app(App\Services\Catalog\VariantService::class)->delete($variant);';
            $this->race([
                ['', $increase],
                ['$product = App\Models\Product::findOrFail('.$product->id.'); $variant = App\Models\ProductVariant::findOrFail('.$variant->id.');', $wait.$delete],
            ]);
            $this->assertNotSoftDeleted($product);
            $this->assertNotSoftDeleted($variant);
            $this->assertDatabaseHas('stock_levels', ['product_variant_id' => $variant->id, 'on_hand' => 2]);
        }
    }

    public function test_concurrent_auto_slugs_retry_with_a_new_suffix(): void
    {
        $category = Category::factory()->create();
        $user = User::factory()->create();
        $jobs = [];
        foreach (['left' => 'right', 'right' => 'left'] as $own => $other) {
            $setup = '$user = App\Models\User::findOrFail('.$user->id.');';
            $operation = 'App\Models\Product::creating(function () { $prefix = getenv("CATALOG_START_FILE"); touch($prefix.'.var_export('.'.$own, true).'); $deadline = microtime(true) + 5; while (!is_file($prefix.'.var_export('.'.$other, true).')) { if (microtime(true) > $deadline) { throw new RuntimeException("Concurrent insert not reached"); } usleep(10000); } });';
            $operation .= 'app(App\Services\Catalog\ProductService::class)->create(["category_id" => '.$category->id.', "name_ar" => "حقيبة", "name_en" => "Same slug", "sales_channel" => "retail"], $user);';
            $jobs[] = [$setup, $operation];
        }
        $this->race($jobs, ['OK', 'OK']);
        $this->assertEqualsCanonicalizing(['same-slug', 'same-slug-2'], Product::pluck('slug')->all());
    }

    private function completeProduct(): Product
    {
        $product = Product::factory()->published()->create();
        ProductVariant::factory()->create(['product_id' => $product->id, 'retail_price' => 100, 'wholesale_price' => 80]);
        $product->addMedia(UploadedFile::fake()->image('first.jpg'))->toMediaCollection('images');

        return $product;
    }

    public function test_concurrent_create_key_retries_return_one_product(): void
    {
        $category = Category::factory()->create();
        $user = User::factory()->create();
        $jobs = [];
        foreach (['left' => 'right', 'right' => 'left'] as $own => $other) {
            $operation = 'App\Models\Product::creating(function () { $prefix = getenv("CATALOG_START_FILE"); touch($prefix.'.var_export('.'.$own, true).'); $deadline = microtime(true) + 5; while (!is_file($prefix.'.var_export('.'.$other, true).')) { if (microtime(true) > $deadline) { throw new RuntimeException("Concurrent create not reached"); } usleep(10000); } });';
            $operation .= 'app(App\Services\Catalog\ProductService::class)->create(["category_id" => '.$category->id.', "name_ar" => "حقيبة", "name_en" => "Same request", "sales_channel" => "retail", "create_key" => "11111111-2222-4333-8444-555555555555"], App\Models\User::findOrFail('.$user->id.'));';
            $jobs[] = ['', $operation];
        }
        $this->race($jobs, ['OK', 'OK']);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_concurrent_image_retry_leaves_one_media_and_one_set_of_files(): void
    {
        $product = Product::factory()->create();
        $jobs = [];
        foreach (['left' => 'right', 'right' => 'left'] as $own => $other) {
            $operation = 'Spatie\MediaLibrary\MediaCollections\Models\Media::creating(function () { $prefix = getenv("CATALOG_START_FILE"); touch($prefix.'.var_export('.'.$own, true).'); $deadline = microtime(true) + 5; while (!is_file($prefix.'.var_export('.'.$other, true).')) { if (microtime(true) > $deadline) { throw new RuntimeException("Concurrent upload not reached"); } usleep(10000); } });';
            $operation .= 'app(App\Services\Catalog\ProductMediaService::class)->upload(App\Models\Product::findOrFail('.$product->id.'), ["collection" => "images", "client_upload_ids" => ["aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee"]], [Illuminate\Http\UploadedFile::fake()->image("photo.jpg")]);';
            $jobs[] = ['', $operation];
        }
        $this->race($jobs, ['OK', 'OK']);
        $this->assertDatabaseCount('media', 1);
        $this->assertCount(3, Storage::disk('r2_public')->allFiles());
    }

    public function test_concurrent_video_replacements_leave_one_video_and_one_file(): void
    {
        $product = Product::factory()->create();
        $jobs = [];
        foreach (['first', 'second'] as $name) {
            $operation = 'app(App\Services\Catalog\ProductMediaService::class)->upload(App\Models\Product::findOrFail('.$product->id.'), ["collection" => "video"], [], Illuminate\Http\UploadedFile::fake()->createWithContent("'.$name.'.mp4", hex2bin("00000018667479706d703432000000006d70343269736f6d")));';
            $jobs[] = ['', $operation];
        }
        $this->race($jobs, ['OK', 'OK']);
        $this->assertSame(1, $product->fresh()->getMedia('video')->count());
        $this->assertCount(1, Storage::disk('r2_public')->allFiles());
    }

    /** Both processes read their models before either starts the mutation. */
    private function race(array $jobs, array $expected = ['OK', 'REJECTED']): void
    {
        $this->assertSame('pgsql', config('database.default'));
        $barrier = tempnam(sys_get_temp_dir(), 'catalog-race-');
        unlink($barrier);
        $db = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $db['database'],
            'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => $db['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'CATALOG_START_FILE' => $barrier, 'CATALOG_MEDIA_ROOT' => Storage::disk('r2')->path(''),
            // H5: catalog media (products/categories/brands) live on r2_public;
            // race subprocesses must redirect that bucket to the local root too.
            'CATALOG_PUBLIC_MEDIA_ROOT' => Storage::disk('r2_public')->path(''),
        ];
        $processes = [];
        try {
            foreach ($jobs as [$setup, $operation]) {
                $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['filesystems.disks.r2' => ['driver' => 'local', 'root' => getenv('CATALOG_MEDIA_ROOT'), 'throw' => true]]);
config(['filesystems.disks.r2_public' => ['driver' => 'local', 'root' => getenv('CATALOG_PUBLIC_MEDIA_ROOT') ?: getenv('CATALOG_MEDIA_ROOT'), 'throw' => true]]);
PHP;
                $script .= $setup;
                $script .= <<<'PHP'
echo "READY\n";
$deadline = microtime(true) + 12;
while (!is_file(getenv('CATALOG_START_FILE'))) {
    if (microtime(true) > $deadline) { exit(4); }
    usleep(10000);
}
try {
PHP;
                $script .= $operation;
                $script .= <<<'PHP'
echo "OK\n";
} catch (App\Exceptions\BusinessException $exception) {
    echo "REJECTED\n";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(3);
}
PHP;
                $process = new Process([PHP_BINARY, '-r', $script], base_path(), $environment, timeout: 15);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')), $process->getErrorOutput());
            }
            touch($barrier);
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = trim(str_replace('READY', '', $process->getOutput()));
            }
            $this->assertEqualsCanonicalizing($expected, $results);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ([$barrier, $barrier.'.left', $barrier.'.right'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
