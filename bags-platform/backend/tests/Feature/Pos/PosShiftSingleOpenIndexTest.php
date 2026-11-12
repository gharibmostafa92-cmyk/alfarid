<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\Finance\PosShiftStatus;
use App\Models\Branch;
use App\Models\PosShift;
use App\Models\Treasury;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PosShiftSingleOpenIndexTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function conflicts(): array
    {
        return [
            'same cashier, different drawer' => [true, 'pos_shifts_one_open_user'],
            'different cashier, same drawer' => [false, 'pos_shifts_one_unsettled_drawer'],
        ];
    }

    #[DataProvider('conflicts')]
    public function test_database_rejects_a_second_open_shift(bool $sameCashier, string $index): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $treasury = Treasury::query()->create([
            'name' => 'Test safe',
            'code' => 'TEST-SAFE-'.uniqid(),
            'branch_id' => $branch->id,
            'type' => 'pos_drawer',
        ]);

        PosShift::query()->create([
            'shift_number' => 'SHIFT-'.uniqid(),
            'user_id' => $cashier->id,
            'branch_id' => $branch->id,
            'treasury_id' => $treasury->id,
            'status' => PosShiftStatus::Open,
            'opened_at' => now(),
        ]);

        $otherCashier = $sameCashier ? $cashier : User::factory()->create(['branch_id' => $branch->id]);
        $otherDrawer = $sameCashier ? Treasury::query()->create([
            'name' => 'Second drawer',
            'code' => 'SECOND-DRAWER',
            'branch_id' => $branch->id,
            'type' => 'pos_drawer',
        ]) : $treasury;

        try {
            // Nested transaction => savepoint, so the unique violation does not abort the test transaction.
            DB::transaction(fn () => PosShift::query()->create([
                'shift_number' => 'SHIFT-'.uniqid(),
                'user_id' => $otherCashier->id,
                'branch_id' => $branch->id,
                'treasury_id' => $otherDrawer->id,
                'status' => PosShiftStatus::Open,
                'opened_at' => now(),
            ]), 1);
            $this->fail('A second open shift must violate the single-open unique index.');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->errorInfo[0]);
            $this->assertStringContainsString($index, $e->getMessage());
        }

        $this->assertSame(1, PosShift::query()->where('status', PosShiftStatus::Open)->count());
    }
}
