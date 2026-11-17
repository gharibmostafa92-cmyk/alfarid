<?php

namespace App\Models;

use App\Models\Concerns\SerializesAccountEmail;
use App\Models\Concerns\TracksEditVersion;
use Database\Factories\UserFactory;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'password', 'branch_id', 'is_active', 'must_change_password'])]
#[Hidden(['password', 'remember_token'])]
/**
 * Staff employee account with role-based permissions, branch constraints, and activity tracking.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    use SerializesAccountEmail;
    use TracksEditVersion;

    protected string $guard_name = 'staff';

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function setEmailAttribute(string $email): void
    {
        $this->attributes['email'] = mb_strtolower(trim($email));
    }

    public function setPhoneAttribute(?string $phone): void
    {
        if ($phone === null) {
            $this->attributes['phone'] = null;

            return;
        }
        $normalized = \App\Support\ArabicDigits::normalize($phone);
        $normalized = preg_replace('/[\p{Cf}]+/u', '', $normalized) ?? $normalized;
        $this->attributes['phone'] = trim($normalized);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }
}
