<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SupplierInvoiceStatus;
use App\Models\Category;
use App\Models\SupplierInvoice;
use App\Models\SupplierUser;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\ExercisesHistoricalResale;
use Tests\TestCase;

class TaskHardeningRegressionTest extends TestCase
{
    use ExercisesHistoricalResale;
    use LazilyRefreshDatabase;

    public function test_scheduled_queue_monitor_uses_queue_connection_names(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database-media.connection' => 'pgsql', 'media-library.queue_connection_name' => 'database-media']);
        $this->app->instance(Schedule::class, new Schedule);
        \Illuminate\Support\Facades\Schedule::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');
        $commands = collect(app(Schedule::class)->events())->pluck('command')->filter()->implode("\n");

        $this->assertStringContainsString('queue:monitor database:default', $commands);
        $this->assertStringContainsString('queue:monitor database-media:media', $commands);
        $this->assertStringNotContainsString('queue:monitor pgsql:', $commands);
    }

    public function test_media_jobs_use_the_queue_consumed_by_the_media_worker(): void
    {
        $this->assertSame('media', config('queue.connections.database-media.queue'));
        $this->assertSame('media', config('media-library.queue_name'));
        $this->assertGreaterThan(600, config('queue.connections.database-media.retry_after'));
    }

    public function test_private_and_public_disks_have_separate_cache_policies(): void
    {
        $private = config('filesystems.disks.r2.options.CacheControl');
        $public = config('filesystems.disks.r2_public.options.CacheControl');

        $this->assertStringContainsString('private', $private);
        $this->assertStringContainsString('public', $public);
        $this->assertNotSame($private, $public);
        $this->assertArrayNotHasKey('CacheControl', config('media-library.remote.extra_headers'));
    }

    #[DataProvider('networkLimiters')]
    public function test_ipv6_addresses_in_one_network_share_rate_limit_keys(string $name): void
    {
        $limiter = RateLimiter::limiter($name);
        $this->assertNotNull($limiter);
        $keys = static function (string $ip) use ($limiter): array {
            $request = Request::create('/', 'POST', [
                'phone' => '01012345678', 'login' => 'same@example.test', 'email' => 'same@example.test',
            ], [], [], ['REMOTE_ADDR' => $ip]);
            $limits = $limiter($request);

            return array_map(static fn ($limit) => $limit->key, is_array($limits) ? $limits : [$limits]);
        };

        $this->assertSame($keys('2001:db8:abcd:1234::1'), $keys('2001:db8:abcd:1234:ffff::2'));
        $this->assertNotSame($keys('2001:db8:abcd:1234::1'), $keys('2001:db8:abcd:1235::1'));
    }

    public static function networkLimiters(): array
    {
        return array_map(static fn ($name) => [$name], [
            'customer-registration', 'customer-phone-send', 'customer-phone-verify',
            'customer-password-reset', 'customer-reset-confirm', 'customer-login',
            'coupon', 'cart', 'checkout', 'unified-login', 'unified-handoff',
            'admin-login', 'supplier-login', 'pos-login', 'order-tracking', 'webhooks',
        ]);
    }

    #[DataProvider('networkLimiters')]
    public function test_ipv4_mapped_addresses_keep_their_own_ipv4_rate_limit_bucket(string $name): void
    {
        $limiter = RateLimiter::limiter($name);
        $keys = static function (string $ip) use ($limiter): array {
            $request = Request::create('/', 'POST', [
                'phone' => '01012345678', 'login' => 'same@example.test', 'email' => 'same@example.test',
            ], [], [], ['REMOTE_ADDR' => $ip]);
            $limits = $limiter($request);

            return array_map(static fn ($limit) => $limit->key, is_array($limits) ? $limits : [$limits]);
        };

        $this->assertNotSame($keys('1.2.3.4'), $keys('5.6.7.8'));
        $this->assertNotSame($keys('::ffff:1.2.3.4'), $keys('::ffff:5.6.7.8'));
        $this->assertSame($keys('1.2.3.4'), $keys('::ffff:1.2.3.4'));
        $this->assertSame($keys('1.2.3.4'), $keys('::ffff:102:304'));
    }

    public function test_invalid_notification_uuid_is_rejected_by_routing(): void
    {
        foreach (['admin', 'store/auth'] as $prefix) {
            $this->postJson('/api/v1/'.$prefix.'/notifications/invalid-uuid/read')->assertNotFound();
        }
    }

    public function test_authorized_attachment_client_can_request_a_signed_url_as_json(): void
    {
        $url = $this->invoiceAttachment();
        Storage::disk('r2')->put('supplier_invoices/receipt.pdf', 'invoice');
        $options = null;
        $expiration = null;
        Storage::disk('r2')->buildTemporaryUrlsUsing(function ($path, $expires, $signedOptions) use (&$options, &$expiration): string {
            $options = $signedOptions;
            $expiration = $expires;

            return 'https://storage.example.test/signed-receipt';
        });

        $this->getJson($url.'?signed=1')->assertOk()
            ->assertExactJson(['url' => 'https://storage.example.test/signed-receipt']);
        $this->assertStringContainsString('attachment;', $options['ResponseContentDisposition']);
        $this->assertStringContainsString('private', $options['ResponseCacheControl']);
        $this->assertLessThanOrEqual(now()->addMinutes(2)->timestamp, $expiration->timestamp);
    }

    public function test_authorized_attachment_navigation_still_redirects(): void
    {
        $url = $this->invoiceAttachment();
        Storage::disk('r2')->put('supplier_invoices/receipt.pdf', 'invoice');

        $this->get($url)->assertRedirect('https://storage.example.test/supplier_invoices%2Freceipt.pdf?signed=1');
    }

    public function test_missing_attachment_returns_not_found(): void
    {
        $url = $this->invoiceAttachment();

        $this->get($url)->assertNotFound();
    }

    public function test_storage_outage_returns_service_unavailable(): void
    {
        $url = $this->invoiceAttachment();
        Storage::shouldReceive('disk')->with('r2')->andThrow(new RuntimeException('storage unavailable'));

        $this->get($url)->assertStatus(503);
    }

    public function test_backfill_does_not_invent_a_layout_when_both_files_are_missing(): void
    {
        $media = Category::factory()->create()->addMedia(UploadedFile::fake()->image('missing.png'))->toMediaCollection('image');
        Storage::disk($media->disk)->deleteDirectory($media->uuid);
        Storage::disk($media->disk)->deleteDirectory((string) $media->id);
        DB::table('media')->where('id', $media->id)->update(['custom_properties' => '{}']);

        $this->artisan('media:backfill-path-layouts')->assertSuccessful();

        $this->assertFalse($media->fresh()->hasCustomProperty('path_layout'));
    }

    public function test_backfill_recognizes_existing_id_and_uuid_layouts(): void
    {
        $legacy = Category::factory()->create()->addMedia(UploadedFile::fake()->image('legacy.png'))->toMediaCollection('image');
        $modern = Category::factory()->create()->addMedia(UploadedFile::fake()->image('modern.png'))->toMediaCollection('image');
        Storage::disk($legacy->disk)->deleteDirectory($legacy->uuid);
        Storage::disk($legacy->disk)->put($legacy->id.'/'.$legacy->file_name, 'legacy image');
        DB::table('media')->whereIn('id', [$legacy->id, $modern->id])->update(['custom_properties' => '{}']);

        $this->artisan('media:backfill-path-layouts')->assertSuccessful();

        $this->assertSame('id', $legacy->fresh()->getCustomProperty('path_layout'));
        $this->assertSame('uuid', $modern->fresh()->getCustomProperty('path_layout'));
    }

    private function invoiceAttachment(): string
    {
        $this->exerciseHistoricalResale();
        $supplier = SupplierUser::factory()->create();
        $invoice = SupplierInvoice::factory()->create([
            'supplier_id' => $supplier->supplier_id,
            'status' => SupplierInvoiceStatus::Posted,
            'attachment_path' => 'supplier_invoices/receipt.pdf',
        ]);
        $this->withToken($supplier->createToken('attachment-regression')->plainTextToken);

        return '/api/v1/supplier/supplier-invoices/'.$invoice->id.'/attachment';
    }
}
