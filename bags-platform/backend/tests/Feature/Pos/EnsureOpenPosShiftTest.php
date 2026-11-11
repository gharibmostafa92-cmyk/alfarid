<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\Finance\PosShiftStatus;
use App\Http\Middleware\EnsureOpenPosShift;
use App\Models\Branch;
use App\Models\PosShift;
use App\Models\Treasury;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/** M5/H3: the shift gate resolves exactly one open shift — never zero silently, never two. */
class EnsureOpenPosShiftTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_middleware_injects_the_single_open_shift(): void
    {
        [$cashier, $shift] = $this->openShift();

        $request = Request::create('/api/v1/pos/sales', 'POST');
        $request->setUserResolver(fn () => $cashier);

        $response = (new EnsureOpenPosShift)->handle($request, fn () => response('ok'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($shift->id, $request->attributes->get('pos_shift')->id);
    }

    public function test_middleware_rejects_a_missing_shift_with_no_open_shift_code(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $cashier = User::factory()->create(['branch_id' => $branch->id]);

        $request = Request::create('/api/v1/pos/sales', 'POST');
        $request->setUserResolver(fn () => $cashier);

        $response = (new EnsureOpenPosShift)->handle($request, fn () => response('ok'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('no_open_shift', $response->getData(true)['code']);
    }

    /** @return array{User, PosShift} */
    private function openShift(): array
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $treasury = Treasury::query()->create([
            'name' => 'Test drawer',
            'code' => 'TEST-DRAWER-'.uniqid(),
            'branch_id' => $branch->id,
            'type' => 'pos_drawer',
        ]);
        $shift = PosShift::query()->create([
            'shift_number' => 'SHIFT-'.uniqid(),
            'user_id' => $cashier->id,
            'branch_id' => $branch->id,
            'treasury_id' => $treasury->id,
            'status' => PosShiftStatus::Open,
            'opened_at' => now(),
        ]);

        return [$cashier->fresh(), $shift];
    }
}
