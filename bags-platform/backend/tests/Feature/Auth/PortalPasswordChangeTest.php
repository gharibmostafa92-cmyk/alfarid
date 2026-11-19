<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Exceptions\BusinessException;
use App\Http\Middleware\ManufacturingBusiness;
use App\Models\Customer;
use App\Models\SupplierUser;
use App\Services\Auth\CustomerAuthService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PortalPasswordChangeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_password_change_keeps_only_the_requesting_session(): void
    {
        $customer = Customer::factory()->create(['password' => 'old-password-12']);
        $current = $customer->createToken('current', ['store-token']);
        $other = $customer->createToken('other', ['store-token']);
        $body = ['current_password' => 'old-password-12', 'password' => 'new-password-34', 'password_confirmation' => 'new-password-34'];

        $this->withToken($current->plainTextToken)->putJson('/api/v1/store/account/password', $body)
            ->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');

        $this->assertTrue(Hash::check('new-password-34', $customer->fresh()->password));
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->getKey()]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->getKey()]);
        Auth::forgetGuards();
        $this->withToken($current->plainTextToken)->putJson('/api/v1/store/account/password', $body)
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_pending_wholesale_account_can_change_password_without_unlocking_the_portal(): void
    {
        $customer = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Pending,
            'password' => 'old-password-12',
        ]);
        $token = $customer->createToken('wholesale', ['wholesale-token']);
        $this->withToken($token->plainTextToken)->putJson('/api/v1/store/account/password', [
            'current_password' => 'old-password-12',
            'password' => 'new-password-34',
            'password_confirmation' => 'new-password-34',
        ])->assertNoContent();

        $this->assertSame(CustomerStatus::Pending, $customer->fresh()->status);
    }

    public function test_portal_routes_reject_other_actor_types(): void
    {
        $supplier = SupplierUser::factory()->create(['password' => 'old-password-12']);
        $token = $supplier->createToken('supplier', ['supplier']);
        $body = ['current_password' => 'old-password-12', 'password' => 'new-password-34', 'password_confirmation' => 'new-password-34'];

        $this->withToken($token->plainTextToken)->putJson('/api/v1/store/account/password', $body)
            ->assertForbidden();

        Auth::forgetGuards();
        $this->withToken($token->plainTextToken)->putJson('/api/v1/supplier/me/password', $body)
            ->assertGone();

        Auth::forgetGuards();
        $this->withoutMiddleware(ManufacturingBusiness::class);
        $this->withToken($token->plainTextToken)->putJson('/api/v1/supplier/me/password', $body)
            ->assertNoContent();

        $this->assertTrue(Hash::check('new-password-34', $supplier->fresh()->password));
    }

    public function test_revoked_credentials_cannot_issue_a_new_token(): void
    {
        $customer = Customer::factory()->create(['password' => 'old-password-12']);
        $verified = $customer->fresh();
        $customer->update(['password' => 'new-password-34']);

        $this->expectException(BusinessException::class);
        app(CustomerAuthService::class)->issueToken($verified, 'store-token');
    }
}
