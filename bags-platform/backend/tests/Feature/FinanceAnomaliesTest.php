<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Enums\Finance\TreasuryTransactionType;
use App\Enums\Finance\TreasuryType;
use App\Models\AiConversation;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Ai\ToolRegistry;
use App\Services\Pos\ExpenseService;
use App\Services\Shared\SettingService;
use App\Services\Pos\TreasuryService;
use Database\Seeders\AiAgentPermissionSeeder;
use Database\Seeders\FinancialPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Phase 5.2: expense anomaly sections with deep links. */
class FinanceAnomaliesTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(AiAgentPermissionSeeder::class);
        $this->seed(FinancialPermissionSeeder::class);
        DB::table('settings')->whereIn('key', [
            'ai_enabled',
            'ai_finance_tools_enabled',
            'ai_action_ttl_minutes',
        ])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        foreach (['ai_agent.use', 'ai_agent.finance', 'expenses.view', 'finance.reports.view'] as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }
        $u->assignRole(Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff'));

        return $u;
    }

    private function treasury(Branch $branch): Treasury
    {
        $t = Treasury::create([
            'name' => 'خزينة '.Str::random(6), 'code' => 'SAFE-'.Str::uuid(),
            'branch_id' => $branch->id, 'type' => TreasuryType::MainSafe,
            'current_balance' => '0.00', 'is_active' => true,
        ]);
        app(TreasuryService::class)->record([
            'treasury_id' => $t->id, 'type' => TreasuryTransactionType::OpeningBalance,
            'amount' => '100000.00', 'created_by' => User::query()->first()?->id,
        ]);

        return $t;
    }

    private function expense(User $admin, Treasury $treasury, string $category, string $amount, string $date): Expense
    {
        return app(ExpenseService::class)->create([
            'branch_id' => $treasury->branch_id, 'treasury_id' => $treasury->id,
            'category' => $category, 'amount' => $amount, 'expense_date' => $date,
        ], $admin, 'anom-'.Str::uuid()->toString());
    }

    private function titles(array $section): array
    {
        return array_column($section, 'title');
    }

    public function test_all_anomaly_sections_are_detected(): void
    {
        $admin = $this->admin();
        $branch = Branch::factory()->create(['is_active' => true]);
        $treasury = $this->treasury($branch);
        $today = now()->toDateString();

        foreach (['-60 days' => '90.00', '-50 days' => '100.00', '-40 days' => '110.00'] as $when => $amount) {
            $this->expense($admin, $treasury, 'كهرباء', $amount, now()->modify($when)->toDateString());
        }
        $dup1 = $this->expense($admin, $treasury, 'نظافة', '150.00', $today);
        $this->expense($admin, $treasury, 'نظافة', '150.00', $today);
        $jump = $this->expense($admin, $treasury, 'كهرباء', '1000.00', $today);
        $this->expense($admin, $treasury, 'صيانة', '2000.00', $today);
        $this->expense($admin, $treasury, 'صيانة', '2000.00', now()->subDay()->toDateString());
        $late = $this->expense($admin, $treasury, 'نثريات', '75.00', $today);
        DB::table('expenses')->where('id', $late->id)->update([
            'created_at' => now()->setTimezone('Africa/Cairo')->setTime(2, 30)->toDateTimeString(),
        ]);
        $newCat = $this->expense($admin, $treasury, 'ضيافة', '60.00', $today);
        $voided = $this->expense($admin, $treasury, 'نظافة', '150.00', $today);
        app(ExpenseService::class)->void($voided, 'خطأ إدخال', $admin);

        $out = app(ToolRegistry::class)->dispatch($admin, AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']), ['name' => 'expense_anomalies', 'args' => []]);
        $this->assertSame('data', $out->type);
        $sections = $out->data['sections'];

        $dupTitles = implode("\n", $this->titles($sections['duplicates']));
        $this->assertStringContainsString($dup1->expense_number, $this->dupDetail($sections['duplicates']));
        $this->assertStringContainsString('مكرر محتمل', $dupTitles);

        $this->assertStringContainsString($jump->expense_number, implode("\n", $this->titles($sections['category_jumps'])));

        $this->assertStringContainsString('مدوّر', implode("\n", $this->titles($sections['round_repeated'])));

        $this->assertStringContainsString($late->expense_number, implode("\n", $this->titles($sections['after_hours'])));

        $this->assertStringContainsString('ضيافة', implode("\n", $this->titles($sections['new_categories'])));

        $this->assertSame('checklist', $out->uiBlock['type']);
        $this->assertStringContainsString('#/finance?tab=exp&id=', implode("\n", array_column($out->uiBlock['items'], 'detail')));
        $this->assertTrue(collect($out->uiBlock['items'])->every(fn ($i) => str_contains((string) ($i['detail'] ?? ''), '#/finance')));
    }

    private function dupDetail(array $entries): string
    {
        return implode("\n", array_column($entries, 'detail'));
    }

    public function test_clean_branch_reports_ok(): void
    {
        $admin = $this->admin();

        $out = app(ToolRegistry::class)->dispatch($admin, AiConversation::create(['user_id' => $admin->id, 'model' => 'gemini-test']), ['name' => 'expense_anomalies', 'args' => []]);
        $this->assertSame('data', $out->type);
        $this->assertSame('ok', $out->uiBlock['items'][0]['severity']);
    }
}
