<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        ConcurrentIndex::ensure('stock_movements_physical_activity_index', "ON stock_movements (product_variant_id, created_at DESC) WHERE type NOT IN ('reserve', 'release_reserve')");
    }

    public function down(): void
    {
        ConcurrentIndex::drop('stock_movements_physical_activity_index');
    }
};
