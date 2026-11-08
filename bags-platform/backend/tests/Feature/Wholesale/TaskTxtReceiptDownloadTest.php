<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Models\Customer;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * task.txt item 29: تنزيل الإيصال (AttachmentController::paymentNotice/download)
 */
class TaskTxtReceiptDownloadTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Customer $trader;

    private string $traderToken;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploads();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->trader = Customer::factory()->create([
            'type' => 'wholesale',
            'status' => 'approved',
        ]);
        $this->traderToken = $this->trader->createToken('portal')->plainTextToken;

        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->adminToken = $admin->createToken('admin')->plainTextToken;
    }

    private function asTrader(): static
    {
        Auth::forgetGuards();

        return $this->withToken($this->traderToken);
    }

    public function test_existing_receipt_redirects_to_two_minute_signed_url_with_private_no_store_and_supports_signed_json(): void
    {
        $path = "wholesale_payment_notices/{$this->trader->id}/receipt-ok.jpg";
        Storage::disk('r2')->put($path, 'fake-image-content');

        $capturedExpiration = null;
        $capturedOptions = [];
        Storage::disk('r2')->buildTemporaryUrlsUsing(
            function (string $p, \DateTimeInterface $expiration, array $options) use (&$capturedExpiration, &$capturedOptions): string {
                $capturedExpiration = $expiration;
                $capturedOptions = $options;

                return 'https://storage.example.test/'.rawurlencode($p).'?signed=1';
            }
        );

        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->trader->id,
            'receipt_file_path' => $path,
        ]);

        // 1. Standard redirect download
        $response = $this->asTrader()->get("/api/v1/wholesale/payment-notices/{$notice->id}/receipt");
        $response->assertRedirect();
        $this->assertStringContainsString('https://storage.example.test/', (string) $response->headers->get('Location'));
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        // Verify 2-minute TTL and private response options passed to R2
        $this->assertNotNull($capturedExpiration);
        $diffSeconds = abs($capturedExpiration->getTimestamp() - now()->addMinutes(2)->getTimestamp());
        $this->assertLessThanOrEqual(5, $diffSeconds);
        $this->assertSame('private, max-age=120', $capturedOptions['ResponseCacheControl'] ?? null);

        // 2. ?signed=1 JSON mode
        $signedResponse = $this->asTrader()->getJson("/api/v1/wholesale/payment-notices/{$notice->id}/receipt?signed=1")
            ->assertOk();
        $this->assertStringContainsString('https://storage.example.test/', (string) $signedResponse->json('url'));
        $signedCache = (string) $signedResponse->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $signedCache);
        $this->assertStringContainsString('no-store', $signedCache);
    }

    public function test_missing_file_on_r2_returns_404(): void
    {
        $missingFileNotice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->trader->id,
            'receipt_file_path' => "wholesale_payment_notices/{$this->trader->id}/does-not-exist.jpg",
        ]);

        $this->asTrader()->get("/api/v1/wholesale/payment-notices/{$missingFileNotice->id}/receipt")
            ->assertNotFound();
    }

    public function test_r2_outage_returns_503_without_leaking_internal_details(): void
    {
        $path = "wholesale_payment_notices/{$this->trader->id}/outage.jpg";
        Storage::disk('r2')->put($path, 'bytes');
        Storage::disk('r2')->buildTemporaryUrlsUsing(function (): string {
            throw new \RuntimeException('S3 internal credential secret=AKIAIOSFODNN7EXAMPLE endpoint failure');
        });

        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->trader->id,
            'receipt_file_path' => $path,
        ]);

        $response = $this->asTrader()->getJson("/api/v1/wholesale/payment-notices/{$notice->id}/receipt?signed=1")
            ->assertStatus(503);

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('AKIAIOSFODNN7EXAMPLE', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
    }
}
