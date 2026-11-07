<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Models\User;
use App\Models\WholesalePaymentNotice;
use App\Services\Ai\AgentTool;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\BranchAccess;
use App\Services\WholesalePaymentNoticeService;
use Throwable;

final class PreviewNoticeAllocationTool implements AgentTool
{
    public function __construct(
        private WholesalePaymentNoticeService $notices,
        private BranchAccess $branches,
    ) {}

    public function name(): string
    {
        return 'preview_notice_allocation';
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
        return ['payment_notices.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'محاكاة اعتماد إشعار سداد: الطلبات اللي هتتقفل والمتبقي، من غير ما يتنفذ الاعتماد.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) ['notice_id' => ['type' => 'INTEGER']],
                'required' => ['notice_id'],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $notice = WholesalePaymentNotice::query()->find((int) ($args['notice_id'] ?? 0));
        if ($notice === null) {
            return ToolOutcome::denied('إشعار السداد مش موجود.', auditable: false);
        }
        try {
            $branchId = $this->branches->restrictedBranch($actor);
        } catch (Throwable) {
            return ToolOutcome::denied('النطاق غير مسموح.', auditable: false);
        }

        $preview = $this->notices->previewNoticeAllocation($notice, $branchId);
        $closed = [];
        $open = [];
        if (($preview['linked_order']['closes'] ?? false) === true) {
            $closed[] = '#'.$preview['linked_order']['order_number'];
        } elseif ($preview['linked_order'] !== null) {
            $open[] = '#'.$preview['linked_order']['order_number'].' هيفضل عليه '.$preview['linked_order']['remaining_after'];
        }
        foreach ($preview['fifo'] as $line) {
            if ($line['closes']) {
                $closed[] = '#'.$line['order_number'];
            } elseif ((float) $line['remaining'] > 0) {
                $open[] = '#'.$line['order_number'].' هيفضل عليه '.$line['remaining'];
            }
        }
        $summary = 'لو اعتمدت الإشعار ده';
        $summary .= $closed === [] ? '، مفيش طلب هيتقفل بالكامل' : '، الطلبات '.implode(' و', $closed).' هتتقفل';
        $summary .= $open === [] ? '.' : '، و'.implode('، و', $open).'.';

        return ToolOutcome::data([
            'summary' => $summary,
            'preview' => $preview,
        ], [
            'type' => 'table',
            'columns' => ['الطلب', 'هيخصص', 'المتبقي', 'يتقفل'],
            'rows' => array_map(fn (array $line) => [
                $line['order_number'],
                $line['allocated'],
                $line['remaining'],
                $line['closes'] ? 'نعم' : 'لا',
            ], $preview['fifo']),
        ], $summary);
    }
}
