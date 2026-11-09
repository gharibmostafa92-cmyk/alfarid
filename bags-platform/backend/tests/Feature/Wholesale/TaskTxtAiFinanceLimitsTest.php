<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\OrderStatus;
use App\Enums\TreasuryType;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolRegistry;
use App\Services\Ai\Wholesale\Tools\ApprovePaymentNoticeTool;
use App\Services\Ai\Wholesale\Tools\BulkApproveMatchedNoticesTool;
use App\Services\Shared\SettingService;
use App\Services\Wholesale\WholesalePaymentNoticeService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskTxtAiFinanceLimitsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WholesalePortalPermissionSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved', 'credit_limit' => 1000000]);
        Treasury::create([
            'name' => 'بنك', 'code' => 'AI-'.uniqid(), 'type' => TreasuryType::BankAccount,
            'current_balance' => 0, 'is_active' => true,
        ]);
    }

    private function actorWith(array $permissions): User
    {
        $role = Role::create(['name' => 'ai-'.uniqid(), 'guard_name' => 'staff']);
        foreach ($permissions as $permission) {
            $role->givePermissionTo($permission);
        }
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function approveTool(?AiConversation $conversation = null): ApprovePaymentNoticeTool
    {
        return $this->toolWithConversation(ApprovePaymentNoticeTool::class, $conversation);
    }

    /** @template T of object
     * @param  class-string<T>  $toolClass
     * @return T */
    private function toolWithConversation(string $toolClass, ?AiConversation $conversation): object
    {
        $builder = new ProposalBuilder(app(SettingService::class));
        $builder->setConversation($conversation);
        app()->instance(ProposalBuilder::class, $builder);

        return app($toolClass);
    }

    private function conversation(string $channel): AiConversation
    {
        return AiConversation::create(['user_id' => $this->admin->id, 'channel' => $channel, 'model' => 'test-model']);
    }

    private function pendingNotice(float $amount = 500, ?float $score = null): WholesalePaymentNotice
    {
        $notice = WholesalePaymentNotice::factory()->create([
            'customer_id' => $this->customer->id,
            'amount' => $amount,
            'status' => 'pending_review',
        ]);
        if ($score !== null) {
            $notice->forceFill(['match_score' => $score])->save();
        }

        return $notice->fresh();
    }

    private function openDebt(float $total): void
    {
        Order::factory()->create([
            'customer_id' => $this->customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'unpaid',
            'status' => OrderStatus::Delivered,
            'grand_total' => $total,
            'subtotal' => $total,
        ]);
    }

    private function enableWhatsappApproval(): void
    {
        foreach (['ai_whatsapp_allow_notice_approval', 'ai_whatsapp_pin_required'] as $key) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => '1', 'group' => 'ai', 'type' => 'boolean']);
        }
        app(SettingService::class)->clearCache();
    }

    public function test_tool_permission_gate_follows_staff_permissions(): void
    {
        $registry = app(ToolRegistry::class);
        $def = $registry->definitions()['approve_payment_notice'];

        $this->assertFalse($registry->actorHasToolPermissions($this->actorWith(['ai_agent.use', 'ai_agent.wholesale']), 'approve_payment_notice', $def));
        $this->assertTrue($registry->actorHasToolPermissions(
            $this->actorWith(['ai_agent.use', 'ai_agent.wholesale', 'payment_notices.approve']),
            'approve_payment_notice',
            $def
        ));
    }

    public function test_whatsapp_prepare_requires_95_and_explicit_settings(): void
    {
        $this->openDebt(1000);
        $conv = $this->conversation('whatsapp');

        $low = $this->pendingNotice(500, 94.99);
        $this->enableWhatsappApproval();
        $outcome = $this->approveTool($conv)->prepare($this->admin, ['notice_id' => $low->id]);
        $this->assertSame('denied', $outcome->type);

        $ok = $this->pendingNotice(500, 96);
        DB::table('settings')->where('key', 'ai_whatsapp_allow_notice_approval')->update(['value' => '0']);
        app(SettingService::class)->clearCache();
        $outcome = $this->approveTool($conv)->prepare($this->admin, ['notice_id' => $ok->id]);
        $this->assertSame('denied', $outcome->type);

        $this->assertSame('pending_review', $low->fresh()->status->value);
        $this->assertSame('pending_review', $ok->fresh()->status->value);
        $this->assertDatabaseCount('treasury_transactions', 0);
    }

    public function test_execute_after_notice_change_is_denied(): void
    {
        $this->openDebt(1000);
        $notice = $this->pendingNotice(500, 96);

        $tool = $this->approveTool($this->conversation('web'));
        $proposal = $tool->prepare($this->admin, ['notice_id' => $notice->id]);
        $this->assertNotSame('denied', $proposal->type);

        $action = AiPendingAction::query()->where('tool', 'approve_payment_notice')->latest('id')->firstOrFail();
        // Move past second granularity so the staleness tripwire can fire.
        \Carbon\Carbon::setTestNow(now()->addSeconds(5));
        try {
            $notice->update(['admin_notes' => 'تعديل بعد الاقتراح']);
        } finally {
            \Carbon\Carbon::setTestNow();
        }

        $outcome = $tool->execute($this->admin, $action->fresh());
        $this->assertSame('denied', $outcome->type);
        $this->assertSame('pending_review', $notice->fresh()->status->value);
        $this->assertDatabaseCount('treasury_transactions', 0);
    }

    public function test_collision_detector_flags_duplicate_receipt_and_transfer(): void
    {
        // The partial unique index makes persisted duplicates nearly
        // unreachable (race/legacy only), so the detector is proven at unit
        // level with an unsaved candidate carrying a duplicate fingerprint.
        $existing = $this->pendingNotice(500, 96);
        $existing->forceFill(['receipt_sha256' => 'abc123'])->save();

        $service = app(WholesalePaymentNoticeService::class);
        $candidate = new WholesalePaymentNotice([
            'customer_id' => $this->customer->id,
            'amount' => 500,
            'reference_number' => 'OTHER-1',
            'transfer_date' => now()->toDateString(),
            'receipt_sha256' => 'abc123',
            'status' => 'pending_review',
        ]);
        $hits = $service->receiptCollisions($candidate);
        $this->assertNotEmpty($hits);
        $this->assertSame('same_receipt', $hits[0]['reason']);

        $other = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved']);
        $candidate2 = new WholesalePaymentNotice([
            'customer_id' => $other->id,
            'amount' => (string) $existing->amount,
            'reference_number' => $existing->reference_number,
            'transfer_date' => $existing->transfer_date->toDateString(),
            'status' => 'pending_review',
        ]);
        $hits2 = $service->receiptCollisions($candidate2);
        $this->assertNotEmpty($hits2);
        $this->assertSame('same_reference_amount_date', $hits2[0]['reason']);
    }

    public function test_bulk_approves_clean_notices_and_rejects_unknown_keys(): void
    {
        $this->openDebt(100000);
        $first = $this->pendingNotice(500, 96);
        $second = $this->pendingNotice(500, 96);

        $bulk = $this->toolWithConversation(BulkApproveMatchedNoticesTool::class, $this->conversation('web'));
        $proposal = $bulk->prepare($this->admin, []);
        $this->assertNotSame('denied', $proposal->type);

        $action = AiPendingAction::query()->where('tool', 'bulk_approve_matched_notices')->latest('id')->firstOrFail();
        $keys = array_keys($action->args['notices'] ?? []);
        $this->assertContains('n'.$first->id, $keys);
        $this->assertContains('n'.$second->id, $keys);

        $denied = $bulk->executeSelected($this->admin, $action->fresh(), ['n999999']);
        $this->assertSame('denied', $denied->type);

        $bulk->execute($this->admin, $action->fresh());
        $this->assertSame('approved', $first->fresh()->status->value);
        $this->assertSame('approved', $second->fresh()->status->value);
    }

    public function test_reverse_tool_denies_unapproved_missing_reason_and_stale_state(): void
    {
        $this->openDebt(1000);
        $pending = $this->pendingNotice(500, 96);

        $tool = $this->toolWithConversation(
            \App\Services\Ai\Wholesale\Tools\ReversePaymentNoticeApprovalTool::class,
            $this->conversation('web')
        );

        $this->assertSame('denied', $tool->prepare($this->admin, ['notice_id' => $pending->id, 'reason' => 'سبب'])->type);

        $bank = Treasury::query()->where('code', 'like', 'AI-%')->firstOrFail();
        app(WholesalePaymentNoticeService::class)->approve($pending, $this->admin->id, $bank->id);
        $this->assertSame(
            'denied',
            $tool->prepare($this->admin, ['notice_id' => $pending->id, 'reason' => '   '])->type
        );

        $proposal = $tool->prepare($this->admin, ['notice_id' => $pending->id, 'reason' => 'سبب وجيه']);
        $this->assertNotSame('denied', $proposal->type);
        $action = AiPendingAction::query()->where('tool', 'reverse_payment_notice_approval')->latest('id')->firstOrFail();

        app(WholesalePaymentNoticeService::class)->reverse($pending->fresh(), $this->admin->id, 'عكس مباشر');
        $this->assertSame('denied', $tool->execute($this->admin, $action->fresh())->type);
        $this->assertSame('reversed', $pending->fresh()->status->value);
    }

    public function test_bulk_prepare_caps_candidates_at_50(): void
    {
        for ($i = 0; $i < 51; $i++) {
            $this->pendingNotice(10, 96);
        }

        $bulk = $this->toolWithConversation(BulkApproveMatchedNoticesTool::class, $this->conversation('web'));
        $proposal = $bulk->prepare($this->admin, []);
        $this->assertNotSame('denied', $proposal->type);

        $action = AiPendingAction::query()->where('tool', 'bulk_approve_matched_notices')->latest('id')->firstOrFail();
        $this->assertCount(50, $action->args['notices'] ?? []);
    }
}
