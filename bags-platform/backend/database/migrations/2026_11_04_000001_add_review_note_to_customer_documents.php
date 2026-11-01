<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 0.3: سبب رفض المستند — كان التاجر يشوف "مرفوض" من غير ما يعرف ليه.
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
