<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalletBackfillLockTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_disabling_wallet_guard_holds_an_exclusive_table_lock_until_transaction_end(): void
    {
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE customer_wallet_transactions DISABLE TRIGGER wallet_immutable');
            $locks = DB::select("SELECT mode FROM pg_locks WHERE pid = pg_backend_pid() AND relation = 'customer_wallet_transactions'::regclass AND granted");
            $this->assertContains('ShareRowExclusiveLock', array_column($locks, 'mode'));
            DB::statement('ALTER TABLE customer_wallet_transactions ENABLE TRIGGER wallet_immutable');
            $locks = DB::select("SELECT mode FROM pg_locks WHERE pid = pg_backend_pid() AND relation = 'customer_wallet_transactions'::regclass AND granted");
            $this->assertContains('ShareRowExclusiveLock', array_column($locks, 'mode'));
        });
    }
}
