<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Enums\Customer\CustomerType;
use App\Enums\Finance\TreasuryType;
use App\Enums\Wholesale\WholesalePaymentMethod;
use App\Models\AiPendingAction;
use App\Models\Customer;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\Wholesale\WholesaleAccessGuard;
use App\Services\Ai\WriteTool;
use App\Services\Inventory\BranchAccess;
use App\Services\Wholesale\WholesalePaymentNoticeService;
use App\Support\ArabicDigits;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class RecordMerchantPaymentTool implements WriteTool
{
    public function __construct(
        private readonly ProposalBuilder $proposals,
        private readonly WholesalePaymentNoticeService $notices,
        private readonly BranchAccess $branches,
    ) {}

    public function name(): string
    {
        return 'record_merchant_payment';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'high';
    }

    public function permissions(): array
    {
        return ['payment_notices.view', 'payment_notices.approve'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'يجهز تأكيد تسجيل سداد استلمه موظف من تاجر، بالجنيه المصري. اسأل الموظف عن أي بيانات ناقصة ولا تخمّن المرجع أو الخزينة.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'customer_id' => ['type' => 'INTEGER'],
                    'treasury_id' => ['type' => 'INTEGER'],
                    'amount' => ['type' => 'STRING'],
                    'payment_method' => ['type' => 'STRING', 'enum' => ['bank_transfer', 'check', 'deposit']],
                    'bank_name' => ['type' => 'STRING'],
                    'reference_number' => ['type' => 'STRING'],
                    'transfer_date' => ['type' => 'STRING'],
                ],
                'required' => ['customer_id', 'treasury_id', 'amount', 'payment_method', 'bank_name', 'reference_number', 'transfer_date'],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        if ($this->proposals->conversation()?->channel === 'whatsapp') {
            return ToolOutcome::denied('سجّل التحصيل من المساعد في لوحة الإدارة.', auditable: false);
        }
        if ($denied = WholesaleAccessGuard::denyIfNoCentralAccess($this->branches, $actor)) {
            return $denied;
        }
        foreach (['amount', 'bank_name', 'reference_number', 'transfer_date'] as $field) {
            if (is_string($args[$field] ?? null)) {
                $args[$field] = trim(ArabicDigits::normalize($args[$field]));
            }
        }
        if (is_string($args['amount'] ?? null)) {
            $args['amount'] = str_replace('٫', '.', $args['amount']);
        }
        $data = Validator::make($args, [
            'customer_id' => ['required', 'integer', 'min:1'],
            'treasury_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'string', 'regex:/\A[0-9]{1,10}(?:\.[0-9]{1,2})?\z/'],
            'payment_method' => ['required', Rule::enum(WholesalePaymentMethod::class)],
            'bank_name' => ['required', 'string', 'max:100'],
            'reference_number' => ['required', 'string', 'max:100', 'regex:/[\p{L}\p{N}]/u'],
            'transfer_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Cairo')->toDateString()],
        ])->validate();
        $customer = Customer::query()->whereIn('type', CustomerType::commercialValues())->findOrFail($data['customer_id']);
        $treasury = Treasury::query()->where('is_active', true)
            ->whereIn('type', [TreasuryType::MainSafe->value, TreasuryType::BankAccount->value])->findOrFail($data['treasury_id']);
        $this->branches->assertTreasury($actor->id, $treasury);

        $existing = $this->notices->findActivePaymentReference($customer, $data['reference_number']);
        if ($existing !== null) {
            return ToolOutcome::result(false, 'المرجع مسجل. راجع الإشعار الموجود قبل أي تسجيل جديد.', ['notice_id' => $existing->id]);
        }
        $account = $this->notices->accountCredit($customer);
        $amount = BigDecimal::of($data['amount'])->toScale(2);
        if (! $amount->isPositive() || $amount->isGreaterThan($account['amount_due'])) {
            return ToolOutcome::denied('المبلغ لازم يكون أكبر من صفر وفي حدود المديونية.', auditable: false);
        }
        $data['amount'] = (string) $amount;
        $data['account_snapshot'] = $account;
        $after = $this->notices->creditFromOutstanding($customer, (string) BigDecimal::of($account['outstanding'])->minus($amount));

        return $this->proposals->make(
            $actor,
            $this->name(),
            $data,
            risk: 'high',
            title: 'تسجيل سداد تاجر',
            before: ['amount_due' => $account['amount_due'], 'available_credit' => $account['available_credit']],
            after: [
                'customer' => $customer->company_name ?: $customer->name,
                'amount' => $data['amount'],
                'currency' => 'EGP',
                'treasury' => $treasury->name,
                'payment_method' => $data['payment_method'],
                'bank_name' => $data['bank_name'],
                'reference_number' => $data['reference_number'],
                'transfer_date' => $data['transfer_date'],
                'amount_due' => $after['amount_due'],
                'available_credit' => $after['available_credit'],
            ],
            typedConfirmation: $data['amount'],
            typedConfirmationHint: 'مبلغ السداد بمنزلتين عشريتين',
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        abort_if($action->conversation?->channel === 'whatsapp', 403);
        $customer = Customer::query()->findOrFail($action->args['customer_id']);
        $notice = $this->notices->recordStaffPayment($customer, $actor, $action->args, $action->args['account_snapshot']);

        return ToolOutcome::result(true, 'اتسجل السداد برقم '.$notice->notice_number, ['notice_id' => $notice->id]);
    }
}
