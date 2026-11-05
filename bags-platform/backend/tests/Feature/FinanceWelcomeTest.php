<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Enums\TreasuryType;
use App\Models\Branch;
use App\Models\Treasury;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Services\Ai\Finance\FinanceWelcome;
use App\Services\SettingService;
use App\Services\TreasuryService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\FinancialPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Phase 5.6: transfers pending >48h surface first in the morning card. */
class FinanceWelcomeTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        $this->seed(FinancialPermissionSeeder::class);
        app(SettingService::class)->clearCache();
    }

    public function test_overdue_approvals_come_first(): void
    {
        foreach (['ai_agent.use', 'treasuries.view', 'treasury_transfers.view', 'treasury_transfers.approve', 'expenses.view', 'customers.wallet.view', 'finance.reports.view'] as $p) {
            Permission::findOrCreate($p, 'staff');
        }
        $creator = User::factory()->create();
        $approver = User::factory()->create();
        $role = Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff');
        $creator->assignRole($role);
        $approver->assignRole($role);

        $branch = Branch::factory()->create(['is_active' => true]);
        $from = Treasury::create([
            'name' => 'من', 'code' => 'SAFE-'.Str::uuid(), 'branch_id' => $branch->id,
            'type' => TreasuryType::MainSafe, 'current_balance' => '10000.00', 'is_active' => true,
        ]);
        $to = Treasury::create([
            'name' => 'إلى', 'code' => 'SAFE-'.Str::uuid(), 'branch_id' => $branch->id,
            'type' => TreasuryType::MainSafe, 'current_balance' => '0.00', 'is_active' => true,
        ]);
        $old = app(TreasuryService::class)->createTransfer([
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '100.00',
        ], $creator->id);
        TreasuryTransfer::query()->whereKey($old->id)->update(['created_at' => now()->subHours(50)]);
        app(TreasuryService::class)->createTransfer([
            'from_treasury_id' => $from->id, 'to_treasury_id' => $to->id, 'amount' => '50.00',
        ], $creator->id);

        $card = app(FinanceWelcome::class)->card($approver->fresh());
        $this->assertNotNull($card);
        $this->assertStringContainsString('48 ساعة', (string) $card['rows'][0]['label']);
        $this->assertSame(1, $card['rows'][0]['value']);
        $this->assertSame(2, $card['rows'][1]['value']);
    }
}
