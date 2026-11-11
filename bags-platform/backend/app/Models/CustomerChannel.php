<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\BusinessException;
use App\Support\ArabicDigits;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks third-party messaging channels (such as WhatsApp Business) linked to customer accounts.
 */
class CustomerChannel extends Model
{
    protected $fillable = [
        'customer_id',
        'phone',
        'provider',
        'verification_status',
        'opt_in_at',
        'verified_at',
        'verification_expires_at',
    ];

    /**
     * M6: the OTP code hash brute-forces offline in fractions of a second, so
     * it must never leave the model through toArray()/toJson(), and it is set
     * explicitly via forceFill() in the service instead of mass assignment.
     *
     * @var list<string>
     */
    protected $hidden = ['verification_code_hash'];

    protected function casts(): array
    {
        return [
            'opt_in_at' => 'datetime',
            'verified_at' => 'datetime',
            'verification_expires_at' => 'datetime',
        ];
    }

    public static function canonicalPhone(string $phone): ?string
    {
        $phone = preg_replace('/[\s().-]+/u', '', ArabicDigits::normalize($phone)) ?? '';
        $local = Customer::normalizePhone($phone);
        if (preg_match('/\A01[0125][0-9]{8}\z/', $local) === 1) {
            return '+20'.substr($local, 1);
        }

        return preg_match('/\A\+[1-9][0-9]{1,14}\z/', $phone) === 1 ? $phone : null;
    }

    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (string $phone) => self::canonicalPhone($phone)
            ?? throw new BusinessException('رقم قناة واتساب غير صالح.'));
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
