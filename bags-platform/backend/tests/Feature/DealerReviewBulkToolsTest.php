<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CustomerDocumentStatus;
use App\Enums\CustomerDocumentType;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\OrderStatus;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\User;
use App\Services\Ai\ToolRegistry;
use App\Services\Shared\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** المرحلة 3: العمليات الجماعية والتراجع لأدوات مراجِع التجار. */
class DealerReviewBulkToolsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        DB::table('settings')->whereIn('key', ['ai_enabled', 'ai_customer_tools_enabled'])->update(['value' => '1']);
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
        return $this->staff(['ai_agent.use', 'ai_agent.execute', 'inventory.all_branches', 'customers.approve', 'customers.documents.view']);
    }

    private function conv(User $u): AiConversation
    {
        return AiConversation::create(['user_id' => $u->id, 'model' => 'gemini-test']);
    }

    private function group(string $code): CustomerGroup
    {
        return CustomerGroup::create(['name' => 'مجموعة '.$code, 'code' => $code, 'discount_percentage' => 10, 'is_active' => true]);
    }

    private function customer(?CustomerGroup $group = null, array $overrides = []): Customer
    {
        return Customer::factory()->create(['customer_group_id' => $group?->id, ...$overrides]);
    }

    private function document(Customer $customer): CustomerDocument
    {
        return CustomerDocument::create([
            'customer_id' => $customer->id,
            'document_type' => CustomerDocumentType::CommercialRegister,
            'file_path' => 'customer_documents/'.$customer->id.'/doc.pdf',
            'original_name' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'status' => CustomerDocumentStatus::Pending,
        ]);
    }

    /** @return array{parent:AiPendingAction,children:list<AiPendingAction>,block:array} */
    private function proposeBulk(User $admin, array $items): array
    {
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'bulk_actions', 'args' => ['items' => $items]]);
        $this->assertSame('proposal', $outcome->type, 'expected bulk proposal: '.($outcome->message ?? ''));
        $this->assertSame('bulk_plan', $outcome->uiBlock['type']);
        $parent = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $byId = AiPendingAction::whereIn('id', $parent->args['action_ids'])->get()->keyBy('id');
        $children = collect($parent->args['action_ids'])->map(fn ($id) => $byId[$id])->values()->all();

        return ['parent' => $parent, 'children' => $children, 'block' => $outcome->uiBlock];
    }

    private function confirmBulk(User $admin, AiPendingAction $parent, ?array $selected = null)
    {
        $payload = ['typed_confirmation' => 'تنفيذ'];
        if ($selected !== null) {
            $payload['selected_items'] = $selected;
        }

        return $this->withToken($this->token($admin))->postJson("/api/v1/admin/ai/actions/{$parent->id}/confirm", $payload);
    }

    public function test_bulk_enum_includes_customer_tools_and_forbids_approve_reject(): void
    {
        $admin = $this->admin();
        $defs = app(ToolRegistry::class)->definitions();
        $enum = $defs['bulk_actions']['declaration']['parameters']['properties']->items['items']['properties']->tool['enum'];
        foreach (['set_customer_group', 'set_credit_limit', 'review_customer_document', 'reactivate_customer'] as $tool) {
            $this->assertContains($tool, $enum);
        }
        $this->assertNotContains('suspend_customer', $enum);
        $this->assertNotContains('approve_dealer_application', $enum);
        $this->assertNotContains('reject_dealer_application', $enum);

        $customer = $this->customer();
        $group = $this->group('VIP-3');
        foreach ([
            ['tool' => 'approve_dealer_application', 'args' => ['customer_id' => $customer->id]],
            ['tool' => 'reject_dealer_application', 'args' => ['customer_id' => $customer->id, 'reason' => 'سبب']],
        ] as $forbidden) {
            $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'bulk_actions', 'args' => ['items' => [
                $forbidden,
                ['tool' => 'set_customer_group', 'args' => ['customer_id' => $customer->id, 'customer_group_id' => $group->id]],
            ]]]);
            $this->assertSame('denied', $outcome->type);
            $this->assertStringContainsString('لازم يتراجع لوحده', $outcome->message ?? '');
        }
    }

    public function test_bulk_group_move_with_selected_items_cancels_third(): void
    {
        $admin = $this->admin();
        $old = $this->group('OLD-3');
        $vip = $this->group('VIP-3B');
        $customers = [$this->customer($old), $this->customer($old), $this->customer($old)];
        $items = array_map(fn (Customer $c) => ['tool' => 'set_customer_group', 'args' => ['customer_id' => $c->id, 'customer_group_id' => $vip->id]], $customers);
        ['parent' => $parent, 'children' => $children] = $this->proposeBulk($admin, $items);
        $this->assertCount(3, $children);

        $selected = [$children[0]->id, $children[1]->id];
        $this->confirmBulk($admin, $parent, $selected)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame($vip->id, $customers[0]->fresh()->customer_group_id);
        $this->assertSame($vip->id, $customers[1]->fresh()->customer_group_id);
        $this->assertSame($old->id, $customers[2]->fresh()->customer_group_id);
        $this->assertSame('executed', $children[0]->fresh()->status);
        $this->assertSame('executed', $children[1]->fresh()->status);
        $this->assertSame('cancelled', $children[2]->fresh()->status);
    }

    public function test_bulk_review_customer_document_verifies_all(): void
    {
        $admin = $this->admin();
        $customer = $this->customer(null, ['type' => CustomerType::Wholesale, 'status' => CustomerStatus::Pending]);
        $docs = [$this->document($customer), $this->document($customer)];
        $items = array_map(fn (CustomerDocument $d) => ['tool' => 'review_customer_document', 'args' => ['customer_id' => $customer->id, 'document_id' => $d->id, 'status' => 'verified']], $docs);
        ['parent' => $parent] = $this->proposeBulk($admin, $items);

        $this->confirmBulk($admin, $parent)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('verified', $docs[0]->fresh()->status->value);
        $this->assertSame('verified', $docs[1]->fresh()->status->value);
    }

    public function test_bulk_credit_warning_aggregated_above_30_percent_only(): void
    {
        $admin = $this->admin();
        $vip = $this->group('VIP-3C');
        $makeUnderUsed = function () {
            $c = $this->customer(null, ['type' => CustomerType::Wholesale, 'status' => CustomerStatus::Approved, 'credit_limit' => 10000]);
            Order::factory()->create(['customer_id' => $c->id, 'payment_method' => 'credit', 'payment_status' => 'unpaid', 'status' => OrderStatus::Confirmed, 'grand_total' => 5000]);

            return ['tool' => 'set_credit_limit', 'args' => ['customer_id' => $c->id, 'credit_limit' => 1000]];
        };
        // 2 من 3 (66%) — التحذير المجمّع لازم يبقى أول تحذير في الكارت.
        $items = [$makeUnderUsed(), $makeUnderUsed(), ['tool' => 'set_customer_group', 'args' => ['customer_id' => $this->customer()->id, 'customer_group_id' => $vip->id]]];
        ['block' => $block] = $this->proposeBulk($admin, $items);
        $this->assertStringContainsString('تحذير مجمّع', $block['warnings'][0]);
        $this->assertStringContainsString('2 من 3', $block['warnings'][0]);

        // 3 من 10 (30% بالظبط) — مش أكتر من 30%، فمفيش تحذير مجمّع.
        $items = [];
        for ($i = 0; $i < 3; $i++) {
            $items[] = $makeUnderUsed();
        }
        for ($i = 0; $i < 7; $i++) {
            $items[] = ['tool' => 'set_customer_group', 'args' => ['customer_id' => $this->customer()->id, 'customer_group_id' => $vip->id]];
        }
        ['block' => $block] = $this->proposeBulk($admin, $items);
        foreach ($block['warnings'] as $warning) {
            $this->assertStringNotContainsString('تحذير مجمّع', $warning);
        }
    }

    public function test_bulk_all_already_in_target_group_denied_no_change(): void
    {
        $admin = $this->admin();
        $vip = $this->group('VIP-3D');
        $customers = [$this->customer($vip), $this->customer($vip)];
        $items = array_map(fn (Customer $c) => ['tool' => 'set_customer_group', 'args' => ['customer_id' => $c->id, 'customer_group_id' => $vip->id]], $customers);
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'bulk_actions', 'args' => ['items' => $items]]);
        $this->assertSame('denied', $outcome->type);
        $this->assertStringContainsString('مفيش تغيير', $outcome->message ?? '');
        $this->assertSame(0, AiPendingAction::query()->where('tool', 'bulk_actions')->count());
        $this->assertSame(0, AiPendingAction::query()->where('tool', 'set_customer_group')->whereIn('status', ['pending', 'bulk_child'])->count());
        $this->assertSame($vip->id, $customers[0]->fresh()->customer_group_id);
    }

    public function test_bulk_group_undo_within_15_minutes_and_409_after(): void
    {
        $admin = $this->admin();
        $old = $this->group('OLD-3E');
        $vip = $this->group('VIP-3E');
        $customers = [$this->customer($old), $this->customer($old)];
        $items = array_map(fn (Customer $c) => ['tool' => 'set_customer_group', 'args' => ['customer_id' => $c->id, 'customer_group_id' => $vip->id]], $customers);
        ['parent' => $parent, 'children' => $children] = $this->proposeBulk($admin, $items);
        $this->confirmBulk($admin, $parent)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame($vip->id, $customers[0]->fresh()->customer_group_id);

        // خلال 15 دقيقة: التراجع يرجّع المجموعة القديمة.
        $this->withToken($this->token($admin))->postJson("/api/v1/admin/ai/actions/{$children[0]->id}/undo")->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame($old->id, $customers[0]->fresh()->customer_group_id);
        $this->assertSame('undone', $children[0]->fresh()->status);

        // بعد 15 دقيقة: التراجع يترفض بـ 409، والمجموعة تفضل زي ما هي.
        $children[1]->forceFill(['executed_at' => now()->subMinutes(16)])->save();
        $this->withToken($this->token($admin))->postJson("/api/v1/admin/ai/actions/{$children[1]->id}/undo")->assertConflict();
        $this->assertSame($vip->id, $customers[1]->fresh()->customer_group_id);
        $this->assertSame('executed', $children[1]->fresh()->status);
    }

    public function test_bulk_reactivate_executes_and_max_items_enforced(): void
    {
        $admin = $this->admin();
        $customers = [
            $this->customer(null, ['status' => CustomerStatus::Suspended, 'approved_at' => now()]),
            $this->customer(null, ['status' => CustomerStatus::Suspended, 'approved_at' => now()]),
        ];
        $items = array_map(fn (Customer $c) => ['tool' => 'reactivate_customer', 'args' => ['customer_id' => $c->id]], $customers);
        ['parent' => $parent] = $this->proposeBulk($admin, $items);
        $this->confirmBulk($admin, $parent)->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('approved', $customers[0]->fresh()->status->value);
        $this->assertSame('approved', $customers[1]->fresh()->status->value);

        // الحد الأقصى ai_bulk_max_items (25 افتراضيًا) — 26 عنصرًا مرفوض
        // قبل أي اقتراح فرعي.
        $many = array_fill(0, 26, ['tool' => 'set_customer_group', 'args' => ['customer_id' => $customers[0]->id, 'customer_group_id' => 1]]);
        $outcome = app(ToolRegistry::class)->dispatch($admin, $this->conv($admin), ['name' => 'bulk_actions', 'args' => ['items' => $many]]);
        $this->assertSame('denied', $outcome->type);
    }
}
