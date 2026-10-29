<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Concerns\SerializesAccountEmail;
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
    'phone_verified_at',
])]
#[Hidden(['password', 'remember_token'])]
class Customer extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;
    use SerializesAccountEmail;

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

    public function canShop(): bool
    {
        return ! in_array($this->status, [CustomerStatus::Suspended, CustomerStatus::Rejected], true);
    }

    public function getEmailForPasswordReset(): string
    {
        return 'customer:'.$this->getKey();
    }

    public function setEmailAttribute(?string $email): void
    {
        $normalized = $email === null ? null : mb_strtolower(trim($email));
        if (($this->attributes['email'] ?? null) !== $normalized) {
            $this->attributes['email_verified_at'] = null;
        }
        $this->attributes['email'] = $normalized;
    }

    /** خريطة تحويل الأرقام العربية والفارسية إلى أرقام لاتينية */
    private const ARABIC_PERSIAN_DIGITS_MAP = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public function setPhoneAttribute(string $phone): void
    {
        $this->attributes['phone'] = self::normalizePhone($phone);
    }

    public static function normalizePhone(string $phone): string
    {
        $phone = strtr(trim($phone), self::ARABIC_PERSIAN_DIGITS_MAP);
        $phone = preg_replace('/[\s().-]+/u', '', $phone);
        if (str_starts_with($phone, '+20')) {
            return '0'.substr($phone, 3);
        }
        if (str_starts_with($phone, '0020')) {
            return '0'.substr($phone, 4);
        }

        return $phone;
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(CustomerAuditLog::class);
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

    public function returnRequests(): HasMany
    {
        return $this->hasMany(ReturnRequest::class);
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'type' => CustomerType::class,
            'status' => CustomerStatus::class,
            'credit_limit' => 'decimal:2',
            'wallet_balance' => 'decimal:2',
            'approved_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
        ];
    }
}
