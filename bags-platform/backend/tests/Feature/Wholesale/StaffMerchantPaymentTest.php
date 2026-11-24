<?php

declare(strict_types=1);

namespace Tests\Feature\Wholesale;

use App\Enums\Finance\TreasuryType;
use App\Exceptions\BusinessException;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Treasury;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Ai\PendingActionService;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\Wholesale\Tools\RecordMerchantPaymentTool;
use App\Services\Inventory\BranchAccess;
use App\Services\Shared\SettingService;
use App\Services\Wholesale\WholesalePaymentNoticeService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\WholesalePortalPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class StaffMerchantPaymentTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_recorded_payment_updates_debt_and_treasury_once(): void
    {
        $this->seed([RolePermissionSeeder::class, WholesalePortalPermissionSeeder::class, SettingSeeder::class]);
        app(SettingService::class)->updateBatch(['ai_enabled' => true, 'ai_wholesale_tools_enabled' => true]);
        $staff = User::factory()->create(['is_active' => true]);
        $staff->assignRole('Super Admin');
        $treasury = Treasury::create([
            'name' => 'Bank', 'code' => 'STAFF-PAYMENT-BANK', 'is_active' => true,
            'type' => TreasuryType::BankAccount, 'current_balance' => 0,
        ]);
        $customer = Customer::factory()->create(['type' => 'wholesale', 'status' => 'approved', 'credit_limit' => '10000.00']);
        Order::factory()->for($customer)->create([
            'channel' => 'wholesale', 'payment_method' => 'credit', 'grand_total' => '7000.00',
        ]);
        $notices = app(WholesalePaymentNoticeService::class);
        $before = $notices->accountCredit($customer);
        $this->assertSame('3000.00', $before['available_credit']);
        $data = [
            'treasury_id' => $treasury->id, 'amount' => '2000.00',
            'payment_method' => 'bank_transfer', 'bank_name' => 'CIB',
            'reference_number' => 'STAFF-RECEIPT-1', 'transfer_date' => now()->toDateString(),
        ];

        $proposal = app(ProposalBuilder::class);
        $proposal->setConversation(AiConversation::create(['user_id' => $staff->id, 'model' => 'test']));
        $tool = new RecordMerchantPaymentTool($proposal, $notices, app(BranchAccess::class));
        $outcome = $tool->prepare($staff, [...$data, 'customer_id' => $customer->id, 'amount' => '٢٠٠٠']);
        $action = AiPendingAction::findOrFail($outcome->data['pending_action_id']);
        $this->assertSame('2000.00', $outcome->uiBlock['typed_confirmation']);
        $this->assertSame($before, $notices->accountCredit($customer));

        $pending = app(PendingActionService::class);
        $confirmed = $pending->confirm($staff, $action, '2000.00');
        $notice = WholesalePaymentNotice::findOrFail($confirmed->data['notice_id']);
        $pending->confirm($staff, $action->fresh(), '2000.00');
        $this->assertTrue($notice->staff_recorded);
        $this->assertNull($notice->receipt_file_path);
        $this->assertNotNull($notice->treasury_transaction_id);
        $after = $notices->accountCredit($customer);
        $this->assertSame('5000.00', $after['amount_due']);
        $this->assertSame('5000.00', $after['available_credit']);

        try {
            $notices->recordStaffPayment($customer, $staff, $data, $after);
            $this->fail('The same receipt reference was recorded twice.');
        } catch (BusinessException $exception) {
            $this->assertSame(409, $exception->status);
        }
        $this->assertSame('2000.00', $treasury->fresh()->current_balance);
    }
}
