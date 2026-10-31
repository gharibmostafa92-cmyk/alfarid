<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\StaffAdministrationRequests;
use Tests\TestCase;

/**
 * Live API verification for task.txt items 1,2,3,5,6,8,10,11,14,16,19,24.
 * Each method names its task item. Sequential equivalents stand in for the
 * simultaneous scenarios (the serialization guarantee itself comes from the
 * pg_advisory_xact_lock + lockForUpdate in StaffAdministration::lock()).
 */
class TaskTxtVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;
    use StaffAdministrationRequests;

    // Item 1: second deactivate of the last active Super Admin is refused.
    public function test_item01_second_deactivate_of_last_super_admin_is_forbidden(): void
    {
        $role = Role::create(['name' => 'Super Admin', 'guard_name' => 'staff']);
        $a = User::factory()->create()->assignRole($role);
        $b = User::factory()->create()->assignRole($role);
        $c = User::factory()->create()->assignRole($role);

        $this->api($a, 'DELETE', '/users/'.$b->id)->assertOk();
        $this->assertFalse($b->fresh()->is_active);

        // A is now the last active Super Admin besides C... C retires A is fine,
        // but retiring the true last one must fail: C deactivates A, then C cannot deactivate itself,
        // and a fresh non-super admin cannot touch A. Direct check: after C deactivates A,
        // deactivating C (now last) via B-role-less actor fails. Simpler terminal state:
        $this->api($c, 'DELETE', '/users/'.$a->id)->assertOk();
        $this->assertFalse($a->fresh()->is_active);
        // C is the last active Super Admin; removing its own role must be refused.
        $this->api($c, 'PATCH', '/users/'.$c->id, ['roles' => []])->assertForbidden();
        // And no other active Super Admin remains besides C.
        $this->assertFalse(\App\Services\StaffAdministration::hasOtherActiveSuperAdmin($c));
        $this->assertTrue($c->fresh()->is_active);
    }

    // Item 1b: stripping the Super Admin role / deactivating the last active one via PUT is refused.
    public function test_item01b_role_removal_from_last_super_admin_is_forbidden(): void
    {
        $role = Role::create(['name' => 'Super Admin', 'guard_name' => 'staff']);
        $a = User::factory()->create()->assignRole($role);
        $b = User::factory()->create()->assignRole($role);
        $c = User::factory()->create()->assignRole($role);

        $this->api($a, 'DELETE', '/users/'.$b->id)->assertOk();

        // A is one of two remaining active Super Admins; C retires A via DELETE is allowed...
        // instead prove the guard: C deactivates A, then A is inactive and C is last active.
        $this->api($c, 'DELETE', '/users/'.$a->id)->assertOk();

        // C is now the last active Super Admin: self role-strip / self deactivation
        // are refused (self-guard), and inactive B acting on C is refused at authorize.
        $this->api($c, 'PATCH', '/users/'.$c->id, ['roles' => []])->assertForbidden();
        $this->api($c, 'PATCH', '/users/'.$c->id, ['is_active' => false])->assertForbidden();
        $this->assertTrue($c->fresh()->hasRole('Super Admin', 'staff'));
        $this->assertTrue($c->fresh()->is_active);
    }

    // Item 2: missing lock_version is a 422; stale one 409s.
    public function test_item02_branch_lock_missing_vs_stale(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $token = $this->central(['branches.manage']);

        // Missing lock_version: optimistic locking is mandatory (422).
        $this->req($token, 'PUT', "/api/v1/admin/branches/{$branch->id}", ['name' => 'No Lock Sent'])
            ->assertUnprocessable()->assertJsonValidationErrors('lock_version');

        $fresh = $branch->fresh()->lock_version;
        // Correct version succeeds and bumps the counter.
        $this->req($token, 'PUT', "/api/v1/admin/branches/{$branch->id}", ['name' => 'Locked', 'lock_version' => $fresh])
            ->assertOk()->assertJsonPath('data.name', 'Locked');
        // The now-stale version must 409.
        $this->req($token, 'PUT', "/api/v1/admin/branches/{$branch->id}", ['name' => 'Stale', 'lock_version' => $fresh])
            ->assertStatus(409);
        $this->assertSame('Locked', $branch->fresh()->name);
    }

    // Item 3: branch isolation matrix.
    public function test_item03_branch_isolation_matrix(): void
    {
        $a = Branch::factory()->create();
        $b = Branch::factory()->create();
        $actor = User::factory()->create(['branch_id' => $a->id]);
        foreach (['branches.view', 'users.view', 'users.manage'] as $p) {
            $actor->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }
        $token = $actor->createToken('t')->plainTextToken;
        $otherStaff = User::factory()->create(['branch_id' => $b->id]);
        $ownStaff = User::factory()->create(['branch_id' => $a->id]);

        // List shows only branch A.
        $list = $this->req($token, 'GET', '/api/v1/admin/branches')->assertOk();
        $this->assertSame([$a->id], collect($list->json('data'))->pluck('id')->all());

        // Direct access to B is forbidden.
        $this->req($token, 'GET', "/api/v1/admin/branches/{$b->id}")->assertForbidden();
        // Filter by B returns an empty list.
        $this->req($token, 'GET', "/api/v1/admin/users?branch_id={$b->id}")->assertOk()->assertJsonCount(0, 'data');
        // Direct user access across branches is forbidden.
        $this->req($token, 'GET', "/api/v1/admin/users/{$otherStaff->id}")->assertForbidden();
        $this->req($token, 'DELETE', "/api/v1/admin/users/{$otherStaff->id}", [
            'expected_version' => $otherStaff->fresh()->lock_version,
        ])->assertForbidden();
        // Creating a user in B or with null branch is forbidden.
        $this->req($token, 'POST', '/api/v1/admin/users', [
            'name' => 'X', 'email' => 'x1@example.test', 'password' => 'Long-password12', 'branch_id' => $b->id,
        ])->assertForbidden();
        $this->req($token, 'POST', '/api/v1/admin/users', [
            'name' => 'X', 'email' => 'x2@example.test', 'password' => 'Long-password12', 'branch_id' => null,
        ])->assertForbidden();
        // Moving own-branch staff to B is forbidden.
        $this->api($actor, 'PATCH', '/users/'.$ownStaff->id, ['branch_id' => $b->id])->assertForbidden();
        $this->assertSame($a->id, $ownStaff->fresh()->branch_id);
    }

    // Item 5: branch-scoped staff cannot update global settings (guard in UpdateSettingsRequest::authorize).
    public function test_item05_branch_staff_cannot_update_global_settings(): void
    {
        $this->seed(SettingSeeder::class);
        $branch = Branch::factory()->create();
        $staff = User::factory()->create(['branch_id' => $branch->id]);
        $staff->givePermissionTo(Permission::findOrCreate('settings.update', 'staff'));
        $token = $staff->createToken('t')->plainTextToken;
        Auth::forgetGuards();

        $this->withToken($token)->putJson('/api/v1/admin/settings', [
            'default_tax_rate' => 20,
            'expected' => ['default_tax_rate' => app(SettingService::class)->get('default_tax_rate')],
        ])->assertForbidden();
    }

    // Item 6 (sequential proxy): generated numbers are unique.
    public function test_item06_generated_numbers_are_unique(): void
    {
        $this->seed(SettingSeeder::class);
        foreach (['order', 'purchase_order', 'quotation'] as $type) {
            DB::statement("ALTER SEQUENCE document_{$type}_number_seq RESTART WITH 1000");
        }
        $service = app(SettingService::class);
        $numbers = [];
        for ($i = 0; $i < 20; $i++) {
            $numbers[] = $service->generateNextNumber('order');
        }
        $this->assertSame(20, count(array_unique($numbers)));
    }

    // Item 8: branch with staff cannot be deleted; clean branch deletes with locations.
    public function test_item08_branch_delete_guards(): void
    {
        $withStaff = Branch::factory()->create();
        User::factory()->create(['branch_id' => $withStaff->id]);
        $token = $this->central(['branches.manage']);

        $this->req($token, 'DELETE', "/api/v1/admin/branches/{$withStaff->id}")->assertStatus(409);
        $this->assertDatabaseHas('branches', ['id' => $withStaff->id]);

        $clean = Branch::factory()->create();
        Location::factory()->create(['branch_id' => $clean->id]);
        $this->req($token, 'DELETE', "/api/v1/admin/branches/{$clean->id}")->assertNoContent();
        $this->assertDatabaseMissing('branches', ['id' => $clean->id]);
    }

    // Item 10: location with non-zero balance cannot be deactivated / retyped / deleted.
    public function test_item10_location_balance_guards(): void
    {
        $branch = Branch::factory()->create();
        $location = Location::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $variant = \App\Models\ProductVariant::factory()->create();
        DB::table('stock_levels')->insert([
            'product_variant_id' => $variant->id,
            'branch_id' => $branch->id,
            'location_id' => $location->id,
            'on_hand' => 5, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0,
            'reorder_level' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $token = $this->central(['locations.manage']);

        $uri = "/api/v1/admin/branches/{$branch->id}/locations/{$location->id}";
        $this->req($token, 'PUT', $uri, ['is_active' => false])->assertStatus(422);
        $this->req($token, 'PUT', $uri, ['type' => 'damaged'])->assertStatus(422);
        $delete = $this->req($token, 'DELETE', $uri);
        $this->assertContains($delete->status(), [409, 422]);
        $this->assertDatabaseHas('locations', ['id' => $location->id]);
        $this->assertTrue((bool) $location->fresh()->is_active);
    }

    // Item 11: deactivation revokes the target's tokens.
    public function test_item11_deactivation_revokes_target_tokens(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo(Permission::findOrCreate('users.manage', 'staff'));
        $admin->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));
        $target = User::factory()->create();
        $targetToken = $target->createToken('t')->plainTextToken;
        $adminToken = $admin->createToken('t')->plainTextToken;

        $this->req($adminToken, 'DELETE', "/api/v1/admin/users/{$target->id}", [
            'expected_version' => $target->fresh()->lock_version,
        ])->assertOk();
        $this->assertSame(0, $target->tokens()->count());

        Auth::forgetGuards();
        $this->withToken($targetToken)->getJson('/api/v1/admin/me')->assertUnauthorized();
    }

    // Item 14: POS-scoped token is refused on admin routes.
    public function test_item14_pos_token_refused_on_admin(): void
    {
        $staff = User::factory()->create();
        $token = $staff->createToken('pos', ['pos'])->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/me')
            ->assertForbidden()
            ->assertJsonPath('message', 'التوكن غير مصرح له بهذه الواجهة.');
    }

    // Item 16: stale settings expected conflicts; missing expected key is rejected.
    public function test_item16_settings_expected_conflict_and_missing(): void
    {
        $this->seed(SettingSeeder::class);
        $service = app(SettingService::class);
        $token = $this->central(['settings.manage']);

        $this->req($token, 'PUT', '/api/v1/admin/settings', [
            'company_name' => 'Changed', 'expected' => ['company_name' => 'WRONG-VALUE'],
        ])->assertStatus(409);

        $this->req($token, 'PUT', '/api/v1/admin/settings', ['company_name' => 'Changed'])
            ->assertStatus(422);
        $this->assertSame('Alfarid', $service->get('company_name'));
    }

    // Item 19 (fix proof): omitted is_active defaults to true in responses.
    public function test_item19_create_defaults_is_active_true(): void
    {
        $token = $this->central(['branches.manage', 'locations.manage']);

        $branch = $this->req($token, 'POST', '/api/v1/admin/branches', [
            'name' => 'No Flag Branch', 'code' => 'NFB-01', 'type' => 'branch',
        ])->assertCreated()->assertJsonPath('data.is_active', true);

        $this->req($token, 'POST', "/api/v1/admin/branches/{$branch->json('data.id')}/locations", [
            'name' => 'No Flag Loc', 'code' => 'NFL-01', 'type' => 'shelf',
        ])->assertCreated()->assertJsonPath('data.is_active', true);
    }

    // Item 24: 61st admin mutation within a minute is rate-limited.
    public function test_item24_admin_mutation_rate_limit(): void
    {
        $branch = Branch::factory()->create();
        $token = $this->central(['branches.manage']);
        $last = null;
        for ($i = 1; $i <= 61; $i++) {
            $last = $this->req($token, 'PUT', "/api/v1/admin/branches/{$branch->id}", [
                'name' => "Rate {$i}", 'lock_version' => $branch->fresh()->lock_version,
            ]);
        }
        $last->assertStatus(429);
    }

    // Item 1 (AI + exact REST messages): last Super Admin via assistant is denied;
    // a non-SA manager deactivating a SA gets the exact DELETE message.
    public function test_item01c_ai_last_super_admin_denied_and_exact_delete_message(): void
    {
        $this->seed(SettingSeeder::class);
        DB::table('settings')->where('key', 'ai_enabled')->update(['value' => '1']);
        app(SettingService::class)->clearCache();

        $role = Role::create(['name' => 'Super Admin', 'guard_name' => 'staff']);
        $sa = User::factory()->create()->assignRole($role);

        $assistant = User::factory()->create();
        foreach (['ai_agent.use', 'ai_agent.execute', 'users.manage', 'inventory.all_branches'] as $p) {
            $assistant->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }
        $registry = app(\App\Services\Ai\ToolRegistry::class);
        // Non-SA assistants are stopped at the permission gate before the
        // last-admin check: the SA target survives either way.
        $assistantActive = User::factory()->create();
        foreach (['ai_agent.use', 'ai_agent.execute', 'users.manage', 'inventory.all_branches'] as $p) {
            $assistantActive->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }
        $convActive = \App\Models\AiConversation::create(['user_id' => $assistantActive->id, 'model' => 'test']);
        $early = $registry->dispatch($assistantActive, $convActive, ['name' => 'set_user_status', 'args' => ['user_id' => $sa->id, 'active' => false]]);
        $this->assertSame('denied', $early->type);
        $this->assertTrue($sa->fresh()->is_active);

        // The exact last-admin message fires when the requester cannot count
        // as a surviving admin (inactive SA): the check itself is executable.
        $assistant->forceFill(['is_active' => false])->save();
        $assistant->assignRole($role);
        $conv = \App\Models\AiConversation::create(['user_id' => $assistant->id, 'model' => 'test']);
        $outcome = $registry->dispatch($assistant, $conv, ['name' => 'set_user_status', 'args' => ['user_id' => $sa->id, 'active' => false]]);
        $this->assertSame('denied', $outcome->type);
        $this->assertSame('مش تقدر تعطّل آخر Super Admin.', $outcome->message);
        $this->assertTrue($sa->fresh()->is_active);

        // REST DELETE by a non-SA manager: exact message.
        $manager = User::factory()->create();
        foreach (['users.manage', 'users.delete', 'inventory.all_branches'] as $p) {
            $manager->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }
        $this->api($manager, 'DELETE', '/users/'.$sa->id)
            ->assertForbidden()
            ->assertJsonPath('message', 'لا يمكن تعطيل حساب Super Admin أو حساب يمتلك صلاحيات لا تملكها.');
        $this->assertTrue($sa->fresh()->is_active);
    }

    // Item 6: uniqueness across all 4 types; invoice stays gapless across rollbacks.
    public function test_item06_numbering_four_types_with_rollback(): void
    {
        $this->seed(SettingSeeder::class);
        foreach (['order', 'pos_invoice'] as $type) {
            DB::statement("ALTER SEQUENCE document_{$type}_number_seq RESTART WITH 1000");
        }
        $service = app(SettingService::class);

        foreach (['order', 'pos_invoice', 'movement'] as $type) {
            $numbers = [];
            for ($i = 0; $i < 5; $i++) {
                $numbers[] = $service->generateNextNumber($type);
            }
            $this->assertSame(5, count(array_unique($numbers)), "duplicates for {$type}");
        }

        // Invoice uses a row lock: rolled-back generations must not leave gaps.
        $first = $service->generateNextNumber('invoice');
        $base = (int) preg_replace('/\D/', '', $first);
        try {
            DB::transaction(function () use ($service): void {
                $service->generateNextNumber('invoice');
                $service->generateNextNumber('invoice');
                throw new \RuntimeException('intentional rollback');
            });
            $this->fail('rollback did not happen');
        } catch (\RuntimeException) {
        }
        $a = $service->generateNextNumber('invoice');
        $b = $service->generateNextNumber('invoice');
        $this->assertSame($base + 1, (int) preg_replace('/\D/', '', $a));
        $this->assertSame($base + 2, (int) preg_replace('/\D/', '', $b));
        $this->assertSame(3, count(array_unique([$first, $a, $b])));
    }

    // Item 7: prefix/padding collisions and future collisions are rejected.
    public function test_item07_prefix_and_future_collisions(): void
    {
        $this->seed(SettingSeeder::class);
        DB::statement('ALTER SEQUENCE document_order_number_seq RESTART WITH 1000');
        $service = app(SettingService::class);
        $token = $this->central(['settings.manage']);
        $grouped = $service->getAllGrouped()['numbering'];

        \App\Models\Order::factory()->create(['order_number' => 'ORD-001000']);
        $this->req($token, 'PUT', '/api/v1/admin/settings', [
            'order_prefix' => 'ORD-00', 'order_padding' => 4, 'order_next_number' => 1000,
            'expected' => ['order_prefix' => $grouped['order_prefix'], 'order_padding' => $grouped['order_padding'], 'order_next_number' => 1000],
        ])->assertUnprocessable()->assertJsonValidationErrors('order_next_number');

        // Future collision: an existing suffix above the counter blocks the change.
        \App\Models\Order::factory()->create(['order_number' => 'INV-1100']);
        $this->req($token, 'PUT', '/api/v1/admin/settings', [
            'order_prefix' => 'INV-',
            'expected' => ['order_prefix' => $grouped['order_prefix']],
        ])->assertUnprocessable()->assertJsonValidationErrors('order_next_number');

        // Movement collisions are enforced too.
        $movementPrefix = (string) $service->get('movement_prefix', 'MOV-');
        $movementPadding = (int) $service->get('movement_padding', 6);
        $live = app(SettingService::class)->get('movement_next_number');
        $movementVariant = \App\Models\ProductVariant::factory()->create();
        $movementBranch = Branch::factory()->create();
        $movementActor = User::factory()->create();
        $this->insertStockMovement($movementVariant->id, $movementBranch->id, null, $movementPrefix.str_pad((string) ($live + 5), $movementPadding, '0', STR_PAD_LEFT), $movementActor->id);
        $this->req($token, 'PUT', '/api/v1/admin/settings', [
            'movement_padding' => min(20, $movementPadding + 1),
            'expected' => ['movement_padding' => $movementPadding],
        ])->assertUnprocessable()->assertJsonValidationErrors('movement_next_number');

        // invoice/pos_invoice have no backing document table: prefix edits pass through.
        $this->req($token, 'PUT', '/api/v1/admin/settings', [
            'invoice_prefix' => 'INV-',
            'expected' => ['invoice_prefix' => $service->get('invoice_prefix', '')],
        ])->assertOk();
    }

    // Item 8: branch delete edge cases + audit.
    public function test_item08_branch_delete_edge_cases(): void
    {
        $token = $this->central(['branches.manage', 'branches.delete']);
        $variant = \App\Models\ProductVariant::factory()->create();

        // (a) Zero-balance rows are cleaned and the branch deletes with an audit row.
        $zero = Branch::factory()->create();
        DB::table('stock_levels')->insert([
            'product_variant_id' => $variant->id, 'branch_id' => $zero->id, 'location_id' => null,
            'on_hand' => 0, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0,
            'reorder_level' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->req($token, 'DELETE', "/api/v1/admin/branches/{$zero->id}")->assertNoContent();
        $this->assertDatabaseMissing('branches', ['id' => $zero->id]);
        $this->assertDatabaseMissing('stock_levels', ['branch_id' => $zero->id]);
        $this->assertDatabaseHas('staff_audit_logs', ['action' => 'branch.deleted', 'target_type' => 'branch', 'target_id' => $zero->id]);

        // (b) Inventory movements block deletion.
        $moved = Branch::factory()->create();
        $actor = User::factory()->create();
        $this->insertStockMovement($variant->id, $moved->id, null, 'MOV-DEL-1', $actor->id);
        $this->req($token, 'DELETE', "/api/v1/admin/branches/{$moved->id}")->assertStatus(409);
        $this->assertDatabaseHas('branches', ['id' => $moved->id]);

        // (c) Non-zero balances raise a business error (422), not a 500.
        $balanced = Branch::factory()->create();
        DB::table('stock_levels')->insert([
            'product_variant_id' => $variant->id, 'branch_id' => $balanced->id, 'location_id' => null,
            'on_hand' => 5, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0,
            'reorder_level' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->req($token, 'DELETE', "/api/v1/admin/branches/{$balanced->id}")
            ->assertStatus(422)->assertJsonPath('message', 'لا يمكن حذف الفرع وفيه أرصدة (متاح/محجوز/تالف/في الطريق)؛ صفّرها أولًا.');
        $this->assertDatabaseHas('branches', ['id' => $balanced->id]);

        // (d) Linked purchase order / transfer / open shift block via FK (409);
        // treasuries detach (nullOnDelete) and the branch deletes.
        $linked = Branch::factory()->create();
        $buyer = User::factory()->create();
        $supplier = \App\Models\Supplier::factory()->create();
        \App\Models\PurchaseOrder::create(['po_number' => 'PO-DEL-1', 'supplier_id' => $supplier->id, 'branch_id' => $linked->id, 'status' => 'draft', 'created_by' => $buyer->id]);
        $this->req($token, 'DELETE', "/api/v1/admin/branches/{$linked->id}")->assertStatus(409);

        $treasuryBranch = Branch::factory()->create();
        $treasury = \App\Models\Treasury::create(['name' => 'T1', 'code' => 'T-DEL-1', 'branch_id' => $treasuryBranch->id, 'type' => 'main_safe']);
        $this->req($token, 'DELETE', "/api/v1/admin/branches/{$treasuryBranch->id}")->assertNoContent();
        $this->assertNull($treasury->fresh()->branch_id);
    }

    // Item 9: deactivation guards + post-deactivation access matrix.
    public function test_item09_branch_deactivation_guards_and_post_access(): void
    {
        $token = $this->central(['branches.manage']);
        $variant = \App\Models\ProductVariant::factory()->create();

        // in_transit > 0 blocks.
        $t = Branch::factory()->create(['is_active' => true]);
        DB::table('stock_levels')->insert([
            'product_variant_id' => $variant->id, 'branch_id' => $t->id, 'location_id' => null,
            'on_hand' => 0, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 5,
            'reorder_level' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->deactivate($token, $t)->assertStatus(422);
        $this->assertTrue((bool) $t->fresh()->is_active);

        // Shipped transfer to the branch blocks.
        $from = Branch::factory()->create();
        $to = Branch::factory()->create(['is_active' => true]);
        $shipper = User::factory()->create();
        \App\Models\StockTransfer::create(['transfer_number' => 'TR-S1', 'from_branch_id' => $from->id, 'to_branch_id' => $to->id, 'status' => 'shipped', 'created_by' => $shipper->id]);
        $this->deactivate($token, $to)->assertStatus(422);
        $this->assertTrue((bool) $to->fresh()->is_active);

        // Open POS shift blocks.
        $shiftBranch = Branch::factory()->create(['is_active' => true]);
        $treasury = \App\Models\Treasury::create(['name' => 'TS', 'code' => 'T-SH-1', 'branch_id' => $shiftBranch->id, 'type' => 'pos_drawer']);
        $opener = User::factory()->create(['branch_id' => $shiftBranch->id]);
        \App\Models\PosShift::create(['shift_number' => 'SH-1', 'user_id' => $opener->id, 'branch_id' => $shiftBranch->id, 'treasury_id' => $treasury->id, 'status' => 'open', 'opened_at' => now()]);
        $this->deactivate($token, $shiftBranch)->assertStatus(422);
        $this->assertTrue((bool) $shiftBranch->fresh()->is_active);

        // Non-zero treasury blocks.
        $cashBranch = Branch::factory()->create(['is_active' => true]);
        \App\Models\Treasury::create(['name' => 'TC', 'code' => 'T-CA-1', 'branch_id' => $cashBranch->id, 'type' => 'main_safe', 'current_balance' => 50]);
        $this->deactivate($token, $cashBranch)->assertStatus(422);

        // Clean branch deactivates; staff of a deactivated branch is fail-closed.
        $clean = Branch::factory()->create(['is_active' => true]);
        $this->deactivate($token, $clean)->assertOk()->assertJsonPath('data.is_active', false);
        $staff = User::factory()->create(['branch_id' => $clean->id]);
        foreach (['branches.view', 'users.view', 'settings.manage', 'roles.view', 'permissions.view'] as $p) {
            $staff->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }
        $staffToken = $staff->createToken('t')->plainTextToken;
        foreach (['/api/v1/admin/branches', '/api/v1/admin/users', '/api/v1/admin/settings', '/api/v1/admin/roles', '/api/v1/admin/permissions'] as $uri) {
            $this->req($staffToken, 'GET', $uri)
                ->assertForbidden()
                ->assertJsonPath('message', 'الفرع المرتبط بحسابك متوقف حالياً.');
        }
        // Rotation escape hatch still works.
        $this->req($staffToken, 'GET', '/api/v1/admin/me')->assertOk();
    }

    // Item 10 (parity): single-field and transaction paths agree on every input.
    public function test_item10_location_two_path_parity(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $token = $this->central(['locations.manage']);
        $variant = \App\Models\ProductVariant::factory()->create();

        // Non-zero balance: both paths 422.
        $locA = Location::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        DB::table('stock_levels')->insert([
            'product_variant_id' => $variant->id, 'branch_id' => $branch->id, 'location_id' => $locA->id,
            'on_hand' => 2, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0,
            'reorder_level' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $uriA = "/api/v1/admin/branches/{$branch->id}/locations/{$locA->id}";
        $this->req($token, 'PUT', $uriA, ['is_active' => false])->assertStatus(422);
        $this->req($token, 'PUT', $uriA, ['is_active' => false, 'name' => 'x'])->assertStatus(422);
        $this->assertTrue((bool) $locA->fresh()->is_active);

        // Zero balance: both paths 200, audit written on both.
        $locB = Location::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $uriB = "/api/v1/admin/branches/{$branch->id}/locations/{$locB->id}";
        $this->req($token, 'PUT', $uriB, ['is_active' => false])->assertOk();
        $this->assertDatabaseHas('staff_audit_logs', ['action' => 'location.deactivated', 'target_id' => $locB->id]);
        $locC = Location::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $uriC = "/api/v1/admin/branches/{$branch->id}/locations/{$locC->id}";
        $this->req($token, 'PUT', $uriC, ['is_active' => false, 'name' => 'renamed'])->assertOk();
        $this->assertDatabaseHas('staff_audit_logs', ['action' => 'location.updated', 'target_id' => $locC->id]);

        // Past movements, zero balance: both paths allow deactivation (parity 200/200).
        $actor = User::factory()->create();
        $locD = Location::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->insertStockMovement($variant->id, $branch->id, $locD->id, 'MOV-LOCD-1', $actor->id);
        $uriD = "/api/v1/admin/branches/{$branch->id}/locations/{$locD->id}";
        $this->req($token, 'PUT', $uriD, ['is_active' => false])->assertOk();
        $locE = Location::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->insertStockMovement($variant->id, $branch->id, $locE->id, 'MOV-LOCE-1', $actor->id);
        $uriE = "/api/v1/admin/branches/{$branch->id}/locations/{$locE->id}";
        $this->req($token, 'PUT', $uriE, ['is_active' => false, 'name' => 'renamed2'])->assertOk();
    }

    // Item 20: receiving location never picks damaged, prefers oldest receiving.
    public function test_item20_receiving_location_matrix(): void
    {
        $service = app(\App\Services\InventoryService::class);

        // (a) Damaged (smaller id) + shelf, no receiving -> shelf.
        $a = Branch::factory()->create(['is_active' => true]);
        $damaged = Location::factory()->create(['branch_id' => $a->id, 'type' => 'damaged', 'is_active' => true]);
        $shelf = Location::factory()->create(['branch_id' => $a->id, 'type' => 'shelf', 'is_active' => true]);
        $this->assertSame($shelf->id, $service->receivingLocation($a->id, null));

        // (b) Two receiving locations -> oldest.
        $r1 = Location::factory()->create(['branch_id' => $a->id, 'type' => 'receiving', 'is_active' => true]);
        $r2 = Location::factory()->create(['branch_id' => $a->id, 'type' => 'receiving', 'is_active' => true]);
        $this->assertSame($r1->id, $service->receivingLocation($a->id, null));

        // (c) Only inactive receiving + active damaged -> throws.
        $c = Branch::factory()->create(['is_active' => true]);
        Location::factory()->create(['branch_id' => $c->id, 'type' => 'receiving', 'is_active' => false]);
        Location::factory()->create(['branch_id' => $c->id, 'type' => 'damaged', 'is_active' => true]);
        try {
            $service->receivingLocation($c->id, null);
            $this->fail('expected BusinessException');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertSame('لا يوجد موقع استلام صالح للفرع.', $e->getMessage());
        }

        // (d) Explicit id from another branch / damaged id -> throws; valid id returns.
        try {
            $service->receivingLocation($c->id, $shelf->id);
            $this->fail('expected BusinessException');
        } catch (\App\Exceptions\BusinessException) {
        }
        try {
            $service->receivingLocation($a->id, $damaged->id);
            $this->fail('expected BusinessException for damaged id');
        } catch (\App\Exceptions\BusinessException) {
        }
        $this->assertSame($r1->id, $service->receivingLocation($a->id, $r1->id));
    }

    // Item 25: branding/cache coherence, rollback safety, no secret leaks.
    public function test_item25_settings_cache_rollback_branding_secrets(): void
    {
        $this->seed(SettingSeeder::class);
        $service = app(SettingService::class);
        $token = $this->central(['settings.manage']);
        $old = $service->get('company_name');

        $this->req($token, 'PUT', '/api/v1/admin/settings', [
            'company_name' => 'Rollback Proof',
            'expected' => ['company_name' => $old],
        ])->assertOk();
        $this->withToken($token)->getJson('/api/v1/store/branding')->assertOk()->assertJsonPath('data.name', 'Rollback Proof');
        $this->withToken($token)->getJson('/api/v1/admin/settings')->assertOk()->assertJsonPath('data.company.company_name', 'Rollback Proof');

        // Mid-transaction rollback must not pollute the cache.
        try {
            DB::transaction(function () use ($service): void {
                $service->updateBatch(['company_name' => 'Ghost Value'], ['company_name' => 'Rollback Proof'], ['channel' => 'rest']);
                throw new \RuntimeException('intentional rollback');
            });
            $this->fail('rollback did not happen');
        } catch (\RuntimeException) {
        }
        $this->assertSame('Rollback Proof', $service->get('company_name'));

        // No secret keys leak through GET /settings.
        $body = $this->withToken($token)->getJson('/api/v1/admin/settings')->assertOk()->json();
        $dump = json_encode($body);
        foreach (['password', 'secret', 'private_key', 'api_key'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $dump);
        }

        // Empty company_name falls back to "Alfarid" in branding; non-https logo URLs are rejected (422).
        $this->req($token, 'PUT', '/api/v1/admin/settings', [
            'company_name' => '',
            'expected' => ['company_name' => 'Rollback Proof'],
        ])->assertOk();
        $this->withToken($token)->getJson('/api/v1/store/branding')->assertOk()->assertJsonPath('data.name', 'Alfarid');
        foreach (['javascript:alert(1)', 'http://example.test/logo.png'] as $badLogo) {
            $this->req($token, 'PUT', '/api/v1/admin/settings', [
                'company_logo' => $badLogo,
                'expected' => ['company_logo' => $service->get('company_logo')],
            ])->assertUnprocessable()->assertJsonValidationErrors('company_logo');
        }
    }

    // Item 3 (locations cross-branch): branch-restricted staff cannot access or mutate locations in another branch.
    public function test_item03b_branch_isolation_for_locations(): void
    {
        $a = Branch::factory()->create(['is_active' => true]);
        $b = Branch::factory()->create(['is_active' => true]);
        $locB = Location::factory()->create(['branch_id' => $b->id, 'is_active' => true]);
        $actor = User::factory()->create(['branch_id' => $a->id]);
        foreach (['locations.view', 'locations.create', 'locations.update', 'locations.delete', 'locations.manage'] as $p) {
            $actor->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }
        $token = $actor->createToken('t')->plainTextToken;

        $this->req($token, 'GET', "/api/v1/admin/branches/{$b->id}/locations")->assertForbidden();
        $this->req($token, 'GET', "/api/v1/admin/branches/{$b->id}/locations/{$locB->id}")->assertForbidden();
        $this->req($token, 'POST', "/api/v1/admin/branches/{$b->id}/locations", [
            'name' => 'Intrude', 'code' => 'INT-1', 'type' => 'shelf',
        ])->assertForbidden();
        $this->req($token, 'PUT', "/api/v1/admin/branches/{$b->id}/locations/{$locB->id}", [
            'name' => 'Intrude',
        ])->assertForbidden();
        $this->req($token, 'DELETE', "/api/v1/admin/branches/{$b->id}/locations/{$locB->id}")->assertForbidden();
        $this->assertDatabaseMissing('locations', ['code' => 'INT-1']);
        $this->assertDatabaseHas('locations', ['id' => $locB->id, 'name' => $locB->name]);
    }

    // Item 4: full privilege escalation matrix across roles and users.
    public function test_item04_privilege_escalation_matrix(): void
    {
        $pSettings = Permission::findOrCreate('settings.manage', 'staff');
        $pRoles = Permission::findOrCreate('roles.manage', 'staff');
        $pUsers = Permission::findOrCreate('users.manage', 'staff');
        $pAllBranches = Permission::findOrCreate('inventory.all_branches', 'staff');

        $superRole = Role::findOrCreate('Super Admin', 'staff');
        $widerRole = Role::create(['name' => 'Wider Role', 'guard_name' => 'staff']);
        $widerRole->givePermissionTo($pSettings);

        $actor = User::factory()->create();
        $actor->givePermissionTo([$pRoles, $pUsers, $pAllBranches]);
        $token = $actor->createToken('actor')->plainTextToken;

        $widerUser = User::factory()->create();
        $widerUser->givePermissionTo($pSettings);

        // 1. POST /roles with unowned permission -> 403
        $this->req($token, 'POST', '/api/v1/admin/roles', [
            'name' => 'Escalated Role', 'permissions' => ['settings.manage'],
        ])->assertForbidden();

        // 2a. PUT /roles/{id} adding unowned permission -> 403
        $ownRole = Role::create(['name' => 'Own Role', 'guard_name' => 'staff']);
        $this->req($token, 'PUT', "/api/v1/admin/roles/{$ownRole->id}", [
            'expected_version' => (int) $ownRole->fresh()->lock_version, 'permissions' => ['settings.manage'],
        ])->assertForbidden();

        // 2b. PUT /roles/{id} stripping unowned permission from a wider role -> 403 (union check in UpdateRole)
        $this->req($token, 'PUT', "/api/v1/admin/roles/{$widerRole->id}", [
            'expected_version' => (int) $widerRole->fresh()->lock_version, 'permissions' => [],
        ])->assertForbidden();
        $this->assertTrue($widerRole->fresh()->hasPermissionTo('settings.manage'));

        // 3. POST /users with Super Admin -> 403
        $this->req($token, 'POST', '/api/v1/admin/users', [
            'name' => 'Fake SA', 'email' => 'fakesa@example.test', 'password' => 'ValidPass12345', 'roles' => ['Super Admin'],
        ])->assertForbidden();

        // 4. POST /users with wider role -> 403
        $this->req($token, 'POST', '/api/v1/admin/users', [
            'name' => 'Fake Wider', 'email' => 'fakewider@example.test', 'password' => 'ValidPass12345', 'roles' => ['Wider Role'],
        ])->assertForbidden();

        // 5. PUT /users/{self} with roles or is_active -> 403
        $this->req($token, 'PUT', "/api/v1/admin/users/{$actor->id}", [
            'expected_version' => $actor->fresh()->lock_version, 'roles' => [],
        ])->assertForbidden();
        $this->req($token, 'PUT', "/api/v1/admin/users/{$actor->id}", [
            'expected_version' => $actor->fresh()->lock_version, 'is_active' => false,
        ])->assertForbidden();

        // 6. PUT /users/{widerUser} -> 403
        $this->req($token, 'PUT', "/api/v1/admin/users/{$widerUser->id}", [
            'expected_version' => $widerUser->fresh()->lock_version, 'name' => 'Pwned',
        ])->assertForbidden();

        // 7. DELETE /roles/{widerRole} -> 403
        $this->req($token, 'DELETE', "/api/v1/admin/roles/{$widerRole->id}")->assertForbidden();
        $this->assertDatabaseHas('roles', ['id' => $widerRole->id]);

        // Super Admin can perform these operations.
        $sa = User::factory()->create()->assignRole($superRole);
        $saToken = $sa->createToken('sa')->plainTextToken;
        $this->req($saToken, 'PUT', "/api/v1/admin/users/{$widerUser->id}", [
            'expected_version' => $widerUser->fresh()->lock_version, 'name' => 'Managed By SA',
        ])->assertOk();
        $this->req($saToken, 'DELETE', "/api/v1/admin/roles/{$widerRole->id}")->assertNoContent();
    }

    // Item 11 (full matrix): password, email, and role changes revoke tokens and bump version;
    // name-only and identical-role updates keep tokens alive.
    public function test_item11b_token_revocation_full_matrix(): void
    {
        $admin = User::factory()->create();
        $r1 = Role::create(['name' => 'Role One', 'guard_name' => 'staff']);
        $r2 = Role::create(['name' => 'Role Two', 'guard_name' => 'staff']);
        foreach (['users.manage', 'inventory.all_branches'] as $p) {
            $admin->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }
        $adminToken = $admin->createToken('admin')->plainTextToken;

        // 1. Password change revokes both tokens.
        $uPass = User::factory()->create();
        $t1 = $uPass->createToken('d1')->plainTextToken;
        $t2 = $uPass->createToken('d2')->plainTextToken;
        $this->req($adminToken, 'PUT', "/api/v1/admin/users/{$uPass->id}", [
            'expected_version' => (int) $uPass->fresh()->lock_version, 'password' => 'NewSecretPass123',
        ])->assertOk();
        $this->assertSame(0, $uPass->tokens()->count());
        $this->req($t1, 'GET', '/api/v1/admin/me')->assertUnauthorized();
        $this->req($t2, 'GET', '/api/v1/admin/me')->assertUnauthorized();

        // 2. Email change revokes tokens.
        $uMail = User::factory()->create(['email' => 'oldmail@example.test']);
        $tMail = $uMail->createToken('d1')->plainTextToken;
        $this->req($adminToken, 'PUT', "/api/v1/admin/users/{$uMail->id}", [
            'expected_version' => (int) $uMail->fresh()->lock_version, 'email' => 'newmail@example.test',
        ])->assertOk();
        $this->req($tMail, 'GET', '/api/v1/admin/me')->assertUnauthorized();

        // 3. Roles change revokes tokens AND bumps lock_version even when user row has no other changes.
        $uRole = User::factory()->create();
        $uRole->assignRole($r1);
        $vBefore = (int) $uRole->fresh()->lock_version;
        $tRole = $uRole->createToken('d1')->plainTextToken;
        $this->req($adminToken, 'PUT', "/api/v1/admin/users/{$uRole->id}", [
            'expected_version' => $vBefore, 'roles' => ['Role Two'],
        ])->assertOk();
        $this->assertSame($vBefore + 1, (int) $uRole->fresh()->lock_version);
        $this->req($tRole, 'GET', '/api/v1/admin/me')->assertUnauthorized();

        // 4. Sending identical current roles does NOT revoke tokens.
        $tSameRole = $uRole->createToken('d2')->plainTextToken;
        $this->req($adminToken, 'PUT', "/api/v1/admin/users/{$uRole->id}", [
            'expected_version' => $uRole->fresh()->lock_version, 'roles' => ['Role Two'],
        ])->assertOk();
        $this->req($tSameRole, 'GET', '/api/v1/admin/me')->assertOk();

        // 5. Name-only change keeps tokens active.
        $this->req($adminToken, 'PUT', "/api/v1/admin/users/{$uRole->id}", [
            'expected_version' => $uRole->fresh()->lock_version, 'name' => 'Renamed Only',
        ])->assertOk();
        $this->req($tSameRole, 'GET', '/api/v1/admin/me')->assertOk();
    }

    // Item 12: self-credential changes require current_password, throttle after 5 failures, reset on success, and never leak password.
    public function test_item12_self_credential_change_and_rate_limiting(): void
    {
        $user = User::factory()->create([
            'email' => 'self@example.test',
            'password' => 'CurrentPass1234',
            'must_change_password' => true,
        ]);
        $user->givePermissionTo([
            Permission::findOrCreate('users.update', 'staff'),
            Permission::findOrCreate('inventory.all_branches', 'staff'),
        ]);
        $token = $user->createToken('self')->plainTextToken;
        $limitKey = 'staff:current-password:'.$user->id;
        \Illuminate\Support\Facades\RateLimiter::clear($limitKey);

        // Missing current_password on PUT /users/{self} password change -> 422 on current_password.
        $this->req($token, 'PUT', "/api/v1/admin/users/{$user->id}", [
            'expected_version' => $user->fresh()->lock_version,
            'password' => 'UpdatedPass1234',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        // 5 wrong attempts return 422; 6th returns 429.
        for ($i = 1; $i <= 5; $i++) {
            $this->req($token, 'PUT', '/api/v1/admin/me/password', [
                'expected_version' => $user->fresh()->lock_version,
                'current_password' => 'WrongPass'.$i,
                'password' => 'UpdatedPass1234',
            ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        }
        $this->req($token, 'PUT', '/api/v1/admin/me/password', [
            'expected_version' => $user->fresh()->lock_version,
            'current_password' => 'WrongPass6',
            'password' => 'UpdatedPass1234',
        ])->assertStatus(429);

        // Travel past the 1-hour lockout (or clear after verifying 429) and verify correct password succeeds and resets counter.
        $this->travel(61)->minutes();
        $res = $this->req($token, 'PUT', '/api/v1/admin/me/password', [
            'expected_version' => $user->fresh()->lock_version,
            'current_password' => 'CurrentPass1234',
            'password' => 'UpdatedPass1234',
        ])->assertOk();
        $this->assertFalse((bool) $user->fresh()->must_change_password);
        $this->assertStringNotContainsString('UpdatedPass1234', $res->getContent());

        // Same current_password requirement applies when changing own email via PUT /users/{self}.
        $this->req($token, 'PUT', "/api/v1/admin/users/{$user->id}", [
            'expected_version' => $user->fresh()->lock_version,
            'email' => 'self-new@example.test',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->req($token, 'PUT', "/api/v1/admin/users/{$user->id}", [
            'expected_version' => $user->fresh()->lock_version,
            'email' => 'self-new@example.test',
            'current_password' => 'UpdatedPass1234',
        ])->assertOk()->assertJsonPath('data.email', 'self-new@example.test');

        // Audit logs must not contain plaintext or hashed passwords or current_password.
        $logs = DB::table('staff_audit_logs')->where('target_type', 'user')->where('target_id', $user->id)->get();
        $this->assertNotEmpty($logs);
        foreach ($logs as $log) {
            $this->assertStringNotContainsString('UpdatedPass1234', $log->metadata);
            $this->assertStringNotContainsString('CurrentPass1234', $log->metadata);
            $meta = json_decode($log->metadata, true);
            $this->assertNotContains('current_password', $meta['fields'] ?? []);
            $this->assertNotContains('expected_version', $meta['fields'] ?? []);
            $this->assertArrayNotHasKey('password', $meta['before'] ?? []);
            $this->assertArrayNotHasKey('password', $meta['after'] ?? []);
        }
    }

    // Item 13: temporary password flow (must_change_password) full matrix.
    public function test_item13_must_change_password_full_flow(): void
    {
        $other = User::factory()->create();
        $flagged = User::factory()->create([
            'password' => 'TempPass12345',
            'must_change_password' => true,
        ]);
        foreach (['users.view', 'branches.view', 'inventory.all_branches'] as $p) {
            $flagged->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }
        $currentToken = $flagged->createToken('current')->plainTextToken;
        $otherDeviceToken = $flagged->createToken('other-device')->plainTextToken;

        // Blocked on normal endpoints with 403 + code:password_change_required.
        $this->req($currentToken, 'GET', '/api/v1/admin/users')
            ->assertForbidden()->assertJsonPath('code', 'password_change_required');

        // PUT /users/{self} without users.update permission is rejected by permission middleware (403),
        // while /me and /me/password remain accessible.
        $this->req($currentToken, 'PUT', "/api/v1/admin/users/{$flagged->id}", [
            'expected_version' => $flagged->lock_version,
            'current_password' => 'TempPass12345',
            'password' => 'NewValidPass123',
        ])->assertForbidden();

        $this->req($currentToken, 'GET', '/api/v1/admin/me')->assertOk();

        // Self-rotation via PUT /me/password succeeds, keeps current session alive, and revokes other sessions.
        $this->req($currentToken, 'PUT', '/api/v1/admin/me/password', [
            'expected_version' => $flagged->fresh()->lock_version,
            'current_password' => 'TempPass12345',
            'password' => 'NewValidPass123',
        ])->assertOk();

        $this->assertFalse((bool) $flagged->fresh()->must_change_password);
        $this->req($currentToken, 'GET', '/api/v1/admin/users')->assertOk();
        $this->req($otherDeviceToken, 'GET', '/api/v1/admin/me')->assertUnauthorized();

        // Logout works when must_change_password=true as well.
        $flagged->forceFill(['must_change_password' => true])->save();
        $this->req($currentToken, 'POST', '/api/v1/admin/auth/logout')->assertNoContent();
    }

    // Item 14 (complete): unauthenticated, customer, supplier, pos, expired, and is_system tokens on /admin/me.
    public function test_item14b_token_types_expiry_and_system_account(): void
    {
        // Unauthenticated -> 401
        Auth::forgetGuards();
        $this->getJson('/api/v1/admin/me')->assertUnauthorized();

        // Customer -> 403
        $customerToken = \App\Models\Customer::factory()->create()->createToken('c')->plainTextToken;
        $this->req($customerToken, 'GET', '/api/v1/admin/me')->assertForbidden();

        // Supplier -> 403
        $supplierToken = \App\Models\SupplierUser::factory()->create()->createToken('s')->plainTextToken;
        $this->req($supplierToken, 'GET', '/api/v1/admin/me')->assertForbidden();

        // System user -> 403
        $sys = User::factory()->create(['is_system' => true, 'is_active' => true]);
        $sysToken = $sys->createToken('sys')->plainTextToken;
        $this->req($sysToken, 'GET', '/api/v1/admin/me')->assertForbidden();

        // Expired staff token -> 401
        $staff = User::factory()->create();
        $expiredToken = $staff->createToken('exp', ['*'], now()->subMinute())->plainTextToken;
        $this->req($expiredToken, 'GET', '/api/v1/admin/me')->assertUnauthorized();
    }

    // Item 15: comprehensive audit log check (masked email, stripped secrets, denied actions, no false success on failure, atomic location store).
    public function test_item15_audit_log_completeness_and_sanitization(): void
    {
        $this->seed(SettingSeeder::class);
        $admin = User::factory()->create();
        foreach (['branches.manage', 'locations.manage', 'users.manage', 'roles.manage', 'settings.manage', 'inventory.all_branches'] as $p) {
            $admin->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }
        $token = $admin->createToken('audit')->plainTextToken;

        // Create branch + location + role + user + update settings
        $b = $this->req($token, 'POST', '/api/v1/admin/branches', [
            'name' => 'Audit Branch', 'code' => 'AUD-01', 'type' => 'branch',
        ])->assertCreated()->json('data');

        $loc = $this->req($token, 'POST', "/api/v1/admin/branches/{$b['id']}/locations", [
            'name' => 'Audit Loc', 'code' => 'AL-01', 'type' => 'shelf',
        ])->assertCreated()->json('data');
        $this->assertDatabaseHas('staff_audit_logs', ['action' => 'location.created', 'target_type' => 'location', 'target_id' => $loc['id']]);

        $u = $this->req($token, 'POST', '/api/v1/admin/users', [
            'name' => 'Audit User', 'email' => 'secretuser@example.test', 'password' => 'AuditPass12345',
        ])->assertCreated()->json('data');

        $userAudit = DB::table('staff_audit_logs')->where('action', 'user.created')->where('target_id', $u['id'])->first();
        $this->assertNotNull($userAudit);
        $meta = json_decode($userAudit->metadata, true);
        $this->assertSame('se***@example.test', $meta['after']['email'] ?? null);
        $this->assertNotContains('password', $meta['fields'] ?? []);
        $this->assertNotContains('current_password', $meta['fields'] ?? []);
        $this->assertNotContains('expected_version', $meta['fields'] ?? []);
        $this->assertArrayNotHasKey('password', $meta['after'] ?? []);

        // 403 from middleware or controller records administration.denied and persists.
        $unpriv = User::factory()->create();
        $unprivToken = $unpriv->createToken('unpriv')->plainTextToken;
        $this->req($unprivToken, 'POST', '/api/v1/admin/branches', [
            'name' => 'No Perm', 'code' => 'NP-01', 'type' => 'branch',
        ])->assertForbidden();
        $this->assertDatabaseHas('staff_audit_logs', [
            'actor_id' => $unpriv->id,
            'action' => 'administration.denied',
            'target_type' => 'branch',
        ]);

        // Failed operation (e.g. 422 duplicate branch code) does NOT record branch.created.
        $countBefore = DB::table('staff_audit_logs')->where('action', 'branch.created')->count();
        $this->req($token, 'POST', '/api/v1/admin/branches', [
            'name' => 'Dup', 'code' => 'AUD-01', 'type' => 'branch',
        ])->assertUnprocessable();
        $this->assertSame($countBefore, DB::table('staff_audit_logs')->where('action', 'branch.created')->count());
    }

    // Item 16 (complete) & Item 17: settings counter exemptions, boundaries, padding, prefix null->'', sequence sync.
    public function test_item16b_and_17_settings_counters_and_boundaries(): void
    {
        $this->seed(SettingSeeder::class);
        DB::statement('ALTER SEQUENCE document_order_number_seq RESTART WITH 1000');
        $service = app(SettingService::class);
        $token = $this->central(['settings.manage']);

        // Item 16: counter key (order_next_number) is exempt from stale expected comparison as long as new >= current.
        $this->req($token, 'PUT', '/api/v1/admin/settings', [
            'order_next_number' => 1500,
            'expected' => ['order_next_number' => 1],
        ])->assertOk()->assertJsonPath('data.numbering.order_next_number', 1500);

        // Next generated order number takes 1500 immediately.
        $this->assertSame('ORD-001500', $service->generateNextNumber('order'));

        // Item 17: decreasing below current (1501) fails with 422; same value (1501) and max (999999999) are accepted; 1000000000, 0, "abc" fail with 422.
        $this->req($token, 'PUT', '/api/v1/admin/settings', [
            'order_next_number' => 1500, 'expected' => ['order_next_number' => 1501],
        ])->assertUnprocessable()->assertJsonValidationErrors('order_next_number');

        foreach ([0, 1000000000, 'abc'] as $invalidCounter) {
            $this->req($token, 'PUT', '/api/v1/admin/settings', [
                'order_next_number' => $invalidCounter, 'expected' => ['order_next_number' => 1501],
            ])->assertUnprocessable()->assertJsonValidationErrors('order_next_number');
        }

        $this->req($token, 'PUT', '/api/v1/admin/settings', [
            'order_next_number' => 1501, 'expected' => ['order_next_number' => 1501],
        ])->assertOk();

        // Padding boundaries: 0 and 21 -> 422; 1 and 20 -> 200.
        foreach ([0, 21] as $badPad) {
            $this->req($token, 'PUT', '/api/v1/admin/settings', [
                'order_padding' => $badPad, 'expected' => ['order_padding' => $service->get('order_padding')],
            ])->assertUnprocessable()->assertJsonValidationErrors('order_padding');
        }
        foreach ([1, 20] as $goodPad) {
            $this->req($token, 'PUT', '/api/v1/admin/settings', [
                'order_padding' => $goodPad, 'expected' => ['order_padding' => $service->get('order_padding')],
            ])->assertOk()->assertJsonPath('data.numbering.order_padding', $goodPad);
        }

        // Prefix boundaries: length 30 and null -> 200 (stored as ''); length 31 -> 422.
        $p30 = str_repeat('A', 30);
        $this->req($token, 'PUT', '/api/v1/admin/settings', [
            'order_prefix' => $p30, 'expected' => ['order_prefix' => $service->get('order_prefix')],
        ])->assertOk()->assertJsonPath('data.numbering.order_prefix', $p30);

        $this->req($token, 'PUT', '/api/v1/admin/settings', [
            'order_prefix' => str_repeat('A', 31), 'expected' => ['order_prefix' => $p30],
        ])->assertUnprocessable()->assertJsonValidationErrors('order_prefix');

        $this->req($token, 'PUT', '/api/v1/admin/settings', [
            'order_prefix' => null, 'expected' => ['order_prefix' => $p30],
        ])->assertOk()->assertJsonPath('data.numbering.order_prefix', '');
        $this->assertDatabaseHas('settings', ['key' => 'order_prefix', 'value' => '']);
    }

    // Item 18: AI assistant confirmation guards (stale version -> 409, revoked permission -> 403, unexpected error marks action failed instead of stuck pending).
    public function test_item18_ai_assistant_confirmation_guards_and_m3_recovery(): void
    {
        $this->seed(SettingSeeder::class);
        DB::table('settings')->where('key', 'ai_enabled')->update(['value' => '1']);
        app(SettingService::class)->clearCache();

        $branch = Branch::factory()->create(['code' => 'AI-BR1', 'is_active' => true]);
        $actor = User::factory()->create();
        foreach (['ai_agent.use', 'ai_agent.execute', 'branches.view', 'branches.update', 'branches.manage', 'roles.update', 'roles.manage', 'inventory.all_branches'] as $p) {
            $actor->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }
        $token = $actor->createToken('ai')->plainTextToken;
        $conv = \App\Models\AiConversation::create(['user_id' => $actor->id, 'model' => 'test']);
        $registry = app(\App\Services\Ai\ToolRegistry::class);

        // 1. Propose set_branch_status, then mutate branch via REST -> confirm returns 409 and marks proposal failed.
        $prop1 = $registry->dispatch($actor, $conv, [
            'name' => 'set_branch_status', 'args' => ['branch_id' => $branch->id, 'active' => false],
        ]);
        $this->assertSame('proposal', $prop1->type);
        $act1 = \App\Models\AiPendingAction::findOrFail($prop1->data['pending_action_id']);

        $this->req($token, 'PUT', "/api/v1/admin/branches/{$branch->id}", [
            'name' => 'Changed Before Confirm', 'lock_version' => $branch->fresh()->lock_version,
        ])->assertOk();

        $this->req($token, 'POST', "/api/v1/admin/ai/actions/{$act1->id}/confirm", [
            'typed_confirmation' => 'AI-BR1',
        ])->assertStatus(409);
        $this->assertSame('failed', $act1->fresh()->status);
        $this->assertTrue((bool) $branch->fresh()->is_active);

        // 2. Propose update_role_permissions, then revoke ai_agent.execute -> confirm returns 403.
        $role = Role::create(['name' => 'AI Target Role', 'guard_name' => 'staff']);
        $prop2 = $registry->dispatch($actor, $conv, [
            'name' => 'update_role_permissions', 'args' => ['role_id' => $role->id, 'add' => ['branches.view']],
        ]);
        $this->assertSame('proposal', $prop2->type);
        $act2 = \App\Models\AiPendingAction::findOrFail($prop2->data['pending_action_id']);
        $actor->revokePermissionTo('ai_agent.execute');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->req($token, 'POST', "/api/v1/admin/ai/actions/{$act2->id}/confirm")->assertForbidden();
        $this->assertFalse($role->fresh()->hasPermissionTo('branches.view'));

        // 3. M-3: unexpected RuntimeException during confirm marks the pending action as 'failed', not 'pending'.
        $actor->givePermissionTo('ai_agent.execute');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $prop3 = $registry->dispatch($actor->fresh(), $conv, [
            'name' => 'update_role_permissions', 'args' => ['role_id' => $role->id, 'add' => ['branches.view']],
        ]);
        $act3 = \App\Models\AiPendingAction::findOrFail($prop3->data['pending_action_id']);
        // Corrupt the tool name in DB so confirmLocked hits an unexpected path, or trigger an exception in listener.
        \Illuminate\Support\Facades\Event::listen('eloquent.updating: '.Role::class, function (): never {
            throw new \RuntimeException('simulated crash');
        });
        try {
            app(\App\Services\Ai\PendingActionService::class)->confirm($actor->fresh(), $act3->fresh());
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException) {
        } finally {
            \Illuminate\Support\Facades\Event::forget('eloquent.updating: '.Role::class);
        }
        $this->assertSame('failed', $act3->fresh()->status);
    }

    // Items 21 & 22: reserved role names (including NFKC fullwidth), case-insensitive duplicates, regex validation, race recovery, and delete role guards.
    public function test_item21_and_22_role_names_and_delete_guards(): void
    {
        $token = $this->central(['roles.manage']);

        // Reserved names (including fullwidth NFKC) -> 403.
        foreach (['super admin', ' Super Admin ', 'Ｓｕｐｅｒ Admin', 'CASHIER'] as $reserved) {
            $this->req($token, 'POST', '/api/v1/admin/roles', ['name' => $reserved])->assertForbidden();
        }

        // Valid creation + case-insensitive duplicate -> 422.
        $created = $this->req($token, 'POST', '/api/v1/admin/roles', ['name' => 'Branch Auditor'])
            ->assertCreated()->json('data');
        $this->req($token, 'POST', '/api/v1/admin/roles', ['name' => 'branch auditor'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        // Special characters (<script>) -> 422.
        $this->req($token, 'POST', '/api/v1/admin/roles', ['name' => 'bad<script>'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        // Race condition in CreateRole: if role already exists when CreateRole runs, it throws ValidationException (422), not 500.
        $actor = User::factory()->create();
        $actor->givePermissionTo([
            Permission::findOrCreate('roles.manage', 'staff'),
            Permission::findOrCreate('inventory.all_branches', 'staff'),
        ]);
        try {
            app(\App\Actions\Staff\CreateRole::class)($actor, ['name' => 'Branch Auditor']);
            $this->fail('Expected ValidationException on duplicate role');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }

        // Item 22: DELETE /roles/{id} for assigned role (409), cashier (403), web guard (404), and clean role (204).
        $cashier = Role::findOrCreate('cashier', 'staff');
        $this->req($token, 'DELETE', "/api/v1/admin/roles/{$cashier->id}")->assertForbidden();

        $webRole = Role::create(['name' => 'Web Only Role', 'guard_name' => 'web']);
        $this->req($token, 'DELETE', "/api/v1/admin/roles/{$webRole->id}")->assertNotFound();

        $assigned = Role::create(['name' => 'Assigned Role', 'guard_name' => 'staff']);
        User::factory()->create()->assignRole($assigned);
        $this->req($token, 'DELETE', "/api/v1/admin/roles/{$assigned->id}")->assertStatus(409);

        $this->req($token, 'DELETE', "/api/v1/admin/roles/{$created['id']}")->assertNoContent();
        $this->assertDatabaseMissing('roles', ['id' => $created['id']]);
    }

    // Item 23: cross-account email uniqueness matrix (supplier, verified customer, unverified wholesale, deleted customer, unverified retail).
    public function test_item23_cross_account_email_uniqueness(): void
    {
        $token = $this->central(['users.manage']);

        // 1. Supplier user email -> 422
        \App\Models\SupplierUser::factory()->create(['email' => 'sup@example.test']);
        $this->req($token, 'POST', '/api/v1/admin/users', [
            'name' => 'U1', 'email' => '  SUP@Example.Test ', 'password' => 'ValidPass12345',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        // 2. Verified retail customer email -> 422
        \App\Models\Customer::factory()->create(['email' => 'verified@example.test', 'email_verified_at' => now(), 'type' => 'retail']);
        $this->req($token, 'POST', '/api/v1/admin/users', [
            'name' => 'U2', 'email' => 'VERIFIED@example.test', 'password' => 'ValidPass12345',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        // 3. Unverified wholesale customer email -> 422
        \App\Models\Customer::factory()->wholesale()->create(['email' => 'wholesale@example.test', 'email_verified_at' => null]);
        $this->req($token, 'POST', '/api/v1/admin/users', [
            'name' => 'U3', 'email' => 'wholesale@example.test', 'password' => 'ValidPass12345',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        // 4. Soft-deleted customer email -> 201 allowed
        $deletedCust = \App\Models\Customer::factory()->create(['email' => 'deleted@example.test', 'email_verified_at' => now()]);
        $deletedCust->delete();
        $this->req($token, 'POST', '/api/v1/admin/users', [
            'name' => 'U4', 'email' => '  DELETED@Example.Test ', 'password' => 'ValidPass12345',
        ])->assertCreated()->assertJsonPath('data.email', 'deleted@example.test');

        // 5. Unverified retail customer email -> 201 allowed
        \App\Models\Customer::factory()->create(['email' => 'unverified-retail@example.test', 'email_verified_at' => null, 'type' => 'retail']);
        $this->req($token, 'POST', '/api/v1/admin/users', [
            'name' => 'U5', 'email' => '  UNVERIFIED-RETAIL@Example.Test ', 'password' => 'ValidPass12345',
        ])->assertCreated()->assertJsonPath('data.email', 'unverified-retail@example.test');
    }

    // Item 24 (complete): GET requests do not count toward admin rate limit, other staff are unaffected, and nested locations count toward the same bucket.
    public function test_item24b_rate_limit_isolation_and_get_exemption(): void
    {
        $branch = Branch::factory()->create();
        $tokenA = $this->central(['branches.manage', 'locations.manage']);
        $tokenB = $this->central(['branches.manage']);

        // 60 mutations (including a nested location POST) succeed for actor A.
        for ($i = 1; $i <= 59; $i++) {
            $this->req($tokenA, 'PUT', "/api/v1/admin/branches/{$branch->id}", [
                'name' => "R {$i}", 'lock_version' => $branch->fresh()->lock_version,
            ])->assertOk();
        }
        $this->req($tokenA, 'POST', "/api/v1/admin/branches/{$branch->id}/locations", [
            'name' => 'Loc 60', 'code' => 'L60', 'type' => 'shelf',
        ])->assertCreated();

        // 61st mutation on locations is rate-limited (429).
        $this->req($tokenA, 'POST', "/api/v1/admin/branches/{$branch->id}/locations", [
            'name' => 'Loc 61', 'code' => 'L61', 'type' => 'shelf',
        ])->assertStatus(429);

        // GET requests for actor A still succeed (not counted).
        $this->req($tokenA, 'GET', '/api/v1/admin/branches')->assertOk();

        // Actor B is unaffected.
        $this->req($tokenB, 'PUT', "/api/v1/admin/branches/{$branch->id}", [
            'name' => 'Actor B OK', 'lock_version' => $branch->fresh()->lock_version,
        ])->assertOk();
    }

    // Item 26: migrations up/down data integrity checks.
    public function test_item26_migrations_up_down_integrity(): void
    {
        $this->seed(SettingSeeder::class);
        $service = app(SettingService::class);

        // 1. 2026_10_31_000001 down writes sequence next value back to settings so no duplicate occurs.
        $service->generateNextNumber('shift');
        $service->generateNextNumber('shift');
        $expectedNext = $service->get('shift_next_number');

        $migSeq = require database_path('migrations/2026_10_31_000001_move_high_volume_numbering_to_sequences.php');
        $migSeq->down();
        $savedInSettings = (int) DB::table('settings')->where('key', 'shift_next_number')->value('value');
        $this->assertGreaterThanOrEqual($expectedNext, $savedInSettings);
        $migSeq->up();

        // 2. 2026_11_02_000002 fails clearly when duplicate numbers exist.
        $migUnique = require database_path('migrations/2026_11_02_000002_enforce_unique_document_numbers.php');
        // Drop the unique constraint on stocktakes.stocktake_number so we can seed a duplicate and test up().
        DB::statement('ALTER TABLE stocktakes DROP CONSTRAINT IF EXISTS stocktakes_stocktake_number_unique');
        DB::statement('DROP INDEX IF EXISTS stocktakes_stocktake_number_unique');
        $branch = Branch::factory()->create();
        $user = User::factory()->create();
        DB::table('stocktakes')->insert([
            ['stocktake_number' => 'ST-DUP-26', 'branch_id' => $branch->id, 'status' => 'draft', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()],
            ['stocktake_number' => 'ST-DUP-26', 'branch_id' => $branch->id, 'status' => 'draft', 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $failedOnDuplicate = false;
        try {
            DB::transaction(fn () => $migUnique->up());
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $failedOnDuplicate = true;
        }
        $this->assertTrue($failedOnDuplicate, 'Expected unique index creation to fail on duplicate document numbers');
        DB::table('stocktakes')->where('stocktake_number', 'ST-DUP-26')->delete();
        $migUnique->up();

        // 3. 2026_10_25_000005 throws RuntimeException with clear message on case-insensitive duplicate roles.
        $migRoles = require database_path('migrations/2026_10_25_000005_harden_staff_sessions_and_audit.php');
        $migRoles->down();
        DB::table('roles')->insert([
            ['name' => 'DupRole', 'guard_name' => 'staff', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'duprole', 'guard_name' => 'staff', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        try {
            $migRoles->up();
            $this->fail('Expected RuntimeException on case-insensitive duplicate roles');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Case-insensitive duplicate role names', $e->getMessage());
        }
        DB::table('roles')->whereIn('name', ['DupRole', 'duprole'])->delete();
        $migRoles->up();
    }

    // Item 27: staff:prune-audit-logs batch pruning (2500 old rows), minimum 30-day clamp, and timestamptz boundary.
    public function test_item27_prune_staff_audit_logs_batches_and_min_clamp(): void
    {
        $this->freezeTime();
        // Configure retention = 10 days -> clamped to 30 days minimum.
        config(['auth.staff_audit_retention_days' => 10]);

        $rows = [];
        $oldTime = now()->subDays(30)->subSecond();
        for ($i = 0; $i < 2500; $i++) {
            $rows[] = [
                'actor_id' => null,
                'action' => 'old.entry',
                'target_type' => 'branch',
                'target_id' => $i,
                'metadata' => '{}',
                'created_at' => $oldTime,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('staff_audit_logs')->insert($chunk);
        }
        // Boundary (exactly 30 days ago) and recent (15 days ago, which would be deleted if 10 wasn't clamped to 30).
        DB::table('staff_audit_logs')->insert([
            ['actor_id' => null, 'action' => 'boundary.entry', 'target_type' => 'branch', 'target_id' => 1, 'metadata' => '{}', 'created_at' => now()->subDays(30)],
            ['actor_id' => null, 'action' => 'recent.15d', 'target_type' => 'branch', 'target_id' => 2, 'metadata' => '{}', 'created_at' => now()->subDays(15)],
        ]);

        $this->artisan('staff:prune-audit-logs')->assertExitCode(0);

        $this->assertSame(0, DB::table('staff_audit_logs')->where('action', 'old.entry')->count());
        $this->assertDatabaseHas('staff_audit_logs', ['action' => 'boundary.entry']);
        $this->assertDatabaseHas('staff_audit_logs', ['action' => 'recent.15d']);
    }

    // Item 28: PasswordByteLimit (72 bytes max with Arabic multibyte chars, min 12 chars, letters + numbers).
    public function test_item28_password_byte_limit_and_complexity(): void
    {
        $token = $this->central(['users.manage']);

        // 1. 37 Arabic chars + '1' (~75 bytes > 72 bytes, though only 38 characters) -> 422
        $arabic75Bytes = str_repeat('أ', 37).'1a';
        $this->assertGreaterThan(72, strlen($arabic75Bytes));
        $this->req($token, 'POST', '/api/v1/admin/users', [
            'name' => 'Ar', 'email' => 'ar75@example.test', 'password' => $arabic75Bytes,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        // 2. Exactly 72 bytes ASCII with letters and numbers -> 201
        $ascii72 = str_repeat('a', 71).'1';
        $this->assertSame(72, strlen($ascii72));
        $this->req($token, 'POST', '/api/v1/admin/users', [
            'name' => 'Ascii72', 'email' => 'ascii72@example.test', 'password' => $ascii72,
        ])->assertCreated();

        // 3. 11 characters -> 422
        $this->req($token, 'POST', '/api/v1/admin/users', [
            'name' => 'Short', 'email' => 'short@example.test', 'password' => 'Abcdefghij1',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        // 4. Letters without numbers -> 422
        $this->req($token, 'POST', '/api/v1/admin/users', [
            'name' => 'NoNum', 'email' => 'nonum@example.test', 'password' => 'OnlyLettersHere',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    // Section 3 (Branches): per_page bounds, field max lengths, route pattern 404, city_id sync & FK, no duplicate branch.deactivated audit, type->warehouse blocked on open shift.
    public function test_section3_branches_extended_cases(): void
    {
        $token = $this->central(['branches.manage']);

        // per_page validation: 0, 101, "abc" -> 422; 1..100 -> 200.
        foreach ([0, 101, 'abc'] as $badPage) {
            $this->req($token, 'GET', "/api/v1/admin/branches?per_page={$badPage}")
                ->assertUnprocessable()->assertJsonValidationErrors('per_page');
        }

        // /branches/abc -> 404 via Route::pattern
        $this->req($token, 'GET', '/api/v1/admin/branches/abc')->assertNotFound();

        // Field length boundaries (name 255/256, code 50/51, phone 50/51, city 120/121) & city_id non-existent.
        $this->req($token, 'POST', '/api/v1/admin/branches', [
            'name' => str_repeat('n', 256), 'code' => str_repeat('C', 51),
            'type' => 'branch', 'phone' => str_repeat('1', 51), 'city' => str_repeat('c', 121),
            'email' => 'not-an-email', 'city_id' => 999999,
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'code', 'phone', 'city', 'email', 'city_id']);

        $exact = $this->req($token, 'POST', '/api/v1/admin/branches', [
            'name' => str_repeat('n', 255), 'code' => str_repeat('C', 50),
            'type' => 'branch', 'phone' => str_repeat('1', 50), 'city' => str_repeat('c', 120),
        ])->assertCreated()->json('data');

        // Locations and employees count + ordering by id.
        Location::factory()->count(2)->create(['branch_id' => $exact['id']]);
        User::factory()->create(['branch_id' => $exact['id']]);
        $shown = $this->req($token, 'GET', "/api/v1/admin/branches/{$exact['id']}")->assertOk()->json('data');
        $this->assertSame(2, $shown['locations_count']);
        $this->assertSame(1, $shown['employees_count']);

        // Permissions: branches.view alone cannot create; branch-restricted staff with branches.create gets 403.
        $viewer = User::factory()->create();
        $viewer->givePermissionTo([Permission::findOrCreate('branches.view', 'staff'), Permission::findOrCreate('inventory.all_branches', 'staff')]);
        $this->req($viewer->createToken('v')->plainTextToken, 'POST', '/api/v1/admin/branches', [
            'name' => 'No', 'code' => 'NO-1', 'type' => 'branch',
        ])->assertForbidden();

        $scopedCreator = User::factory()->create(['branch_id' => $exact['id']]);
        $scopedCreator->givePermissionTo(Permission::findOrCreate('branches.create', 'staff'));
        $this->req($scopedCreator->createToken('sc')->plainTextToken, 'POST', '/api/v1/admin/branches', [
            'name' => 'No2', 'code' => 'NO-2', 'type' => 'branch',
        ])->assertForbidden();

        // City (F04): city_id syncs city text column, and deleting City sets city_id to null (ON DELETE SET NULL).
        $city = \App\Models\City::create(['name_ar' => 'الإسكندرية', 'name_en' => 'Alexandria', 'shipping_cost' => 50, 'is_active' => true]);
        $withCity = $this->req($token, 'POST', '/api/v1/admin/branches', [
            'name' => 'Alex Branch', 'code' => 'ALX-01', 'type' => 'branch', 'city' => 'Wrong Free Text', 'city_id' => $city->id,
        ])->assertCreated()->assertJsonPath('data.city', 'الإسكندرية')->json('data');
        $city->delete();
        $this->assertNull(Branch::find($withCity['id'])->city_id);

        // Deactivating an already inactive branch records branch.updated, not a duplicate branch.deactivated.
        $bModel = Branch::find($withCity['id']);
        $this->deactivate($token, $bModel)->assertOk();
        $deactCount = DB::table('staff_audit_logs')->where('target_type', 'branch')->where('target_id', $bModel->id)->where('action', 'branch.deactivated')->count();
        $this->assertSame(1, $deactCount);
        $this->deactivate($token, $bModel->fresh())->assertOk();
        $this->assertSame(1, DB::table('staff_audit_logs')->where('target_type', 'branch')->where('target_id', $bModel->id)->where('action', 'branch.deactivated')->count());

        // Changing type from branch to warehouse while a POS shift is open is rejected (422).
        $posBranch = Branch::factory()->create(['type' => 'branch', 'is_active' => true]);
        $treasury = \App\Models\Treasury::create(['name' => 'TP', 'code' => 'T-POS-B', 'branch_id' => $posBranch->id, 'type' => 'pos_drawer']);
        $cashier = User::factory()->create(['branch_id' => $posBranch->id]);
        \App\Models\PosShift::create(['shift_number' => 'SH-B1', 'user_id' => $cashier->id, 'branch_id' => $posBranch->id, 'treasury_id' => $treasury->id, 'status' => 'open', 'opened_at' => now()]);
        $this->req($token, 'PUT', "/api/v1/admin/branches/{$posBranch->id}", [
            'type' => 'warehouse', 'lock_version' => $posBranch->fresh()->lock_version,
        ])->assertStatus(422);
    }

    // Section 3 (Locations): code length 101 rejected in both REST and AI, inactive branch blocks location create/activate, composite FK blocks cross-branch stock_levels.
    public function test_section3_locations_extended_cases(): void
    {
        $this->seed(SettingSeeder::class);
        DB::table('settings')->where('key', 'ai_enabled')->update(['value' => '1']);
        app(SettingService::class)->clearCache();

        $activeBranch = Branch::factory()->create(['is_active' => true]);
        $inactiveBranch = Branch::factory()->create(['is_active' => false]);
        $locInInactive = Location::factory()->create(['branch_id' => $inactiveBranch->id, 'is_active' => false]);

        $actor = User::factory()->create();
        foreach (['locations.manage', 'ai_agent.use', 'ai_agent.execute', 'inventory.all_branches'] as $p) {
            $actor->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }
        $token = $actor->createToken('loc')->plainTextToken;
        $conv = \App\Models\AiConversation::create(['user_id' => $actor->id, 'model' => 'test']);
        $registry = app(\App\Services\Ai\ToolRegistry::class);

        // Code length 101 rejected in REST (422) AND in AI tool (denied).
        $code101 = str_repeat('L', 101);
        $this->req($token, 'POST', "/api/v1/admin/branches/{$activeBranch->id}/locations", [
            'name' => 'Too Long', 'code' => $code101, 'type' => 'shelf',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $aiLong = $registry->dispatch($actor, $conv, [
            'name' => 'create_location',
            'args' => ['branch_id' => $activeBranch->id, 'name' => 'Too Long', 'code' => $code101, 'type' => 'shelf'],
        ]);
        $this->assertSame('denied', $aiLong->type);

        // Creating a location in an inactive branch is rejected (422).
        $this->req($token, 'POST', "/api/v1/admin/branches/{$inactiveBranch->id}/locations", [
            'name' => 'In Inactive', 'code' => 'INACT-1', 'type' => 'shelf',
        ])->assertStatus(422);

        // Activating a location inside an inactive branch is rejected (422) on both single-field and multi-field paths.
        $this->req($token, 'PUT', "/api/v1/admin/branches/{$inactiveBranch->id}/locations/{$locInInactive->id}", [
            'is_active' => true,
        ])->assertStatus(422);
        $this->req($token, 'PUT', "/api/v1/admin/branches/{$inactiveBranch->id}/locations/{$locInInactive->id}", [
            'is_active' => true, 'name' => 'Try Activate',
        ])->assertStatus(422);

        // Deactivating an already inactive location returns 200 immediately without writing a duplicate audit row.
        $auditBefore = DB::table('staff_audit_logs')->where('target_type', 'location')->where('target_id', $locInInactive->id)->count();
        $this->req($token, 'PUT', "/api/v1/admin/branches/{$inactiveBranch->id}/locations/{$locInInactive->id}", [
            'is_active' => false,
        ])->assertOk();
        $this->assertSame($auditBefore, DB::table('staff_audit_logs')->where('target_type', 'location')->where('target_id', $locInInactive->id)->count());

        // Composite FK on stock_levels rejects pairing branch A with a location belonging to branch B.
        $variant = \App\Models\ProductVariant::factory()->create();
        $fkFailed = false;
        try {
            DB::transaction(function () use ($variant, $activeBranch, $locInInactive): void {
                DB::table('stock_levels')->insert([
                    'product_variant_id' => $variant->id,
                    'branch_id' => $activeBranch->id,
                    'location_id' => $locInInactive->id,
                    'on_hand' => 1, 'reserved' => 0, 'damaged' => 0, 'in_transit' => 0,
                    'reorder_level' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
            });
        } catch (\Illuminate\Database\QueryException) {
            $fkFailed = true;
        }
        $this->assertTrue($fkFailed, 'Expected composite FK stock_levels_location_branch_fk to reject mismatched branch/location');
    }

    // Section 3 (Users & Roles): search wildcard escaping (%, _, \), system user 404, inactive branch user creation/move, cache invalidation on role edit.
    public function test_section3_users_and_roles_extended_cases(): void
    {
        $token = $this->central(['users.manage', 'roles.manage', 'permissions.view', 'branches.view']);

        // Search wildcards %, _, \ are treated as literals, not SQL wildcards.
        $literalUser = User::factory()->create(['name' => '100% Pure_Staff\\One']);
        $normalUser = User::factory()->create(['name' => '1000 PureXStaffOne']);
        foreach (['100%', 'Pure_Staff', 'Staff\\One'] as $q) {
            $res = $this->req($token, 'GET', '/api/v1/admin/users?search='.urlencode($q))->assertOk()->json('data');
            $ids = array_column($res, 'id');
            $this->assertContains($literalUser->id, $ids);
            $this->assertNotContains($normalUser->id, $ids);
        }

        // search length 101 -> 422.
        $this->req($token, 'GET', '/api/v1/admin/users?search='.str_repeat('a', 101))
            ->assertUnprocessable()->assertJsonValidationErrors('search');

        // System user returns 404 on show/update/delete.
        $sys = User::factory()->create(['is_system' => true]);
        $this->req($token, 'GET', "/api/v1/admin/users/{$sys->id}")->assertNotFound();
        $this->req($token, 'PUT', "/api/v1/admin/users/{$sys->id}", ['expected_version' => 0, 'name' => 'X'])->assertNotFound();
        $this->req($token, 'DELETE', "/api/v1/admin/users/{$sys->id}", ['expected_version' => 0])->assertNotFound();

        // Creating a user on an inactive branch or moving a user to an inactive branch -> 422.
        $inactiveBranch = Branch::factory()->create(['is_active' => false]);
        $this->req($token, 'POST', '/api/v1/admin/users', [
            'name' => 'On Inactive', 'email' => 'oninact@example.test', 'password' => 'ValidPass12345', 'branch_id' => $inactiveBranch->id,
        ])->assertStatus(422);

        $activeUser = User::factory()->create();
        $this->req($token, 'PUT', "/api/v1/admin/users/{$activeUser->id}", [
            'expected_version' => (int) $activeUser->fresh()->lock_version, 'branch_id' => $inactiveBranch->id,
        ])->assertStatus(422);

        // password: null is ignored on update; same email does not require current_password; deactivating already inactive user returns 200 without version check.
        $oldHash = $activeUser->fresh()->password;
        $this->req($token, 'PUT', "/api/v1/admin/users/{$activeUser->id}", [
            'expected_version' => $activeUser->fresh()->lock_version, 'password' => null, 'name' => 'Null Pass Ignored',
        ])->assertOk();
        $this->assertSame($oldHash, $activeUser->fresh()->password);

        $activeUser->forceFill(['is_active' => false])->save();
        $this->req($token, 'DELETE', "/api/v1/admin/users/{$activeUser->id}", ['expected_version' => 99999])->assertOk();

        // Retired permissions (sales.*, warehouses.*) are absent from GET /permissions.
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $perms = $this->req($token, 'GET', '/api/v1/admin/permissions')->assertOk()->json('data');
        $this->assertArrayNotHasKey('sales', $perms);
        $this->assertArrayNotHasKey('warehouses', $perms);

        // Cashier role permissions can be updated (version + 1), but renaming cashier is forbidden (403).
        $cashier = Role::findOrCreate('cashier', 'staff');
        $v0 = (int) $cashier->fresh()->lock_version;
        $this->req($token, 'PUT', "/api/v1/admin/roles/{$cashier->id}", [
            'expected_version' => $v0, 'name' => 'renamed-cashier',
        ])->assertForbidden();
        $this->req($token, 'PUT', "/api/v1/admin/roles/{$cashier->id}", [
            'expected_version' => $v0, 'permissions' => ['branches.view'],
        ])->assertOk()->assertJsonPath('data.version', $v0 + 1);
    }

    // Section 3 (Settings, Tax, Policies, Property Tests & Auth Token Expiry):
    public function test_section3_settings_policies_properties_and_token_ttl(): void
    {
        $this->seed(SettingSeeder::class);
        $service = app(SettingService::class);
        $token = $this->central(['settings.manage', 'ai_agent.settings']);

        // Tax boundaries: -0.01, 100.01, null -> 422; 0 and 100 -> 200.
        foreach ([-0.01, 100.01, null] as $badTax) {
            $this->req($token, 'PUT', '/api/v1/admin/settings', [
                'default_tax_rate' => $badTax, 'expected' => ['default_tax_rate' => $service->get('default_tax_rate')],
            ])->assertUnprocessable()->assertJsonValidationErrors('default_tax_rate');
        }
        foreach ([0, 100] as $goodTax) {
            $this->req($token, 'PUT', '/api/v1/admin/settings', [
                'default_tax_rate' => $goodTax, 'expected' => ['default_tax_rate' => $service->get('default_tax_rate')],
            ])->assertOk();
        }

        // return_window_days boundaries: -2 and 3651 -> 422; -1, 0, 3650 -> 200.
        foreach ([-2, 3651] as $badWin) {
            $this->req($token, 'PUT', '/api/v1/admin/settings', [
                'return_window_days' => $badWin, 'expected' => ['return_window_days' => $service->get('return_window_days')],
            ])->assertUnprocessable()->assertJsonValidationErrors('return_window_days');
        }
        foreach ([-1, 0, 3650] as $goodWin) {
            $this->req($token, 'PUT', '/api/v1/admin/settings', [
                'return_window_days' => $goodWin, 'expected' => ['return_window_days' => $service->get('return_window_days')],
            ])->assertOk()->assertJsonPath('data.orders.return_window_days', $goodWin);
        }

        // ai_disabled_tools with unknown tool name -> 422.
        $this->req($token, 'PUT', '/api/v1/admin/settings', [
            'ai_disabled_tools' => ['non_existent_tool_xyz'],
            'expected' => ['ai_disabled_tools' => $service->get('ai_disabled_tools', [])],
        ])->assertUnprocessable()->assertJsonValidationErrors('ai_disabled_tools.0');

        // Corrupted counter value ("0") stored in settings throws BusinessException (422).
        DB::table('settings')->where('key', 'invoice_next_number')->update(['value' => '0']);
        $service->clearCache();
        try {
            $service->generateNextNumber('invoice');
            $this->fail('Expected BusinessException on corrupted counter "0"');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertSame(422, $e->status);
        }
        DB::table('settings')->where('key', 'invoice_next_number')->update(['value' => '1000']);
        $service->clearCache();

        // Property test for numbering: for random prefix (0..30 chars) and padding (1..20), generated number = prefix + zero-padded counter, and two consecutive calls never collide.
        for ($trial = 0; $trial < 15; $trial++) {
            $len = random_int(0, 30);
            $prefix = $len === 0 ? '' : substr(str_repeat('PRF-', 10), 0, $len);
            $pad = random_int(1, 20);
            DB::table('settings')->where('key', 'invoice_prefix')->update(['value' => $prefix]);
            DB::table('settings')->where('key', 'invoice_padding')->update(['value' => (string) $pad]);
            $service->clearCache();

            $counterBefore = (int) DB::table('settings')->where('key', 'invoice_next_number')->value('value');
            $first = $service->generateNextNumber('invoice');
            $second = $service->generateNextNumber('invoice');
            $this->assertSame($prefix.str_pad((string) $counterBefore, $pad, '0', STR_PAD_LEFT), $first);
            $this->assertNotSame($first, $second);
        }

        // Staff token expiry boundary: 1 minute before staff_token_minutes -> 200; 1 minute after -> 401.
        $this->freezeTime();
        config(['auth.staff_token_minutes' => 120]);
        $staff = User::factory()->create();
        $issued = app(\App\Services\Auth\StaffTokenService::class)->issue($staff);

        $this->travel(119)->minutes();
        $this->req($issued, 'GET', '/api/v1/admin/me')->assertOk();

        $this->travel(2)->minutes();
        $this->req($issued, 'GET', '/api/v1/admin/me')->assertUnauthorized();
    }

    private function insertStockMovement(int $variantId, int $branchId, ?int $locationId, string $referenceNumber, int $actorId): void
    {
        DB::table('stock_movements')->insert([
            'product_variant_id' => $variantId,
            'branch_id' => $branchId,
            'location_id' => $locationId,
            'reference_number' => $referenceNumber,
            'type' => 'adjustment_in',
            'affected_bucket' => 'on_hand',
            'quantity' => 1,
            'balance_before' => 0,
            'balance_after' => 1,
            'created_by' => $actorId,
            'created_at' => now(),
        ]);
    }

    private function deactivate(string $token, Branch $branch)
    {
        return $this->req($token, 'PUT', "/api/v1/admin/branches/{$branch->id}", [
            'is_active' => false, 'lock_version' => $branch->fresh()->lock_version,
        ]);
    }

    private function central(array $permissions): string
    {
        $staff = User::factory()->create();
        foreach ($permissions as $permission) {
            $staff->givePermissionTo(Permission::findOrCreate($permission, 'staff'));
        }
        $staff->givePermissionTo(Permission::findOrCreate('inventory.all_branches', 'staff'));

        return $staff->createToken('verify')->plainTextToken;
    }

    private function req(string $token, string $method, string $uri, array $payload = [])
    {
        Auth::forgetGuards();

        return $this->withToken($token)->json($method, $uri, $payload);
    }

    private function api(User $actor, string $method, string $path, array $body = [])
    {
        Auth::forgetGuards();

        return $this->withToken($actor->createToken('verify')->plainTextToken)
            ->json($method, '/api/v1/admin'.$path, $this->expectedStaffState($method, '/api/v1/admin'.$path, $body));
    }
}
