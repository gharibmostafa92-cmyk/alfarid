<?php

declare(strict_types=1);

namespace App\Services\Ai\Shipping\Tools;

use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Shipping\ShippingArgs;
use App\Services\Ai\Shipping\ShippingQuoteExplainer;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;

final class ExplainShippingQuoteTool implements AgentTool
{
    public function __construct(private ShippingQuoteExplainer $explainer) {}

    public function name(): string
    {
        return 'explain_shipping_quote';
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
        return ['shipping.view'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'اشرح تكلفة التوصيل ورسوم التحصيل لمدينة وقيمة بضاعة. الرقم ييجي من محرك الشحن. الفائز أول شركة نشطة حسب المعرّف مش الأرخص. عتبة الشحن المجاني بتصغّر التكلفة بس ومش رسوم التحصيل. متحسبش الرقم بنفسك.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'city_id' => ['type' => 'INTEGER'],
                    'city_query' => ['type' => 'STRING'],
                    'merchandise_total' => ['type' => 'NUMBER'],
                    'payment_method' => ['type' => 'STRING', 'enum' => ['cod', 'other']],
                ],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        ShippingArgs::rejectSecrets($args);
        $city = ShippingArgs::city($args);
        if ($city instanceof ToolOutcome) {
            return $city;
        }
        $merchandise = ShippingArgs::money($args['merchandise_total'] ?? 0, 'merchandise_total');
        $cod = ($args['payment_method'] ?? 'cod') === 'cod';

        return ToolOutcome::data($this->explainer->explain($city->id, (float) $merchandise, $cod));
    }
}
