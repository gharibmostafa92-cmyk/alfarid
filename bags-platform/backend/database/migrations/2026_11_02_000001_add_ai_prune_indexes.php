<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * M-13: فهارس جزئية لاستعلامات التنظيف — الفهرس (user_id,status)
     * لا يخدم status='pending' AND expires_at<now، وشرط
     * COALESCE(last_message_at, created_at) لا يستفيد من أي فهرس.
     */
    public function up(): void
    {
        // NOTE: non-concurrent form so the migration also runs inside the
        // test transaction; production tables are small enough for a brief lock.
        DB::statement("CREATE INDEX IF NOT EXISTS ai_pending_actions_prune_pending_index ON ai_pending_actions (expires_at) WHERE status IN ('pending', 'bulk_child')");
        DB::statement("CREATE INDEX IF NOT EXISTS ai_pending_actions_prune_confirmed_index ON ai_pending_actions (updated_at) WHERE status = 'confirmed'");
        DB::statement('CREATE INDEX IF NOT EXISTS ai_conversations_prune_active_index ON ai_conversations ((COALESCE(last_message_at, created_at)))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ai_pending_actions_prune_pending_index');
        DB::statement('DROP INDEX IF EXISTS ai_pending_actions_prune_confirmed_index');
        DB::statement('DROP INDEX IF EXISTS ai_conversations_prune_active_index');
    }
};
