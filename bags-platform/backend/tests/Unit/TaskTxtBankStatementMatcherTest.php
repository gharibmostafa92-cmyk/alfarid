<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\WholesalePaymentNotice;
use App\Services\Wholesale\BankStatementMatcher;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TaskTxtBankStatementMatcherTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    private function pendingNotice(): WholesalePaymentNotice
    {
        $customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved']);

        return WholesalePaymentNotice::factory()->create([
            'customer_id' => $customer->id,
            'amount' => 1000,
            'reference_number' => 'STMT-1',
            'transfer_date' => '2026-11-06',
            'status' => 'pending_review',
        ]);
    }

    public function test_duplicate_rows_reference_the_same_notice_as_candidates(): void
    {
        // Locks the current contract (also asserted by the execution suite):
        // rows are matched independently, so one notice can back two rows.
        // Whether reconciliation should enforce one-to-one claiming is an
        // open owner decision; this test pins behavior until it lands.
        $notice = $this->pendingNotice();

        $result = app(BankStatementMatcher::class)->match([
            ['amount' => 1000, 'reference' => 'STMT-1', 'date' => '2026-11-06'],
            ['amount' => 1000, 'reference' => 'STMT-1', 'date' => '2026-11-09'],
        ]);

        $this->assertSame($notice->id, $result['exact'][0]['notice_id'] ?? null);
        $this->assertSame($notice->id, $result['probable'][0]['notice_id'] ?? null);
        $this->assertCount(0, $result['unmatched']);
    }

    public function test_garbage_row_date_does_not_abort_matching(): void
    {
        $this->pendingNotice();

        $result = app(BankStatementMatcher::class)->match([
            ['amount' => 1000, 'reference' => 'STMT-1', 'date' => 'abc'],
            ['amount' => 1000, 'reference' => 'STMT-1', 'date' => '2026-11-06'],
        ]);

        $this->assertCount(1, $result['exact']);
        $this->assertCount(1, array_merge($result['unmatched'], $result['probable']));
    }
}
