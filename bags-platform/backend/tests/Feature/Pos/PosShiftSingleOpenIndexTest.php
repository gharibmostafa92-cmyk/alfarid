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
use Tests\TestCase;

class PosShiftSingleOpenIndexTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_second_open_shift_for_same_cashier_branch_is_rejected(): void
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

        try {
            // Nested transaction => savepoint, so the unique violation does not abort the test transaction.
            DB::transaction(fn () => PosShift::query()->create([
                'shift_number' => 'SHIFT-'.uniqid(),
                'user_id' => $cashier->id,
                'branch_id' => $branch->id,
                'treasury_id' => $treasury->id,
                'status' => PosShiftStatus::Open,
                'opened_at' => now(),
            ]), 1);
            $this->fail('A second open shift must violate the single-open unique index.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->assertSame(1, PosShift::query()
            ->where('user_id', $cashier->id)
            ->where('branch_id', $branch->id)
            ->where('status', PosShiftStatus::Open)
            ->count());
    }
}
