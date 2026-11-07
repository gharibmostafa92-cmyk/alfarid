<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Explains reasons for document rejection to wholesale applicants.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_documents', function (Blueprint $table): void {
            $table->text('review_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customer_documents', function (Blueprint $table): void {
            $table->dropColumn('review_note');
        });
    }
};
