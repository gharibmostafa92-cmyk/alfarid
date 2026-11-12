<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::scalar('SELECT EXISTS (SELECT 1 FROM coupons GROUP BY upper(code) HAVING count(*) > 1)')) {
            throw new RuntimeException('Resolve duplicate case-insensitive coupon codes before creating coupons_code_upper_unique.');
        }

        ConcurrentIndex::ensure('coupons_code_upper_unique', 'ON coupons (upper(code))', unique: true);
    }

    public function down(): void
    {
        ConcurrentIndex::drop('coupons_code_upper_unique');
    }
};
