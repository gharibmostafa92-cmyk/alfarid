<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\Customer\CustomerStatus;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Notifications\Auth\CustomerPasswordReset;
use App\Services\Auth\CustomerAuthService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CommercialUnverifiedPasswordResetTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unverified_commercial_customer_can_reset_with_the_login_email(): void
    {
        Notification::fake();
        $customer = Customer::factory()->wholesale()->create([
            'email' => 'trader@example.test',
            'status' => CustomerStatus::Approved,
        ]);
        $customer->forceFill(['email_verified_at' => null])->save();
        $this->assertNull($customer->fresh()->email_verified_at);

        app(CustomerAuthService::class)->sendPasswordReset('trader@example.test', null);

        $token = '';
        Notification::assertSentTo($customer, CustomerPasswordReset::class, function (CustomerPasswordReset $notification) use (&$token, $customer): bool {
            $sms = $notification->toSms($customer);
            parse_str((string) parse_url($sms, PHP_URL_QUERY), $query);
            $token = (string) ($query['token'] ?? '');

            return strlen($token) === 64;
        });

        app(CustomerAuthService::class)->resetPassword([
            'email' => 'trader@example.test',
            'token' => $token,
            'password' => 'wholesale-pass-12',
        ]);

        $this->assertTrue(Hash::check('wholesale-pass-12', (string) $customer->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $customer->getEmailForPasswordReset()]);
    }

    public function test_unknown_email_reset_does_not_lock_a_null_token_row(): void
    {
        $before = DB::table('password_reset_tokens')->count();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        try {
            app(CustomerAuthService::class)->resetPassword([
                'email' => 'nobody@example.test',
                'token' => str_repeat('a', 64),
                'password' => 'new-password-123',
            ]);
            $this->fail('Unknown reset tokens must fail generically.');
        } catch (BusinessException $exception) {
            $this->assertSame('رمز الاستعادة غير صالح أو انتهت صلاحيته.', $exception->getMessage());
        }

        $tokenQueries = array_values(array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'password_reset_tokens')
        ));
        $this->assertSame([], $tokenQueries);
        $this->assertSame($before, DB::table('password_reset_tokens')->count());
    }
}
