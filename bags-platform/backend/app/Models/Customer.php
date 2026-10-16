<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name',
    'email',
    'phone',
    'password',
    'type',
    'status',
    'customer_group_id',
    'company_name',
    'tax_number',
    'commercial_register',
    'credit_limit',
    'rejection_reason',
    'suspension_reason',
    'approved_at',
    'approved_by',
])]
#[Hidden(['password', 'remember_token'])]
class Customer extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Customer $customer): void {
            if (! array_key_exists('status', $customer->getAttributes())) {
                $customer->status = in_array($customer->type, [CustomerType::Wholesale, CustomerType::Distributor], true)
                    ? CustomerStatus::Pending : CustomerStatus::Approved;
            }
        });
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    public function getEmailForPasswordReset(): string
    {
        return 'customer:'.$this->getKey();
    }

    public function setEmailAttribute(?string $email): void
    {
        $this->attributes['email'] = $email === null ? null : mb_strtolower(trim($email));
    }

    public function setPhoneAttribute(string $phone): void
    {
        $this->attributes['phone'] = trim($phone);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CustomerDocument::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'type' => CustomerType::class,
            'status' => CustomerStatus::class,
            'credit_limit' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }
}
