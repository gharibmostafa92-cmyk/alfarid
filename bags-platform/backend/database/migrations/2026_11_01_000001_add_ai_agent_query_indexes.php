<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * L10: PostgreSQL لا ينشئ index تلقائيًا لأعمدة FK، وفهرس
     * ai_messages(conversation_id,id) لا يخدم استعلامات النطاق الزمني
     * وعدّ التوكنز اليومي.
     */
    public function up(): void
    {
        // NOTE: non-concurrent form so the migration also runs inside the
        // test transaction; production tables are small enough for a brief lock.
        DB::statement('CREATE INDEX IF NOT EXISTS ai_pending_actions_conversation_id_index ON ai_pending_actions (conversation_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS ai_messages_conversation_created_index ON ai_messages (conversation_id, created_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS ai_messages_created_at_index ON ai_messages (created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ai_pending_actions_conversation_id_index');
        DB::statement('DROP INDEX IF EXISTS ai_messages_conversation_created_index');
        DB::statement('DROP INDEX IF EXISTS ai_messages_created_at_index');
    }
};
