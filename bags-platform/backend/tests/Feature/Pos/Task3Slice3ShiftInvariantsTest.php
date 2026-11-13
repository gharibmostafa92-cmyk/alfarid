<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\Finance\PosShiftStatus;
use App\Enums\Finance\TreasuryType;
use App\Exceptions\BusinessException;
use App\Models\PosShift;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Pos\PosService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class Task3Slice3ShiftInvariantsTest extends PosScenarioFixture
{
    use LazilyRefreshDatabase;

    private function as(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    public function test_get_available_drawers_hides_balance_for_busy_drawers_without_permission(): void
    {
        // $this->cashier currently has an open shift on $this->drawer
        $token = $this->tokenFor($this->cashier, ['pos.access']);

        $res = $this->as($token)->getJson('/api/v1/pos/treasuries/available')->assertOk()->json('data');

        $drawerData = collect($res)->firstWhere('id', $this->drawer->id);
        $this->assertNotNull($drawerData);
        $this->assertTrue($drawerData['busy']);
        $this->assertNull($drawerData['balance'], 'Busy drawer balance must be null to prevent blind-close oracle leak.');
    }

    public function test_get_available_drawers_reveals_balance_for_busy_drawers_with_permission(): void
    {
        // Manager with pos_shifts.view_expected permission
        $token = $this->tokenFor($this->cashier, ['pos.access', 'pos_shifts.view_expected']);

        $res = $this->as($token)->getJson('/api/v1/pos/treasuries/available')->assertOk()->json('data');

        $drawerData = collect($res)->firstWhere('id', $this->drawer->id);
        $this->assertNotNull($drawerData);
        $this->assertTrue($drawerData['busy']);
        $this->assertNotNull($drawerData['balance']);
    }

    public function test_reopen_shift_for_recount_rejects_when_cashier_has_another_open_shift(): void
    {
        $supervisor = $this->makeCashier('sup@example.com', $this->branch->id, ['pos.access', 'pos_shifts.approve']);
        $posService = app(PosService::class);

        // 1. Current shift on drawer 1
        $shift1 = PosShift::where('user_id', $this->cashier->id)->where('status', PosShiftStatus::Open)->firstOrFail();

        // 2. Cashier closes shift 1 -> ClosedPendingApproval
        $posService->closeShift($shift1, 100.0, 'إغلاق وردية 1');
        $shift1->refresh();
        $this->assertSame(PosShiftStatus::ClosedPendingApproval, $shift1->status);

        // 3. Create a second drawer in same branch
        $drawer2 = Treasury::create([
            'name' => 'Drawer 2', 'code' => 'DRW-02-'.Str::random(4),
            'branch_id' => $this->branch->id, 'type' => TreasuryType::PosDrawer,
            'current_balance' => 0, 'is_active' => true,
        ]);

        // 4. Cashier opens shift 2 on drawer 2 -> Open
        $shift2 = $posService->openShift($this->cashier, $this->branch->id, $drawer2->id, 0.0);
        $this->assertSame(PosShiftStatus::Open, $shift2->status);

        // 5. Supervisor tries to reopen shift 1 for recount -> Must reject with 409 BusinessException
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('لدى الكاشير وردية أخرى مفتوحة حالياً');

        $posService->reopenShiftForRecount($shift1, $supervisor, 'طلب إعادة الجرد');
    }
}
