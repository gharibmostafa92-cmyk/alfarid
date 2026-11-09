<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Pricing\PriceListService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ExportPriceListTool implements AgentTool
{
    public function __construct(private PriceListService $lists) {}

    public function name(): string
    {
        return 'export_price_list';
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
        return ['price_lists.export'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'جهّز زر تنزيل CSV لقائمة أسعار محددة.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['price_list_id'],
                'properties' => (object) ['price_list_id' => ['type' => 'INTEGER']],
            ],
        ];
    }

    public function handle(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        try {
            $list = $this->lists->find((int) ($args['price_list_id'] ?? 0), $actor);
        } catch (ModelNotFoundException) {
            return ToolOutcome::denied('قائمة الأسعار غير موجودة.', auditable: false);
        }

        return ToolOutcome::data(
            ['deep_link' => route('admin.price-lists.export', ['price_list' => $list], absolute: false)],
            ['type' => 'price_list_export', 'price_list_id' => $list->id],
        );
    }
}
