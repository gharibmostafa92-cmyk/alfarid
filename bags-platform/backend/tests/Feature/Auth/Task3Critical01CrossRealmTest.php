<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\SupplierUser;
use App\Rules\EmailNotUsedByOtherAccountType;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// task3 CRITICAL-01: unverified retail emails must block cross-realm reuse.
class Task3Critical01CrossRealmTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fails(string $ownTable, ?string $value): bool
    {
        $failed = false;
        (new EmailNotUsedByOtherAccountType($ownTable))->validate('email', $value, function () use (&$failed): void {
            $failed = true;
        });

        return $failed;
    }

    public function test_unverified_retail_email_blocks_staff_creation(): void
    {
        Customer::factory()->create([
            'email' => 'shadow@example.test',
            'email_verified_at' => null,
            'type' => 'retail',
        ]);

        $this->assertTrue($this->fails('users', 'shadow@example.test'));
    }

    public function test_unverified_retail_email_blocks_supplier_creation(): void
    {
        Customer::factory()->create([
            'email' => 'shadow-supplier@example.test',
            'email_verified_at' => null,
            'type' => 'retail',
        ]);

        $this->assertTrue($this->fails('supplier_users', 'shadow-supplier@example.test'));
    }

    public function test_saving_hook_rejects_supplier_email_shadowed_by_unverified_retail(): void
    {
        Customer::factory()->create([
            'email' => 'hook-shadow@example.test',
            'email_verified_at' => null,
            'type' => 'retail',
        ]);

        $this->expectException(ValidationException::class);
        SupplierUser::factory()->create(['email' => 'hook-shadow@example.test']);
    }
}
