<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalizes normalized guest phone onto coupon usages so guest usage limits can be checked
 * via an indexed count query instead of in-memory collection scans while holding coupon row locks.
 */
return new class extends Migration
{
    /**
     * F22: frozen copy of the phone normalization rules as of this migration.
     * Migrations must not call App\Models\Customer (renames/deletes would
     * break already-applied deploys on fresh installs).
     */
    private const ARABIC_DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    private static function normalizePhone(string $phone): string
    {
        $trimmed = preg_replace('/^\s+|\s+$/u', '', $phone) ?? trim($phone);
        if (preg_match('/^legacy-[0-9]+$/', $trimmed)) {
            return $trimmed;
        }
        $phone = strtr($trimmed, self::ARABIC_DIGITS);
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

    public function up(): void
    {
        Schema::table('coupon_usages', function (Blueprint $table): void {
            $table->string('guest_phone_normalized', 32)->nullable();
            $table->index(['coupon_id', 'guest_phone_normalized']);
        });

        DB::table('coupon_usages')
            ->join('orders', 'orders.id', '=', 'coupon_usages.order_id')
            ->whereNull('coupon_usages.customer_id')
            ->whereNotNull('orders.guest_phone')
            ->orderBy('coupon_usages.id')
            ->select('coupon_usages.id as usage_id', 'orders.guest_phone')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('coupon_usages')->where('id', $row->usage_id)
                        ->update(['guest_phone_normalized' => self::normalizePhone((string) $row->guest_phone)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('coupon_usages', function (Blueprint $table): void {
            $table->dropIndex(['coupon_id', 'guest_phone_normalized']);
            $table->dropColumn('guest_phone_normalized');
        });
    }
};
