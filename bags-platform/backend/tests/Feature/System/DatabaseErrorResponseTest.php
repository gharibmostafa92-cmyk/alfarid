<?php

namespace Tests\Feature\System;

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

    public function test_webhook_database_errors_are_transient_except_duplicates(): void
    {
        // M1: on api/v1/webhooks/* every QueryException is transient (503) so
        // the carrier retries; only a caller-supplied duplicate (23505) is final.
        Route::post('/api/v1/webhooks/probe/fk', function (): void {
            $previous = new PDOException('update or delete on table violates foreign key');
            $previous->errorInfo = ['23503', 7, 'update or delete on table violates foreign key'];
            throw new QueryException('pgsql', 'delete from "x"', [], $previous);
        });
        Route::post('/api/v1/webhooks/probe/dup', function (): void {
            $previous = new PDOException('duplicate key value violates unique constraint');
            $previous->errorInfo = ['23505', 7, 'duplicate key value violates unique constraint'];
            throw new \Illuminate\Database\UniqueConstraintViolationException('pgsql', 'insert into "x"', [], $previous);
        });
        Route::post('/api/test-non-webhook-fk', function (): void {
            $previous = new PDOException('update or delete on table violates foreign key');
            $previous->errorInfo = ['23503', 7, 'update or delete on table violates foreign key'];
            throw new QueryException('pgsql', 'delete from "x"', [], $previous);
        });

        $this->postJson('/api/v1/webhooks/probe/fk')->assertStatus(503);
        $this->postJson('/api/v1/webhooks/probe/dup')->assertStatus(422);
        $this->postJson('/api/test-non-webhook-fk')->assertStatus(409);
    }

    public function test_oversized_bodies_are_rejected_on_all_webhook_paths(): void
    {
        // M2: payment/* had a middleware body cap, but whatsapp/customers
        // parses unbounded JSON into memory with no cap at all.
        $this->postJson('/api/v1/webhooks/shipping/acme', ['padding' => str_repeat('x', 256 * 1024)])
            ->assertStatus(413);
        $this->postJson('/api/v1/webhooks/whatsapp/customers', ['padding' => str_repeat('x', 256 * 1024)])
            ->assertStatus(413);
    }
}
