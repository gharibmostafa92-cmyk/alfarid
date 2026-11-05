<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendCustomerPhoneVerification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PhoneRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_registration_options_expose_only_public_verification_configuration(): void
    {
        config([
            'auth.require_phone_verification' => true,
            'services.turnstile.site_key' => 'public-site-key',
            'services.turnstile.secret_key' => 'private-secret-key',
        ]);

        $response = $this->getJson('/api/v1/store/auth/registration-options')->assertOk()
            ->assertJsonPath('data.phone_verification_required', true)
            ->assertJsonPath('data.turnstile_site_key', 'public-site-key');
        $this->assertStringNotContainsString('private-secret-key', $response->getContent());
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function test_optional_verification_does_not_require_a_widget(): void
    {
        config(['auth.require_phone_verification' => false]);
        $this->getJson('/api/v1/store/auth/registration-options')->assertOk()
            ->assertJsonPath('data.phone_verification_required', false)
            ->assertJsonPath('data.turnstile_site_key', null);
    }

    public function test_missing_or_rejected_human_verification_never_queues_sms(): void
    {
        config(['services.turnstile.secret_key' => 'private-secret-key']);
        Queue::fake();
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

        $this->postJson('/api/v1/store/auth/phone-verification/send', ['phone' => '01012345678'])
            ->assertUnprocessable()->assertJsonValidationErrors('turnstile_token');
        Http::assertNothingSent();
        $this->postJson('/api/v1/store/auth/phone-verification/send', [
            'phone' => '01012345679', 'turnstile_token' => 'invalid-token',
        ])->assertUnprocessable()->assertJsonValidationErrors('turnstile_token');
        Queue::assertNothingPushed();
    }

    public function test_successful_human_verification_queues_sms_with_network_budget_key(): void
    {
        config(['services.turnstile.secret_key' => 'private-secret-key']);
        Queue::fake();
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->postJson('/api/v1/store/auth/phone-verification/send', [
            'phone' => '01012345678', 'turnstile_token' => 'fresh-token',
        ])->assertOk();
        Http::assertSent(fn ($request) => $request['response'] === 'fresh-token'
            && $request['secret'] === 'private-secret-key');
        Queue::assertPushed(SendCustomerPhoneVerification::class, fn ($job) => $job->phone === '01012345678' && $job->networkKey !== null);
        Queue::assertPushed(SendCustomerPhoneVerification::class, 1);
    }

    public function test_verifier_outage_never_queues_sms(): void
    {
        config(['services.turnstile.secret_key' => 'private-secret-key']);
        Queue::fake();
        Http::fake(['challenges.cloudflare.com/*' => Http::failedConnection()]);

        $this->postJson('/api/v1/store/auth/phone-verification/send', [
            'phone' => '01012345678', 'turnstile_token' => 'fresh-token',
        ])->assertUnprocessable()->assertJsonValidationErrors('turnstile_token');
        Queue::assertNothingPushed();
    }
}
