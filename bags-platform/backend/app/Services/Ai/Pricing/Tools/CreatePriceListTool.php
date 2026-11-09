<?php

declare(strict_types=1);

namespace App\Services\Ai\Pricing\Tools;

use App\Enums\PriceListType;
use App\Models\AiPendingAction;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Services\Ai\AgentTool;
use App\Services\Ai\EntityResolver;
use App\Services\Ai\Pricing\PricingArgs;
use App\Services\Ai\Pricing\PricingPresenter;
use App\Services\Ai\ProposalBuilder;
use App\Services\Ai\ToolKind;
use App\Services\Ai\ToolOutcome;
use App\Services\Ai\WriteTool;
use App\Services\Inventory\BranchAccess;
use App\Services\Pricing\PriceListService;
use App\Services\Auth\StaffAdministration;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

final class CreatePriceListTool implements WriteTool
{
    public function __construct(
        private EntityResolver $resolver,
        private PriceListService $lists,
        private ProposalBuilder $proposals,
        private PricingPresenter $presenter,
        private BranchAccess $branches,
    ) {}

    public function name(): string
    {
        return 'create_price_list';
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
        return ['price_lists.create'];
    }

    public function declaration(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'إنشاء قائمة أسعار جديدة فارغة (الاسم، النوع، المالك حسب النوع: عميل/مجموعة/فرع). لإضافة البنود استخدم upsert_price_list_items. دي قوائم أسعار — مش السعر الأساسي على المتغير (ده bulk_update_prices).',
            'parameters' => [
                'type' => 'OBJECT',
                'required' => ['name', 'type'],
                'properties' => (object) [
                    'name' => ['type' => 'STRING'],
                    'type' => ['type' => 'STRING', 'enum' => array_column(PriceListType::cases(), 'value')],
                    'customer_id' => ['type' => 'INTEGER'],
                    'customer_group_id' => ['type' => 'INTEGER'],
                    'branch_id' => ['type' => 'INTEGER'],
                    'starts_at' => ['type' => 'STRING'],
                    'ends_at' => ['type' => 'STRING'],
                    'priority' => ['type' => 'INTEGER'],
                    'is_active' => ['type' => 'BOOLEAN'],
                ],
            ],
        ];
    }

    public function prepare(User $actor, array $args): ToolOutcome
    {
        $args = PricingArgs::normalize($args);
        $name = trim((string) ($args['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'اسم القائمة مطلوب.']);
        }
        $type = (string) ($args['type'] ?? '');
        if (! in_array($type, array_column(PriceListType::cases(), 'value'), true)) {
            throw ValidationException::withMessages(['type' => 'نوع القائمة غير صالح.']);
        }

        $data = ['name' => $name, 'type' => $type];
        if ($type === PriceListType::CustomerSpecific->value) {
            if (empty($args['customer_id']) || ! Customer::query()->find((int) $args['customer_id'])) {
                throw ValidationException::withMessages(['customer_id' => 'قائمة العميل الخاص تتطلب عميل موجود.']);
            }
            $data['customer_id'] = (int) $args['customer_id'];
        } elseif ($type === PriceListType::CustomerGroup->value) {
            if (empty($args['customer_group_id']) || ! CustomerGroup::query()->find((int) $args['customer_group_id'])) {
                throw ValidationException::withMessages(['customer_group_id' => 'قائمة المجموعة تتطلب مجموعة عملاء موجودة.']);
            }
            $data['customer_group_id'] = (int) $args['customer_group_id'];
        } elseif (! empty($args['customer_id']) || ! empty($args['customer_group_id'])) {
            throw ValidationException::withMessages(['type' => 'النوع ده لا يقبل مالك عميل أو مجموعة.']);
        }

        $scope = PricingArgs::scopeBranchId($actor, $this->branches, $args);
        if ($scope instanceof ToolOutcome) {
            return $scope;
        }
        if ($scope['branchId'] !== null) {
            $data['branch_id'] = $scope['branchId'];
        }
        foreach (['starts_at', 'ends_at'] as $field) {
            if (isset($args[$field]) && trim((string) $args[$field]) !== '') {
                $data[$field] = trim((string) $args[$field]);
            }
        }
        if (isset($args['priority']) && is_numeric($args['priority'])) {
            $data['priority'] = (int) $args['priority'];
        }
        if (array_key_exists('is_active', $args)) {
            $data['is_active'] = (bool) $args['is_active'];
        }
        try {
            if (isset($data['starts_at'], $data['ends_at']) && Carbon::parse($data['ends_at'])->lt(Carbon::parse($data['starts_at']))) {
                throw ValidationException::withMessages(['ends_at' => 'نهاية الصلاحية يجب ألا تسبق بدايتها.']);
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['starts_at' => 'صيغة التاريخ غير صالحة.']);
        }

        return $this->proposals->make(
            $actor,
            $this->name(),
            $data,
            risk: $this->risk(),
            title: 'إنشاء قائمة أسعار: '.$name,
            before: ['items_count' => 0],
            after: ['name' => $name, 'type' => $type],
        );
    }

    public function execute(User $actor, AiPendingAction $action): ToolOutcome
    {
        $list = $this->lists->create((array) $action->args, $actor);

        StaffAdministration::audit(
            $actor,
            'price_list.created',
            'price_list',
            $list->id,
            ['name', 'type'],
            [],
            ['name' => $list->name, 'type' => $list->type instanceof PriceListType ? $list->type->value : (string) $list->type],
            ['channel' => 'ai', 'tool' => $this->name(), 'entity' => 'price_list:'.$list->id, 'conversation_id' => $action->conversation_id, 'action_id' => $action->id],
        );

        return ToolOutcome::result(true, 'تم إنشاء قائمة الأسعار.', [
            'price_list_id' => $list->id,
            'deep_link' => '#/pricing?list='.$list->id,
        ]);
    }
}
