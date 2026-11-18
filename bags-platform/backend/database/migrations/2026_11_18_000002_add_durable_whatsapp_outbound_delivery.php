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
        Schema::table('ai_whatsapp_outbound', function (Blueprint $table): void {
            $table->foreignId('inbound_id')->nullable()->after('id')
                ->constrained('ai_whatsapp_inbound')->nullOnDelete();
            $table->unsignedSmallInteger('sequence')->default(0)->after('inbound_id');
            $table->string('delivery_status', 16)->default('sent')->after('wa_message_id');
            $table->string('recipient_jid')->nullable()->after('delivery_status');
            $table->text('body')->nullable()->after('recipient_jid');
            $table->unique(['inbound_id', 'sequence'], 'ai_whatsapp_outbound_inbound_sequence_unique');
            $table->index('delivery_status', 'ai_whatsapp_outbound_delivery_status_index');
            $table->string('wa_message_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('ai_whatsapp_outbound')->whereNull('wa_message_id')->orderBy('id')->get(['id'])->each(function (object $row): void {
            DB::table('ai_whatsapp_outbound')->where('id', $row->id)->update(['wa_message_id' => 'undelivered-'.$row->id]);
        });

        Schema::table('ai_whatsapp_outbound', function (Blueprint $table): void {
            $table->dropUnique('ai_whatsapp_outbound_inbound_sequence_unique');
            $table->dropIndex('ai_whatsapp_outbound_delivery_status_index');
            $table->dropForeign(['inbound_id']);
            $table->dropColumn(['inbound_id', 'sequence', 'delivery_status', 'recipient_jid', 'body']);
            $table->string('wa_message_id')->nullable(false)->change();
        });
    }
};
