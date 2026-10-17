<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '2s'");
        Schema::table('customers', function (Blueprint $table): void {
            $table->timestamp('phone_verified_at')->nullable();
        });
        Schema::table('customer_documents', function (Blueprint $table): void {
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
        });
        Schema::create('customer_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'created_at']);
        });
        Schema::create('customer_message_budgets', function (Blueprint $table): void {
            $table->string('key', 64);
            $table->date('date')->index();
            $table->unsignedSmallInteger('sends')->default(0);
            $table->primary(['key', 'date']);
        });
        Schema::create('customer_phone_verifications', function (Blueprint $table): void {
            $table->string('phone_key', 64)->primary();
            $table->string('code_hash');
            $table->string('proof_hash')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('expires_at')->index();
        });
        DB::statement("ALTER TABLE customers ALTER COLUMN status SET DEFAULT 'pending'");
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_nonnegative_credit CHECK (credit_limit >= 0) NOT VALID');
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_approved_business CHECK (
            status <> 'approved' OR type = 'retail' OR (
                NULLIF(TRIM(company_name), '') IS NOT NULL AND NULLIF(TRIM(tax_number), '') IS NOT NULL
                AND NULLIF(TRIM(commercial_register), '') IS NOT NULL
            )) NOT VALID");
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_valid_phone CHECK (phone ~ '^01[0125][0-9]{8}$' OR phone ~ '^legacy-[0-9]+$') NOT VALID");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_nonnegative_credit');
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_approved_business');
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_valid_phone');
        DB::statement("ALTER TABLE customers ALTER COLUMN status SET DEFAULT 'approved'");
        Schema::dropIfExists('customer_phone_verifications');
        Schema::dropIfExists('customer_message_budgets');
        Schema::dropIfExists('customer_audit_logs');
        Schema::table('customer_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn('reviewed_at');
        });
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('phone_verified_at'));
    }
};
