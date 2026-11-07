<?php

declare(strict_types=1);

use App\Services\SettingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TREASURY_TYPES = "'opening_balance','pos_sale','pos_return','expense','expense_reversal','transfer_in','transfer_out','supplier_payment','customer_receipt','customer_receipt_reversal','cash_shortage','cash_overage'";

    public function up(): void
    {
        DB::statement('ALTER TABLE treasury_transactions DROP CONSTRAINT IF EXISTS treasury_transactions_type_check');
        DB::statement('ALTER TABLE treasury_transactions ADD CONSTRAINT treasury_transactions_type_check CHECK (type IN ('.self::TREASURY_TYPES.'))');

        DB::statement('ALTER TABLE wholesale_payment_notices DROP CONSTRAINT IF EXISTS wholesale_payment_notices_status_check');
        DB::statement("ALTER TABLE wholesale_payment_notices ADD CONSTRAINT wholesale_payment_notices_status_check CHECK (status::text IN ('pending_review','approved','rejected','reversed'))");
        DB::statement('DROP INDEX IF EXISTS wholesale_payment_notices_receipt_sha256_active');
        DB::statement("CREATE UNIQUE INDEX wholesale_payment_notices_receipt_sha256_active ON wholesale_payment_notices (receipt_sha256) WHERE receipt_sha256 IS NOT NULL AND status::text NOT IN ('rejected', 'reversed')");

        Schema::table('wholesale_payment_notices', function (Blueprint $table): void {
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversal_treasury_transaction_id')->nullable()->constrained('treasury_transactions')->nullOnDelete();
        });

        Schema::table('customer_channels', function (Blueprint $table): void {
            $table->string('verification_code_hash')->nullable();
            $table->timestamp('verification_expires_at')->nullable();
        });

        $now = now();
        DB::table('settings')->insertOrIgnore([
            ['group' => 'ai', 'key' => 'ai_wholesale_shadow_mode', 'value' => '1', 'type' => 'boolean', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'ai', 'key' => 'ai_wholesale_quote_sla_hours', 'value' => '24', 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'ai', 'key' => 'ai_wholesale_notice_sla_hours', 'value' => '24', 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'ai', 'key' => 'ai_wholesale_reminder_weekly_cap', 'value' => '3', 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'ai', 'key' => 'ai_quote_high_risk_total', 'value' => '100000', 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'ai', 'key' => 'ai_whatsapp_allow_notice_approval', 'value' => '0', 'type' => 'boolean', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'ai', 'key' => 'ai_whatsapp_quote_max_discount_percent', 'value' => '5', 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('message_templates')->insertOrIgnore([
            ['name' => 'customer_otp', 'language' => 'ar', 'body' => 'رمز التحقق: {{code}}', 'variables' => json_encode(['code']), 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'payment_reminder', 'language' => 'ar', 'body' => 'تذكير سداد طلب {{order_number}} استحقاقه {{due_at}}.', 'variables' => json_encode(['order_number', 'due_at']), 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'staff_escalation', 'language' => 'ar', 'body' => '{{message}}', 'variables' => json_encode(['message']), 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::afterCommit(fn () => app(SettingService::class)->clearCache());
    }

    public function down(): void
    {
        Schema::table('customer_channels', function (Blueprint $table): void {
            $table->dropColumn(['verification_code_hash', 'verification_expires_at']);
        });
        Schema::table('wholesale_payment_notices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversal_treasury_transaction_id');
            $table->dropColumn('reversed_at');
        });
        DB::statement('ALTER TABLE wholesale_payment_notices DROP CONSTRAINT IF EXISTS wholesale_payment_notices_status_check');
        DB::statement("ALTER TABLE wholesale_payment_notices ADD CONSTRAINT wholesale_payment_notices_status_check CHECK (status::text IN ('pending_review','approved','rejected'))");
        DB::statement('ALTER TABLE treasury_transactions DROP CONSTRAINT IF EXISTS treasury_transactions_type_check');
        DB::statement("ALTER TABLE treasury_transactions ADD CONSTRAINT treasury_transactions_type_check CHECK (type IN ('opening_balance','pos_sale','pos_return','expense','expense_reversal','transfer_in','transfer_out','supplier_payment','customer_receipt','cash_shortage','cash_overage'))");
    }
};
