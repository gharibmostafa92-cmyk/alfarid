<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Finance\PosShiftStatus;
use App\Enums\Finance\TreasuryType;
use App\Models\Branch;
use App\Models\PosShift;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Pos\PosService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class Task8Slice5ForceCloseAndClientContractTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private Treasury $drawer;

    private User $cashier;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->drawer = Treasury::create([
            'name' => 'Slice5 Drawer',
            'code' => 'DRW-S5-'.Str::uuid(),
            'branch_id' => $this->branch->id,
            'type' => TreasuryType::PosDrawer,
            'current_balance' => '0.00',
            'is_active' => true,
        ]);
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->supervisor = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->supervisor->givePermissionTo('pos_shifts.force_close', 'pos_shifts.view');
    }

    private function openShift(): PosShift
    {
        return PosShift::create([
            'shift_number' => 'SH-S5-'.Str::uuid(),
            'user_id' => $this->cashier->id,
            'branch_id' => $this->branch->id,
            'treasury_id' => $this->drawer->id,
            'status' => PosShiftStatus::Open,
            'opened_at' => now(),
            'opening_cash' => '100.00',
        ]);
    }

    public function test_f02_force_close_enforces_two_decimal_precision_and_preserves_high_value_decimals(): void
    {
        $shift = $this->openShift();

        Auth::forgetGuards();
        $token = $this->supervisor->createToken('s5')->plainTextToken;

        // 3 decimal places must fail validation with 422.
        $this->withToken($token)
            ->postJson("/api/v1/admin/shifts/{$shift->id}/force-close", [
                'actual_closing_cash' => '100.999',
                'reason' => 'اختبار دقة الكسور',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actual_closing_cash');

        // Over numeric(14,2) DB capacity must fail validation with 422.
        $this->withToken($token)
            ->postJson("/api/v1/admin/shifts/{$shift->id}/force-close", [
                'actual_closing_cash' => '1000000000000.00',
                'reason' => 'اختبار تجاوز السعة',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actual_closing_cash');

        // Exact max numeric(14,2) boundary succeeds without float rounding drift.
        $res = $this->withToken($token)
            ->withHeader('Idempotency-Key', 'fc-max-boundary-1')
            ->postJson("/api/v1/admin/shifts/{$shift->id}/force-close", [
                'actual_closing_cash' => '999999999999.99',
                'reason' => 'إغلاق بالحد الأقصى الدقيق',
            ])
            ->assertOk();

        $this->assertSame('999999999999.99', $res->json('data.actual_closing_cash'));
        $this->assertSame('999999999899.99', $res->json('data.cash_difference'));
    }

    public function test_f02_pos_service_force_close_accepts_decimal_string_without_float_drift(): void
    {
        $shift = $this->openShift();

        $closed = app(PosService::class)->forceCloseShift(
            $shift,
            $this->supervisor,
            '123456789012.34',
            'إغلاق مباشر عبر الخدمة بدقة عشرية'
        );

        $this->assertSame('123456789012.34', $closed->actual_closing_cash);
        $this->assertSame('123456788912.34', $closed->cash_difference);
    }
}
