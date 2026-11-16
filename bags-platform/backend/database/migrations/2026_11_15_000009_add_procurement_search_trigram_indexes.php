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
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        ConcurrentIndex::ensure('purchase_orders_po_number_trgm', 'ON purchase_orders USING GIN (po_number gin_trgm_ops)');
        ConcurrentIndex::ensure('suppliers_name_trgm', 'ON suppliers USING GIN (name gin_trgm_ops)');
        ConcurrentIndex::ensure('suppliers_code_trgm', 'ON suppliers USING GIN (code gin_trgm_ops)');
        ConcurrentIndex::ensure('suppliers_company_name_trgm', 'ON suppliers USING GIN (company_name gin_trgm_ops)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        ConcurrentIndex::drop('suppliers_company_name_trgm');
        ConcurrentIndex::drop('suppliers_code_trgm');
        ConcurrentIndex::drop('suppliers_name_trgm');
        ConcurrentIndex::drop('purchase_orders_po_number_trgm');
    }
};
