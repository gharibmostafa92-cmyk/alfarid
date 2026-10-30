<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

class DatabaseErrorResponseTest extends TestCase
{
    public function test_database_errors_do_not_expose_sql_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/test-database-error', function (): void {
            $previous = new PDOException('private database details');
            $previous->errorInfo = ['25P02', 7, 'private database details'];
            throw new QueryException('pgsql', 'update "cache_locks" set "owner" = ?', ['secret-owner'], $previous);
        });

        $this->getJson('/api/test-database-error')->assertStatus(500)
            ->assertExactJson(['message' => 'تعذر حفظ البيانات بسبب خطأ داخلي؛ أعد المحاولة، وإذا استمرت المشكلة تواصل مع الدعم.']);
    }
}
