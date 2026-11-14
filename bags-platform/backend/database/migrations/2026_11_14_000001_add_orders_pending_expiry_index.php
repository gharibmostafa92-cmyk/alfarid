<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        ConcurrentIndex::ensure(
            'orders_pending_expiry_idx',
            "ON orders (id, created_at, payment_method) WHERE status = 'pending'",
        );
    }

    public function down(): void
    {
        ConcurrentIndex::drop('orders_pending_expiry_idx');
    }
};
