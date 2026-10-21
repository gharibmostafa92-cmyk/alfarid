<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * H3: فهارس تعبيرية LOWER(email) على users وsupplier_users.
 * - على PostgreSQL لا يستخدم index عادي مع whereRaw('LOWER(email) = ?')، فكانت
 *   كل محاولة دخول بالإيميل sequential scan.
 * - الـ unique الحالي حساس لحالة الأحرف، فيسمح بتكرار Admin@x.com/admin@x.com
 *   عبر أي مسار كتابة لا يمر بالـ FormRequest. الفهرس التعبيري الفريد يمنع ذلك
 *   على مستوى قاعدة البيانات.
 * customers مغطاة أصلًا (customers_verified_email_unique الجزئي).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("SET lock_timeout = '2s'");
        try {
            foreach (['users', 'supplier_users'] as $table) {
                $duplicate = DB::table($table)
                    ->selectRaw('LOWER(TRIM(email)) AS normalized')
                    ->groupByRaw('LOWER(TRIM(email))')
                    ->havingRaw('COUNT(*) > 1')
                    ->exists();
                if ($duplicate) {
                    throw new RuntimeException("Resolve duplicate {$table}.email values (case-insensitive) before adding the unique index.");
                }
                DB::statement("UPDATE {$table} SET email = LOWER(TRIM(email)) WHERE email <> LOWER(TRIM(email))");
            }

            $this->dropInvalidIndex('users_email_ci_unique');
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS users_email_ci_unique ON users (LOWER(email))');
            $this->dropInvalidIndex('supplier_users_email_ci_unique');
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS supplier_users_email_ci_unique ON supplier_users (LOWER(email))');
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS supplier_users_email_ci_unique');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS users_email_ci_unique');
    }

    private function dropInvalidIndex(string $name): void
    {
        $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
        if ($index !== null && ! $index->indisvalid) {
            DB::statement('DROP INDEX CONCURRENTLY '.$name);
        }
    }
};
