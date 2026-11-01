<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // H4: هذه الأعمدة قد توجد أصلًا (النظام كان يستخدم Sanctum والفروع قبل
        // هذا التاريخ)، فاحمِ الإضافة حتى لا يفشل migrate في الإنتاج.
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone')->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'branch_id')) {
                $table->unsignedBigInteger('branch_id')->nullable()->after('password');
            }
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('branch_id');
            }
        });
    }

    public function down(): void
    {
        // L-4: الـ up() يضيف الأعمدة بشرط hasColumn لأنها قد توجد
        // أصلًا قبل الـ migration. الـ down() القديم كان يحذف الثلاثة
        // من غير شرط، فيضيّع phone لو كان موجودًا قبلها (عمود بيانات
        // قديم لا تملكه هذه الـ migration). phone لا يُحذف أبدًا هنا،
        // وbranch_id/is_active يُحذفان فقط إن وُجدا.
        Schema::table('users', function (Blueprint $table): void {
            $drop = [];
            if (Schema::hasColumn('users', 'branch_id')) {
                $drop[] = 'branch_id';
            }
            if (Schema::hasColumn('users', 'is_active')) {
                $drop[] = 'is_active';
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
