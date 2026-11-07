<?php

declare(strict_types=1);

namespace App\Services\Ai\Wholesale\Tools;

use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Wholesale\WholesaleInsights;

final class WholesaleInsightsTool implements AgentTool
{
    public function __construct(private WholesaleInsights $insights) {}

    public function name(): string
    {
        return 'wholesale_insights';
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
        return ['quotations.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'تقارير قراءة: أولوية التحصيل، توقع إعادة الطلب، قبول العروض، اقتراح حد ائتمان، وفروق الإيصالات. لا يرسل رسائل ولا يغيّر الحد.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'report' => ['type' => 'STRING', 'enum' => ['collection', 'reorder', 'quotes', 'credit', 'anomalies']],
                ],
                'required' => ['report'],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $report = (string) ($args['report'] ?? '');
        $data = match ($report) {
            'collection' => $this->insights->collectionPriorities(),
            'reorder' => $this->insights->reorderOutlook(),
            'quotes' => $this->insights->quoteAcceptance(),
            'credit' => $this->insights->creditSuggestions(),
            'anomalies' => $this->insights->anomalies(),
            default => null,
        };
        if ($data === null) {
            return ToolOutcome::denied('التقرير لازم يكون collection أو reorder أو quotes أو credit أو anomalies.', auditable: false);
        }

        return ToolOutcome::present(['report' => $report, 'data' => $data], ['type' => 'table'], 'تقرير الجملة');
    }
}
