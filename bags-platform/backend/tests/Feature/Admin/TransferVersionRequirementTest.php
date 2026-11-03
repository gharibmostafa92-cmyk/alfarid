<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\TransferService;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * M3: admin transfer approve/ship/receive require expected_version so the
 * approver always names the draft revision they reviewed.
 */
class TransferVersionRequirementTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SettingSeeder::class, InventoryPermissionSeeder::class]);
        $this->staff = User::factory()->create();
        $this->staff->givePermissionTo(Permission::where('guard_name', 'staff')->get());
    }

    private function api(string $method, string $path, array $data = []): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($this->staff->createToken('t')->plainTextToken)
            ->json($method, '/api/v1/admin/'.$path, $data);
    }

    private function transfer(): StockTransfer
    {
        $from = Branch::factory()->create();
        $variant = ProductVariant::factory()->create();
        app(InventoryService::class)->recordMovement([
            'product_variant_id' => $variant->id, 'branch_id' => $from->id, 'type' => 'adjustment_in',
            'affected_bucket' => 'on_hand', 'quantity' => 10, 'created_by' => $this->staff->id,
        ]);

        return app(TransferService::class)->create([
            'from_branch_id' => $from->id, 'to_branch_id' => Branch::factory()->create()->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 2]],
        ], $this->staff->id);
    }

    public function test_stage_endpoints_reject_missing_version(): void
    {
        $transfer = $this->transfer();
        $approved = app(TransferService::class)->approve($transfer, [], $this->staff->id);
        $shipped = app(TransferService::class)->ship($approved, [], $this->staff->id);

        // approve on a fresh draft without version
        $fresh = $this->transfer();
        $this->api('POST', "transfers/{$fresh->id}/approve", [])->assertUnprocessable();

        // ship/receive without version
        $this->api('POST', "transfers/{$transfer->id}/ship", [])->assertUnprocessable();
        $this->api('POST', "transfers/{$shipped->id}/receive", [])->assertUnprocessable();

        // stale version conflicts instead of silently applying
        $this->api('POST', "transfers/{$fresh->id}/approve", ['expected_version' => 999])->assertConflict();
    }

    public function test_stage_endpoints_accept_current_version(): void
    {
        $transfer = $this->transfer();
        $this->api('POST', "transfers/{$transfer->id}/approve", ['expected_version' => $transfer->version])->assertOk();
        $this->api('POST', "transfers/{$transfer->id}/ship", ['expected_version' => $transfer->fresh()->version])->assertOk();
        $this->api('POST', "transfers/{$transfer->id}/receive", ['expected_version' => $transfer->fresh()->version])->assertOk();
        $this->assertSame('received', $transfer->fresh()->status->value);
    }
}
