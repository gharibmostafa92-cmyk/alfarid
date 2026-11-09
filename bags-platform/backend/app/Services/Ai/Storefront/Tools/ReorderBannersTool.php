<?php

declare(strict_types=1);

namespace App\Services\Ai\Storefront\Tools;

use App\Enums\BannerPosition;
use App\Models\AiPendingAction;
use App\Models\Banner;
use App\Models\User;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Catalog\BannerService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ReorderBannersTool implements WriteTool
{
    public function __construct(private ProposalBuilder $proposals, private BannerService $banners) {}

    public function name(): string
    {
        return 'reorder_banners';
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function risk(): string
    {
        return 'medium';
    }

    public function permissions(): array
    {
        return ['store.manage'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إعادة ترتيب كل بانرات موضع واحد. القائمة لازم تكون كاملة لنفس الموضع. يحتاج تأكيد.',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['position', 'ids'],
                'properties' => (object) [
                    'position' => ['type' => 'STRING', 'enum' => ['main_slider', 'middle_banner', 'popup']],
                    'ids' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $data = ['position' => (string) ($args['position'] ?? ''), 'ids' => array_map(intval(...), (array) ($args['ids'] ?? []))];
        Validator::make($data, [
            'position' => ['required', Rule::enum(BannerPosition::class)],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ])->validate();
        $existing = Banner::query()->where('position', $data['position'])->orderBy('sort_order')->orderBy('id')->pluck('id');
        if ($existing->sort()->values()->all() !== collect($data['ids'])->sort()->values()->all()) {
            return ToolOutcome::denied('القائمة لازم تحتوي كل بانرات هذا الموضع وبدون زيادة.', auditable: false);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            $data,
            risk: $this->risk(),
            title: 'ترتيب بانرات '.$data['position'],
            before: ['ids' => $existing->all()],
            after: ['ids' => $data['ids']],
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $existing = Banner::query()->where('position', $action->args['position'])
            ->orderBy('id')->lockForUpdate()->pluck('id')->all();
        if ($existing !== collect($action->args['ids'])->sort()->values()->all()) {
            return ToolOutcome::result(false, 'قائمة البانرات اتغيّرت بعد المعاينة. اطلب ترتيب جديد.');
        }

        $this->banners->reorder((string) $action->args['position'], $action->args['ids']);

        return ToolOutcome::result(true, 'تم ترتيب البانرات.', ['position' => $action->args['position']]);
    }
}
