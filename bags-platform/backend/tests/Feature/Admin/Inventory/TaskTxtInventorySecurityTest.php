<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Inventory;

use App\Enums\Finance\TreasuryType;
use App\Models\Branch;
use App\Models\ProductVariant;
use App\Models\Stocktake;
use App\Models\StockTransfer;
use App\Models\Treasury;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * task.txt §11 (branch isolation)، §12 (endpoint permissions)،
 * §13 (cost hiding)، §15 (blind stocktake).
 */
class TaskTxtInventorySecurityTest extends TaskTxtInventoryCase
{
    private function branchUser(Branch $branch, array $permissions): array
    {
        $user = $this->staffWithPermissions($permissions, $branch->id);

        return [$user, $this->tokenFor($user)];
    }

    // ---------- §11: branch isolation matrix ----------

    public function test_confined_user_cannot_touch_other_branches(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $adminToken = $this->tokenFor($admin);
        [$a, $b, $c] = [
            Branch::factory()->create(['is_active' => true]),
            Branch::factory()->create(['is_active' => true]),
            Branch::factory()->create(['is_active' => true]),
        ];
        $variant = ProductVariant::factory()->create();
        $this->seedStock($adminToken, $b, $variant, 5);
        $this->seedStock($adminToken, $a, $variant, 5);

        [$user, $token] = $this->branchUser($a, [
            'inventory.view', 'inventory.adjust',
            'transfers.view', 'transfers.create', 'transfers.update',
            'transfers.approve', 'transfers.ship', 'transfers.receive',
            'stocktakes.view', 'stocktakes.create', 'stocktakes.update', 'stocktakes.delete', 'stocktakes.approve',
        ]);

        // Adjustments on B → 403.
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $b->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 1]],
        ])->assertForbidden();

        // Transfer between B and C: show → 403.
        $bc = $this->requestWithToken($adminToken, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $b->id, 'to_branch_id' => $c->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 1]],
        ])->assertCreated()->json('data');
        $this->requestWithToken($token, 'GET', "/api/v1/admin/transfers/{$bc['id']}")->assertForbidden();

        // Approve/ship as destination-only → 403.
        $toA = $this->requestWithToken($adminToken, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $b->id, 'to_branch_id' => $a->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 1]],
        ])->assertCreated()->json('data');
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$toA['id']}/approve", [
            'expected_version' => $toA['version'],
        ])->assertForbidden();
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$toA['id']}/ship", [
            'expected_version' => $toA['version'],
        ])->assertForbidden();

        // Receive as source-only → 403.
        $fromA = $this->requestWithToken($adminToken, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $a->id, 'to_branch_id' => $b->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 1]],
        ])->assertCreated()->json('data');
        $ok = $this->requestWithToken($adminToken, 'POST', "/api/v1/admin/transfers/{$fromA['id']}/approve", [
            'expected_version' => $fromA['version'],
        ])->assertOk()->json('data');
        $shipped = $this->requestWithToken($adminToken, 'POST', "/api/v1/admin/transfers/{$fromA['id']}/ship", [
            'expected_version' => $ok['version'],
        ])->assertOk()->json('data');
        $this->requestWithToken($token, 'POST', "/api/v1/admin/transfers/{$fromA['id']}/receive", [
            'expected_version' => $shipped['version'],
        ])->assertForbidden();

        // Stocktake of B → 403.
        $takeB = $this->requestWithToken($adminToken, 'POST', '/api/v1/admin/stocktakes', [
            'branch_id' => $b->id,
            'items' => [['product_variant_id' => $variant->id, 'counted_qty' => 1]],
        ])->assertCreated()->json('data');
        $this->requestWithToken($token, 'GET', "/api/v1/admin/stocktakes/{$takeB['id']}")->assertForbidden();

        // Index scopes: only A rows / A-involved transfers.
        $levels = $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory')->assertOk()->json('data');
        $this->assertNotEmpty($levels);
        foreach ($levels as $row) {
            $this->assertSame($a->id, $row['branch']['id']);
        }

        $moves = $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory/movements')->assertOk()->json('data');
        $this->assertNotEmpty($moves);

        $takes = $this->requestWithToken($token, 'GET', '/api/v1/admin/stocktakes')->assertOk()->json('data');
        foreach ($takes as $row) {
            $this->assertSame($a->id, $row['branch']['id']);
        }

        $listed = $this->requestWithToken($token, 'GET', '/api/v1/admin/transfers')->assertOk()->json('data');
        $this->assertNotEmpty($listed);
        foreach ($listed as $row) {
            $this->assertTrue($row['from_branch']['id'] === $a->id || $row['to_branch']['id'] === $a->id);
        }
    }

    public function test_stopped_branch_user_is_denied_everywhere(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $adminToken = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($adminToken, $branch, $variant, 5);

        [$user, $token] = $this->branchUser($branch, ['inventory.view', 'inventory.adjust']);
        $branch->update(['is_active' => false]);
        // Fresh BranchAccess per request (no request-level cache here) — app re-resolves per call.

        $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory')->assertForbidden();
        $this->requestWithToken($token, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 1]],
        ])->assertForbidden();
    }

    public function test_branchless_user_without_global_privilege_is_denied(): void
    {
        [$user, $token] = $this->branchUser(Branch::factory()->create(['is_active' => true]), ['inventory.view']);
        $user->update(['branch_id' => null]);

        $this->requestWithToken($token, 'GET', '/api/v1/admin/inventory')->assertForbidden();
    }

    public function test_super_admin_passes_branch_gates(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $adminToken = $this->tokenFor($admin);
        $a = Branch::factory()->create(['is_active' => true]);
        $b = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($adminToken, $a, $variant, 5);

        $super = $this->staffWithPermissions([], null);
        $super->assignRole(Role::findOrCreate('Super Admin', 'staff'));
        $superToken = $this->tokenFor($super);

        $this->requestWithToken($superToken, 'GET', '/api/v1/admin/inventory')->assertOk();
        $this->requestWithToken($superToken, 'POST', '/api/v1/admin/inventory/adjustments', [
            'branch_id' => $b->id,
            'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 1]],
        ])->assertCreated();
    }

    // ---------- §12: endpoint permission matrix ----------

    public function test_endpoint_permission_matrix(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $other = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();

        $bootstrap = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $bootstrapToken = $this->tokenFor($bootstrap);
        $this->seedStock($bootstrapToken, $branch, $variant, 20);

        $mkTransfer = fn () => $this->requestWithToken($bootstrapToken, 'POST', '/api/v1/admin/transfers', [
            'from_branch_id' => $branch->id, 'to_branch_id' => $other->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 2]],
        ])->assertCreated()->json('data');
        $mkTake = fn () => $this->requestWithToken($bootstrapToken, 'POST', '/api/v1/admin/stocktakes', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'counted_qty' => 1]],
        ])->assertCreated()->json('data');

        $tApprove = $mkTransfer();
        $tShip = $mkTransfer();
        $shipReady = $this->requestWithToken($bootstrapToken, 'POST', "/api/v1/admin/transfers/{$tShip['id']}/approve", [
            'expected_version' => $tShip['version'],
        ])->assertOk()->json('data');
        $tReceive = $mkTransfer();
        $ra = $this->requestWithToken($bootstrapToken, 'POST', "/api/v1/admin/transfers/{$tReceive['id']}/approve", [
            'expected_version' => $tReceive['version'],
        ])->assertOk()->json('data');
        $itemId = $tReceive['items'][0]['id'];
        $rs = $this->requestWithToken($bootstrapToken, 'POST', "/api/v1/admin/transfers/{$tReceive['id']}/ship", [
            'expected_version' => $ra['version'],
            'items' => [['id' => $itemId, 'shipped_qty' => 2]],
        ])->assertOk()->json('data');
        $tDelete = $mkTransfer();
        $tUpdate = $mkTransfer();
        $take = $mkTake();

        $cases = [
            ['GET', '/api/v1/admin/inventory', [], 'inventory.view'],
            ['GET', '/api/v1/admin/inventory/movements', [], 'inventory.view'],
            ['POST', '/api/v1/admin/inventory/adjustments', [
                'branch_id' => $branch->id,
                'items' => [['product_variant_id' => $variant->id, 'adjustment_type' => 'increase', 'quantity' => 1]],
            ], 'inventory.adjust'],
            ['GET', '/api/v1/admin/transfers', [], 'transfers.view'],
            ['GET', "/api/v1/admin/transfers/{$tApprove['id']}", [], 'transfers.view'],
            ['POST', '/api/v1/admin/transfers', [
                'from_branch_id' => $branch->id, 'to_branch_id' => $other->id,
                'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 1]],
            ], 'transfers.create'],
            ['PUT', "/api/v1/admin/transfers/{$tUpdate['id']}", ['notes' => 'perm-check'], 'transfers.update'],
            ['POST', "/api/v1/admin/transfers/{$tApprove['id']}/approve", ['expected_version' => $tApprove['version']], 'transfers.approve'],
            ['POST', "/api/v1/admin/transfers/{$tShip['id']}/ship", ['expected_version' => $shipReady['version']], 'transfers.ship'],
            ['POST', "/api/v1/admin/transfers/{$tReceive['id']}/receive", [
                'expected_version' => $rs['version'],
                'items' => [['id' => $itemId, 'received_qty' => 2]],
            ], 'transfers.receive', $other->id],
            ['POST', "/api/v1/admin/transfers/{$tApprove['id']}/cancel", [], 'transfers.update'],
            ['DELETE', "/api/v1/admin/transfers/{$tDelete['id']}", [], 'transfers.delete'],
            ['GET', '/api/v1/admin/stocktakes', [], 'stocktakes.view'],
            ['POST', '/api/v1/admin/stocktakes', [
                'branch_id' => $branch->id,
                'items' => [['product_variant_id' => $variant->id, 'counted_qty' => 1]],
            ], 'stocktakes.create'],
            ['GET', "/api/v1/admin/stocktakes/{$take['id']}", [], 'stocktakes.view'],
            ['PUT', "/api/v1/admin/stocktakes/{$take['id']}", ['notes' => 'perm-check'], 'stocktakes.update'],
            ['POST', "/api/v1/admin/stocktakes/{$take['id']}/approve", [], 'stocktakes.approve'],
            ['DELETE', "/api/v1/admin/stocktakes/{$take['id']}", [], 'stocktakes.delete'],
        ];

        foreach ($cases as $case) {
            [$method, $uri, $payload, $permission] = $case;
            $gateBranchId = $case[4] ?? null;
            $gateBranchId ??= $branch->id;
            $nobody = $this->staffWithPermissions([], $gateBranchId);
            $nobodyToken = $this->tokenFor($nobody);
            $this->requestWithToken($nobodyToken, $method, $uri, $payload)->assertForbidden();

            $allowed = $this->staffWithPermissions([$permission], $gateBranchId);
            $allowedToken = $this->tokenFor($allowed);
            $response = $this->requestWithToken($allowedToken, $method, $uri, $payload);
            $this->assertNotSame(403, $response->getStatusCode(), "{$method} {$uri} with {$permission} must pass the gate");
        }

        // Production receipt needs inventory.adjust AND a cost permission.
        $receiptPayload = [
            'branch_id' => $branch->id, 'batch_number' => 'PERM-1',
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_cost' => '2.0000']],
        ];
        $adjustOnly = $this->staffWithPermissions(['inventory.adjust'], $branch->id);
        $this->requestWithToken($this->tokenFor($adjustOnly), 'POST', '/api/v1/admin/production-receipts', $receiptPayload)
            ->assertForbidden();

        $withCost = $this->staffWithPermissions(['inventory.adjust', 'inventory.view_cost'], $branch->id);
        $this->assertNotSame(403, $this->requestWithToken($this->tokenFor($withCost), 'POST', '/api/v1/admin/production-receipts', $receiptPayload)->getStatusCode());

        // POS gates.
        [$cashier, $cashierToken] = $this->posCashierWithShift($branch, []);
        $this->posWithToken($cashierToken)->postJson('/api/v1/pos/transfer-requests', [
            'from_branch_id' => $other->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 1]],
        ], ['Idempotency-Key' => 'perm-pos-1'])->assertForbidden();

        [$creator, $creatorToken] = $this->posCashierWithShift($branch, ['transfers.create']);
        $this->assertNotSame(403, $this->posWithToken($creatorToken)->postJson('/api/v1/pos/transfer-requests', [
            'from_branch_id' => $other->id,
            'items' => [['product_variant_id' => $variant->id, 'requested_qty' => 1]],
        ], ['Idempotency-Key' => 'perm-pos-2'])->getStatusCode());
    }

    // ---------- §13: cost hiding ----------

    public function test_cost_fields_hidden_without_cost_permission(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $adminToken = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $this->seedStock($adminToken, $branch, $variant, 10);

        $this->requestWithToken($adminToken, 'POST', '/api/v1/admin/production-receipts', [
            'branch_id' => $branch->id, 'batch_number' => 'COST-1',
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 2, 'unit_cost' => '7.5000']],
        ])->assertCreated();

        [$viewer, $viewerToken] = $this->branchUser($branch, ['inventory.view']);
        $moves = $this->requestWithToken($viewerToken, 'GET', '/api/v1/admin/inventory/movements')->assertOk()->json('data');
        $this->assertNotEmpty($moves);
        foreach ($moves as $row) {
            $this->assertArrayNotHasKey('unit_cost', $row);
        }

        $receipts = $this->requestWithToken($viewerToken, 'GET', '/api/v1/admin/production-receipts')->assertOk()->json('data');
        $this->assertNotEmpty($receipts);
        foreach ($receipts[0]['items'] as $item) {
            $this->assertArrayNotHasKey('unit_cost', $item);
        }

        // Receiving without cost permission → 403.
        $this->requestWithToken($viewerToken, 'POST', '/api/v1/admin/production-receipts', [
            'branch_id' => $branch->id, 'batch_number' => 'COST-2',
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 1, 'unit_cost' => '1.0000']],
        ])->assertForbidden();

        // With permission the field is visible.
        [$costed, $costedToken] = $this->branchUser($branch, ['inventory.view', 'inventory.view_cost']);
        $moves = $this->requestWithToken($costedToken, 'GET', '/api/v1/admin/inventory/movements')->assertOk()->json('data');
        $this->assertArrayHasKey('unit_cost', $moves[0]);
    }

    // ---------- §15: blind stocktake ----------

    public function test_blind_stocktake_hides_system_quantities_from_counters(): void
    {
        $admin = $this->staffWithPermissions(self::ALL_PERMISSIONS);
        $adminToken = $this->tokenFor($admin);
        $branch = Branch::factory()->create(['is_active' => true]);
        $variant = ProductVariant::factory()->create(['barcode' => '6220000000011']);
        $this->seedStock($adminToken, $branch, $variant, 10);

        $take = $this->requestWithToken($adminToken, 'POST', '/api/v1/admin/stocktakes', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'counted_qty' => 4]],
        ])->assertCreated()->json('data');

        [$counter, $counterToken] = $this->branchUser($branch, ['stocktakes.view']);
        $shown = $this->requestWithToken($counterToken, 'GET', "/api/v1/admin/stocktakes/{$take['id']}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('system_qty', $shown['items'][0]);
        $this->assertArrayNotHasKey('difference', $shown['items'][0]);

        // Approver sees them even on a draft.
        [$approver, $approverToken] = $this->branchUser($branch, ['stocktakes.view', 'stocktakes.approve']);
        $full = $this->requestWithToken($approverToken, 'GET', "/api/v1/admin/stocktakes/{$take['id']}")->assertOk()->json('data');
        $this->assertArrayHasKey('system_qty', $full['items'][0]);

        // After approval everyone with view sees them.
        $this->requestWithToken($adminToken, 'POST', "/api/v1/admin/stocktakes/{$take['id']}/approve", [])->assertOk();
        $after = $this->requestWithToken($counterToken, 'GET', "/api/v1/admin/stocktakes/{$take['id']}")->assertOk()->json('data');
        $this->assertArrayHasKey('system_qty', $after['items'][0]);

        // POS scan: system_qty only for approvers.
        $take2 = $this->requestWithToken($adminToken, 'POST', '/api/v1/admin/stocktakes', [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'counted_qty' => 1]],
        ])->assertCreated()->json('data');
        [, $scanToken] = $this->posCashierWithShift($branch, ['stocktakes.update']);
        $scan = $this->posWithToken($scanToken)->postJson("/api/v1/pos/stocktakes/{$take2['id']}/scan", [
            'barcode' => '6220000000011', 'qty' => 1, 'scan_id' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertOk()->json();
        $this->assertArrayNotHasKey('system_qty', $scan);

        [, $scanApproverToken] = $this->posCashierWithShift($branch, ['stocktakes.update', 'stocktakes.approve']);
        $scan2 = $this->posWithToken($scanApproverToken)->postJson("/api/v1/pos/stocktakes/{$take2['id']}/scan", [
            'barcode' => '6220000000011', 'qty' => 1, 'scan_id' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertOk()->json();
        $this->assertArrayHasKey('system_qty', $scan2);
    }
}
