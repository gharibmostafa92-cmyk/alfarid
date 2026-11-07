<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Enums\CustomerStatus;
use App\Enums\QuotationStatus;
use App\Enums\WholesalePaymentNoticeStatus;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Ai\AgentTool;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;

final class WholesaleInboxTool implements AgentTool
{
    public function name(): string
    {
        return 'wholesale_inbox';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Read;
    }

    public function risk(): string
    {
        return 'low';
    }

    public function permissions(): array
    {
        return ['quotations.view', 'payment_notices.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'طابور شغل الجملة: عروض مستنية تسعير، إشعارات مستنية مراجعة، عروض قربت تنتهي، وطلبات تجار جديدة.',
            'parameters' => ['type' => 'OBJECT', 'properties' => (object) []],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $quotes = [];
        $expiring = [];
        if ($actor->can('quotations.view')) {
            $quotes = Quotation::query()
                ->with('customer:id,name,company_name')
                ->where('status', QuotationStatus::Requested)
                ->orderBy('id')
                ->limit(25)
                ->get()
                ->map(fn (Quotation $q) => [
                    'id' => $q->id,
                    'number' => $q->quotation_number,
                    'customer' => $q->customer?->company_name ?: $q->customer?->name,
                    'waiting_since' => $q->created_at?->toDateTimeString(),
                ])->all();
            $expiring = Quotation::query()
                ->where('status', QuotationStatus::PricedByAdmin)
                ->whereNotNull('valid_until')
                ->whereDate('valid_until', '>', now()->toDateString())
                ->whereDate('valid_until', '<=', now()->addDays(2)->toDateString())
                ->orderBy('valid_until')
                ->limit(25)
                ->get(['id', 'quotation_number', 'valid_until'])
                ->map(fn (Quotation $q) => [
                    'id' => $q->id,
                    'number' => $q->quotation_number,
                    'valid_until' => $q->valid_until?->toDateString(),
                ])->all();
        }

        $notices = [];
        if ($actor->can('payment_notices.view')) {
            $notices = WholesalePaymentNotice::query()
                ->with('customer:id,name,company_name')
                ->where('status', WholesalePaymentNoticeStatus::PendingReview)
                ->orderBy('id')
                ->limit(25)
                ->get()
                ->map(fn (WholesalePaymentNotice $n) => [
                    'id' => $n->id,
                    'number' => $n->notice_number,
                    'customer' => $n->customer?->company_name ?: $n->customer?->name,
                    'amount' => (string) $n->amount,
                    'match_score' => $n->match_score,
                ])->all();
        }

        $applications = Customer::query()
            ->commercial()
            ->where('status', CustomerStatus::Pending)
            ->orderBy('id')
            ->limit(15)
            ->get(['id', 'name', 'company_name', 'created_at'])
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->company_name ?: $c->name,
                'since' => $c->created_at?->toDateTimeString(),
            ])->all();

        $rows = [];
        foreach ($quotes as $row) {
            $rows[] = ['عرض مستني تسعير', $row['number'], (string) $row['customer'], (string) $row['waiting_since']];
        }
        foreach ($notices as $row) {
            $rows[] = ['إشعار مستني مراجعة', $row['number'], (string) $row['customer'], (string) $row['amount']];
        }
        foreach ($expiring as $row) {
            $rows[] = ['عرض قرب ينتهي', $row['number'], '', (string) $row['valid_until']];
        }
        foreach ($applications as $row) {
            $rows[] = ['طلب تاجر جديد', '#'.$row['id'], $row['name'], (string) $row['since']];
        }

        return ToolOutcome::data([
            'quotations_waiting' => $quotes,
            'notices_waiting' => $notices,
            'quotations_expiring' => $expiring,
            'new_dealer_applications' => $applications,
            'quick_actions' => ['get_quotation', 'get_payment_notice'],
        ], [
            'type' => 'table',
            'quick_actions' => [
                ['label' => 'تفاصيل عرض', 'tool' => 'get_quotation'],
                ['label' => 'تفاصيل إشعار', 'tool' => 'get_payment_notice'],
            ],
            'columns' => ['النوع', 'الرقم', 'التاجر', 'التفصيل'],
            'rows' => $rows,
        ]);
    }
}
