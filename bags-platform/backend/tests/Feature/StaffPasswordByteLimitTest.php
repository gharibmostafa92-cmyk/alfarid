<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffPasswordByteLimitTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_creation_rejects_passwords_above_72_bytes_without_creating_an_account(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('password-validation')->plainTextToken;
        foreach ([str_repeat('a', 79).'1', str_repeat('ع', 39).'a1'] as $password) {
            Auth::forgetGuards();
            $this->withToken($token)->postJson('/api/v1/admin/users', [
                'name' => 'Byte limit test', 'email' => 'too-long@example.test', 'password' => $password,
            ])->assertUnprocessable()->assertJsonValidationErrors('password')
                ->assertJsonPath('errors.password.0', 'كلمة المرور يجب ألا تتجاوز 72 بايت.');
            $this->assertDatabaseMissing('users', ['email' => 'too-long@example.test']);
        }
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/login', [
            'login' => 'too-long@example.test', 'password' => str_repeat('a', 72),
        ])->assertUnauthorized();
    }

    public function test_update_rejects_multibyte_overflow_without_changing_password_version_or_sessions(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create(['password' => 'Existing-password12'])->refresh();
        $hash = $target->password;
        $version = $target->lock_version;
        $target->createToken('existing-session');
        $this->withToken($admin->createToken('password-validation')->plainTextToken)
            ->patchJson('/api/v1/admin/users/'.$target->id, [
                'expected_version' => $version, 'password' => str_repeat('ع', 36).'1',
            ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $target->refresh();
        $this->assertSame($hash, $target->password);
        $this->assertSame($version, $target->lock_version);
        $this->assertSame(1, $target->tokens()->count());
    }

    public function test_creation_and_update_accept_exactly_72_bytes(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('password-validation')->plainTextToken;
        $password = str_repeat('a', 71).'1';
        $response = $this->withToken($token)->postJson('/api/v1/admin/users', [
            'name' => 'Boundary test', 'email' => 'boundary@example.test', 'password' => $password,
        ])->assertCreated();
        $target = User::findOrFail($response->json('data.id'));
        $this->assertTrue(Hash::check($password, $target->password));
        $replacement = str_repeat('ع', 35).'a1';
        Auth::forgetGuards();
        $this->withToken($token)->patchJson('/api/v1/admin/users/'.$target->id, [
            'expected_version' => $target->lock_version, 'password' => $replacement,
        ])->assertOk();
        $this->assertTrue(Hash::check($replacement, $target->fresh()->password));
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole(Role::findOrCreate('Super Admin', 'staff'));
    }
}
