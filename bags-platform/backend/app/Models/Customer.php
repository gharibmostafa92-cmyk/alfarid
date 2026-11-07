<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Concerns\SerializesAccountEmail;
use App\Support\ArabicDigits;
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
    'whatsapp_phone',
    'whatsapp_opt_in_at',
    'preferred_contact_hours',
    'payment_terms_days',
    'credit_limit',
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
/**
 * Customer account entity supporting retail guests, registered shoppers, and wholesale merchants.
 */
class Customer extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;
    use SerializesAccountEmail;

    protected static function booted(): void
    {
        static::creating(function (Customer $customer): void {
            if (! array_key_exists('status', $customer->getAttributes())) {
                // Wholesale and distributor accounts require commercial vetting before order placement.
                $customer->status = $customer->isCommercialTier()
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

    /**
     * Check if the account belongs to wholesale or distributor commercial tiers.
     */
    public function isCommercialTier(): bool
    {
        return in_array($this->type, [CustomerType::Wholesale, CustomerType::Distributor], true);
    }

    /**
     * Check if the account has approved commercial status (wholesale or distributor).
     */
    public function isWholesaleApproved(): bool
    {
        return $this->status === CustomerStatus::Approved && $this->isCommercialTier();
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

    public function setPhoneAttribute(string $phone): void
    {
        $this->attributes['phone'] = self::normalizePhone($phone);
    }

    public function setWhatsappPhoneAttribute(?string $phone): void
    {
        $trimmed = $phone === null ? '' : trim($phone);
        $this->attributes['whatsapp_phone'] = $trimmed === '' ? null : self::normalizePhone($trimmed);
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
        $phone = ArabicDigits::normalize($trimmed);
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
     * Scope query to find customer by either canonical primary phone or secondary WhatsApp phone.
     */
    public function scopeWhereAnyPhone($query, string $phone)
    {
        $normalized = self::normalizePhone($phone);

        return $query->where(fn ($q) => $q->where('phone', $normalized)->orWhere('whatsapp_phone', $normalized));
    }

    /**
     * Scope query to commercial tier accounts (wholesale and distributor).
     */
    public function scopeCommercial($query)
    {
        return $query->whereIn('type', [CustomerType::Wholesale, CustomerType::Distributor]);
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
            'payment_terms_days' => 'integer',
            'preferred_contact_hours' => 'array',
            'approved_at' => 'datetime',
            'whatsapp_opt_in_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
        ];
    }
}
