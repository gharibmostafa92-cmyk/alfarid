<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 5.1: استكمال بنية العملاء.
 * - customers: حالة suspended بدل blocked، بريد nullable، هاتف unique وإلزامي،
 *   وبيانات الجملة والاعتماد (شركة/ائتمان/أسباب/اعتماد) + حذف ناعم.
 * - جداول جديدة: customer_documents وcities وcustomer_addresses.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- customers: الحالة suspended بدل blocked (بيانات قديمة تُرحَّل) ---
        DB::statement("UPDATE customers SET status = 'suspended' WHERE status = 'blocked'");
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_status_check');
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'suspended'))");
        // الافتراضي العام approved؛ تسجيل الجملة يفرض pending برمجيًا (مهمة 5.3)
        DB::statement("ALTER TABLE customers ALTER COLUMN status SET DEFAULT 'approved'");

        // --- البريد يصبح اختياريًا، والهاتف هو المعرّف الإلزامي الفريد ---
        DB::statement('ALTER TABLE customers ALTER COLUMN email DROP NOT NULL');
        DB::statement("UPDATE customers SET phone = 'legacy-' || id WHERE phone IS NULL");
        DB::statement('ALTER TABLE customers ALTER COLUMN phone SET NOT NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS customers_phone_unique ON customers (phone)');

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('company_name')->nullable();
            $table->decimal('credit_limit', 12, 2)->default(0);
            $table->text('rejection_reason')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });

        Schema::create('customer_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->enum('document_type', ['commercial_register', 'tax_card', 'national_id', 'other'])->default('other');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->enum('status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained();
            $table->string('address_line');
            $table->string('building_no')->nullable();
            $table->string('floor_no')->nullable();
            $table->string('apartment_no')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['customer_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('customer_documents');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['company_name', 'credit_limit', 'rejection_reason', 'suspension_reason', 'approved_at']);
            $table->dropSoftDeletes();
        });

        DB::statement('DROP INDEX IF EXISTS customers_phone_unique');
        DB::statement('ALTER TABLE customers ALTER COLUMN phone DROP NOT NULL');
        DB::statement('ALTER TABLE customers ALTER COLUMN email SET NOT NULL');
        DB::statement("UPDATE customers SET status = 'blocked' WHERE status = 'suspended'");
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_status_check');
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'blocked'))");
        DB::statement("ALTER TABLE customers ALTER COLUMN status SET DEFAULT 'pending'");
    }
};
