<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CustomerDocumentStatus;
use App\Enums\CustomerDocumentType;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\CustomerDocument;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\User;
use App\Services\Ai\ToolRegistry;
use App\Services\CustomerManagementService;
use App\Services\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** المرحلة 2: أدوات الكتابة الفردية لمساعد مراجِع التجار. */
class DealerReviewWriteToolsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        DB::table('settings')->where('key', 'ai_enabled')->update(['value' => '1']);
        app(SettingService::class)->clearCache();
    }

    private function staff(array $perms): User
    {
        $u = User::factory()->create();
        foreach ($perms as $p) {
            $u->givePermissionTo(Permission::findOrCreate($p, 'staff'));
        }

        return $u;
    }

    private function token(User $u): string
    {
        Auth::forgetGuards();

        return $u->createToken('test')->plainTextToken;
    }

    private function admin(): User
    {
        // inventory.all_branches = وصول مركزي (زي اختبارات الأدوار) —
        // أدوات المجموعات مركزية زي REST.
        return $this->staff(['ai_agent.use', 'ai_agent.execute', 'inventory.all_branches', 'customers.create', 'customers.update', 'customers.approve', 'customers.documents.view', 'customers.delete', 'customer_groups.create', 'customer_groups.update']);
    }

    private function conv(User $u): AiConversation
    {
        return AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
    }

    /** @return array{action:AiPendingAction,block:array} */
    private function propose(User $u, string $tool, array $args): array
    {
        $outcome = app(ToolRegistry::class)->dispatch($u, $this->conv($u), ['name' => $tool, 'args' => $args]);
        $this->assertSame('proposal', $outcome->type, 'expected proposal for '.$tool.': '.($outcome->message ?? ''));
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $this->assertSame('pending', $action->status);

        return ['action' => $action, 'block' => $outcome->uiBlock];
    }

    private function confirm(User $u, AiPendingAction $action, ?string $typed = null)
    {
        return $this->withToken($this->token($u))->postJson("/api/v1/admin/ai/actions/{$action->id}/confirm", $typed === null ? [] : ['typed_confirmation' => $typed]);
    }

    private function undo(User $u, AiPendingAction $action)
    {
        return $this->withToken($this->token($u))->postJson("/api/v1/admin/ai/actions/{$action->id}/undo");
    }

    private function wholesaleCustomer(array $overrides = []): Customer
    {
        return Customer::factory()->create(['type' => CustomerType::Wholesale, 'status' => CustomerStatus::Pending, ...$overrides]);
    }

    private function document(Customer $customer, array $overrides = []): CustomerDocument
    {
        return CustomerDocument::create([
            'customer_id' => $customer->id,
            'document_type' => CustomerDocumentType::CommercialRegister,
            'file_path' => 'customer_documents/'.$customer->id.'/doc.pdf',
            'original_name' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Pending,
            ...$overrides,
        ]);
    }

    private function group(array $overrides = []): CustomerGroup
    {
        return CustomerGroup::create(['name' => 'مجموعة اختبار', 'code' => 'GRP-'.random_int(1000, 9999), 'discount_percentage' => 10, 'is_active' => true, ...$overrides]);
    }

    // ── create_customer ────────────────────────────────────────────────────

    public function test_create_customer_retail_confirms_and_password_shows_once(): void
    {
        $admin = $this->admin();
        ['action' => $action] = $this->propose($admin, 'create_customer', ['name' => 'عميل تجزئة', 'phone' => '01510000001', 'type' => 'retail']);
        $this->assertSame('low', $action->risk);

        $res = $this->confirm($admin, $action)->assertOk();
        $plain = $res->json('data.result.generated_password');
        $this->assertIsString($plain);
        $customer = Customer::where('phone', '01510000001')->firstOrFail();
        $this->assertSame('approved', $customer->status->value);
        $this->assertTrue(Hash::check($plain, $customer->password));
        $this->assertSame('created', CustomerAuditLog::query()->where('customer_id', $customer->id)->latest('id')->value('action'));
        // الباسورد لا يُخزَّن في نتيجة الإجراء ولا في حدث المحادثة.
        $this->assertArrayNotHasKey('generated_password', $action->fresh()->result ?? []);
        $event = AiMessage::query()->where('conversation_id', $action->conversation_id)->where('role', 'event')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString($plain, $event->content);
        $this->assertStringNotContainsString($plain, json_encode($event->ui_blocks));
    }

    public function test_create_customer_wholesale_stays_pending_with_warning(): void
    {
        $admin = $this->admin();
        ['action' => $action, 'block' => $block] = $this->propose($admin, 'create_customer', ['name' => 'تاجر جملة', 'phone' => '01510000002', 'type' => 'wholesale', 'company_name' => 'شركة', 'tax_number' => '123', 'commercial_register' => '456']);
        $this->assertSame('medium', $action->risk);
        $this->assertContains('هيتسجل قيد المراجعة.', $block['warnings']);

        $this->confirm($admin, $action)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('pending', Customer::where('phone', '01510000002')->firstOrFail()->status->value);
    }

    // ── update_customer_profile ────────────────────────────────────────────

    public function test_update_customer_profile_confirms_and_undoes(): void
    {
        $admin = $this->admin();
        $customer = $this->wholesaleCustomer(['status' => CustomerStatus::Approved, 'approved_at' => now(), 'email' => 'old@example.com', 'phone' => '01510000003']);
        ['action' => $action] = $this->propose($admin, 'update_customer_profile', ['customer_id' => $customer->id, 'name' => 'اسم جديد', 'email' => 'new@example.com']);
        $this->confirm($admin, $action)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('اسم جديد', $customer->fresh()->name);
        $this->assertSame('new@example.com', $customer->fresh()->email);

        $this->undo($admin, $action)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('old@example.com', $customer->fresh()->email);
    }

    public function test_update_customer_profile_legal_change_on_approved_is_high_risk_and_needs_approve(): void
    {
        $admin = $this->admin();
        $customer = $this->wholesaleCustomer(['status' => CustomerStatus::Approved, 'approved_at' => now()]);
        ['action' => $action, 'block' => $block] = $this->propose($admin, 'update_customer_profile', ['customer_id' => $customer->id, 'company_name' => 'شركة جديدة']);
        $this->assertSame('high', $action->risk);
        $this->assertContains('تغيير الهوية القانونية لتاجر معتمد.', $block['warnings']);

        $limited = $this->staff(['ai_agent.use', 'ai_agent.execute', 'customers.update']);
        $outcome = app(ToolRegistry::class)->dispatch($limited, $this->conv($limited), ['name' => 'update_customer_profile', 'args' => ['customer_id' => $customer->id, 'company_name' => 'شركة تانية']]);
        $this->assertSame('denied', $outcome->type);
    }

    public function test_update_customer_profile_rest_change_causes_409(): void
    {
        $admin = $this->admin();
        $customer = $this->wholesaleCustomer();
        ['action' => $action] = $this->propose($admin, 'update_customer_profile', ['customer_id' => $customer->id, 'name' => 'اسم جديد']);
        app(CustomerManagementService::class)->update($customer, ['email' => 'rest@example.com'], $admin->id);

        $this->confirm($admin, $action)->assertStatus(409);
        $this->assertSame('failed', $action->fresh()->status);
    }

    public function test_wallet_balance_change_does_not_cause_409(): void
    {
        $admin = $this->admin();
        $customer = $this->wholesaleCustomer(['credit_limit' => 10000]);
        ['action' => $action] = $this->propose($admin, 'set_credit_limit', ['customer_id' => $customer->id, 'credit_limit' => 15000]);
        Customer::whereKey($customer->id)->update(['wallet_balance' => 500]);

        $this->confirm($admin, $action)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(15000.0, (float) $customer->fresh()->credit_limit);
    }

    // ── set_customer_group / set_credit_limit ──────────────────────────────

    public function test_set_customer_group_confirms_and_undoes(): void
    {
        $admin = $this->admin();
        $customer = $this->wholesaleCustomer();
        $group = $this->group();
        ['action' => $action] = $this->propose($admin, 'set_customer_group', ['customer_id' => $customer->id, 'customer_group_id' => $group->id]);
        $this->confirm($admin, $action)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame($group->id, $customer->fresh()->customer_group_id);

        $this->undo($admin, $action)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertNull($customer->fresh()->customer_group_id);
    }

    public function test_set_credit_limit_medium_confirms_without_typed_and_high_requires_it(): void
    {
        $admin = $this->admin();
        $customer = $this->wholesaleCustomer(['credit_limit' => 10000, 'phone' => '01510000004']);
        ['action' => $medium] = $this->propose($admin, 'set_credit_limit', ['customer_id' => $customer->id, 'credit_limit' => 15000]);
        $this->assertSame('medium', $medium->risk);
        $this->confirm($admin, $medium)->assertOk()->assertJsonPath('data.ok', true);

        ['action' => $high, 'block' => $block] = $this->propose($admin, 'set_credit_limit', ['customer_id' => $customer->id, 'credit_limit' => 200000]);
        $this->assertSame('high', $high->risk);
        $this->assertSame('0004', $block['typed_confirmation']);
        $this->assertSame('آخر 4 أرقام من موبايل التاجر', $block['typed_confirmation_hint']);
        // نص غلط → 422، وغياب النص → 422 بالتعليمة من غير الرقم.
        $this->confirm($admin, $high, '9999')->assertStatus(422);
        $this->confirm($admin, $high)->assertStatus(422)->assertJsonFragment(['message' => 'اكتب آخر 4 أرقام من موبايل التاجر.']);
        $this->confirm($admin, $high, '0004')->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(200000.0, (float) $customer->fresh()->credit_limit);
    }

    public function test_set_credit_limit_below_used_warns(): void
    {
        $admin = $this->admin();
        $customer = $this->wholesaleCustomer(['credit_limit' => 10000]);
        Order::factory()->create(['customer_id' => $customer->id, 'payment_method' => 'credit', 'payment_status' => 'unpaid', 'status' => OrderStatus::Confirmed, 'grand_total' => 5000]);
        ['action' => $action, 'block' => $block] = $this->propose($admin, 'set_credit_limit', ['customer_id' => $customer->id, 'credit_limit' => 3000]);
        $this->assertContains('التاجر هيبقى فوق الحد فورًا، وطلباته الآجلة الجاية هتترفض.', $block['warnings']);
        $this->assertSame(5000.0, (float) $block['before']['used_credit']);
    }

    // ── review_customer_document ───────────────────────────────────────────

    public function test_review_customer_document_verify_reject_and_undo(): void
    {
        $admin = $this->admin();
        $customer = $this->wholesaleCustomer();
        $doc = $this->document($customer);
        ['action' => $verify] = $this->propose($admin, 'review_customer_document', ['customer_id' => $customer->id, 'document_id' => $doc->id, 'status' => 'verified']);
        $this->confirm($admin, $verify)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('verified', $doc->fresh()->status->value);

        $this->undo($admin, $verify)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('pending', $doc->fresh()->status->value);

        // رفض من غير ملاحظة مرفوض أصلًا وقت الاقتراح.
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'review_customer_document', 'args' => ['customer_id' => $customer->id, 'document_id' => $doc->id, 'status' => 'rejected']]);
        $this->assertSame('denied', $outcome->type);
        ['action' => $reject] = $this->propose($admin, 'review_customer_document', ['customer_id' => $customer->id, 'document_id' => $doc->id, 'status' => 'rejected', 'note' => 'صورة غير واضحة']);
        $this->confirm($admin, $reject)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('rejected', $doc->fresh()->status->value);
        $this->assertSame('صورة غير واضحة', $doc->fresh()->review_note);
    }

    public function test_review_customer_document_scoped_to_customer(): void
    {
        $admin = $this->admin();
        $owner = $this->wholesaleCustomer();
        $other = $this->wholesaleCustomer();
        $doc = $this->document($owner);
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'review_customer_document', 'args' => ['customer_id' => $other->id, 'document_id' => $doc->id, 'status' => 'verified']]);
        $this->assertSame('denied', $outcome->type);
        $this->assertSame('المستند مش تابع للعميل ده.', $outcome->message);
    }

    // ── approve_dealer_application ─────────────────────────────────────────

    private function approvableCustomer(): array
    {
        $customer = $this->wholesaleCustomer(['phone' => '01510000005']);
        $verified = $this->document($customer, ['status' => CustomerDocumentStatus::Verified, 'document_type' => CustomerDocumentType::TaxCard]);
        $pending = $this->document($customer, ['document_type' => CustomerDocumentType::CommercialRegister]);

        return [$customer, $verified, $pending];
    }

    public function test_approve_dealer_application_verifies_and_approves_in_one_confirm(): void
    {
        $admin = $this->admin();
        [$customer, $verified, $pending] = $this->approvableCustomer();
        $group = $this->group();
        ['action' => $action, 'block' => $block] = $this->propose($admin, 'approve_dealer_application', ['customer_id' => $customer->id, 'verify_document_ids' => [$pending->id], 'customer_group_id' => $group->id, 'credit_limit' => 75000]);
        $this->assertSame('high', $action->risk);
        $this->assertSame('0005', $block['typed_confirmation']);
        $this->assertSame('approved', $block['after']['status']);
        $this->assertSame($group->id, $block['after']['customer_group']['id']);

        $this->confirm($admin, $action, '0005')->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('approved', $customer->fresh()->status->value);
        $this->assertSame('verified', $pending->fresh()->status->value);
        $this->assertSame($group->id, $customer->fresh()->customer_group_id);
        $this->assertSame(75000.0, (float) $customer->fresh()->credit_limit);
        $this->assertSame('approved', CustomerAuditLog::query()->where('customer_id', $customer->id)->latest('id')->value('action'));
    }

    public function test_approve_dealer_application_wrong_typed_is_422(): void
    {
        $admin = $this->admin();
        [$customer, $verified, $pending] = $this->approvableCustomer();
        ['action' => $action] = $this->propose($admin, 'approve_dealer_application', ['customer_id' => $customer->id, 'verify_document_ids' => [$pending->id]]);
        $this->confirm($admin, $action, '1111')->assertStatus(422);
        $this->assertSame('pending', $action->fresh()->status);
    }

    public function test_approve_dealer_application_denied_when_blocker_remains(): void
    {
        $admin = $this->admin();
        [$customer, $verified, $pending] = $this->approvableCustomer();
        // المستند المعلق مش في قائمة التوثيق → blocker بعد المحاكاة.
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'approve_dealer_application', 'args' => ['customer_id' => $customer->id]]);
        $this->assertSame('denied', $outcome->type);
        $this->assertStringContainsString('مستندات لسه مش verified', $outcome->message);
    }

    public function test_approve_dealer_application_denied_when_rejected_document_remains(): void
    {
        $admin = $this->admin();
        $customer = $this->wholesaleCustomer();
        $this->document($customer, ['status' => CustomerDocumentStatus::Rejected]);
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'approve_dealer_application', 'args' => ['customer_id' => $customer->id]]);
        $this->assertSame('denied', $outcome->type);
        $this->assertSame('فيه مستند مرفوض، لازم يتراجع أو يترفع بديل الأول.', $outcome->message);
    }

    public function test_approve_dealer_application_rolls_back_when_new_document_appears(): void
    {
        $admin = $this->admin();
        [$customer, $verified, $pending] = $this->approvableCustomer();
        ['action' => $action] = $this->propose($admin, 'approve_dealer_application', ['customer_id' => $customer->id, 'verify_document_ids' => [$pending->id]]);
        // مستند جديد معلق اترفع بين الاقتراح والتأكيد — approve() تفشل
        // بعد توثيق المحدد، والـ transaction ترجّع كل حاجة.
        $this->document($customer, ['document_type' => CustomerDocumentType::TaxCard]);

        $this->confirm($admin, $action, '0005')->assertOk()->assertJsonPath('data.ok', false);
        $this->assertSame('failed', $action->fresh()->status);
        $this->assertSame('pending', $pending->fresh()->status->value);
        $this->assertSame('pending', $customer->fresh()->status->value);
    }

    // ── reject / suspend / reactivate / restore ────────────────────────────

    public function test_reject_dealer_application_confirms_with_typed(): void
    {
        $admin = $this->admin();
        $customer = $this->wholesaleCustomer(['phone' => '01510000006']);
        ['action' => $action, 'block' => $block] = $this->propose($admin, 'reject_dealer_application', ['customer_id' => $customer->id, 'reason' => 'المستندات غير مكتملة، برجاء إعادة الرفع.']);
        $this->assertSame('rejected', $block['after']['status']);
        $this->confirm($admin, $action, '0006')->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('rejected', $customer->fresh()->status->value);
        $this->assertSame('المستندات غير مكتملة، برجاء إعادة الرفع.', $customer->fresh()->rejection_reason);
    }

    public function test_suspend_and_reactivate_with_undo(): void
    {
        $admin = $this->admin();
        $customer = $this->wholesaleCustomer(['status' => CustomerStatus::Approved, 'approved_at' => now(), 'phone' => '01510000007']);
        ['action' => $suspend, 'block' => $block] = $this->propose($admin, 'suspend_customer', ['customer_id' => $customer->id, 'reason' => 'مخالفة']);
        $this->assertContains('هيتم إنهاء كل جلسات التاجر فورًا، والتراجع هيرجّع الحالة بس، مش الجلسات.', $block['warnings']);
        $this->confirm($admin, $suspend, '0007')->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('suspended', $customer->fresh()->status->value);

        $this->undo($admin, $suspend)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('approved', $customer->fresh()->status->value);

        ['action' => $suspend2] = $this->propose($admin, 'suspend_customer', ['customer_id' => $customer->id, 'reason' => 'مخالفة']);
        $this->confirm($admin, $suspend2, '0007')->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('suspended', $customer->fresh()->status->value);

        ['action' => $reactivate] = $this->propose($admin, 'reactivate_customer', ['customer_id' => $customer->id]);
        $this->confirm($admin, $reactivate)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('approved', $customer->fresh()->status->value);
    }

    public function test_restore_customer(): void
    {
        $admin = $this->admin();
        $customer = $this->wholesaleCustomer();
        $customer->delete();
        $this->assertTrue($customer->fresh()->trashed());
        ['action' => $action] = $this->propose($admin, 'restore_customer', ['customer_id' => $customer->id]);
        $this->confirm($admin, $action)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertFalse($customer->fresh()->trashed());
    }

    // ── customer groups ────────────────────────────────────────────────────

    public function test_create_and_update_customer_group_with_undo(): void
    {
        $admin = $this->admin();
        ['action' => $create] = $this->propose($admin, 'create_customer_group', ['name' => 'مجموعة جديدة', 'code' => 'GRP-NEW', 'discount_percentage' => 5]);
        $this->confirm($admin, $create)->assertOk()->assertJsonPath('data.ok', true);
        $group = CustomerGroup::where('code', 'GRP-NEW')->firstOrFail();
        $this->assertDatabaseHas('staff_audit_logs', ['action' => 'customer_group.created', 'target_type' => 'customer_group', 'target_id' => $group->id]);

        $customer = $this->wholesaleCustomer(['customer_group_id' => $group->id]);
        ['action' => $update, 'block' => $block] = $this->propose($admin, 'update_customer_group', ['customer_group_id' => $group->id, 'discount_percentage' => 15]);
        $this->assertSame('high', $update->risk);
        $this->confirm($admin, $update)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(15.0, (float) $group->fresh()->discount_percentage);

        $this->undo($admin, $update)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(5.0, (float) $group->fresh()->discount_percentage);
    }

    // ── guards ─────────────────────────────────────────────────────────────

    public function test_forbidden_operations_abort_explicitly(): void
    {
        $admin = $this->admin();
        $customer = $this->wholesaleCustomer();
        $registry = app(ToolRegistry::class);
        $method = new \ReflectionMethod($registry, 'prepareWrite');
        $method->setAccessible(true);

        // أداة حذف غير معرّفة أصلًا — والـ abort الصريح يسبق الـ match.
        try {
            $method->invoke($registry, $admin, $this->conv($admin), 'delete_customer', ['customer_id' => $customer->id]);
            $this->fail('delete_customer should abort');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        // تغيير باسورد/نوع من update_customer_profile — مرفوض صراحة.
        foreach ([['password' => 'secret123'], ['type' => 'retail']] as $extra) {
            try {
                $method->invoke($registry, $admin, $this->conv($admin), 'update_customer_profile', ['customer_id' => $customer->id, ...$extra]);
                $this->fail('forbidden field should abort');
            } catch (HttpExceptionInterface $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function test_permission_denial_is_audited_via_dispatch(): void
    {
        $limited = $this->staff(['ai_agent.use', 'ai_agent.execute']);
        $customer = $this->wholesaleCustomer();
        $conv = $this->conv($limited);
        $outcome = app(ToolRegistry::class)->dispatch($limited, $conv, ['name' => 'suspend_customer', 'args' => ['customer_id' => $customer->id, 'reason' => 'x']]);
        $this->assertSame('denied', $outcome->type);
        $this->assertDatabaseHas('staff_audit_logs', ['action' => 'administration.denied', 'target_type' => 'ai_conversation', 'target_id' => $conv->id]);
    }
}
