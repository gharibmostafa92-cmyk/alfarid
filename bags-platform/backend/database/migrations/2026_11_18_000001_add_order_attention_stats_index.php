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

        ConcurrentIndex::ensure(
            'orders_admin_queue_stats_idx',
            "ON orders (allocated_branch_id, status) INCLUDE (needs_attention, requires_refund) WHERE status IN ('pending', 'processing') OR needs_attention IS TRUE OR requires_refund IS TRUE",
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            ConcurrentIndex::drop('orders_admin_queue_stats_idx');
        }
    }
};
