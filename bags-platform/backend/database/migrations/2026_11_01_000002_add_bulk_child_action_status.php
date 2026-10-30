<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * M10: حالة bulk_child تمنع التأكيد المنفرد للإجراءات الفرعية.
     * (VARCHAR + CHECK — تعمل داخل transaction بما فيها بيئة الاختبار.)
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE ai_pending_actions DROP CONSTRAINT IF EXISTS ai_pending_actions_status_check");
        DB::statement("ALTER TABLE ai_pending_actions ADD CONSTRAINT ai_pending_actions_status_check CHECK (status IN ('pending','confirmed','executed','failed','cancelled','expired','undone','bulk_child'))");
    }

    public function down(): void
    {
        // PG native enum values cannot be removed safely; CHECK rollback only.
        DB::statement("ALTER TABLE ai_pending_actions DROP CONSTRAINT IF EXISTS ai_pending_actions_status_check");
        DB::statement("ALTER TABLE ai_pending_actions ADD CONSTRAINT ai_pending_actions_status_check CHECK (status IN ('pending','confirmed','executed','failed','cancelled','expired','undone'))");
    }
};
