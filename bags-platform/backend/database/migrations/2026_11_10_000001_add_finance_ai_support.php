<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('treasury_transfers', 'rejection_reason')) {
            Schema::table('treasury_transfers', function (Blueprint $table): void {
                $table->string('rejection_reason', 500)->nullable()->after('approved_by');
            });
        }

        if (! Schema::hasTable('wallet_adjustments')) {
            Schema::create('wallet_adjustments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->restrictOnDelete();
                $table->decimal('amount', 12, 2);
                $table->enum('direction', ['credit', 'debit']);
                $table->string('reason', 500);
                $table->enum('source', ['admin', 'ai']);
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->ulid('ai_action_id')->nullable();
                $table->foreignId('reverses_id')->nullable()->constrained('wallet_adjustments')->nullOnDelete();
                $table->timestamp('created_at')->useCurrent();

                $table->index('customer_id');
                $table->index('ai_action_id');
            });
        }

        // The money-trail tool queries treasury_transactions by polymorphic
        // reference; the existing migrations only index treasury_id/shift_id.
        $referenceIndex = DB::selectOne(
            "SELECT 1 FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'treasury_transactions_reference_index'"
        );
        if ($referenceIndex === null) {
            Schema::table('treasury_transactions', function (Blueprint $table): void {
                $table->index(['reference_type', 'reference_id'], 'treasury_transactions_reference_index');
            });
        }

        // WalletService::record() writes created_by, but the original wallet
        // migration file does not show the column; backfill it when missing.
        if (! Schema::hasColumn('customer_wallet_transactions', 'created_by')) {
            Schema::table('customer_wallet_transactions', function (Blueprint $table): void {
                $table->foreignId('created_by')->nullable()->after('reference_id')->constrained('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_wallet_transactions', 'created_by')) {
            Schema::table('customer_wallet_transactions', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('created_by');
            });
        }

        Schema::table('treasury_transactions', function (Blueprint $table): void {
            $table->dropIndex('treasury_transactions_reference_index');
        });

        Schema::dropIfExists('wallet_adjustments');

        if (Schema::hasColumn('treasury_transfers', 'rejection_reason')) {
            Schema::table('treasury_transfers', function (Blueprint $table): void {
                $table->dropColumn('rejection_reason');
            });
        }
    }
};
