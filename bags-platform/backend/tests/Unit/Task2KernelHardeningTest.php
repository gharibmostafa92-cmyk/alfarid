<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Models\WebhookLog;
use App\Rules\MaxImagePixels;
use App\Services\Shared\CostAccess;
use App\Services\Shared\IdempotencyFingerprint;
use App\Services\Shared\ProportionalDistribution;
use App\Services\Shared\SpokenQuantity;
use Illuminate\Http\UploadedFile;
use JsonSerializable;
use PHPUnit\Framework\TestCase;

/** task2 section 1: shared kernel correctness (H2, M1, M10, M11, L1, L3). */
class Task2KernelHardeningTest extends TestCase
{
    public function test_cost_access_strips_stdclass_and_json_serializable_objects(): void
    {
        $object = (object) [
            'sku' => 'S-1',
            'cost_price' => 5.0,
            'nested' => (object) ['ok' => 1, 'unit_cost' => 2.0],
        ];
        $dto = new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return (object) ['sku' => 'J-1', 'avg_cost' => 3.0, 'last_cost_price' => 5.0, 'ok' => 2];
            }
        };

        $clean = CostAccess::strip(['row' => $object, 'dto' => $dto]);

        $this->assertSame(
            ['row' => ['sku' => 'S-1', 'nested' => ['ok' => 1]], 'dto' => ['sku' => 'J-1', 'ok' => 2]],
            $clean,
        );
    }

    public function test_proportional_distribution_preserves_caller_key_order(): void
    {
        $shares = ProportionalDistribution::distribute(100.0, [42 => 1.0, 7 => 1.0]);

        $this->assertSame([42, 7], array_keys($shares));
        $this->assertSame([50.0, 50.0], array_values($shares));
    }

    public function test_proportional_distribution_rejects_non_finite_weights(): void
    {
        foreach ([INF, -INF, NAN] as $weight) {
            try {
                ProportionalDistribution::distribute(100.0, [1.0, $weight]);
                $this->fail('Non-finite weight must be rejected.');
            } catch (BusinessException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_proportional_distribution_rejects_negative_total_instead_of_silent_zero(): void
    {
        $this->expectException(BusinessException::class);
        ProportionalDistribution::distribute(-50.0, [10.0, 20.0]);
    }

    public function test_spoken_two_hundred_multiplies_a_following_thousand(): void
    {
        $this->assertSame(200000.0, SpokenQuantity::parse('ميتين ألف'));
        $this->assertSame(250.0, SpokenQuantity::parse('ميتين وخمسين'));
        $this->assertSame(1200.0, SpokenQuantity::parse('ألف وميتين'));
    }

    public function test_idempotency_fingerprint_does_not_collapse_large_numeric_ids(): void
    {
        $this->assertNotSame(
            IdempotencyFingerprint::hash(['id' => '99999999999999999999']),
            IdempotencyFingerprint::hash(['id' => '99999999999999999998']),
        );

        $this->assertSame(
            IdempotencyFingerprint::hash(['id' => '0009']),
            IdempotencyFingerprint::hash(['id' => 9]),
        );
    }

    public function test_max_image_pixels_rejects_an_unreadable_image(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'img_');
        file_put_contents($path, 'this is not an image');
        $file = new UploadedFile($path, 'bomb.jpg', 'image/jpeg', null, true);

        $failed = false;
        (new MaxImagePixels)->validate('image', $file, function () use (&$failed): void {
            $failed = true;
        });

        @unlink($path);

        $this->assertTrue($failed, 'Unreadable image dimensions must fail closed.');
    }

    public function test_webhook_log_redacts_paymob_pii_and_secret_key_variants(): void
    {
        $clean = WebhookLog::sanitizePayload([
            'obj' => [
                'billing_data' => [
                    'first_name' => 'Ahmed',
                    'phone_number' => '01000000000',
                    'email' => 'ahmed@example.test',
                    'street' => '1 Somewhere',
                ],
                'source_data' => ['pan' => '4111111111111111'],
                'amount_cents' => 100,
            ],
            'api-key' => 'key-a',
            'api_key' => 'key-b',
            'secretToken' => 'secret-c',
            'access-token' => 'token-d',
        ]);

        $this->assertSame('[redacted]', $clean['obj']['billing_data']);
        $this->assertSame('[redacted]', $clean['obj']['source_data']);
        $this->assertSame(100, $clean['obj']['amount_cents']);
        $this->assertSame('[redacted]', $clean['api-key']);
        $this->assertSame('[redacted]', $clean['api_key']);
        $this->assertSame('[redacted]', $clean['secretToken']);
        $this->assertSame('[redacted]', $clean['access-token']);
    }
}
