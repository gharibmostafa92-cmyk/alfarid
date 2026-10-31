<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // INFO: حذف سجلات التدقيق القديمة (staff:prune-audit-logs) يمسح بنطاق
        // created_at فقط — الفهارس الحالية بادئتها actor/target فلا تخدمه.
        if (! Schema::hasIndex('staff_audit_logs', 'staff_audit_logs_created_at_index')) {
            Schema::table('staff_audit_logs', function (Blueprint $table): void {
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('staff_audit_logs', 'staff_audit_logs_created_at_index')) {
            Schema::table('staff_audit_logs', function (Blueprint $table): void {
                $table->dropIndex(['created_at']);
            });
        }
    }
};
