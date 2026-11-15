<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE return_requests DROP CONSTRAINT IF EXISTS return_requests_refund_method_check');
        DB::statement("ALTER TABLE return_requests ADD CONSTRAINT return_requests_refund_method_check CHECK (refund_method IS NULL OR refund_method IN ('original_payment', 'wallet', 'bank_transfer', 'cash', 'credit_note'))");
    }

    public function down(): void
    {
        if (DB::table('return_requests')->where('refund_method', 'credit_note')->exists()) {
            throw new RuntimeException('Cannot remove credit_note while credit-note return records exist.');
        }

        DB::statement('ALTER TABLE return_requests DROP CONSTRAINT IF EXISTS return_requests_refund_method_check');
        DB::statement("ALTER TABLE return_requests ADD CONSTRAINT return_requests_refund_method_check CHECK (refund_method IS NULL OR refund_method IN ('original_payment', 'wallet', 'bank_transfer', 'cash'))");
    }
};
