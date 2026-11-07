<?php

declare(strict_types=1);

namespace App\Services\Ai\Shipping\Tools;

use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Shipping\ShippingArgs;
use App\Services\Ai\Shipping\ShippingQuoteExplainer;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;

final class WhatIfShippingChangeTool implements AgentTool
{
    public function __construct(private ShippingQuoteExplainer $explainer) {}

    public function name(): string
    {
        return 'what_if_shipping_change';
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
            'description' => 'جرّب تغيير تكلفة أو رسوم تحصيل أو عتبة شحن مجاني في الذاكرة من غير حفظ. قارن تحت العتبة وعندها وفوقها، للتحصيل ولغيره.',
            'parameters' => [
                'type' => 'OBJECT',
                'properties' => (object) [
                    'city_id' => ['type' => 'INTEGER'],
                    'city_query' => ['type' => 'STRING'],
                    'base_cost' => ['type' => 'NUMBER'],
                    'cod_fee' => ['type' => 'NUMBER'],
                    'free_shipping_threshold' => ['type' => 'NUMBER'],
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
        $winner = $this->explainer->matchedRates($city->id)->first();
        $override = ['rate_id' => $winner?->id];
        foreach (['base_cost', 'cod_fee'] as $field) {
            if (array_key_exists($field, $args)) {
                $override[$field] = ShippingArgs::money($args[$field], $field);
            }
        }
        if (array_key_exists('free_shipping_threshold', $args) && $args['free_shipping_threshold'] !== null && $args['free_shipping_threshold'] !== '') {
            $override['free_shipping_threshold'] = ShippingArgs::money($args['free_shipping_threshold'], 'free_shipping_threshold');
        }
        $threshold = $override['free_shipping_threshold'] ?? ($winner?->free_shipping_threshold !== null ? (float) $winner->free_shipping_threshold : null);
        $samples = $threshold === null
            ? [['label' => 'given', 'merchandise' => 100.0]]
            : [
                ['label' => 'below', 'merchandise' => max(0, (float) $threshold - 1)],
                ['label' => 'at', 'merchandise' => (float) $threshold],
                ['label' => 'above', 'merchandise' => (float) $threshold + 1],
            ];
        $rows = [];
        foreach ($samples as $sample) {
            foreach ([true, false] as $cod) {
                $current = $this->explainer->explain($city->id, $sample['merchandise'], $cod);
                $next = $this->explainer->explain($city->id, $sample['merchandise'], $cod, $override);
                $rows[] = [
                    'label' => $sample['label'],
                    'merchandise_total' => $sample['merchandise'],
                    'payment_method' => $cod ? 'cod' : 'other',
                    'current_cost' => $current['cost'],
                    'current_cod_fee' => $current['cod_fee'],
                    'simulated_cost' => $next['cost'],
                    'simulated_cod_fee' => $next['cod_fee'],
                ];
            }
        }

        return ToolOutcome::data([
            'saved' => false,
            'winner_rate_id' => $winner?->id,
            'note' => $winner ? 'المحاكاة على التعريفة الفائزة الحالية فقط.' : 'مفيش تعريفة فائزة؛ العرض يفضل احتياط المدينة.',
            'samples' => $rows,
        ]);
    }
}
