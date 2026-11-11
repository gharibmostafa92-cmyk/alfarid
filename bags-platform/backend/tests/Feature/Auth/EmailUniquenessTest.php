<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\SupplierUser;
use App\Models\User;
use App\Rules\EmailNotUsedByOtherAccountType;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmailUniquenessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_store_registration_rejects_a_staff_email_case_insensitively(): void
    {
        User::factory()->create(['email' => 'identity@example.test']);

        $this->postJson('/api/v1/store/auth/register', [
            'name' => 'New customer', 'phone' => '01088997766',
            'email' => 'IDENTITY@example.test', 'password' => 'customer-pass',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('customers', ['phone' => '01088997766']);
    }

    public function test_public_registration_does_not_reveal_the_owning_account_type(): void
    {
        User::factory()->create(['email' => 'owner@example.test']);

        $response = $this->postJson('/api/v1/store/auth/register', [
            'name' => 'New customer', 'phone' => '01088997767',
            'email' => 'owner@example.test', 'password' => 'customer-pass',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSame(
            'تعذر استخدام بيانات التسجيل المقدمة.',
            (string) $response->json('errors.email.0'),
        );
    }

    private function fails(string $ownTable, ?string $value): bool
    {
        $failed = false;
        (new EmailNotUsedByOtherAccountType($ownTable))->validate('email', $value, function () use (&$failed): void {
            $failed = true;
        });

        return $failed;
    }

    public function test_email_used_by_staff_blocks_customer_registration(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $this->assertTrue($this->fails('customers', 'TAKEN@example.test'));
        $this->assertFalse($this->fails('users', 'taken@example.test'));
    }

    public function test_email_used_by_supplier_user_blocks_staff_creation(): void
    {
        SupplierUser::factory()->create(['email' => 'supplier-taken@example.test']);

        $this->assertTrue($this->fails('users', 'supplier-taken@example.test'));
        $this->assertFalse($this->fails('supplier_users', 'supplier-taken@example.test'));
    }

    public function test_soft_deleted_customer_email_is_reusable(): void
    {
        $customer = Customer::factory()->create(['email' => 'gone@example.test']);
        $customer->delete();

        $this->assertFalse($this->fails('users', 'gone@example.test'));
    }

    public function test_blank_email_is_ignored(): void
    {
        User::factory()->create(['email' => 'someone@example.test']);

        $this->assertFalse($this->fails('customers', null));
        $this->assertFalse($this->fails('customers', '   '));
    }

    public function test_verifying_a_retail_email_rejects_an_address_taken_by_staff(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'later@example.test',
            'email_verified_at' => null,
            'type' => 'retail',
        ]);
        User::factory()->create(['email' => 'later@example.test']);

        $this->expectException(ValidationException::class);
        $customer->forceFill(['email_verified_at' => now()])->save();
    }
}
