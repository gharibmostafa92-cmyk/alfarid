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
            'customers_live_verified_email_unique',
            'ON customers (lower(email)) WHERE email_verified_at IS NOT NULL AND deleted_at IS NULL',
            unique: true,
        );
        ConcurrentIndex::drop('customers_verified_email_unique');
    }

    public function down(): void
    {
        ConcurrentIndex::ensure(
            'customers_verified_email_unique',
            'ON customers (lower(email)) WHERE email_verified_at IS NOT NULL',
            unique: true,
        );
        ConcurrentIndex::drop('customers_live_verified_email_unique');
    }
};
