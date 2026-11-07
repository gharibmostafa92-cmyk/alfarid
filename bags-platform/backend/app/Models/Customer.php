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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;

/**
 * Customer entity representing retail shoppers and wholesale/distributor trading partners.
 */
#[Fillable([
    'name',
    'email',
    'phone',
    'password',
    'type',
    'customer_group_id',
    'company_name',
    'tax_number',
    'commercial_register',
    'status',
    'rejection_reason',
    'suspension_reason',
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
                // Wholesale and distributor accounts require commercial vetting before order placement.
                $customer->status = in_array($customer->type, [CustomerType::Wholesale, CustomerType::Distributor], true)
                    ? CustomerStatus::Pending : CustomerStatus::Approved;
            }
        });
    }

    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    /**
     * Check if the account has shopping and ordering privileges.
     *
     * @return bool True if active or pending; false if suspended or rejected.
     */
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
        // Changing email invalidates prior verification and requires re-confirming ownership.
        if (($this->attributes['email'] ?? null) !== $normalized) {
            $this->attributes['email_verified_at'] = null;
        }
        $this->attributes['email'] = $normalized;
    }

    /** Transliteration map for Eastern Arabic and Persian numerals to ASCII digits. */
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

    /**
     * Standardize Egyptian mobile numbers into canonical local format (01xxxxxxxxx).
     *
     * Transliterates Eastern Arabic/Persian digits, strips punctuation, and normalizes country prefixes.
     *
     * @param  string  $phone  Raw phone number string.
     * @return string Canonical local phone string.
     */
    public static function normalizePhone(string $phone): string
    {
        $trimmed = preg_replace('/^\s+|\s+$/u', '', $phone) ?? trim($phone);
        // Preserve synthetic placeholder identifiers for legacy accounts without valid phones.
        if (preg_match('/^legacy-[0-9]+$/', $trimmed)) {
            return $trimmed;
        }
        // Normalize Arabic-Indic and Eastern Arabic digits into ASCII equivalents.
        $phone = strtr($trimmed, self::ARABIC_PERSIAN_DIGITS_MAP);
        $phone = preg_replace('/[\s().-]+/u', '', $phone);
        if (preg_match('/^201[0125][0-9]{8}$/', $phone)) {
            return '0'.substr($phone, 2);
        }
        if (str_starts_with($phone, '+20')) {
            return '0'.substr($phone, 3);
        }
        if (str_starts_with($phone, '0020')) {
            return '0'.substr($phone, 4);
        }

        return $phone;
    }

    /**
     * Normalize an authentication identifier (email or phone number).
     *
     * @param  string|null  $identifier  Raw email or phone number.
     * @return string|null Canonical lowercase email or normalized Egyptian phone.
     */
    public static function normalizeIdentifier(?string $identifier): ?string
    {
        if ($identifier === null) {
            return null;
        }
        $trimmed = trim($identifier);
        if ($trimmed === '') {
            return '';
        }

        return str_contains($trimmed, '@')
            ? mb_strtolower($trimmed)
            : self::normalizePhone($trimmed);
    }

    /**
     * Map database unique constraint collisions on customer attributes to validation exceptions.
     *
     * @param  \Throwable  $e  Captured database or runtime exception.
     * @return \Throwable Converted ValidationException or original exception.
     */
    public static function translateUniqueViolation(\Throwable $e): \Throwable
    {
        if ($e instanceof UniqueConstraintViolationException) {
            $message = $e->getMessage();
            if (str_contains($message, 'phone')) {
                return ValidationException::withMessages(['phone' => 'رقم الموبايل مسجل بالفعل.']);
            }
            if (str_contains($message, 'email')) {
                return ValidationException::withMessages(['email' => 'البريد الإلكتروني مستخدم بالفعل.']);
            }
        }

        return $e;
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
