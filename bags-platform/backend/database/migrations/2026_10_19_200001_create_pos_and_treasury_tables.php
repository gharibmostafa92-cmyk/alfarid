<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ===== الخزائن المالية =====
        Schema::create('treasuries', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->enum('type', ['main_safe', 'pos_drawer', 'bank_account']);
            $table->decimal('current_balance', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('branch_id');
            $table->index(['branch_id', 'type']);
            $table->index('is_active');
        });

        // ===== ورديات الكاشير =====
        Schema::create('pos_shifts', function (Blueprint $table): void {
            $table->id();
            $table->string('shift_number')->unique();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('treasury_id')->constrained('treasuries');
            $table->enum('status', ['open', 'closed_pending_approval', 'approved'])->default('open');

            // التوقيتات
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            // الأرقام المالية
            $table->decimal('opening_cash', 12, 2)->default(0);
            $table->decimal('cash_sales_total', 12, 2)->default(0);
            $table->decimal('card_sales_total', 12, 2)->default(0);
            $table->decimal('cash_returns_total', 12, 2)->default(0);
            $table->decimal('cash_expenses_total', 12, 2)->default(0);
            $table->decimal('expected_closing_cash', 12, 2)->default(0);
            $table->decimal('actual_closing_cash', 12, 2)->nullable();
            $table->decimal('cash_difference', 12, 2)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('user_id');
            $table->index('branch_id');
            $table->index('treasury_id');
            $table->index('status');
            $table->index(['user_id', 'status']);
            $table->index(['treasury_id', 'status']);
        });

        // ===== حركات الخزينة (دفتر أستاذ مالي) =====
        Schema::create('treasury_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('treasury_id')->constrained('treasuries');
            $table->foreignId('shift_id')->nullable()->constrained('pos_shifts')->nullOnDelete();
            $table->enum('type', [
                'opening_balance',
                'pos_sale',
                'pos_return',
                'expense',
                'transfer_in',
                'transfer_out',
                'supplier_payment',
                'customer_receipt',
            ]);
            $table->decimal('amount', 14, 2); // موجب للقبض، سالب للصرف
            $table->decimal('balance_after', 14, 2);
            $table->nullableMorphs('reference');  // reference_type, reference_id
            $table->foreignId('created_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('treasury_id');
            $table->index('shift_id');
            $table->index(['treasury_id', 'created_at']);
        });

        // ===== المصروفات =====
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->string('expense_number')->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('treasury_id')->constrained('treasuries');
            $table->foreignId('shift_id')->nullable()->constrained('pos_shifts')->nullOnDelete();
            $table->string('category');
            $table->decimal('amount', 12, 2);
            $table->date('expense_date');
            $table->string('attachment_path')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('branch_id');
            $table->index('treasury_id');
            $table->index('shift_id');
            $table->index('expense_date');
        });

        // ===== تحويلات الخزائن =====
        Schema::create('treasury_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_number')->unique();
            $table->foreignId('from_treasury_id')->constrained('treasuries');
            $table->foreignId('to_treasury_id')->constrained('treasuries');
            $table->decimal('amount', 14, 2);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('from_treasury_id');
            $table->index('to_treasury_id');
            $table->index('status');
        });

        // ===== الفواتير المعلقة =====
        Schema::create('pos_held_sales', function (Blueprint $table): void {
            $table->id();
            $table->string('hold_reference')->unique();
            $table->foreignId('branch_id')->constrained('branches');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->json('items_payload');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('branch_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_held_sales');
        Schema::dropIfExists('treasury_transfers');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('treasury_transactions');
        Schema::dropIfExists('pos_shifts');
        Schema::dropIfExists('treasuries');
    }
};
