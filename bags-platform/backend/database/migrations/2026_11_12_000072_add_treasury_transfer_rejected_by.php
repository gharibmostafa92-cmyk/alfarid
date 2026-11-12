<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F9: approvals and rejections must be attributable separately. Storing a
 * rejecter in approved_by made audit trails ambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treasury_transfers', function (Blueprint $table): void {
            if (! Schema::hasColumn('treasury_transfers', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->after('approved_by')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('treasury_transfers', function (Blueprint $table): void {
            if (Schema::hasColumn('treasury_transfers', 'rejected_by')) {
                $table->dropConstrainedForeignId('rejected_by');
            }
        });
    }
};
