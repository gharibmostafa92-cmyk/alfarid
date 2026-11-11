<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/* F22: Spatie/SettingService calls inlined as raw DB + Cache calls; no app coupling. */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // The schema/data work commits atomically. Its last table marks that
        // phase complete if either concurrent index build needs to be retried.
        if (! Schema::hasTable('inbound_messages')) {
            DB::transaction(function (): void {
                DB::statement("SET LOCAL lock_timeout = '3s'");
                $this->prepareTables();
            });
        }

        ConcurrentIndex::ensure('orders_payment_method_payment_status_due_at_index', 'ON orders (payment_method, payment_status, due_at)');
        ConcurrentIndex::ensure('wholesale_payment_notices_reference_number_index', 'ON wholesale_payment_notices (reference_number)');
        ConcurrentIndex::ensure('wholesale_payment_notices_receipt_sha256_active', "ON wholesale_payment_notices (receipt_sha256) WHERE receipt_sha256 IS NOT NULL AND status <> 'rejected'", unique: true);
    }

    private function prepareTables(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('credit_limit');
            $table->string('whatsapp_phone')->nullable()->after('phone');
            $table->timestamp('whatsapp_opt_in_at')->nullable()->after('whatsapp_phone');
            $table->json('preferred_contact_hours')->nullable()->after('whatsapp_opt_in_at');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('due_at')->nullable()->after('payment_status');
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1)->after('status');
            $table->timestamp('priced_at')->nullable()->after('valid_until');
            $table->foreignId('priced_by')->nullable()->after('priced_at')->constrained('users')->nullOnDelete();
            $table->string('source', 20)->default('portal')->after('priced_by');
        });

        Schema::table('wholesale_payment_notices', function (Blueprint $table): void {
            $table->timestamp('approved_at')->nullable()->after('reviewed_by');
            $table->char('receipt_sha256', 64)->nullable()->after('receipt_file_path');
            $table->json('ocr_payload')->nullable()->after('receipt_sha256');
            $table->decimal('match_score', 5, 2)->nullable()->after('ocr_payload');
        });

        DB::table('wholesale_payment_notices')
            ->where('status', 'approved')
            ->whereNull('approved_at')
            ->update(['approved_at' => DB::raw('updated_at')]);

        Schema::create('customer_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('phone');
            $table->string('provider', 40);
            $table->string('verification_status', 20)->default('unverified');
            $table->timestamp('opt_in_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'provider']);
            $table->unique(['provider', 'phone']);
        });

        Schema::create('message_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('language', 10)->default('ar');
            $table->text('body');
            $table->json('variables')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamps();
        });

        Schema::create('outbound_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('channel', 40);
            $table->string('idempotency_key')->unique();
            $table->string('status', 20)->default('pending');
            $table->string('template')->nullable();
            $table->json('payload');
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
        });

        Schema::create('inbound_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_message_id')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->json('payload');
            $table->timestamps();
        });

        $now = now();
        DB::table('message_templates')->insertOrIgnore([
            ['name' => 'quotation_priced', 'language' => 'ar', 'body' => 'عرض السعر {{quotation_number}} اتسعّر بإجمالي {{grand_total}}.', 'variables' => json_encode(['quotation_number', 'grand_total']), 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'quotation_expiring', 'language' => 'ar', 'body' => 'عرض السعر {{quotation_number}} هينتهي يوم {{valid_until}}.', 'variables' => json_encode(['quotation_number', 'valid_until']), 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'payment_notice_submitted', 'language' => 'ar', 'body' => 'استلمنا إشعار السداد {{notice_number}} بمبلغ {{amount}}.', 'variables' => json_encode(['notice_number', 'amount']), 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'payment_notice_reviewed', 'language' => 'ar', 'body' => 'إشعار السداد {{notice_number}} حالته {{status}}.', 'variables' => json_encode(['notice_number', 'status']), 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'order_overdue_reminder', 'language' => 'ar', 'body' => 'طلب {{order_number}} متأخر من {{due_at}}.', 'variables' => json_encode(['order_number', 'due_at']), 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'staff_daily_briefing', 'language' => 'ar', 'body' => '{{message}}', 'variables' => json_encode(['message']), 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('settings')->insertOrIgnore([
            ['group' => 'ai', 'key' => 'ai_wholesale_tools_enabled', 'value' => '0', 'type' => 'boolean', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'ai', 'key' => 'ai_wholesale_receipt_vision_enabled', 'value' => '0', 'type' => 'boolean', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'ai', 'key' => 'ai_quote_high_risk_discount_percent', 'value' => '15', 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'policy', 'key' => 'wholesale_fifo_mode', 'value' => 'strict', 'type' => 'string', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'policy', 'key' => 'wholesale_default_payment_terms_days', 'value' => '30', 'type' => 'integer', 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('permissions')->insertOrIgnore(['name' => 'ai_agent.wholesale', 'guard_name' => 'staff', 'created_at' => $now, 'updated_at' => $now]);
        $permissionId = (int) DB::table('permissions')->where('name', 'ai_agent.wholesale')->where('guard_name', 'staff')->value('id');
        $roleId = DB::table('roles')->where('name', 'Super Admin')->where('guard_name', 'staff')->value('id');
        if ($roleId !== null) {
            DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }
        Cache::forget('spatie.permission.cache');
        Cache::forget('spatie.permission.cache.v2');

        DB::afterCommit(fn () => Cache::forever('app_settings_static_version', (string) Str::uuid()));
        DB::afterCommit(fn () => Cache::forget('app_settings_static'));
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_messages');
        Schema::dropIfExists('outbound_messages');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('customer_channels');

        ConcurrentIndex::drop('wholesale_payment_notices_receipt_sha256_active');
        ConcurrentIndex::drop('wholesale_payment_notices_reference_number_index');
        ConcurrentIndex::drop('orders_payment_method_payment_status_due_at_index');

        Schema::table('wholesale_payment_notices', function (Blueprint $table): void {
            $table->dropColumn(['approved_at', 'receipt_sha256', 'ocr_payload', 'match_score']);
        });

        Schema::table('quotations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('priced_by');
            $table->dropColumn(['revision', 'priced_at', 'source']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('due_at');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['payment_terms_days', 'whatsapp_phone', 'whatsapp_opt_in_at', 'preferred_contact_hours']);
        });

        DB::table('settings')->whereIn('key', [
            'ai_wholesale_tools_enabled',
            'ai_wholesale_receipt_vision_enabled',
            'ai_quote_high_risk_discount_percent',
            'wholesale_fifo_mode',
            'wholesale_default_payment_terms_days',
        ])->delete();
    }
};
