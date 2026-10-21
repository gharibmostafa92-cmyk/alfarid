<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M4: supplier_users.supplier_id كان رقمًا بلا قيد قابل للتنفيذ على بيانات
 * قديمة (المورد المحذوف يترك supplier_id يتيمًا فيسبب 500 عند الدخول وفي /me).
 * - قيّده بـ restrictOnDelete حتى لا يُحذف مورد له مستخدمون بصمت.
 * - فهرس صريح لأن عمود unsignedBigInteger اليدوي بلا فهرس.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::selectOne(
            "SELECT 1 FROM pg_constraint WHERE conname = 'supplier_users_supplier_id_foreign'"
        );
        if ($exists !== null) {
            Schema::table('supplier_users', function (Blueprint $table): void {
                $table->dropForeign(['supplier_id']);
            });
        }

        DB::statement('CREATE INDEX IF NOT EXISTS supplier_users_supplier_id_index ON supplier_users (supplier_id)');

        Schema::table('supplier_users', function (Blueprint $table): void {
            $table->foreign('supplier_id', 'supplier_users_supplier_id_foreign')
                ->references('id')->on('suppliers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_users', function (Blueprint $table): void {
            $table->dropForeign('supplier_users_supplier_id_foreign');
            $table->foreign('supplier_id', 'supplier_users_supplier_id_foreign')
                ->references('id')->on('suppliers')->nullOnDelete();
        });
        DB::statement('DROP INDEX IF EXISTS supplier_users_supplier_id_index');
    }
};
