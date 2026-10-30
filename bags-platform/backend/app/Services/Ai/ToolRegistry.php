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
use App\Enums\LocationType;
use App\Http\Requests\Api\V1\Admin\UpdateSettingsRequest;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\Location;
use App\Models\Setting;
use App\Models\StockLevel;
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
        $locationTypes = array_column(LocationType::cases(), 'value');

        return [
            'bulk_actions' => ['kind' => ToolKind::Write, 'permissions' => ['branches.update', 'branches.manage', 'users.update', 'users.manage', 'roles.update', 'roles.manage', 'locations.update', 'locations.manage'], 'risk' => 'high', 'declaration' => ['name' => 'bulk_actions', 'description' => 'اقتراح مجموعة تعديلات مستقلة بكارت واحد. راجع كل عنصر قبل التأكيد؛ لا تنفذ أي تعديل فوراً.', 'parameters' => $obj(['items' => ['type' => 'ARRAY', 'minItems' => 2, 'items' => $obj(['tool' => ['type' => 'STRING', 'enum' => ['set_branch_status', 'update_role_permissions', 'set_user_status', 'assign_roles', 'revoke_user_sessions', 'set_location_status', 'create_location']], 'args' => $obj(['branch_id' => $int, 'user_id' => $int, 'role_id' => $int, 'location_id' => $int, 'active' => $bool, 'add' => $strArr, 'remove' => $strArr, 'name' => $str, 'code' => $str, 'type' => $str])], ['tool', 'args'])]], ['items'])]],
            'find_entities' => ['kind' => ToolKind::Read, 'permissions' => ['branches.view', 'branches.manage', 'users.view', 'users.manage', 'roles.view', 'roles.manage', 'permissions.view'], 'risk' => 'low', 'declaration' => ['name' => 'find_entities', 'description' => 'بحث عن فروع أو موظفين أو أدوار أو صلاحيات أو مواقع تخزين', 'parameters' => $obj(['type' => ['type' => 'STRING', 'enum' => ['branch', 'user', 'role', 'permission', 'location']], 'query' => $str, 'branch_id' => $int], ['type', 'query'])]],
            'list_branches' => ['kind' => ToolKind::Read, 'permissions' => ['branches.view', 'branches.manage'], 'risk' => 'low', 'declaration' => ['name' => 'list_branches', 'description' => 'قائمة الفروع (active=false للمتوقفة)', 'parameters' => $obj(['active' => $bool, 'city' => $str, 'type' => ['type' => 'STRING', 'enum' => $branchTypes]])]],
            'list_permissions' => ['kind' => ToolKind::Read, 'permissions' => ['permissions.view', 'roles.view', 'roles.manage'], 'risk' => 'low', 'declaration' => ['name' => 'list_permissions', 'description' => 'كتالوج الصلاحيات المتاحة', 'parameters' => $obj([])]],
            'list_roles' => ['kind' => ToolKind::Read, 'permissions' => ['roles.view', 'roles.manage'], 'risk' => 'low', 'declaration' => ['name' => 'list_roles', 'description' => 'قائمة الأدوار', 'parameters' => $obj([])]],
            'list_staff' => ['kind' => ToolKind::Read, 'permissions' => ['users.view', 'users.manage'], 'risk' => 'low', 'declaration' => ['name' => 'list_staff', 'description' => 'قائمة الموظفين', 'parameters' => $obj(['query' => $str])]],
            'explain_user_access' => ['kind' => ToolKind::Read, 'permissions' => ['users.view', 'users.manage'], 'risk' => 'low', 'declaration' => ['name' => 'explain_user_access', 'description' => 'شرح أدوار وصلاحيات ونطاق موظف', 'parameters' => $obj(['user_id' => $int], ['user_id'])]],
            'who_can' => ['kind' => ToolKind::Read, 'permissions' => ['roles.view', 'roles.manage', 'permissions.view'], 'risk' => 'low', 'declaration' => ['name' => 'who_can', 'description' => 'مين معاه صلاحية معينة', 'parameters' => $obj(['permission' => $str], ['permission'])]],
            'compare_roles' => ['kind' => ToolKind::Read, 'permissions' => ['roles.view', 'roles.manage'], 'risk' => 'low', 'declaration' => ['name' => 'compare_roles', 'description' => 'مقارنة صلاحيات دورين', 'parameters' => $obj(['role_a' => $int, 'role_b' => $int], ['role_a', 'role_b'])]],
            'recent_audit' => ['kind' => ToolKind::Read, 'permissions' => ['users.view', 'users.manage'], 'risk' => 'low', 'declaration' => ['name' => 'recent_audit', 'description' => 'آخر أحداث سجل التدقيق الإداري', 'parameters' => $obj(['days' => $int])]],
            'ask_user_to_choose' => ['kind' => ToolKind::Ui, 'permissions' => ['branches.view', 'branches.manage', 'users.view', 'users.manage', 'roles.view', 'roles.manage'], 'risk' => 'low', 'declaration' => ['name' => 'ask_user_to_choose', 'description' => 'اطلب من المستخدم اختيار كيان من قائمة حقيقية', 'parameters' => $obj(['entity_type' => ['type' => 'STRING', 'enum' => ['branch', 'user', 'role', 'location']], 'purpose' => $str, 'query' => $str], ['entity_type', 'purpose'])]],
            'create_branch' => ['kind' => ToolKind::Write, 'permissions' => ['branches.create', 'branches.manage'], 'risk' => 'low', 'declaration' => ['name' => 'create_branch', 'description' => 'إنشاء فرع جديد (يحتاج تأكيد)', 'parameters' => $obj(['name' => $str, 'code' => $str, 'type' => ['type' => 'STRING', 'enum' => $branchTypes], 'city' => $str, 'phone' => $str], ['name', 'code', 'type'])]],
            'set_branch_status' => ['kind' => ToolKind::Write, 'permissions' => ['branches.update', 'branches.manage'], 'risk' => 'high', 'declaration' => ['name' => 'set_branch_status', 'description' => 'تفعيل أو تعطيل فرع (يحتاج تأكيد)', 'parameters' => $obj(['branch_id' => $int, 'branch_query' => $str, 'active' => $bool], ['active'])]],
            'create_role' => ['kind' => ToolKind::Write, 'permissions' => ['roles.create', 'roles.manage'], 'risk' => 'medium', 'declaration' => ['name' => 'create_role', 'description' => 'إنشاء دور جديد (يحتاج تأكيد)', 'parameters' => $obj(['name' => $str, 'permissions' => $strArr], ['name'])]],
            'update_role_permissions' => ['kind' => ToolKind::Write, 'permissions' => ['roles.update', 'roles.manage'], 'risk' => 'medium', 'declaration' => ['name' => 'update_role_permissions', 'description' => 'تعديل صلاحيات دور (يحتاج تأكيد)', 'parameters' => $obj(['role_id' => $int, 'add' => $strArr, 'remove' => $strArr], ['role_id'])]],
            'delete_role' => ['kind' => ToolKind::Write, 'permissions' => ['roles.delete', 'roles.manage'], 'risk' => 'high', 'declaration' => ['name' => 'delete_role', 'description' => 'حذف دور (يحتاج تأكيد)', 'parameters' => $obj(['role_id' => $int], ['role_id'])]],
            'create_staff_user' => ['kind' => ToolKind::Write, 'permissions' => ['users.create', 'users.manage'], 'risk' => 'medium', 'declaration' => ['name' => 'create_staff_user', 'description' => 'إنشاء موظف جديد، الباسورد يتولد في السيرفر (يحتاج تأكيد)', 'parameters' => $obj(['name' => $str, 'email' => $str, 'phone' => $str, 'branch_id' => $int, 'roles' => $strArr], ['name', 'email'])]],
            'set_user_status' => ['kind' => ToolKind::Write, 'permissions' => ['users.update', 'users.manage'], 'risk' => 'high', 'declaration' => ['name' => 'set_user_status', 'description' => 'تفعيل أو تعطيل موظف (يحتاج تأكيد)', 'parameters' => $obj(['user_id' => $int, 'active' => $bool], ['user_id', 'active'])]],
            'assign_roles' => ['kind' => ToolKind::Write, 'permissions' => ['users.update', 'users.manage'], 'risk' => 'high', 'declaration' => ['name' => 'assign_roles', 'description' => 'إضافة أو إزالة أدوار موظف (يحتاج تأكيد، بيخرجه من كل الأجهزة)', 'parameters' => $obj(['user_id' => $int, 'add' => $strArr, 'remove' => $strArr], ['user_id'])]],
            'revoke_user_sessions' => ['kind' => ToolKind::Write, 'permissions' => ['users.update', 'users.manage'], 'risk' => 'medium', 'declaration' => ['name' => 'revoke_user_sessions', 'description' => 'تسجيل خروج موظف من كل الأجهزة (يحتاج تأكيد)', 'parameters' => $obj(['user_id' => $int], ['user_id'])]],

            // ── P03 Locations ──────────────────────────────────────────────────────
            // H3: صلاحيات الأدوات موحّدة مع الـ REST (locations.*) — مين ملوش
            // locations.* على الشاشة مش هيحذف/يعطّل مواقع من المساعد.
            'list_locations' => ['kind' => ToolKind::Read, 'permissions' => ['locations.view', 'locations.manage'], 'risk' => 'low', 'declaration' => ['name' => 'list_locations', 'description' => 'عرض مواقع التخزين في فرع معين', 'parameters' => $obj(['branch_id' => $int, 'branch_query' => $str, 'type' => ['type' => 'STRING', 'enum' => $locationTypes]])]],
            'create_location' => ['kind' => ToolKind::Write, 'permissions' => ['locations.create', 'locations.manage'], 'risk' => 'low', 'declaration' => ['name' => 'create_location', 'description' => 'إنشاء موقع تخزين جديد في فرع (يحتاج تأكيد)', 'parameters' => $obj(['branch_id' => $int, 'branch_query' => $str, 'name' => $str, 'code' => $str, 'type' => ['type' => 'STRING', 'enum' => $locationTypes]], ['name', 'code', 'type'])]],
            'set_location_status' => ['kind' => ToolKind::Write, 'permissions' => ['locations.update', 'locations.manage'], 'risk' => 'medium', 'declaration' => ['name' => 'set_location_status', 'description' => 'تفعيل أو تعطيل موقع تخزين (يحتاج تأكيد)', 'parameters' => $obj(['location_id' => $int, 'active' => $bool], ['location_id', 'active'])]],
            'delete_location' => ['kind' => ToolKind::Write, 'permissions' => ['locations.delete', 'locations.manage'], 'risk' => 'high', 'declaration' => ['name' => 'delete_location', 'description' => 'حذف موقع تخزين (يحتاج تأكيد، ممنوع لو فيه رصيد أو حركات)', 'parameters' => $obj(['location_id' => $int], ['location_id'])]],

            // ── P03 Settings & Numbering ───────────────────────────────────────────
            'view_settings' => ['kind' => ToolKind::Read, 'permissions' => ['settings.view', 'settings.manage'], 'risk' => 'low', 'declaration' => ['name' => 'view_settings', 'description' => 'عرض إعدادات المؤسسة (هوية الشركة، الضرائب، السياسات، الترقيم)', 'parameters' => $obj(['group' => ['type' => 'STRING', 'enum' => ['company', 'tax', 'policy', 'numbering', 'store', 'purchases', 'ai', 'all']]])]],
            'view_document_numbering' => ['kind' => ToolKind::Read, 'permissions' => ['settings.view', 'settings.manage'], 'risk' => 'low', 'declaration' => ['name' => 'view_document_numbering', 'description' => 'عرض بادئات المستندات وعدّاداتها الحالية وعينة الرقم', 'parameters' => $obj(['type' => ['type' => 'STRING', 'enum' => ['order', 'invoice', 'purchase_order', 'quotation', 'transfer', 'stocktake', 'return', 'purchase_request', 'delivery', 'supplier_invoice', 'supplier_payment', 'shift', 'expense', 'treasury_transfer', 'payment_notice', 'movement', 'all']]])]],
            'update_settings' => ['kind' => ToolKind::Write, 'permissions' => ['settings.manage'], 'risk' => 'high', 'declaration' => ['name' => 'update_settings', 'description' => 'تعديل إعدادات المؤسسة (يحتاج تأكيد). اقرأ الإعدادات الحالية أولاً بـ view_settings.', 'parameters' => $obj(['changes' => $obj(['company_name' => $str, 'company_email' => $str, 'company_phone' => $str, 'company_address' => $str, 'company_logo' => $str, 'commercial_register' => $str, 'tax_enabled' => $bool, 'tax_number' => $str, 'default_tax_rate' => ['type' => 'NUMBER'], 'prices_include_tax' => $bool, 'return_window_days' => $int, 'allow_self_approve_purchases' => $bool])], ['changes'])]],
            'update_document_numbering' => ['kind' => ToolKind::Write, 'permissions' => ['settings.manage'], 'risk' => 'high', 'declaration' => ['name' => 'update_document_numbering', 'description' => 'تعديل بادئة أو عداد أو طول ترقيم مستند (يحتاج تأكيد). العداد لا ينخفض.', 'parameters' => $obj(['type' => ['type' => 'STRING', 'enum' => ['order', 'invoice', 'purchase_order', 'quotation', 'transfer', 'stocktake', 'return', 'purchase_request', 'delivery', 'supplier_invoice', 'supplier_payment', 'shift', 'expense', 'treasury_transfer', 'payment_notice', 'movement']], 'prefix' => $str, 'next_number' => $int, 'padding' => $int], ['type'])]],
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
        // L11: سجل denied لرفض الصلاحيات فقط — أخطاء التحقق و"مش موجود"
        // من prompt injection كانت تملأ سجل التدقيق (6 خطوات × أدوات).
        if ($outcome->type === 'denied' && $outcome->auditable) {
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
            return ToolOutcome::denied('أداة غير معروفة.', auditable: false);
        }
        $disabled = $this->settings->get('ai_disabled_tools', []);
        if (is_array($disabled) && in_array($name, $disabled, true)) {
            return ToolOutcome::denied('الأداة دي مقفولة من الإعدادات.', auditable: false);
        }
        if ($defs[$name]['kind'] === ToolKind::Write && ! $actor->can('ai_agent.execute')) {
            return ToolOutcome::denied('محتاج صلاحية ai_agent.execute لتنفيذ تعديلات.');
        }
        if (! $actor->canAny($defs[$name]['permissions'])) {
            return ToolOutcome::denied('معندكش الصلاحية الأصلية للأداة دي.');
        }
        try {
            try {
                $this->validateArgs($name, $args);
            } catch (ValidationException $e) {
                return ToolOutcome::denied(collect($e->errors())->flatten()->implode(' '), auditable: false);
            }

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
                'list_locations' => $this->listLocations($actor, $args),
                'view_settings' => $this->viewSettings($actor, $args),
                'view_document_numbering' => $this->viewDocumentNumbering($actor, $args),
                default => $this->prepareWrite($actor, $conv, $name, $args),
            };
        } catch (ValidationException $e) {
            return ToolOutcome::denied(collect($e->errors())->flatten()->implode(' '), auditable: false);
        } catch (HttpExceptionInterface $e) {
            $auditable = in_array($e->getStatusCode(), [401, 403], true);

            return ToolOutcome::denied($e->getMessage() ?: 'مرفوض.', auditable: $auditable);
        } catch (\Throwable $e) {
            report($e);

            return ToolOutcome::denied('حصل خطأ أثناء تنفيذ الأداة.', auditable: false);
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
            Validator::make($args, ['items' => ['required', 'array', 'min:2', 'max:'.max(1, (int) $this->settings->get('ai_bulk_max_items', 25))], 'items.*.tool' => ['required', Rule::in(['set_branch_status', 'update_role_permissions', 'set_user_status', 'assign_roles', 'revoke_user_sessions', 'set_location_status', 'create_location'])], 'items.*.args' => ['required', 'array']])->validate();

            return;
        }
        $schema = $this->definitions()[$name]['declaration']['parameters'];
        $rules = [];
        foreach ($schema['properties'] as $key => $property) {
            $rules[$key] = [in_array($key, $schema['required'] ?? [], true) ? 'required' : 'sometimes', match ($property['type']) {
                'INTEGER' => 'integer', 'BOOLEAN' => 'boolean', 'ARRAY' => 'array', 'OBJECT' => 'array', 'NUMBER' => 'numeric', default => 'string'
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
            'location' => ToolOutcome::data(['results' => $this->resolver->locations($actor, $query, isset($args['branch_id']) ? (int) $args['branch_id'] : null)]),
            'permission' => ToolOutcome::data(['results' => Permission::query()->where('guard_name', 'staff')->when($query !== '', fn ($q) => $q->where('name', 'ilike', \App\Services\SearchTerm::pattern($query)))->orderBy('name')->limit(50)->get()->map(fn ($p) => ['id' => $p->name, 'label' => $p->name, 'meta' => ''])->values()->all()]),
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
            'location' => ['locations.view', 'locations.manage'],
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
            'branch' => $this->resolver->branches($actor, $query), 'user' => $this->resolver->users($actor, $query), 'role' => $this->resolver->roles($query), 'location' => $this->resolver->locations($actor, $query), default => []
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
            // M10: الأجراءات الفرعية بتبقى bulk_child لحد ما المجموعة تتأكد —
            // كده اصلی الـ confirm() المباشر لواحد منهم مرفوض من غير مراجعة.
            AiPendingAction::whereIn('id', $ids)->where('status', 'pending')->update(['status' => 'bulk_child']);
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
            'create_location' => $this->prepCreateLocation($actor, $conv, $args),
            'set_location_status' => $this->prepSetLocationStatus($actor, $conv, $args),
            'delete_location' => $this->prepDeleteLocation($actor, $conv, $args),
            'update_settings' => $this->prepUpdateSettings($actor, $conv, $args),
            'update_document_numbering' => $this->prepUpdateDocNumbering($actor, $conv, $args),
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
        try {
            $this->branches->assertCentralAccess($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('تعديل صلاحيات الأدوار محتاج وصول مركزي.');
        }
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
        try {
            $this->branches->assertCentralAccess($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('حذف الأدوار محتاج وصول مركزي.');
        }
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
                'create_location' => $this->execCreateLocation($actor, $args, $via, $action),
                'set_location_status' => $this->execSetLocationStatus($actor, $args, $via, $action),
                'delete_location' => $this->execDeleteLocation($actor, $args, $via, $action),
                'update_settings' => $this->execUpdateSettings($actor, $args, $via, $action),
                'update_document_numbering' => $this->execUpdateDocNumbering($actor, $args, $via, $action),
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
            // H2: الأدوار عامة على كل الفروع — إدارتها من المساعد محتاجة
            // وصول مركزي، زي ما محصل في شاشة الأدوار.
            $this->branches->assertCentralAccess($actor);
            $role = Role::where('guard_name', 'staff')->findOrFail($args['role_id']);
            abort_if(StaffRole::isReserved($role->name), 403);
        }
        // H2: إنشاء الأدوار بلا role_id — الفحص أعلاه لا يغطيه، والتحقق
        // في prepare وحده لا يكفي وقت execute.
        if (in_array($action->tool, ['create_role', 'update_role_permissions', 'delete_role'], true)) {
            $this->branches->assertCentralAccess($actor);
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
        } elseif (str_starts_with($key, 'location:')) {
            // H3: الموقع نسختة lock هي بصمة الحالة، بتقارن كذا في قفل الصف.
            abort_if($this->hashArgs(['is_active' => (bool) $model->is_active]) !== $expected, 409, 'حد عدّل السجل ده بعد الاقتراح.');
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
                'branch' => Branch::class, 'user' => User::class, 'role' => Role::class, 'location' => Location::class, default => null
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
        return $action->inverse && in_array($action->tool, ['set_branch_status', 'update_role_permissions', 'set_user_status', 'assign_roles', 'set_location_status'], true);
    }

    public function targetState(AiPendingAction $action): string
    {
        $args = $action->args;
        if (isset($args['branch_id']) && $action->tool === 'set_branch_status') {
            return $this->branchVersion(Branch::findOrFail($args['branch_id']));
        }
        if (isset($args['location_id']) && $action->tool === 'set_location_status') {
            $loc = Location::findOrFail($args['location_id']);

            return $this->hashArgs(['is_active' => (bool) $loc->is_active]);
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

    // ── P03: Locations ─────────────────────────────────────────────────────

    private function resolveBranch(User $actor, array $args): ?Branch
    {
        if (! empty($args['branch_id'])) {
            $branch = Branch::find((int) $args['branch_id']);
            if ($branch) {
                $restricted = $this->branches->restrictedBranch($actor);
                if ($restricted !== null && (int) $branch->id !== $restricted) {
                    return null;
                }
            }

            return $branch;
        }
        if (! empty($args['branch_query'])) {
            $opts = $this->resolver->branches($actor, (string) $args['branch_query']);
            if (count($opts) === 1) {
                return Branch::find($opts[0]['id']);
            }
        }

        return null;
    }

    private function listLocations(User $actor, array $args): ToolOutcome
    {
        $branch = $this->resolveBranch($actor, $args);
        if (! $branch) {
            return $this->pickerFor($actor, 'branch', 'اختر الفرع لعرض مواقعه', (string) ($args['branch_query'] ?? ''));
        }
        $locations = $branch->locations()
            ->when(isset($args['type']), fn ($q) => $q->where('type', $args['type']))
            ->orderBy('id')
            // M7: بلا حد، فرع فيه آلاف المواقع يلاحظ الطلب ونتيجة ضخمة.
            ->limit(200)
            ->get(['id', 'name', 'code', 'type', 'is_active']);

        if ($locations->isEmpty()) {
            return ToolOutcome::data(['locations' => [], 'branch' => $branch->name, 'message' => 'مفيش مواقع تخزين في الفرع ده.']);
        }

        return ToolOutcome::data(
            ['locations' => $locations->toArray(), 'branch' => $branch->name],
            ['type' => 'table', 'columns' => ['ID', 'الاسم', 'الكود', 'النوع', 'الحالة'],
                'rows' => $locations->map(fn ($l) => [$l->id, $l->name, $l->code, $l->type->value, $l->is_active ? 'نشط' : 'معطّل'])->all()]
        );
    }

    private function prepCreateLocation(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        $branch = $this->resolveBranch($actor, $args);
        if (! $branch) {
            return $this->pickerFor($actor, 'branch', 'اختر الفرع لإنشاء الموقع فيه', (string) ($args['branch_query'] ?? ''));
        }
        try {
            $this->branches->assertBranch($actor->id, $branch->id);
        } catch (\Throwable) {
            return ToolOutcome::denied('الفرع خارج نطاقك.');
        }
        $data = [
            'branch_id' => $branch->id,
            'name' => (string) $args['name'],
            'code' => mb_strtoupper(trim((string) $args['code'])),
            'type' => (string) $args['type'],
        ];
        $warnings = [];
        if ($branch->locations()->where('code', $data['code'])->exists()) {
            $warnings[] = 'الكود ده مستخدم بالفعل في الفرع ده.';
        }

        return $this->makeProposal($actor, $conv, 'create_location', $data, [],
            ['title' => 'إنشاء موقع '.$data['name'].' في '.$branch->name,
                'after' => array_merge($data, ['branch_name' => $branch->name]),
                'warnings' => $warnings], null, 'low');
    }

    private function execCreateLocation(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $branch = Branch::findOrFail($args['branch_id']);
        $this->branches->assertBranch($actor->id, $branch->id);
        StaffAdministration::authorize($actor, ['locations.create', 'locations.manage']);

        $location = $branch->locations()->create([
            'name' => $args['name'],
            'code' => $args['code'],
            'type' => $args['type'],
            'is_active' => true,
        ]);

        StaffAdministration::audit($actor, 'location.created', 'location', $location->id,
            [], [], ['name' => $args['name'], 'code' => $args['code'], 'branch' => $branch->name], $via);

        $action->forceFill(['inverse' => ['location_id' => $location->id]])->save();

        return ToolOutcome::result(true, 'تم إنشاء الموقع.', ['location_id' => $location->id],
            ['type' => 'result', 'ok' => true, 'message' => 'تم إنشاء موقع '.$args['name'].' ('.$args['code'].') في '.$branch->name,
                'deep_link' => '/branches?branchId='.$branch->id]);
    }

    private function prepSetLocationStatus(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        $location = Location::find((int) ($args['location_id'] ?? 0));
        if (! $location) {
            return ToolOutcome::denied('الموقع مش موجود.');
        }
        try {
            $this->branches->assertBranch($actor->id, $location->branch_id);
        } catch (\Throwable) {
            return ToolOutcome::denied('الفرع خارج نطاقك.');
        }
        $branch = Branch::find($location->branch_id);
        $active = (bool) ($args['active'] ?? false);
        $warnings = [];
        if (! $active) {
            $hasStock = StockLevel::query()->where('location_id', $location->id)->hasNonZeroBalance()->exists();
            if ($hasStock) {
                $warnings[] = 'الموقع فيه أرصدة مخزنية.';
            }
        }

        return $this->makeProposal($actor, $conv, 'set_location_status',
            ['location_id' => $location->id, 'active' => $active],
            // H3: إصدار حالة الموقع بيتقفل وقت التنفيذ (lockTargets).
            ['location:'.$location->id => $this->hashArgs(['is_active' => (bool) $location->is_active])],
            ['title' => ($active ? 'تفعيل ' : 'تعطيل ').$location->name.' في '.($branch?->name ?? ''),
                'before' => ['is_active' => (bool) $location->is_active],
                'after' => ['is_active' => $active],
                'warnings' => $warnings],
            ['is_active' => (bool) $location->is_active], $active ? 'low' : 'medium');
    }

    private function execSetLocationStatus(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $branchId = null;
        $before = [];
        try {
            // H3: قفل صف الموقع وقت التنفيذ زي LocationController::destroy،
            // وتدقيق بقيمة before الفعلية مو بالعكس.
            $location = DB::transaction(function () use ($actor, $args, $via, &$branchId, &$before): Location {
                $locked = Location::query()->lockForUpdate()->findOrFail($args['location_id']);
                $this->branches->assertBranch($actor->id, $locked->branch_id);
                StaffAdministration::authorize($actor, ['locations.update', 'locations.manage']);
                $before = ['is_active' => (bool) $locked->is_active];
                $locked->update(['is_active' => (bool) $args['active']]);
                $branchId = $locked->branch_id;

                return $locked;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return ToolOutcome::result(false, 'تعذّر تحديث الموقع؛ حاول مرة أخرى.');
        }
        StaffAdministration::audit($actor, $args['active'] ? 'location.activated' : 'location.deactivated', 'location', $location->id,
            [], $before, ['is_active' => (bool) $args['active']], $via);

        return ToolOutcome::result(true, $args['active'] ? 'تم تفعيل الموقع.' : 'تم تعطيل الموقع.',
            ['location_id' => $location->id],
            ['type' => 'result', 'ok' => true, 'message' => 'تم تحديث حالة الموقع.',
                'deep_link' => '/branches?branchId='.$branchId]);
    }

    private function prepDeleteLocation(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        $location = Location::find((int) ($args['location_id'] ?? 0));
        if (! $location) {
            return ToolOutcome::denied('الموقع مش موجود.');
        }
        try {
            $this->branches->assertBranch($actor->id, $location->branch_id);
        } catch (\Throwable) {
            return ToolOutcome::denied('الفرع خارج نطاقك.');
        }
        $branch = Branch::find($location->branch_id);
        $warnings = [];
        $hasMovements = DB::table('stock_movements')->where('location_id', $location->id)->exists();
        if ($hasMovements) {
            $warnings[] = 'الموقع مرتبط بحركات مخزنية — الحذف هيترفض.';
        }
        $hasBalances = StockLevel::query()->where('location_id', $location->id)
            ->hasNonZeroBalance()
            ->exists();
        if ($hasBalances) {
            $warnings[] = 'الموقع فيه أرصدة — صفّرها أولاً.';
        }

        return $this->makeProposal($actor, $conv, 'delete_location',
            ['location_id' => $location->id], [],
            ['title' => 'حذف موقع '.$location->name.' ('.$location->code.') من '.($branch?->name ?? ''),
                'before' => ['name' => $location->name, 'code' => $location->code, 'type' => $location->type->value],
                'warnings' => $warnings], null, 'high');
    }

    private function execDeleteLocation(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        try {
            $summary = DB::transaction(function () use ($actor, $args, $via): array {
                // H3: قفل صف الموقع زي LocationController::destroy بالظبط —
                // الحذف ضد حركة متزامنة بيرجع failed بدل QueryException/500.
                $locked = Location::query()->lockForUpdate()->findOrFail($args['location_id']);
                $this->branches->assertBranch($actor->id, $locked->branch_id);
                StaffAdministration::authorize($actor, ['locations.delete', 'locations.manage']);

                if (DB::table('stock_movements')->where('location_id', $locked->id)->exists()) {
                    return ['ok' => false, 'message' => 'لا يمكن حذف الموقع لارتباطه بحركات مخزنية.', 'branch_id' => $locked->branch_id];
                }
                $hasBalances = StockLevel::query()->where('location_id', $locked->id)
                    ->hasNonZeroBalance()
                    ->exists();
                if ($hasBalances) {
                    return ['ok' => false, 'message' => 'لا يمكن حذف الموقع وفيه أرصدة. صفّرها أولاً.', 'branch_id' => $locked->branch_id];
                }

                $branch = Branch::find($locked->branch_id);
                $name = $locked->name;
                $code = $locked->code;
                StockLevel::query()->where('location_id', $locked->id)->delete();

                StaffAdministration::audit($actor, 'location.deleted', 'location', $locked->id,
                    [], ['name' => $name, 'code' => $code], [], $via);
                $locked->delete();

                return ['ok' => true, 'name' => $name, 'branch_id' => $locked->branch_id, 'branch_name' => $branch?->name ?? ''];
            });
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return ToolOutcome::result(false, 'تعذّر حذف الموقع؛ تربطه حكّة متزامنة. حاول مرة أخرى.');
        }
        if (! $summary['ok']) {
            return ToolOutcome::result(false, $summary['message'], [], ['type' => 'result', 'ok' => false, 'message' => $summary['message']]);
        }
        $message = 'تم حذف موقع '.$summary['name'].' من '.$summary['branch_name'];

        return ToolOutcome::result(true, 'تم حذف الموقع.', [],
            ['type' => 'result', 'ok' => true, 'message' => $message,
                'deep_link' => '/branches?branchId='.$summary['branch_id']]);
    }

    // ── P03: Settings & Document Numbering ─────────────────────────────────

    private function viewSettings(User $actor, array $args): ToolOutcome
    {
        try {
            $this->branches->assertCentralAccess($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('عرض الإعدادات محتاج وصول مركزي.');
        }
        $all = $this->settings->getAllGrouped();
        $group = (string) ($args['group'] ?? 'all');

        // حماية إعدادات AI
        if (! $actor->can('ai_agent.settings')) {
            unset($all['ai']);
        }

        if ($group !== 'all' && $group !== '') {
            $data = $all[$group] ?? [];
            if ($data === []) {
                return ToolOutcome::denied('المجموعة "'.$group.'" مش موجودة أو فاضية.');
            }

            return ToolOutcome::data(['settings' => $data, 'group' => $group]);
        }

        return ToolOutcome::data(['settings' => $all]);
    }

    private function viewDocumentNumbering(User $actor, array $args): ToolOutcome
    {
        try {
            $this->branches->assertCentralAccess($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('عرض الترقيم محتاج وصول مركزي.');
        }

        $allTypes = ['order', 'invoice', 'purchase_order', 'quotation', 'transfer', 'stocktake',
            'return', 'purchase_request', 'delivery', 'supplier_invoice', 'supplier_payment',
            'shift', 'expense', 'treasury_transfer', 'payment_notice', 'movement'];

        $type = (string) ($args['type'] ?? 'all');

        $readNumbering = function (string $t): array {
            $prefix = $this->settings->get($t.'_prefix', '');
            $next = $this->settings->get($t.'_next_number', 1);
            $padding = (int) $this->settings->get($t.'_padding', 6);

            return [
                'type' => $t,
                'prefix' => $prefix,
                'next_number' => $next,
                'padding' => $padding,
                'sample' => $prefix.str_pad((string) $next, $padding, '0', STR_PAD_LEFT),
            ];
        };

        if ($type !== 'all' && $type !== '') {
            if (! in_array($type, $allTypes, true)) {
                return ToolOutcome::denied('نوع مستند غير معروف.');
            }

            return ToolOutcome::data(['numbering' => $readNumbering($type)]);
        }

        $rows = [];
        foreach ($allTypes as $t) {
            $info = $readNumbering($t);
            $rows[] = [$info['type'], $info['prefix'], $info['next_number'], $info['padding'], $info['sample']];
        }

        return ToolOutcome::data(
            ['numbering' => $rows],
            ['type' => 'table', 'columns' => ['النوع', 'البادئة', 'العداد', 'الطول', 'عينة'], 'rows' => $rows]
        );
    }

    private function prepUpdateSettings(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        try {
            $this->branches->assertCentralAccess($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('تعديل الإعدادات محتاج وصول مركزي.');
        }
        $changes = $args['changes'] ?? [];
        if (empty($changes)) {
            return ToolOutcome::denied('مفيش تغييرات.');
        }

        // منع تعديل AI settings بدون صلاحية
        $aiKeys = array_filter(array_keys($changes), fn ($k) => str_starts_with($k, 'ai_'));
        if ($aiKeys !== [] && ! $actor->can('ai_agent.settings')) {
            return ToolOutcome::denied('تعديل إعدادات الذكاء الاصطناعي محتاج صلاحية ai_agent.settings.');
        }

        // منع تعديل ترقيم من هنا — ليها أداة مخصوصة
        $numberingKeys = array_filter(array_keys($changes), fn ($k) => str_ends_with($k, '_prefix') || str_ends_with($k, '_next_number') || str_ends_with($k, '_padding'));
        if ($numberingKeys !== []) {
            return ToolOutcome::denied('ترقيم المستندات يتعدل من أداة update_document_numbering.');
        }

        // التحقق إن المفاتيح موجودة ومسموحة
        // H1: مفاتيح expected/expected.* وai_disabled_tools.* ليست إعدادات قابلة للتعديل.
        $allowedKeys = array_values(array_filter(
            array_keys((new \App\Http\Requests\Api\V1\Admin\UpdateSettingsRequest)->rules()),
            fn (string $key): bool => $key !== 'expected' && ! str_starts_with($key, 'expected.') && ! str_ends_with($key, '.*')
        ));
        $unknownKeys = array_diff(array_keys($changes), $allowedKeys);
        if ($unknownKeys !== []) {
            return ToolOutcome::denied('إعدادات غير معروفة: '.implode(', ', $unknownKeys));
        }

        // قراءة القيم الحالية
        $current = [];
        foreach (array_keys($changes) as $key) {
            $current[$key] = $this->settings->get($key);
        }

        // H1: تطبيق نفس قواعد الـ REST على قيم الوكيل (نحو/نطاقات/حدود
        // prefix وpadding) — المصدر واحد: UpdateSettingsRequest::rules().
        $this->validateSettingChanges($changes);

        $warnings = [];
        if (isset($changes['tax_enabled']) && ! $changes['tax_enabled']) {
            $warnings[] = 'تعطيل الضرائب هيأثر على كل الفواتير الجديدة.';
        }
        if (isset($changes['return_window_days'])) {
            if ($changes['return_window_days'] === 0) {
                $warnings[] = 'الإرجاع هيكون متوقف تماماً.';
            } elseif ($changes['return_window_days'] < 0) {
                $warnings[] = 'الإرجاع هيكون بلا حد زمني.';
            }
        }

        return $this->makeProposal($actor, $conv, 'update_settings',
            ['changes' => $changes, 'expected' => $current], [],
            ['title' => 'تعديل '.count($changes).' إعداد',
                'before' => $current, 'after' => $changes,
                'warnings' => $warnings], $current, 'high');
    }

    /**
     * H1: تحقق من قيم الإعدادات بمصدر القواعد الواحد (UpdateSettingsRequest)
     * مع تجاهل مفاتيح expected وبقاعدتها اللي بتشترط وجود القيمة الأصلية.
     */
    private function validateSettingChanges(array $changes): void
    {
        $rules = array_filter(
            (new UpdateSettingsRequest)->rules(),
            fn (string $key): bool => $key !== 'expected' && ! str_starts_with($key, 'expected.') && ! str_ends_with($key, '.*'),
            ARRAY_FILTER_USE_KEY
        );
        // H1: مفتاح غير معروف (من هلوسة أو حقن) يترفض هنا أيضًا في execute،
        // حتى لو args اتخزنت قبل الإصلاح.
        $unknown = array_diff(array_keys($changes), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'إعداد غير معروف.'));
        }
        $applicable = [];
        foreach (array_keys($changes) as $key) {
            $applicable[$key] = $rules[$key];
        }
        Validator::make($changes, $applicable)->validate();
    }

    private function execUpdateSettings(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $this->branches->assertCentralAccess($actor);
        StaffAdministration::authorize($actor, ['settings.manage']);
        // H1: إعادة التحقق وقت التأكيد تحمي من args خزّنت قبل الإصلاح.
        $this->validateSettingChanges($args['changes']);
        // L6: التدقيق يعرف إن التعديل جه من قناة المساعد.
        $this->settings->updateBatch($args['changes'], $args['expected'], $via);

        return ToolOutcome::result(true, 'تم تحديث الإعدادات.',
            ['updated_keys' => array_keys($args['changes'])],
            ['type' => 'result', 'ok' => true,
                'message' => 'تم تحديث '.count($args['changes']).' إعداد.',
                'deep_link' => '/settings']);
    }

    private function prepUpdateDocNumbering(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        try {
            $this->branches->assertCentralAccess($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('تعديل الترقيم محتاج وصول مركزي.');
        }
        $type = (string) ($args['type'] ?? '');
        if ($type === '') {
            return ToolOutcome::denied('نوع المستند مطلوب.');
        }

        $changes = [];
        $current = [];
        if (isset($args['prefix'])) {
            $key = $type.'_prefix';
            $changes[$key] = (string) $args['prefix'];
            $current[$key] = $this->settings->get($key, '');
        }
        if (isset($args['next_number'])) {
            $key = $type.'_next_number';
            $changes[$key] = (int) $args['next_number'];
            $current[$key] = $this->settings->get($key, 1);
        }
        if (isset($args['padding'])) {
            $key = $type.'_padding';
            $changes[$key] = (int) $args['padding'];
            $current[$key] = $this->settings->get($key, 6);
        }

        if (empty($changes)) {
            return ToolOutcome::denied('حدد قيمة واحدة على الأقل (prefix أو next_number أو padding).');
        }
        // H1: مسار الترقيم مطبّق عليه حدود الـ REST: بادئة 30 رمزًا
        // كحد أقصى، padding من 1 لـ 20، عداد بين 1 و999999999.
        $this->validateSettingChanges($changes);

        $warnings = [];
        if (isset($args['next_number'])) {
            $currentNext = $current[$type.'_next_number'] ?? 1;
            if ($args['next_number'] < $currentNext) {
                $warnings[] = 'العداد لا ينخفض — لو القيمة أقل من الحالية هتتجاهل.';
            }
        }

        // بناء عينة الرقم الجديد
        $newPrefix = $changes[$type.'_prefix'] ?? $this->settings->get($type.'_prefix', '');
        $newNext = $changes[$type.'_next_number'] ?? $this->settings->get($type.'_next_number', 1);
        $newPadding = $changes[$type.'_padding'] ?? $this->settings->get($type.'_padding', 6);
        $sample = $newPrefix.str_pad((string) $newNext, $newPadding, '0', STR_PAD_LEFT);

        return $this->makeProposal($actor, $conv, 'update_document_numbering',
            ['type' => $type, 'changes' => $changes, 'expected' => $current], [],
            ['title' => 'تعديل ترقيم '.$type,
                'before' => $current, 'after' => array_merge($changes, ['sample' => $sample]),
                'warnings' => $warnings], $current, 'high');
    }

    private function execUpdateDocNumbering(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $this->branches->assertCentralAccess($actor);
        StaffAdministration::authorize($actor, ['settings.manage']);
        // H1: إعادة التحقق وقت التأكيد.
        $this->validateSettingChanges($args['changes']);
        // L6: التدقيق يعرف إن التعديل جه من قناة المساعد.
        $this->settings->updateBatch($args['changes'], $args['expected'], $via);

        return ToolOutcome::result(true, 'تم تحديث ترقيم '.$args['type'].'.',
            ['type' => $args['type'], 'updated_keys' => array_keys($args['changes'])],
            ['type' => 'result', 'ok' => true,
                'message' => 'تم تحديث ترقيم '.$args['type'].'.',
                'deep_link' => '/settings']);
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
            if ($tool === 'set_location_status' && isset($inv['is_active'])) {
                $loc = Location::findOrFail($args['location_id']);
                $this->branches->assertBranch($actor->id, $loc->branch_id);
                $loc->update(['is_active' => (bool) $inv['is_active']]);
                StaffAdministration::audit($actor, 'location.status_undone', 'location', $loc->id, [], [], ['is_active' => $inv['is_active']], $via);

                return ToolOutcome::result(true, 'تم التراجع.');
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
