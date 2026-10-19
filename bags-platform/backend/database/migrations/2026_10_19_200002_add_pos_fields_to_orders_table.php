<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // ربط الطلب بوردية الكاشير عند البيع عبر الـ POS
            $table->foreignId('pos_shift_id')
                ->nullable()
                ->after('allocated_branch_id')
                ->constrained('pos_shifts')
                ->nullOnDelete();

            // دعم الدفع المختلط: جزء نقدي وجزء بطاقة
            $table->decimal('cash_amount', 12, 2)->nullable()->after('grand_total');
            $table->decimal('card_amount', 12, 2)->nullable()->after('cash_amount');

            $table->index('pos_shift_id');
        });

        // تحديث قيد التحقق لطريقة الدفع لدعم البيع النقدي والمختلط في الـ POS
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_payment_method_check");
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_method_check CHECK (payment_method IS NULL OR payment_method IN ('cod', 'card', 'wallet', 'bank_transfer', 'credit', 'cash', 'mixed'))");
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_payment_method_check");
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_payment_method_check CHECK (payment_method IS NULL OR payment_method IN ('cod', 'card', 'wallet', 'bank_transfer', 'credit'))");

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['pos_shift_id']);
            $table->dropColumn(['pos_shift_id', 'cash_amount', 'card_amount']);
        });
    }
};
