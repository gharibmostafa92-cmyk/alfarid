<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Actions\Branches\CreateBranch;
use App\Actions\Branches\UpdateBranch;
use App\Actions\Staff\CreateRole;
use App\Actions\Staff\CreateStaffUser;
use App\Actions\Staff\DeleteRole;
use App\Actions\Staff\UpdateRole;
use App\Actions\Staff\UpdateStaffUser;
use App\Enums\BranchType;
use App\Enums\StaffRole;
use App\Http\Requests\Api\V1\Admin\StoreBranchRequest;
use App\Http\Requests\Api\V1\Admin\StoreRoleRequest;
use App\Http\Requests\Api\V1\Admin\StoreUserRequest;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\CostAccess;
use App\Services\SettingService;
use App\Services\StaffAdministration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ToolRegistry
{
    public function __construct(private EntityResolver $resolver, private BranchAccess $branches, private SettingService $settings) {}

    /** @return array<string, array{kind:ToolKind, permissions:list<string>, declaration:array<string,mixed>, risk:string}> */
    public function definitions(): array
    {
        $obj = fn (array $props, array $req = []) => ['type' => 'OBJECT', 'properties' => (object) $props, 'required' => $req];
        $str = ['type' => 'STRING'];
        $int = ['type' => 'INTEGER'];
        $bool = ['type' => 'BOOLEAN'];
        $strArr = ['type' => 'ARRAY', 'items' => ['type' => 'STRING']];
        $branchTypes = array_column(BranchType::cases(), 'value');

        return [
            'bulk_actions' => ['kind' => ToolKind::Write, 'permissions' => ['branches.update', 'branches.manage', 'users.update', 'users.manage', 'roles.update', 'roles.manage'], 'risk' => 'high', 'declaration' => ['name' => 'bulk_actions', 'description' => 'اقتراح مجموعة تعديلات مستقلة بكارت واحد. راجع كل عنصر قبل التأكيد؛ لا تنفذ أي تعديل فوراً.', 'parameters' => $obj(['items' => ['type' => 'ARRAY', 'minItems' => 2, 'items' => $obj(['tool' => ['type' => 'STRING', 'enum' => ['set_branch_status', 'update_role_permissions', 'set_user_status', 'assign_roles', 'revoke_user_sessions']], 'args' => $obj(['branch_id' => $int, 'user_id' => $int, 'role_id' => $int, 'active' => $bool, 'add' => $strArr, 'remove' => $strArr])], ['tool', 'args'])]], ['items'])]],
            'find_entities' => ['kind' => ToolKind::Read, 'permissions' => ['branches.view', 'branches.manage', 'users.view', 'users.manage', 'roles.view', 'roles.manage', 'permissions.view'], 'risk' => 'low', 'declaration' => ['name' => 'find_entities', 'description' => 'بحث عن فروع أو موظفين أو أدوار أو صلاحيات', 'parameters' => $obj(['type' => ['type' => 'STRING', 'enum' => ['branch', 'user', 'role', 'permission']], 'query' => $str], ['type', 'query'])]],
            'list_branches' => ['kind' => ToolKind::Read, 'permissions' => ['branches.view', 'branches.manage'], 'risk' => 'low', 'declaration' => ['name' => 'list_branches', 'description' => 'قائمة الفروع (active=false للمتوقفة)', 'parameters' => $obj(['active' => $bool, 'city' => $str, 'type' => ['type' => 'STRING', 'enum' => $branchTypes]])]],
            'list_permissions' => ['kind' => ToolKind::Read, 'permissions' => ['permissions.view', 'roles.view', 'roles.manage'], 'risk' => 'low', 'declaration' => ['name' => 'list_permissions', 'description' => 'كتالوج الصلاحيات المتاحة', 'parameters' => $obj([])]],
            'list_roles' => ['kind' => ToolKind::Read, 'permissions' => ['roles.view', 'roles.manage'], 'risk' => 'low', 'declaration' => ['name' => 'list_roles', 'description' => 'قائمة الأدوار', 'parameters' => $obj([])]],
            'list_staff' => ['kind' => ToolKind::Read, 'permissions' => ['users.view', 'users.manage'], 'risk' => 'low', 'declaration' => ['name' => 'list_staff', 'description' => 'قائمة الموظفين', 'parameters' => $obj(['query' => $str])]],
            'explain_user_access' => ['kind' => ToolKind::Read, 'permissions' => ['users.view', 'users.manage'], 'risk' => 'low', 'declaration' => ['name' => 'explain_user_access', 'description' => 'شرح أدوار وصلاحيات ونطاق موظف', 'parameters' => $obj(['user_id' => $int], ['user_id'])]],
            'who_can' => ['kind' => ToolKind::Read, 'permissions' => ['roles.view', 'roles.manage', 'permissions.view'], 'risk' => 'low', 'declaration' => ['name' => 'who_can', 'description' => 'مين معاه صلاحية معينة', 'parameters' => $obj(['permission' => $str], ['permission'])]],
            'compare_roles' => ['kind' => ToolKind::Read, 'permissions' => ['roles.view', 'roles.manage'], 'risk' => 'low', 'declaration' => ['name' => 'compare_roles', 'description' => 'مقارنة صلاحيات دورين', 'parameters' => $obj(['role_a' => $int, 'role_b' => $int], ['role_a', 'role_b'])]],
            'recent_audit' => ['kind' => ToolKind::Read, 'permissions' => ['users.view', 'users.manage'], 'risk' => 'low', 'declaration' => ['name' => 'recent_audit', 'description' => 'آخر أحداث سجل التدقيق الإداري', 'parameters' => $obj(['days' => $int])]],
            'ask_user_to_choose' => ['kind' => ToolKind::Ui, 'permissions' => ['branches.view', 'branches.manage', 'users.view', 'users.manage', 'roles.view', 'roles.manage'], 'risk' => 'low', 'declaration' => ['name' => 'ask_user_to_choose', 'description' => 'اطلب من المستخدم اختيار كيان من قائمة حقيقية', 'parameters' => $obj(['entity_type' => ['type' => 'STRING', 'enum' => ['branch', 'user', 'role']], 'purpose' => $str, 'query' => $str], ['entity_type', 'purpose'])]],
            'create_branch' => ['kind' => ToolKind::Write, 'permissions' => ['branches.create', 'branches.manage'], 'risk' => 'low', 'declaration' => ['name' => 'create_branch', 'description' => 'إنشاء فرع جديد (يحتاج تأكيد)', 'parameters' => $obj(['name' => $str, 'code' => $str, 'type' => ['type' => 'STRING', 'enum' => $branchTypes], 'city' => $str, 'phone' => $str], ['name', 'code', 'type'])]],
            'set_branch_status' => ['kind' => ToolKind::Write, 'permissions' => ['branches.update', 'branches.manage'], 'risk' => 'high', 'declaration' => ['name' => 'set_branch_status', 'description' => 'تفعيل أو تعطيل فرع (يحتاج تأكيد)', 'parameters' => $obj(['branch_id' => $int, 'branch_query' => $str, 'active' => $bool], ['active'])]],
            'create_role' => ['kind' => ToolKind::Write, 'permissions' => ['roles.create', 'roles.manage'], 'risk' => 'medium', 'declaration' => ['name' => 'create_role', 'description' => 'إنشاء دور جديد (يحتاج تأكيد)', 'parameters' => $obj(['name' => $str, 'permissions' => $strArr], ['name'])]],
            'update_role_permissions' => ['kind' => ToolKind::Write, 'permissions' => ['roles.update', 'roles.manage'], 'risk' => 'medium', 'declaration' => ['name' => 'update_role_permissions', 'description' => 'تعديل صلاحيات دور (يحتاج تأكيد)', 'parameters' => $obj(['role_id' => $int, 'add' => $strArr, 'remove' => $strArr], ['role_id'])]],
            'delete_role' => ['kind' => ToolKind::Write, 'permissions' => ['roles.delete', 'roles.manage'], 'risk' => 'high', 'declaration' => ['name' => 'delete_role', 'description' => 'حذف دور (يحتاج تأكيد)', 'parameters' => $obj(['role_id' => $int], ['role_id'])]],
            'create_staff_user' => ['kind' => ToolKind::Write, 'permissions' => ['users.create', 'users.manage'], 'risk' => 'medium', 'declaration' => ['name' => 'create_staff_user', 'description' => 'إنشاء موظف جديد، الباسورد يتولد في السيرفر (يحتاج تأكيد)', 'parameters' => $obj(['name' => $str, 'email' => $str, 'phone' => $str, 'branch_id' => $int, 'roles' => $strArr], ['name', 'email'])]],
            'set_user_status' => ['kind' => ToolKind::Write, 'permissions' => ['users.update', 'users.manage'], 'risk' => 'high', 'declaration' => ['name' => 'set_user_status', 'description' => 'تفعيل أو تعطيل موظف (يحتاج تأكيد)', 'parameters' => $obj(['user_id' => $int, 'active' => $bool], ['user_id', 'active'])]],
            'assign_roles' => ['kind' => ToolKind::Write, 'permissions' => ['users.update', 'users.manage'], 'risk' => 'high', 'declaration' => ['name' => 'assign_roles', 'description' => 'إضافة أو إزالة أدوار موظف (يحتاج تأكيد، بيخرجه من كل الأجهزة)', 'parameters' => $obj(['user_id' => $int, 'add' => $strArr, 'remove' => $strArr], ['user_id'])]],
            'revoke_user_sessions' => ['kind' => ToolKind::Write, 'permissions' => ['users.update', 'users.manage'], 'risk' => 'medium', 'declaration' => ['name' => 'revoke_user_sessions', 'description' => 'تسجيل خروج موظف من كل الأجهزة (يحتاج تأكيد)', 'parameters' => $obj(['user_id' => $int], ['user_id'])]],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function declarationsFor(User $actor): array
    {
        $disabled = $this->settings->get('ai_disabled_tools', []);
        if (! is_array($disabled)) {
            $disabled = [];
        }
        $out = [];
        foreach ($this->definitions() as $name => $def) {
            if (in_array($name, $disabled, true)) {
                continue;
            }
            if ($def['kind'] === ToolKind::Write && ! $actor->can('ai_agent.execute')) {
                continue;
            }
            if (! $actor->canAny($def['permissions'])) {
                continue;
            }
            $out[] = $def['declaration'];
        }

        return $out;
    }

    public function isWrite(string $name): bool
    {
        return ($this->definitions()[$name]['kind'] ?? null) === ToolKind::Write;
    }

    /** @param array{name:string,args:array<string,mixed>} $call */
    public function dispatch(User $actor, AiConversation $conv, array $call): ToolOutcome
    {
        $outcome = $this->dispatchAllowed($actor, $conv, $call);
        if ($outcome->type === 'denied') {
            StaffAdministration::audit($actor, 'administration.denied', 'ai_conversation', $conv->id, [], via: ['channel' => 'ai', 'conversation_id' => $conv->id]);
        }

        return $outcome;
    }

    private function dispatchAllowed(User $actor, AiConversation $conv, array $call): ToolOutcome
    {
        $name = $call['name'];
        $args = $call['args'] ?? [];
        $defs = $this->definitions();
        if (! isset($defs[$name])) {
            return ToolOutcome::denied('أداة غير معروفة.');
        }
        $disabled = $this->settings->get('ai_disabled_tools', []);
        if (is_array($disabled) && in_array($name, $disabled, true)) {
            return ToolOutcome::denied('الأداة دي مقفولة من الإعدادات.');
        }
        if ($defs[$name]['kind'] === ToolKind::Write && ! $actor->can('ai_agent.execute')) {
            return ToolOutcome::denied('محتاج صلاحية ai_agent.execute لتنفيذ تعديلات.');
        }
        if (! $actor->canAny($defs[$name]['permissions'])) {
            return ToolOutcome::denied('معندكش الصلاحية الأصلية للأداة دي.');
        }
        try {
            $this->validateArgs($name, $args);

            return match ($name) {
                'find_entities' => $this->findEntities($actor, $args),
                'list_branches' => $this->entityTable('branches', $this->resolver->branches($actor, '', $args)),
                'list_roles' => $this->entityTable('roles', $this->resolver->roles()),
                'list_permissions' => $this->permissionsTable(),
                'list_staff' => $this->entityTable('staff', $this->resolver->users($actor, (string) ($args['query'] ?? ''))),
                'explain_user_access' => $this->explainUser($actor, $args),
                'who_can' => $this->whoCan($actor, $args),
                'compare_roles' => $this->compareRoles($args),
                'recent_audit' => $this->recentAudit($actor, $args),
                'ask_user_to_choose' => $this->picker($actor, $args),
                'bulk_actions' => $this->prepareBulk($actor, $conv, $args),
                default => $this->prepareWrite($actor, $conv, $name, $args),
            };
        } catch (ValidationException $e) {
            return ToolOutcome::denied(collect($e->errors())->flatten()->implode(' '));
        } catch (HttpExceptionInterface $e) {
            return ToolOutcome::denied($e->getMessage() ?: 'مرفوض.');
        } catch (\Throwable $e) {
            report($e);

            return ToolOutcome::denied('حصل خطأ أثناء تنفيذ الأداة.');
        }
    }

    private function entityTable(string $key, array $items): ToolOutcome
    {
        return ToolOutcome::data([$key => $items], ['type' => 'table', 'columns' => ['الرقم', 'الاسم', 'التفاصيل'], 'rows' => array_map(fn ($item) => [$item['id'], $item['label'], $item['meta']], $items)]);
    }

    private function permissionsTable(): ToolOutcome
    {
        $names = Permission::query()->where('guard_name', 'staff')->orderBy('name')->pluck('name')->all();

        return ToolOutcome::data(['permissions' => $names], ['type' => 'table', 'columns' => ['الصلاحية'], 'rows' => array_map(fn ($name) => [$name], $names)]);
    }

    private function validateArgs(string $name, array &$args): void
    {
        if ($name === 'create_role' && is_string($args['name'] ?? null)) {
            $args['name'] = StaffRole::normalizeName($args['name']);
        }
        if ($name === 'create_branch' && is_string($args['code'] ?? null)) {
            $args['code'] = mb_strtoupper(trim($args['code']));
        }
        if ($name === 'create_staff_user' && is_string($args['email'] ?? null)) {
            $args['email'] = mb_strtolower(trim($args['email']));
        }
        if ($name === 'bulk_actions') {
            Validator::make($args, ['items' => ['required', 'array', 'min:2', 'max:'.max(1, (int) $this->settings->get('ai_bulk_max_items', 25))], 'items.*.tool' => ['required', Rule::in(['set_branch_status', 'update_role_permissions', 'set_user_status', 'assign_roles', 'revoke_user_sessions'])], 'items.*.args' => ['required', 'array']])->validate();

            return;
        }
        $schema = $this->definitions()[$name]['declaration']['parameters'];
        $rules = [];
        foreach ($schema['properties'] as $key => $property) {
            $rules[$key] = [in_array($key, $schema['required'] ?? [], true) ? 'required' : 'sometimes', match ($property['type']) {
                'INTEGER' => 'integer', 'BOOLEAN' => 'boolean', 'ARRAY' => 'array', default => 'string'
            }];
            if ($property['type'] === 'STRING') {
                $rules[$key][] = 'max:255';
            }
            if ($property['type'] === 'INTEGER') {
                $rules[$key][] = 'min:1';
            }
            if (isset($property['enum'])) {
                $rules[$key][] = Rule::in($property['enum']);
            }
            if ($property['type'] === 'ARRAY') {
                $rules[$key][] = 'max:200';
                $rules[$key.'.*'] = ['string', 'distinct', 'max:255'];
            }
        }
        if (in_array($name, ['create_role', 'update_role_permissions'], true)) {
            foreach (['permissions', 'add', 'remove'] as $key) {
                if (isset($rules[$key])) {
                    $rules[$key.'.*'][] = Rule::exists('permissions', 'name')->where('guard_name', 'staff');
                }
            }
        }
        if ($name === 'assign_roles' || $name === 'create_staff_user') {
            foreach (['roles', 'add', 'remove'] as $key) {
                if (isset($rules[$key])) {
                    $rules[$key.'.*'][] = Rule::exists('roles', 'name')->where('guard_name', 'staff');
                }
            }
        }
        if ($name === 'create_staff_user') {
            $userRules = (new StoreUserRequest)->rules();
            unset($userRules['password']);
            $rules = array_replace($rules, $userRules);
        }
        if ($name === 'create_branch') {
            $rules = array_replace($rules, (new StoreBranchRequest)->rules());
        }
        if ($name === 'create_role') {
            $rules = array_replace($rules, (new StoreRoleRequest)->rules());
        }
        Validator::make($args, $rules)->validate();
        abort_if(array_diff(array_keys($args), array_keys((array) $schema['properties'])) !== [], 422, 'بيانات أداة غير مسموحة.');
    }

    private function findEntities(User $actor, array $args): ToolOutcome
    {
        $type = (string) ($args['type'] ?? '');
        $query = (string) ($args['query'] ?? '');
        if (! $this->canReadEntity($actor, $type)) {
            return ToolOutcome::denied('معندكش صلاحية عرض النوع ده.');
        }

        return match ($type) {
            'branch' => ToolOutcome::data(['results' => $this->resolver->branches($actor, $query)]),
            'user' => ToolOutcome::data(['results' => $this->resolver->users($actor, $query)]),
            'role' => ToolOutcome::data(['results' => $this->resolver->roles($query)]),
            'permission' => ToolOutcome::data(['results' => Permission::query()->where('guard_name', 'staff')->get()->filter(fn ($p) => EntityResolver::normalize($p->name) && str_contains(EntityResolver::normalize($p->name), EntityResolver::normalize($query)))->map(fn ($p) => ['id' => $p->name, 'label' => $p->name, 'meta' => ''])->values()->all()]),
            default => ToolOutcome::denied('نوع كيان غير معروف.'),
        };
    }

    private function explainUser(User $actor, array $args): ToolOutcome
    {
        $user = User::query()->where('is_system', false)->find((int) ($args['user_id'] ?? 0));
        if (! $user) {
            return ToolOutcome::denied('الموظف مش موجود.');
        }
        $restricted = null;
        try {
            $restricted = $this->branches->restrictedBranch($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('النطاق غير مسموح.');
        }
        if ($restricted !== null && (int) $user->branch_id !== $restricted) {
            return ToolOutcome::denied('الموظف خارج نطاق فرعك.');
        }

        return ToolOutcome::data(['user' => ['id' => $user->id, 'name' => $user->name, 'email' => $this->resolver->maskEmail($user->email), 'branch_id' => $user->branch_id, 'is_active' => (bool) $user->is_active, 'roles' => $user->roles()->pluck('name')->all(), 'permissions' => $user->getAllPermissions()->pluck('name')->all(), 'can_view_cost' => CostAccess::canView($user)]]);
    }

    public function canReadEntity(User $actor, string $type): bool
    {
        $permissions = match ($type) {
            'branch' => ['branches.view', 'branches.manage'],
            'user' => ['users.view', 'users.manage'],
            'role' => ['roles.view', 'roles.manage'],
            'permission' => ['permissions.view', 'roles.view', 'roles.manage'],
            default => [],
        };

        return $permissions !== [] && $actor->canAny($permissions);
    }

    private function whoCan(User $actor, array $args): ToolOutcome
    {
        $perm = (string) ($args['permission'] ?? '');
        $roles = Role::query()->where('guard_name', 'staff')->whereHas('permissions', fn ($q) => $q->where('name', $perm))->pluck('name')->all();
        $restricted = $this->branches->restrictedBranch($actor);
        $users = $this->canReadEntity($actor, 'user') ? User::query()->where('is_system', false)->when($restricted !== null, fn ($q) => $q->where('branch_id', $restricted))->where(function ($q) use ($roles, $perm) {
            $q->whereHas('roles', fn ($r) => $r->where('guard_name', 'staff')->whereIn('name', $roles))->orWhereHas('permissions', fn ($p) => $p->where('guard_name', 'staff')->where('name', $perm));
        })->pluck('name')->all() : [];

        return ToolOutcome::data(['permission' => $perm, 'roles' => $roles, 'users' => $users]);
    }

    private function compareRoles(array $args): ToolOutcome
    {
        $a = Role::where('guard_name', 'staff')->find((int) ($args['role_a'] ?? 0));
        $b = Role::where('guard_name', 'staff')->find((int) ($args['role_b'] ?? 0));
        if (! $a || ! $b) {
            return ToolOutcome::denied('دور مش موجود.');
        }
        $pa = $a->permissions()->pluck('name')->all();
        $pb = $b->permissions()->pluck('name')->all();

        return ToolOutcome::data(['only_a' => array_values(array_diff($pa, $pb)), 'only_b' => array_values(array_diff($pb, $pa)), 'common' => array_values(array_intersect($pa, $pb))]);
    }

    private function recentAudit(User $actor, array $args): ToolOutcome
    {
        $this->branches->assertCentralAccess($actor);
        $days = max(1, min(90, (int) ($args['days'] ?? 7)));
        $rows = DB::table('staff_audit_logs')->where('created_at', '>=', now()->subDays($days))->orderByDesc('id')->limit(50)->get(['id', 'actor_id', 'action', 'target_type', 'target_id', 'created_at'])->map(fn ($r) => (array) $r)->all();

        return ToolOutcome::data(['audit' => $rows]);
    }

    private function picker(User $actor, array $args): ToolOutcome
    {
        $type = (string) ($args['entity_type'] ?? 'branch');
        $query = (string) ($args['query'] ?? '');
        if (! $this->canReadEntity($actor, $type)) {
            return ToolOutcome::denied('معندكش صلاحية عرض النوع ده.');
        }
        $options = match ($type) {
            'branch' => $this->resolver->branches($actor, $query), 'user' => $this->resolver->users($actor, $query), 'role' => $this->resolver->roles($query), default => []
        };

        return ToolOutcome::picker(['type' => 'entity_picker', 'entity' => $type, 'purpose' => (string) ($args['purpose'] ?? ''), 'multi' => false, 'options' => $options]);
    }

    private function pickerFor(User $actor, string $entity, string $purpose, string $query = ''): ToolOutcome
    {
        return $this->picker($actor, ['entity_type' => $entity, 'purpose' => $purpose, 'query' => $query]);
    }

    public function hashArgs(array $args): string
    {
        $c = $this->canonical($args);

        return hash_hmac('sha256', json_encode($c, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function prepareBulk(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        return DB::transaction(function () use ($actor, $conv, $args): ToolOutcome {
            $ids = [];
            $items = [];
            foreach ($args['items'] as $item) {
                $outcome = $this->dispatch($actor, $conv, ['name' => $item['tool'], 'args' => $item['args']]);
                if ($outcome->type !== 'proposal') {
                    AiPendingAction::whereIn('id', $ids)->update(['status' => 'cancelled']);

                    return ToolOutcome::denied($outcome->message ?? 'حدد كل العناصر بوضوح قبل تجهيز العملية الجماعية.');
                }
                $id = $outcome->data['pending_action_id'];
                $ids[] = $id;
                $items[] = ['key' => $id, 'label' => $outcome->uiBlock['title'], 'change' => $item['tool'], 'before' => $outcome->uiBlock['before'] ?? null, 'after' => $outcome->uiBlock['after'] ?? null];
            }
            $outcome = $this->makeProposal($actor, $conv, 'bulk_actions', ['action_ids' => $ids], [], ['title' => 'مراجعة '.count($ids).' عمليات', 'items' => $items, 'warnings' => ['كل عنصر بيتنفذ لوحده. راجع التغييرات واستبعد أي عنصر مش مطلوب.']], null, 'high');
            $block = $outcome->uiBlock;
            $block['type'] = 'bulk_plan';
            $block['items'] = $items;
            $block['typed_confirmation'] = 'تنفيذ';

            return ToolOutcome::proposal($block, $outcome->data);
        });
    }

    private function canonical(mixed $v): mixed
    {
        if (is_array($v)) {
            $isList = array_is_list($v);
            $out = array_map(fn ($x) => $this->canonical($x), $v);
            if (! $isList) {
                ksort($out);
            }

            return $out;
        }

        return $v;
    }

    private function makeProposal(User $actor, AiConversation $conv, string $tool, array $args, array $expected, array $preview, ?array $inverse, string $risk): ToolOutcome
    {
        if (in_array($tool, ['create_staff_user', 'assign_roles', 'set_user_status'], true)) {
            $names = array_merge($args['roles'] ?? [], $tool === 'assign_roles' ? ($args['add'] ?? []) : []);
            if (isset($args['user_id'])) {
                $names = array_merge($names, User::findOrFail($args['user_id'])->roles()->pluck('name')->all());
            }
            foreach (Role::where('guard_name', 'staff')->whereIn('name', $names)->get() as $role) {
                $expected['role:'.$role->id] = (int) $role->lock_version;
            }
        }
        $ttl = max(1, (int) $this->settings->get('ai_action_ttl_minutes', 10));
        $action = AiPendingAction::create([
            'conversation_id' => $conv->id, 'user_id' => $actor->id, 'tool' => $tool, 'args' => $args,
            'expected_versions' => $expected, 'preview' => $preview, 'inverse' => $inverse,
            'args_hash' => $this->hashArgs($args), 'risk' => $risk, 'status' => 'pending', 'expires_at' => now()->addMinutes($ttl),
        ]);
        $typed = null;
        if ($tool === 'set_branch_status' && isset($args['branch_id'])) {
            $b = Branch::find($args['branch_id']);
            $typed = $b?->code;
        }
        if ($tool === 'delete_role' && isset($args['role_id'])) {
            $r = Role::find($args['role_id']);
            $typed = $r?->name;
        }
        $block = ['type' => 'confirm_action', 'action_id' => $action->id, 'tool' => $tool, 'risk' => $risk, 'title' => $preview['title'] ?? $tool, 'before' => $preview['before'] ?? null, 'after' => $preview['after'] ?? null, 'warnings' => $preview['warnings'] ?? [], 'expires_at' => $action->expires_at->toIso8601String()];
        if ($risk === 'high' && $typed) {
            $block['typed_confirmation'] = $typed;
        }
        if (isset($preview['permission_diff'])) {
            $block = array_merge($block, ['type' => 'permission_diff'] + $block + $preview['permission_diff']);
        }

        return ToolOutcome::proposal($block, ['pending_action_id' => $action->id]);
    }

    private function prepareWrite(User $actor, AiConversation $conv, string $tool, array $args): ToolOutcome
    {
        $this->assertAiConstraints($actor, new AiPendingAction(['tool' => $tool, 'args' => $args]));
        // Forbidden constants
        if ($tool === 'assign_roles' || $tool === 'create_staff_user') {
            $names = array_merge($args['add'] ?? [], $args['roles'] ?? []);
            if (in_array(StaffRole::SuperAdmin->value, $names, true)) {
                return ToolOutcome::denied('المساعد مش بيقدر يدّي دور Super Admin. استخدم شاشة الموظفين.');
            }
        }
        if (in_array($tool, ['set_user_status', 'assign_roles', 'revoke_user_sessions'], true) && (int) ($args['user_id'] ?? 0) === $actor->id) {
            return ToolOutcome::denied('مش تقدر تعدّل حسابك من المساعد. استخدم الشاشة العادية.');
        }
        if ($tool === 'assign_roles' && in_array(StaffRole::SuperAdmin->value, $args['remove'] ?? [], true)) {
            return ToolOutcome::denied('المساعد مش بيقدر يشيل دور Super Admin. استخدم شاشة الموظفين.');
        }

        return match ($tool) {
            'create_branch' => $this->prepCreateBranch($actor, $conv, $args),
            'set_branch_status' => $this->prepSetBranchStatus($actor, $conv, $args),
            'create_role' => $this->prepCreateRole($actor, $conv, $args),
            'update_role_permissions' => $this->prepUpdateRolePerms($actor, $conv, $args),
            'delete_role' => $this->prepDeleteRole($actor, $conv, $args),
            'create_staff_user' => $this->prepCreateStaffUser($actor, $conv, $args),
            'set_user_status' => $this->prepSetUserStatus($actor, $conv, $args),
            'assign_roles' => $this->prepAssignRoles($actor, $conv, $args),
            'revoke_user_sessions' => $this->prepRevokeSessions($actor, $conv, $args),
            default => ToolOutcome::denied('أداة كتابة غير معروفة.'),
        };
    }

    private function prepCreateBranch(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        try {
            $this->branches->assertCentralAccess($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('إنشاء الفروع محتاج وصول مركزي.');
        }
        if (empty($args['name']) || empty($args['code']) || empty($args['type'])) {
            return ToolOutcome::denied('بيانات الفرع ناقصة.');
        }
        if (! in_array($args['type'], array_column(BranchType::cases(), 'value'), true)) {
            return ToolOutcome::denied('نوع فرع غير صحيح.');
        }
        $warnings = [];
        if (Branch::where('code', mb_strtoupper(trim((string) $args['code'])))->exists()) {
            $warnings[] = 'الكود ده مستخدم قبل كده.';
        }
        $data = ['name' => (string) $args['name'], 'code' => mb_strtoupper(trim((string) $args['code'])), 'type' => (string) $args['type'], 'city' => $args['city'] ?? null, 'phone' => $args['phone'] ?? null];

        return $this->makeProposal($actor, $conv, 'create_branch', $data, [], ['title' => 'إنشاء فرع '.$data['name'], 'after' => $data, 'warnings' => $warnings], null, 'low');
    }

    private function prepSetBranchStatus(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        $branch = null;
        if (! empty($args['branch_id'])) {
            $branch = Branch::find((int) $args['branch_id']);
        } elseif (! empty($args['branch_query'])) {
            $opts = $this->resolver->branches($actor, (string) $args['branch_query']);
            if (count($opts) === 1) {
                $branch = Branch::find($opts[0]['id']);
            }
        }
        if (! $branch) {
            return $this->pickerFor($actor, 'branch', 'set_branch_status', (string) ($args['branch_query'] ?? ''));
        }
        $restricted = null;
        try {
            $restricted = $this->branches->restrictedBranch($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('النطاق غير مسموح.');
        }
        if ($restricted !== null) {
            return ToolOutcome::denied('تعطيل/تفعيل الفروع محتاج وصول مركزي.');
        }
        $active = (bool) ($args['active'] ?? false);
        $warnings = ['عدد الموظفين في الفرع: '.$branch->users()->count()];
        if (! $active) {
            $warnings[] = 'تعطيل فرع عملية خطيرة.';
        }
        $risk = $active ? 'low' : 'high';

        return $this->makeProposal($actor, $conv, 'set_branch_status', ['branch_id' => $branch->id, 'active' => $active], ['branch:'.$branch->id => $this->branchVersion($branch)], ['title' => ($active ? 'تفعيل ' : 'تعطيل ').$branch->name, 'before' => ['is_active' => (bool) $branch->is_active], 'after' => ['is_active' => $active], 'warnings' => $warnings], ['is_active' => (bool) $branch->is_active], $risk);
    }

    private function prepCreateRole(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        try {
            $this->branches->assertCentralAccess($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('إنشاء الأدوار محتاج وصول مركزي.');
        }
        $name = StaffRole::normalizeName((string) ($args['name'] ?? ''));
        if ($name === '' || StaffRole::isReserved($name)) {
            return ToolOutcome::denied('اسم الدور محجوز أو فاضي.');
        }
        $perms = array_values(array_unique($args['permissions'] ?? []));
        if (! StaffAdministration::mayGrantPermissions($actor, $perms)) {
            return ToolOutcome::denied('لا يمكنك إسناد صلاحيات لا تملكها.');
        }
        $sensitive = array_filter($perms, fn ($p) => str_ends_with($p, '.manage') || in_array($p, ['ai_agent.execute', 'ai_agent.settings', 'settings.manage'], true));

        return $this->makeProposal($actor, $conv, 'create_role', ['name' => $name, 'permissions' => $perms], [], ['title' => 'إنشاء دور '.$name, 'after' => ['name' => $name, 'permissions' => $perms], 'warnings' => $sensitive ? ['فيه '.count($sensitive).' صلاحية حساسة.'] : []], null, $sensitive ? 'high' : 'medium');
    }

    private function prepUpdateRolePerms(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        $role = Role::find((int) ($args['role_id'] ?? 0));
        if (! $role || $role->guard_name !== 'staff') {
            return ToolOutcome::denied('الدور مش موجود.');
        }
        if (StaffRole::isReserved($role->name)) {
            return ToolOutcome::denied('مش تقدر تعدّل دور محجوز من المساعد.');
        }
        $current = $role->permissions()->pluck('name')->all();
        $add = array_values(array_unique($args['add'] ?? []));
        $remove = array_values(array_unique($args['remove'] ?? []));
        $next = array_values(array_unique(array_merge(array_diff($current, $remove), $add)));
        if (! StaffAdministration::mayGrantPermissions($actor, $next)) {
            return ToolOutcome::denied('لا يمكنك إسناد صلاحيات لا تملكها.');
        }
        $warnings = ['الدور ده عليه '.$role->users()->count().' موظف.'];
        $risk = (array_intersect($add, ['ai_agent.execute', 'settings.manage']) || $remove) ? 'high' : 'medium';
        $preview = ['title' => 'تعديل صلاحيات '.$role->name, 'before' => ['permissions' => $current], 'after' => ['permissions' => $next], 'warnings' => $warnings, 'permission_diff' => ['role' => ['id' => $role->id, 'name' => $role->name], 'add' => $add, 'remove' => $remove, 'affected_users' => $role->users()->count()]];

        return $this->makeProposal($actor, $conv, 'update_role_permissions', ['role_id' => $role->id, 'add' => $add, 'remove' => $remove], ['role:'.$role->id => (int) $role->lock_version], $preview, ['permissions' => $current], $risk);
    }

    private function prepDeleteRole(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        $role = Role::find((int) ($args['role_id'] ?? 0));
        if (! $role) {
            return ToolOutcome::denied('الدور مش موجود.');
        }
        if (StaffRole::isReserved($role->name)) {
            return ToolOutcome::denied('مش تقدر تحذف دور محجوز.');
        }
        $warnings = $role->users()->count() ? ['الدور عليه '.$role->users()->count().' موظف — الحذف هيترفض لحد ما تنقلهم.'] : [];

        return $this->makeProposal($actor, $conv, 'delete_role', ['role_id' => $role->id], ['role:'.$role->id => (int) $role->lock_version], ['title' => 'حذف دور '.$role->name, 'before' => StaffAdministration::roleState($role), 'warnings' => $warnings], ['name' => $role->name, 'permissions' => $role->permissions()->pluck('name')->all()], 'high');
    }

    private function prepCreateStaffUser(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        if (empty($args['name']) || empty($args['email'])) {
            return ToolOutcome::denied('الاسم والإيميل مطلوبين.');
        }
        $roles = array_values(array_unique($args['roles'] ?? []));
        if (! StaffAdministration::mayManageUser($actor, roles: $roles)) {
            return ToolOutcome::denied('لا يمكنك إسناد أدوار بصلاحيات لا تملكها.');
        }
        $data = ['name' => (string) $args['name'], 'email' => mb_strtolower(trim((string) $args['email'])), 'phone' => $args['phone'] ?? null, 'branch_id' => isset($args['branch_id']) ? (int) $args['branch_id'] : null, 'roles' => $roles];

        return $this->makeProposal($actor, $conv, 'create_staff_user', $data, [], ['title' => 'إنشاء موظف '.$data['name'], 'after' => ['name' => $data['name'], 'email' => $data['email'], 'roles' => $roles], 'warnings' => ['الباسورد هيتولد في السيرفر ويظهر مرة واحدة بعد التأكيد.']], null, 'medium');
    }

    private function prepSetUserStatus(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        $user = User::where('is_system', false)->find((int) ($args['user_id'] ?? 0));
        if (! $user) {
            return ToolOutcome::denied('الموظف مش موجود.');
        }
        if ($user->hasRole(StaffRole::SuperAdmin->value, 'staff') && ! ($args['active'] ?? true) && ! StaffAdministration::hasOtherActiveSuperAdmin($user)) {
            return ToolOutcome::denied('مش تقدر تعطّل آخر Super Admin.');
        }
        $active = (bool) ($args['active'] ?? false);
        $warnings = $active ? [] : ['هيتعمله تسجيل خروج من كل الأجهزة.'];

        return $this->makeProposal($actor, $conv, 'set_user_status', ['user_id' => $user->id, 'active' => $active], ['user:'.$user->id => (int) $user->lock_version], ['title' => ($active ? 'تفعيل ' : 'تعطيل ').$user->name, 'before' => ['is_active' => (bool) $user->is_active], 'after' => ['is_active' => $active], 'warnings' => $warnings], ['is_active' => (bool) $user->is_active, 'roles' => $user->roles()->pluck('name')->all()], $active ? 'medium' : 'high');
    }

    private function prepAssignRoles(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        $user = User::where('is_system', false)->find((int) ($args['user_id'] ?? 0));
        if (! $user) {
            return ToolOutcome::denied('الموظف مش موجود.');
        }
        $current = $user->roles()->pluck('name')->all();
        $add = array_values(array_unique($args['add'] ?? []));
        $remove = array_values(array_unique($args['remove'] ?? []));
        $next = array_values(array_unique(array_merge(array_diff($current, $remove), $add)));
        if (in_array(StaffRole::SuperAdmin->value, $add, true)) {
            return ToolOutcome::denied('المساعد مش بيقدر يدّي دور Super Admin.');
        }
        if ($user->hasRole(StaffRole::SuperAdmin->value, 'staff') && array_diff($current, $next) && ! StaffAdministration::hasOtherActiveSuperAdmin($user)) {
            return ToolOutcome::denied('حماية آخر Super Admin.');
        }

        return $this->makeProposal($actor, $conv, 'assign_roles', ['user_id' => $user->id, 'add' => $add, 'remove' => $remove], ['user:'.$user->id => (int) $user->lock_version], ['title' => 'تعديل أدوار '.$user->name, 'before' => ['roles' => $current], 'after' => ['roles' => $next], 'warnings' => ['تغيير الأدوار هيخرج الموظف من كل الأجهزة.']], ['roles' => $current], 'high');
    }

    private function prepRevokeSessions(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        $user = User::where('is_system', false)->find((int) ($args['user_id'] ?? 0));
        if (! $user) {
            return ToolOutcome::denied('الموظف مش موجود.');
        }

        return $this->makeProposal($actor, $conv, 'revoke_user_sessions', ['user_id' => $user->id], ['user:'.$user->id => (int) $user->lock_version], ['title' => 'تسجيل خروج '.$user->name.' من كل الأجهزة', 'warnings' => []], null, 'medium');
    }

    public function executeWrite(User $actor, AiPendingAction $action, array $via): ToolOutcome
    {
        $args = $action->args;
        $tool = $action->tool;
        try {
            $this->assertAiConstraints($actor, $action);

            return match ($tool) {
                'create_branch' => $this->execCreateBranch($actor, $args, $via, $action),
                'set_branch_status' => $this->execSetBranchStatus($actor, $args, $via, $action),
                'create_role' => $this->execCreateRole($actor, $args, $via, $action),
                'update_role_permissions' => $this->execUpdateRolePerms($actor, $args, $via, $action),
                'delete_role' => $this->execDeleteRole($actor, $args, $via, $action),
                'create_staff_user' => $this->execCreateStaffUser($actor, $args, $via, $action),
                'set_user_status' => $this->execSetUserStatus($actor, $args, $via, $action),
                'assign_roles' => $this->execAssignRoles($actor, $args, $via, $action),
                'revoke_user_sessions' => $this->execRevoke($actor, $args, $via, $action),
                default => ToolOutcome::result(false, 'أداة غير معروفة.'),
            };
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() === 409) {
                throw $e;
            }

            return ToolOutcome::result(false, $e->getMessage() ?: 'فشل التنفيذ.');
        }
    }

    private function assertAiConstraints(User $actor, AiPendingAction $action): void
    {
        $args = $action->args;
        if (isset($args['user_id'])) {
            $user = User::where('is_system', false)->findOrFail($args['user_id']);
            abort_if($actor->is($user), 403, 'لا يمكن تعديل حسابك من المساعد.');
            $branch = $this->branches->restrictedBranch($actor);
            abort_if($branch !== null && (int) $user->branch_id !== $branch, 403);
            abort_unless(StaffAdministration::mayManageUser($actor, $user), 403);
            if ($action->tool === 'assign_roles') {
                abort_if($user->hasRole(StaffRole::SuperAdmin->value, 'staff'), 403, 'استخدم الشاشة لإدارة Super Admin.');
            }
        }
        if (isset($args['role_id'])) {
            $role = Role::where('guard_name', 'staff')->findOrFail($args['role_id']);
            abort_if(StaffRole::isReserved($role->name), 403);
        }
        $roles = array_merge($args['add'] ?? [], $args['remove'] ?? [], $args['roles'] ?? []);
        if (in_array($action->tool, ['assign_roles', 'create_staff_user'], true)) {
            abort_if(in_array(StaffRole::SuperAdmin->value, $roles, true), 403);
        }
    }

    private function checkVersion(string $key, mixed $expected, $model): void
    {
        if (str_starts_with($key, 'branch:')) {
            abort_if($this->branchVersion($model) !== $expected, 409, 'حد عدّل السجل ده بعد الاقتراح.');
        } else {
            abort_if((int) $model->lock_version !== $expected, 409, 'حد عدّل السجل ده بعد الاقتراح.');
        }
    }

    private function branchVersion(Branch $branch): string
    {
        return $this->hashArgs($branch->getRawOriginal());
    }

    public function lockTargets(AiPendingAction $action): void
    {
        foreach ($action->expected_versions ?? [] as $key => $version) {
            [$type, $id] = explode(':', $key, 2);
            $model = match ($type) {
                'branch' => Branch::class, 'user' => User::class, 'role' => Role::class, default => null
            };
            abort_unless($model, 409);
            $record = $model::query()->lockForUpdate()->find($id);
            abort_unless($record, 409, 'السجل اتحذف.');
            if ($action->status === 'pending') {
                $this->checkVersion($key, $version, $record);
            }
        }
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return $action->inverse && in_array($action->tool, ['set_branch_status', 'update_role_permissions', 'set_user_status', 'assign_roles'], true);
    }

    public function targetState(AiPendingAction $action): string
    {
        $args = $action->args;
        if (isset($args['branch_id']) && $action->tool === 'set_branch_status') {
            return $this->branchVersion(Branch::findOrFail($args['branch_id']));
        }
        if (isset($args['role_id'])) {
            $role = Role::findOrFail($args['role_id']);

            return $this->hashArgs(['record' => $role->getRawOriginal(), 'state' => StaffAdministration::roleState($role)]);
        }
        $user = User::findOrFail($args['user_id']);

        return $this->hashArgs(['record' => $user->getRawOriginal(), 'state' => StaffAdministration::userState($user), 'role_versions' => $user->roles()->orderBy('roles.id')->pluck('lock_version', 'roles.id')->all()]);
    }

    private function execCreateBranch(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $branch = app(CreateBranch::class)($actor, $args, $via);
        $action->forceFill(['inverse' => ['branch_id' => $branch->id]])->save();

        return ToolOutcome::result(true, 'تم إنشاء الفرع.', ['branch_id' => $branch->id], ['type' => 'result', 'ok' => true, 'message' => 'تم إنشاء الفرع '.$branch->name, 'deep_link' => '/branches?branchId='.$branch->id]);
    }

    private function execSetBranchStatus(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $branch = Branch::findOrFail($args['branch_id']);
        app(UpdateBranch::class)($actor, $branch, ['is_active' => (bool) $args['active']], $via);

        return ToolOutcome::result(true, $args['active'] ? 'تم تفعيل الفرع.' : 'تم تعطيل الفرع.', ['branch_id' => $branch->id], ['type' => 'result', 'ok' => true, 'message' => 'تم تحديث حالة الفرع.', 'deep_link' => '/branches?branchId='.$branch->id]);
    }

    private function execCreateRole(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $role = app(CreateRole::class)($actor, $args, $via);
        $action->forceFill(['inverse' => ['role_id' => $role->id]])->save();

        return ToolOutcome::result(true, 'تم إنشاء الدور.', ['role_id' => $role->id], ['type' => 'result', 'ok' => true, 'message' => 'تم إنشاء الدور '.$role->name, 'deep_link' => '/roles?roleId='.$role->id]);
    }

    private function execUpdateRolePerms(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $role = Role::findOrFail($args['role_id']);
        $current = $role->permissions()->pluck('name')->all();
        $next = array_values(array_unique(array_merge(array_diff($current, $args['remove'] ?? []), $args['add'] ?? [])));
        $failure = app(UpdateRole::class)($actor, $role, ['permissions' => $next, 'expected_version' => (int) ($action->expected_versions['role:'.$role->id] ?? $role->lock_version)], $via);
        if ($failure) {
            return ToolOutcome::result(false, 'الدور محمي من التعديل.');
        }

        return ToolOutcome::result(true, 'تم تعديل صلاحيات الدور.', ['role_id' => $role->id], ['type' => 'result', 'ok' => true, 'message' => 'تم تعديل صلاحيات '.$role->name, 'deep_link' => '/roles?roleId='.$role->id]);
    }

    private function execDeleteRole(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $role = Role::findOrFail($args['role_id']);
        $result = app(DeleteRole::class)($actor, $role, $via);
        if ($result !== 'deleted') {
            return ToolOutcome::result(false, $result === 'protected' ? 'الدور محجوز ومش بيتحذف.' : 'الدور عليه موظفين، انقلهم الأول.');
        }

        return ToolOutcome::result(true, 'تم حذف الدور.', [], ['type' => 'result', 'ok' => true, 'message' => 'تم حذف الدور.']);
    }

    private function execCreateStaffUser(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $res = app(CreateStaffUser::class)($actor, $args, $via, generatePassword: true);
        $action->forceFill(['inverse' => ['user_id' => $res['user']->id]])->save();

        // plain password returned only in result block for the confirming admin, never stored in ai_messages by orchestrator (result is in pending action)
        return ToolOutcome::result(true, 'تم إنشاء الموظف.', ['user_id' => $res['user']->id, 'generated_password' => $res['plain_password']], ['type' => 'result', 'ok' => true, 'message' => 'تم إنشاء الموظف. الباسورد المؤقت (يظهر مرة واحدة): '.$res['plain_password'], 'deep_link' => '/users?userId='.$res['user']->id]);
    }

    private function execSetUserStatus(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $user = User::findOrFail($args['user_id']);
        $failure = app(UpdateStaffUser::class)($actor, $user, ['is_active' => (bool) $args['active'], 'expected_version' => (int) ($action->expected_versions['user:'.$user->id] ?? $user->lock_version)], $via);
        if ($failure) {
            return ToolOutcome::result(false, 'مش مسموح بالتعديل ده.');
        }

        return ToolOutcome::result(true, 'تم تحديث حالة الموظف.', ['user_id' => $user->id], ['type' => 'result', 'ok' => true, 'message' => 'تم تحديث حالة الموظف.', 'deep_link' => '/users?userId='.$user->id]);
    }

    private function execAssignRoles(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $user = User::findOrFail($args['user_id']);
        $current = $user->roles()->pluck('name')->all();
        $next = array_values(array_unique(array_merge(array_diff($current, $args['remove'] ?? []), $args['add'] ?? [])));
        $failure = app(UpdateStaffUser::class)($actor, $user, ['roles' => $next, 'expected_version' => (int) ($action->expected_versions['user:'.$user->id] ?? $user->lock_version)], $via);
        if ($failure) {
            return ToolOutcome::result(false, 'مش مسموح بتعديل الأدوار ده.');
        }

        return ToolOutcome::result(true, 'تم تعديل أدوار الموظف.', ['user_id' => $user->id], ['type' => 'result', 'ok' => true, 'message' => 'تم تعديل الأدوار وهيخرج من كل الأجهزة.', 'deep_link' => '/users?userId='.$user->id]);
    }

    private function execRevoke(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $user = User::findOrFail($args['user_id']);
        StaffAdministration::authorize($actor, ['users.update', 'users.manage']);
        $user->tokens()->delete();
        StaffAdministration::audit($actor, 'user.sessions_revoked', 'user', $user->id, [], [], [], $via);

        return ToolOutcome::result(true, 'تم تسجيل الخروج من كل الأجهزة.', ['user_id' => $user->id], ['type' => 'result', 'ok' => true, 'message' => 'تم تسجيل الخروج من كل الأجهزة.']);
    }

    public function executeUndo(User $actor, AiPendingAction $action, array $via): ToolOutcome
    {
        $inv = $action->inverse ?? [];
        $tool = $action->tool;
        $args = $action->args;
        try {
            $this->assertAiConstraints($actor, $action);
            if ($tool === 'set_branch_status' && isset($inv['is_active'])) {
                $b = Branch::findOrFail($args['branch_id']);
                app(UpdateBranch::class)($actor, $b, ['is_active' => (bool) $inv['is_active']], $via);

                return ToolOutcome::result(true, 'تم التراجع.');
            }
            if ($tool === 'update_role_permissions' && isset($inv['permissions'])) {
                $r = Role::findOrFail($args['role_id']);
                $f = app(UpdateRole::class)($actor, $r, ['permissions' => $inv['permissions']], $via);

                return $f ? ToolOutcome::result(false, 'الدور محمي.') : ToolOutcome::result(true, 'تم التراجع.');
            }
            if ($tool === 'set_user_status' && isset($inv['is_active'])) {
                $u = User::findOrFail($args['user_id']);
                $f = app(UpdateStaffUser::class)($actor, $u, ['is_active' => (bool) $inv['is_active']], $via);

                return $f ? ToolOutcome::result(false, 'مش مسموح.') : ToolOutcome::result(true, 'تم التراجع.');
            }
            if ($tool === 'assign_roles' && isset($inv['roles'])) {
                $u = User::findOrFail($args['user_id']);
                $f = app(UpdateStaffUser::class)($actor, $u, ['roles' => $inv['roles']], $via);

                return $f ? ToolOutcome::result(false, 'مش مسموح.') : ToolOutcome::result(true, 'تم التراجع.');
            }

            return ToolOutcome::result(false, 'العملية دي مفيهاش تراجع تلقائي.');
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() === 409) {
                throw $e;
            }

            return ToolOutcome::result(false, $e->getMessage() ?: 'فشل التراجع.');
        }
    }
}
