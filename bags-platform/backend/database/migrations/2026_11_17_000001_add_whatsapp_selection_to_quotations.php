<?php

declare(strict_types=1);

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('quotations', 'whatsapp_selection_token')) {
            Schema::table('quotations', static function (Blueprint $table): void {
                $table->string('whatsapp_selection_token', 12)->nullable();
            });
        }

        ConcurrentIndex::ensure(
            'quotations_customer_wa_selection_unique',
            'ON quotations (customer_id, whatsapp_selection_token) WHERE whatsapp_selection_token IS NOT NULL',
            unique: true,
        );
    }

    public function down(): void
    {
        ConcurrentIndex::drop('quotations_customer_wa_selection_unique');
        Schema::table('quotations', static function (Blueprint $table): void {
            $table->dropColumn('whatsapp_selection_token');
        });
    }
};
