<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\Customer\CustomerStatus;
use App\Enums\Customer\CustomerType;
use App\Enums\Finance\TreasuryType;
use App\Enums\StaffRole;
use App\Enums\Wholesale\QuotationStatus;
use App\Enums\Wholesale\WholesalePaymentNoticeStatus;
use App\Exceptions\BusinessException;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Ai\Contracts\LocksTargets;
use App\Services\Ai\Pricing\PricingFingerprint;
use App\Services\Ai\Pricing\PricingLocks;
use App\Services\Ai\Pricing\Tools\DeletePriceListItemsTool;
use App\Services\Ai\Pricing\Tools\UpsertPriceListItemsTool;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolRegistry;
use App\Services\Ai\Wholesale\Tools\BulkApproveMatchedNoticesTool;
use App\Services\Ai\Wholesale\Tools\BulkPriceQuotationsTool;
use App\Services\Ai\Wholesale\Tools\ExpireQuotationTool;
use App\Services\Ai\Wholesale\Tools\ExtendQuotationValidityTool;
use App\Services\Finance\WalletService;
use App\Services\Pos\TreasuryService;
use App\Services\Shared\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class Task2ExecutionSlice2LocksAndUndoTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        DB::table('settings')->whereIn('key', [
            'ai_enabled',
            'ai_wholesale_tools_enabled',
            'ai_finance_tools_enabled',
            'ai_finance_wallet_tools_enabled',
            'ai_pricing_tools_enabled',
        ])->update(['value' => '1']);
        app(SettingService::class)->clearCache();
    }

    private function staff(array $perms, ?Branch $branch = null): User
    {
        $u = User::factory()->create(['branch_id' => $branch?->id]);
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function conv(User $u): AiConversation
    {
        return AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
    }

    public function test_bulk_child_actions_enforce_version_checks_in_tool_registry_and_pricing_locks(): void
    {
        $actor = $this->staff(['ai_agent.use', 'ai_agent.execute', 'inventory.all_branches', 'branches.update', 'price_lists.update', 'coupons.update']);
        $branch = Branch::factory()->create(['is_active' => true]);

        $bulkAction = AiPendingAction::create([
            'conversation_id' => $this->conv($actor)->id,
            'user_id' => $actor->id,
            'tool' => 'set_branch_status',
            'args' => ['branch_id' => $branch->id, 'active' => false],
            'expected_versions' => ['branch:'.$branch->id => 'stale-hash'],
            'preview' => [],
            'args_hash' => 'h1',
            'risk' => 'medium',
            'status' => 'bulk_child',
            'expires_at' => now()->addMinutes(10),
        ]);

        $registryThrown = false;
        try {
            DB::transaction(fn () => app(ToolRegistry::class)->lockTargets($bulkAction));
        } catch (HttpException $e) {
            $registryThrown = $e->getStatusCode() === 409;
        }
        $this->assertTrue($registryThrown, 'ToolRegistry::lockTargets must check expected_versions for bulk_child');

        $list = PriceList::create(['name' => 'PL1', 'type' => 'wholesale', 'is_active' => true]);
        $listChild = AiPendingAction::create([
            'conversation_id' => $this->conv($actor)->id,
            'user_id' => $actor->id,
            'tool' => 'update_price_list',
            'args' => ['price_list_id' => $list->id],
            'expected_versions' => ['pl:'.$list->id => 'stale'],
            'preview' => [],
            'args_hash' => 'h2',
            'risk' => 'medium',
            'status' => 'bulk_child',
            'expires_at' => now()->addMinutes(10),
        ]);

        $pricingThrown = false;
        try {
            DB::transaction(fn () => PricingLocks::lockListAndItems($listChild, $list->id, [], 'stale'));
        } catch (HttpException $e) {
            $pricingThrown = $e->getStatusCode() === 409;
        }
        $this->assertTrue($pricingThrown, 'PricingLocks::lockListAndItems must check version for bulk_child');

        $coupon = Coupon::create([
            'code' => 'C10',
            'type' => 'percentage',
            'value' => 10,
            'is_active' => true,
        ]);
        $couponChild = AiPendingAction::create([
            'conversation_id' => $this->conv($actor)->id,
            'user_id' => $actor->id,
            'tool' => 'update_coupon',
            'args' => ['coupon_id' => $coupon->id],
            'expected_versions' => ['coupon:'.$coupon->id => 'stale'],
            'preview' => [],
            'args_hash' => 'h3',
            'risk' => 'medium',
            'status' => 'bulk_child',
            'expires_at' => now()->addMinutes(10),
        ]);

        $couponThrown = false;
        try {
            DB::transaction(fn () => PricingLocks::lockCoupon($couponChild, $coupon->id, 'stale'));
        } catch (HttpException $e) {
            $couponThrown = $e->getStatusCode() === 409;
        }
        $this->assertTrue($couponThrown, 'PricingLocks::lockCoupon must check version for bulk_child');
    }

    public function test_tool_registry_handles_numeric_string_lock_version_and_prevents_last_super_admin_lockout(): void
    {
        $superRole = Role::findOrCreate(StaffRole::SuperAdmin->value, 'staff');
        $actor = $this->staff(['ai_agent.use', 'ai_agent.execute', 'inventory.all_branches', 'users.update']);
        $actor->assignRole($superRole);
        $otherSuper = User::factory()->create(['is_active' => true]);
        $otherSuper->assignRole($superRole);

        $conv = $this->conv($actor);
        $registry = app(ToolRegistry::class);

        // Numeric string expected_version should NOT cause false 409
        $role = Role::create(['name' => 'auditor_role', 'guard_name' => 'staff']);
        $roleAction = AiPendingAction::create([
            'conversation_id' => $conv->id,
            'user_id' => $actor->id,
            'tool' => 'update_role_permissions',
            'args' => ['role_id' => $role->id, 'add' => [], 'remove' => []],
            'expected_versions' => ['role:'.$role->id => (string) ($role->lock_version ?? 0)],
            'preview' => [],
            'args_hash' => 'h-role',
            'risk' => 'medium',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ]);
        DB::transaction(fn () => $registry->lockTargets($roleAction));
        $this->assertTrue(true);

        // Prepare disabling $otherSuper while $actor is active, then deactivate $actor before execution
        $disableAction = AiPendingAction::create([
            'conversation_id' => $conv->id,
            'user_id' => $actor->id,
            'tool' => 'set_user_status',
            'args' => ['user_id' => $otherSuper->id, 'active' => false],
            'expected_versions' => ['user:'.$otherSuper->id => $otherSuper->lock_version ?? 0],
            'preview' => [],
            'args_hash' => 'h-sa',
            'risk' => 'high',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(10),
        ]);

        // Now $actor becomes inactive, leaving $otherSuper as the ONLY active SuperAdmin
        $actor->forceFill(['is_active' => false])->save();

        $outcome = DB::transaction(fn () => $registry->executeWrite($actor, $disableAction, ['channel' => 'ai']));
        $this->assertSame('لا يمكن تعطيل آخر Super Admin نشط.', $outcome->message);
        $this->assertTrue((bool) $otherSuper->fresh()->is_active, 'Last active SuperAdmin must not be disabled');
    }

    public function test_proposal_builder_conversation_is_reset_after_dispatch(): void
    {
        $actor = $this->staff(['ai_agent.use']);
        $conv = $this->conv($actor);

        app(ToolRegistry::class)->dispatch($actor, $conv, ['name' => 'unknown_tool_xyz', 'args' => []]);
        $this->assertNull(app(ProposalBuilder::class)->conversation());

        app(ToolRegistry::class)->dispatch($actor, $conv, ['name' => 'list_branches', 'args' => []]);
        $this->assertNull(app(ProposalBuilder::class)->conversation());
    }

    public function test_wholesale_bulk_and_quotation_tools_implement_locks_targets_and_notice_fingerprint(): void
    {
        $this->assertInstanceOf(LocksTargets::class, app(BulkApproveMatchedNoticesTool::class));
        $this->assertInstanceOf(LocksTargets::class, app(BulkPriceQuotationsTool::class));
        $this->assertInstanceOf(LocksTargets::class, app(ExpireQuotationTool::class));
        $this->assertInstanceOf(LocksTargets::class, app(ExtendQuotationValidityTool::class));
        $this->assertInstanceOf(LocksTargets::class, app(DeletePriceListItemsTool::class));
        $this->assertInstanceOf(LocksTargets::class, app(UpsertPriceListItemsTool::class));

        $actor = $this->staff(['ai_agent.use', 'ai_agent.execute', 'ai_agent.wholesale', 'inventory.all_branches', 'payment_notices.approve']);
        $customer = Customer::factory()->create(['type' => CustomerType::Wholesale, 'status' => CustomerStatus::Approved]);
        $branch = Branch::factory()->create(['is_active' => true]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'allocated_branch_id' => $branch->id]);
        $notice = WholesalePaymentNotice::create([
            'notice_number' => 'WPN-FP-1',
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'amount' => '500.00',
            'payment_method' => 'bank_transfer',
            'bank_name' => 'CIB',
            'reference_number' => 'REF-FP-1',
            'transfer_date' => now()->toDateString(),
            'receipt_file_path' => 'receipts/fp.jpg',
            'status' => WholesalePaymentNoticeStatus::PendingReview,
            'match_score' => 98,
        ]);

        $outcome = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), ['name' => 'bulk_approve_matched_notices', 'args' => []]);
        $this->assertSame('proposal', $outcome->type);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $expectedVersion = $action->expected_versions['n:'.$notice->id] ?? '';
        // Must not be plain updated_at timestamp string
        $this->assertNotSame((string) $notice->updated_at, $expectedVersion);
        $this->assertSame(64, strlen($expectedVersion));
    }

    public function test_upsert_undo_fingerprints_item_validity_fields_and_locks_created_rows(): void
    {
        $actor = $this->staff(['ai_agent.use', 'inventory.all_branches', 'price_lists.update']);
        $conversation = $this->conv($actor);
        $list = PriceList::create(['name' => 'PL-UNDO-STATE', 'type' => 'wholesale', 'is_active' => true]);
        $variant = ProductVariant::factory()->create();
        $item = PriceListItem::create([
            'price_list_id' => $list->id,
            'product_variant_id' => $variant->id,
            'min_qty' => 1,
            'price' => 120,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);
        $action = AiPendingAction::create([
            'conversation_id' => $conversation->id,
            'user_id' => $actor->id,
            'tool' => 'upsert_price_list_items',
            'args' => ['price_list_id' => $list->id, 'rows' => [['variant_id' => $variant->id, 'min_qty' => 1, 'price' => 120]]],
            'expected_versions' => [],
            'inverse' => ['updated' => [], 'created' => [$item->id]],
            'preview' => [],
            'args_hash' => 'h-upsert-undo-state',
            'risk' => 'medium',
            'status' => 'executed',
            'executed_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ]);
        $tool = app(UpsertPriceListItemsTool::class);

        $before = $tool->targetState($action);
        $item->forceFill(['ends_at' => now()->addDays(3)])->save();
        $this->assertNotSame($before, $tool->targetState($action), 'Undo state must include validity dates and other mutable item fields.');

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        DB::transaction(fn () => $tool->lockTargets($action));
        $itemLock = collect($queries)->first(fn (string $sql) => str_contains($sql, 'price_list_items') && str_contains($sql, 'for update'));
        $this->assertNotNull($itemLock, 'Undo locking must include the rows created by the action before checking target state.');
        $this->assertStringContainsString(' in ', $itemLock);
    }

    public function test_update_treasury_undo_blocks_branch_move_when_treasury_has_transactions(): void
    {
        $branchA = Branch::factory()->create(['is_active' => true]);
        $branchB = Branch::factory()->create(['is_active' => true]);
        $actor = $this->staff(['ai_agent.use', 'ai_agent.execute', 'ai_agent.finance', 'inventory.all_branches', 'treasuries.update']);

        $treasury = Treasury::create([
            'name' => 'خزينة قابلة للنقل',
            'code' => 'TR-MOVE-1',
            'type' => TreasuryType::MainSafe,
            'branch_id' => $branchA->id,
            'current_balance' => '0.00',
            'is_active' => true,
        ]);

        $outcome = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'update_treasury',
            'args' => ['treasury_id' => $treasury->id, 'branch_id' => $branchB->id],
        ]);
        $this->assertSame('proposal', $outcome->type);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);

        $token = $actor->createToken('test')->plainTextToken;
        $confirm = $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm");
        $confirm->assertOk();

        // Now record a transaction on the treasury in branch B
        app(TreasuryService::class)->record([
            'treasury_id' => $treasury->id,
            'amount' => '100.00',
            'type' => 'opening_balance',
            'created_by' => $actor->id,
            'notes' => 'رصيد بعد النقل',
        ]);

        $undo = $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/undo");
        $this->assertContains($undo->getStatusCode(), [409, 422]);
        $this->assertSame($branchB->id, $treasury->fresh()->branch_id);
    }

    public function test_adjust_customer_wallet_requires_customers_view_for_direct_id_and_includes_balance_in_fingerprint(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $customer = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
            'phone' => '01012345678',
        ]);

        // Actor has customers.wallet.adjust but NOT customers.view
        $noViewActor = $this->staff(['ai_agent.use', 'ai_agent.execute', 'ai_agent.finance', 'inventory.all_branches', 'customers.wallet.adjust']);
        $denied = app(ToolRegistry::class)->dispatch($noViewActor, $this->conv($noViewActor), [
            'name' => 'adjust_customer_wallet',
            'args' => [
                'customer_id' => $customer->id,
                'kind' => 'adjustment',
                'direction' => 'credit',
                'amount' => '100.00',
                'reason' => 'تسوية',
                'idempotency_key' => 'key-1',
            ],
        ]);
        $this->assertSame('denied', $denied->type);

        // With customers.view, expected_versions['wallet:id'] includes balance
        $fullActor = $this->staff(['ai_agent.use', 'ai_agent.execute', 'ai_agent.finance', 'inventory.all_branches', 'customers.wallet.adjust', 'customers.view']);
        $proposal = app(ToolRegistry::class)->dispatch($fullActor, $this->conv($fullActor), [
            'name' => 'adjust_customer_wallet',
            'args' => [
                'customer_id' => $customer->id,
                'kind' => 'adjustment',
                'direction' => 'credit',
                'amount' => '100.00',
                'reason' => 'تسوية',
                'idempotency_key' => 'key-2',
            ],
        ]);
        $this->assertSame('proposal', $proposal->type);
        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        $this->assertStringContainsString(':0.00', (string) ($action->expected_versions['wallet:'.$customer->id] ?? ''));
    }

    public function test_adjust_customer_wallet_undo_rejects_a_later_wallet_transaction(): void
    {
        $actor = $this->staff([
            'ai_agent.use', 'ai_agent.execute', 'ai_agent.finance', 'inventory.all_branches',
            'customers.wallet.adjust', 'customers.view',
        ]);
        $customer = Customer::factory()->create([
            'type' => CustomerType::Wholesale,
            'status' => CustomerStatus::Approved,
            'wallet_balance' => '0.00',
        ]);
        $proposal = app(ToolRegistry::class)->dispatch($actor, $this->conv($actor), [
            'name' => 'adjust_customer_wallet',
            'args' => [
                'customer_id' => $customer->id,
                'kind' => 'adjustment',
                'direction' => 'credit',
                'amount' => '100.00',
                'reason' => 'تسوية اختبار',
                'idempotency_key' => 'wallet-undo-state-1',
            ],
        ]);
        $this->assertSame('proposal', $proposal->type);
        $action = AiPendingAction::findOrFail($proposal->data['pending_action_id']);
        $token = $actor->createToken('wallet-undo-state')->plainTextToken;
        $confirmation = (string) ($action->preview['typed_confirmation'] ?? 'تأكيد نهائي');
        $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm", [
            'typed_confirmation' => $confirmation,
        ])->assertOk();

        app(WalletService::class)->record($customer->id, '1.00', 'topup', 'manual_test', 90001, $actor->id);
        $undo = $this->withToken($token)->postJson("/api/v1/admin/ai/actions/{$action->id}/undo");

        $this->assertSame(409, $undo->status());
        $this->assertSame('101.00', $customer->fresh()->wallet_balance);
    }
}
