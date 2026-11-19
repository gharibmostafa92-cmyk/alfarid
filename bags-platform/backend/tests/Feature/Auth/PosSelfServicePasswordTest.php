<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PosSelfServicePasswordTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_pos_password_change_does_not_require_a_shift_or_grant_admin_access(): void
    {
        $cashier = User::factory()->create([
            'password' => 'Old-password-12',
            'branch_id' => Branch::factory()->create()->getKey(),
        ])->refresh();
        $cashier->givePermissionTo(Permission::findOrCreate('pos.access', 'staff'));
        $session = $cashier->createToken('pos-session', ['pos']);
        $other = $cashier->createToken('other-pos-session', ['pos']);

        $this->withToken($session->plainTextToken)->putJson('/api/v1/pos/me/password', [
            'current_password' => 'Old-password-12',
            'password' => 'New-password-34',
            'expected_version' => $cashier->lock_version,
        ])->assertOk()->assertExactJson(['data' => ['version' => $cashier->lock_version + 1]]);

        $this->assertTrue(Hash::check('New-password-34', $cashier->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->getKey()]);
        Auth::forgetGuards();
        $this->withToken($session->plainTextToken)->getJson('/api/v1/admin/me')->assertForbidden();
    }
}
