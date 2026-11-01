<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Actions\Branches\CreateBranch;
use App\Actions\Branches\UpdateBranch;
use App\Actions\Locations\SetLocationStatus;
use App\Actions\Staff\CreateRole;
use App\Actions\Staff\CreateStaffUser;
use App\Actions\Staff\DeleteRole;
use App\Actions\Staff\UpdateRole;
use App\Actions\Staff\UpdateStaffUser;
use App\Enums\BranchType;
use App\Enums\CustomerDocumentStatus;
use App\Enums\CustomerDocumentType;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Enums\LocationType;
use App\Enums\StaffRole;
use App\Http\Requests\Admin\Customer\ApproveCustomerRequest;
use App\Http\Requests\Admin\Customer\StoreCustomerRequest;
use App\Http\Requests\Admin\Customer\UpdateCustomerRequest;
use App\Http\Requests\Admin\Pricing\StoreCustomerGroupRequest;
use App\Http\Requests\Admin\Pricing\UpdateCustomerGroupRequest;
use App\Http\Requests\Api\V1\Admin\StoreBranchRequest;
use App\Http\Requests\Api\V1\Admin\StoreRoleRequest;
use App\Http\Requests\Api\V1\Admin\StoreUserRequest;
use App\Http\Requests\Api\V1\Admin\UpdateSettingsRequest;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAuditLog;
use App\Models\CustomerDocument;
use App\Models\CustomerGroup;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\CostAccess;
use App\Services\CustomerGroupService;
use App\Services\CustomerManagementService;
use App\Services\SearchTerm;
use App\Services\SettingService;
use App\Services\StaffAdministration;
use App\Services\WholesalePaymentNoticeService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ToolRegistry
{
    public function __construct(private EntityResolver $resolver, private BranchAccess $branches, private SettingService $settings) {}

    /** INFO: التعريفات تُبنى من الصفر في كل نداء وكانت تُستدعى عشرات
     * المرات في الطلب الواحد (من declarationsFor وisWrite وdispatch
     * وauthorizeTool وconfirmLocked، ومنها داخل rules() وauditableSettings()
     * في UpdateSettingsRequest). خزّنها في property — لا حالة طلب
     * داخلها، فهي ثوابت من الـ enums فقط. */
    private ?array $definitionsCache = null;

    /** @return array<string, array{kind:ToolKind, permissions:list<string>, declaration:array<string,mixed>, risk:string}> */
    public function definitions(): array
    {
        return $this->definitionsCache ??= $this->buildDefinitions();
    }

    /** @return array<string, array{kind:ToolKind, permissions:list<string>, declaration:array<string,mixed>, risk:string}> */
    private function buildDefinitions(): array
    {
        $obj = fn (array $props, array $req = []) => ['type' => 'OBJECT', 'properties' => (object) $props, 'required' => $req];
        $str = ['type' => 'STRING'];
        $int = ['type' => 'INTEGER'];
        $bool = ['type' => 'BOOLEAN'];
        $strArr = ['type' => 'ARRAY', 'items' => ['type' => 'STRING']];
        $branchTypes = array_column(BranchType::cases(), 'value');
        $locationTypes = array_column(LocationType::cases(), 'value');

        return [
            'bulk_actions' => ['kind' => ToolKind::Write, 'permissions' => ['branches.update', 'branches.manage', 'users.update', 'users.manage', 'roles.update', 'roles.manage', 'locations.update', 'locations.manage', 'customers.approve', 'customers.documents.view'], 'risk' => 'high', 'declaration' => ['name' => 'bulk_actions', 'description' => 'اقتراح مجموعة تعديلات مستقلة بكارت واحد. راجع كل عنصر قبل التأكيد؛ لا تنفذ أي تعديل فوراً. الاعتماد والرفض ممنوعان في الجماعي — كل قرار اعتماد أو رفض لازم يتراجع لوحده.', 'parameters' => $obj(['items' => ['type' => 'ARRAY', 'minItems' => 2, 'items' => $obj(['tool' => ['type' => 'STRING', 'enum' => ['update_role_permissions', 'set_user_status', 'assign_roles', 'revoke_user_sessions', 'set_location_status', 'create_location', 'set_customer_group', 'set_credit_limit', 'review_customer_document', 'suspend_customer', 'reactivate_customer']], 'args' => $obj(['branch_id' => $int, 'user_id' => $int, 'role_id' => $int, 'location_id' => $int, 'active' => $bool, 'add' => $strArr, 'remove' => $strArr, 'name' => $str, 'code' => $str, 'type' => $str, 'customer_id' => $int, 'customer_group_id' => $int, 'credit_limit' => ['type' => 'NUMBER'], 'document_id' => $int, 'status' => $str, 'note' => $str, 'reason' => $str])], ['tool', 'args'])]], ['items'])]],
            'find_entities' => ['kind' => ToolKind::Read, 'permissions' => ['branches.view', 'branches.manage', 'users.view', 'users.manage', 'roles.view', 'roles.manage', 'permissions.view'], 'risk' => 'low', 'declaration' => ['name' => 'find_entities', 'description' => 'بحث عن فروع أو موظفين أو أدوار أو صلاحيات أو مواقع تخزين أو عملاء أو مجموعات عملاء', 'parameters' => $obj(['type' => ['type' => 'STRING', 'enum' => ['branch', 'user', 'role', 'permission', 'location', 'customer', 'customer_group']], 'query' => $str, 'branch_id' => $int], ['type', 'query'])]],
            'list_branches' => ['kind' => ToolKind::Read, 'permissions' => ['branches.view', 'branches.manage'], 'risk' => 'low', 'declaration' => ['name' => 'list_branches', 'description' => 'قائمة الفروع (active=false للمتوقفة)', 'parameters' => $obj(['active' => $bool, 'city' => $str, 'type' => ['type' => 'STRING', 'enum' => $branchTypes]])]],
            'list_permissions' => ['kind' => ToolKind::Read, 'permissions' => ['permissions.view', 'roles.view', 'roles.manage'], 'risk' => 'low', 'declaration' => ['name' => 'list_permissions', 'description' => 'كتالوج الصلاحيات المتاحة', 'parameters' => $obj([])]],
            'list_roles' => ['kind' => ToolKind::Read, 'permissions' => ['roles.view', 'roles.manage'], 'risk' => 'low', 'declaration' => ['name' => 'list_roles', 'description' => 'قائمة الأدوار', 'parameters' => $obj([])]],
            'list_staff' => ['kind' => ToolKind::Read, 'permissions' => ['users.view', 'users.manage'], 'risk' => 'low', 'declaration' => ['name' => 'list_staff', 'description' => 'قائمة الموظفين', 'parameters' => $obj(['query' => $str])]],
            'explain_user_access' => ['kind' => ToolKind::Read, 'permissions' => ['users.view', 'users.manage'], 'risk' => 'low', 'declaration' => ['name' => 'explain_user_access', 'description' => 'شرح أدوار وصلاحيات ونطاق موظف', 'parameters' => $obj(['user_id' => $int], ['user_id'])]],
            'who_can' => ['kind' => ToolKind::Read, 'permissions' => ['roles.view', 'roles.manage', 'permissions.view'], 'risk' => 'low', 'declaration' => ['name' => 'who_can', 'description' => 'مين معاه صلاحية معينة', 'parameters' => $obj(['permission' => $str], ['permission'])]],
            'compare_roles' => ['kind' => ToolKind::Read, 'permissions' => ['roles.view', 'roles.manage'], 'risk' => 'low', 'declaration' => ['name' => 'compare_roles', 'description' => 'مقارنة صلاحيات دورين', 'parameters' => $obj(['role_a' => $int, 'role_b' => $int], ['role_a', 'role_b'])]],
            'recent_audit' => ['kind' => ToolKind::Read, 'permissions' => ['users.view', 'users.manage'], 'risk' => 'low', 'declaration' => ['name' => 'recent_audit', 'description' => 'آخر أحداث سجل التدقيق الإداري', 'parameters' => $obj(['days' => $int])]],
            'ask_user_to_choose' => ['kind' => ToolKind::Ui, 'permissions' => ['branches.view', 'branches.manage', 'users.view', 'users.manage', 'roles.view', 'roles.manage'], 'risk' => 'low', 'declaration' => ['name' => 'ask_user_to_choose', 'description' => 'اطلب من المستخدم اختيار كيان من قائمة حقيقية', 'parameters' => $obj(['entity_type' => ['type' => 'STRING', 'enum' => ['branch', 'user', 'role', 'location', 'customer', 'customer_group']], 'purpose' => $str, 'query' => $str], ['entity_type', 'purpose'])]],
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

            // ── P04 Customers (قراءة + كتابة — مساعد مراجِع تجار) ───────────
            // أدوات القراءة السبع من المرحلة 1، وأدوات الكتابة من المرحلة 2
            // بنفس نمط propose→confirm→undo؛ الاعتماد والرفض بلا تراجع
            // تلقائي (التراجع فيهما قرار إداري جديد، مش undo).
            'list_customers' => ['kind' => ToolKind::Read, 'permissions' => ['customers.view'], 'risk' => 'low', 'declaration' => ['name' => 'list_customers', 'description' => 'قائمة العملاء/التجار مع فلاتر (بيانات مقنّعة للنموذج، وكاملة في كارت العرض للأدمن)', 'parameters' => $obj(['query' => $str, 'type' => ['type' => 'STRING', 'enum' => ['retail', 'wholesale', 'distributor']], 'status' => ['type' => 'STRING', 'enum' => ['pending', 'approved', 'rejected', 'suspended']], 'customer_group_id' => $int, 'business_only' => $bool, 'pending_older_than_hours' => $int, 'limit' => $int])]],
            'get_customer_profile' => ['kind' => ToolKind::Read, 'permissions' => ['customers.view'], 'risk' => 'low', 'declaration' => ['name' => 'get_customer_profile', 'description' => 'ملف عميل كامل مقنّع: المستندات حسب الحالة، والمجموعة، وحد الائتمان، والمستخدم منه', 'parameters' => $obj(['customer_id' => $int], ['customer_id'])]],
            'dealer_queue_summary' => ['kind' => ToolKind::Read, 'permissions' => ['customers.view'], 'risk' => 'low', 'declaration' => ['name' => 'dealer_queue_summary', 'description' => 'ملخص طابور مراجعة التجار: قيد المراجعة، المتأخر عن المهلة، الناقص مستندات، الجاهز للاعتماد', 'parameters' => $obj(['sla_hours' => $int])]],
            'review_dealer_application' => ['kind' => ToolKind::Read, 'permissions' => ['customers.view', 'customers.documents.view'], 'risk' => 'low', 'declaration' => ['name' => 'review_dealer_application', 'description' => 'فحص طلب اعتماد تاجر قبل الاعتماد: نفس شروط approve() زائد تحذيرات، مع حكم وخطوات تالية', 'parameters' => $obj(['customer_id' => $int], ['customer_id'])]],
            'customer_history' => ['kind' => ToolKind::Read, 'permissions' => ['customers.view'], 'risk' => 'low', 'declaration' => ['name' => 'customer_history', 'description' => 'سجل تغييرات ملف عميل من CustomerAuditLog (الأحدث أولًا)', 'parameters' => $obj(['customer_id' => $int, 'limit' => $int], ['customer_id'])]],
            'find_duplicate_customers' => ['kind' => ToolKind::Read, 'permissions' => ['customers.view'], 'risk' => 'low', 'declaration' => ['name' => 'find_duplicate_customers', 'description' => 'تجار ممكن يكونوا نفس الشخص: تطابق ضريبي/سجل، تشابه اسم، أو نفس إيميل حساب محذوف', 'parameters' => $obj(['customer_id' => $int], ['customer_id'])]],
            'list_customer_groups' => ['kind' => ToolKind::Read, 'permissions' => ['customer_groups.view'], 'risk' => 'low', 'declaration' => ['name' => 'list_customer_groups', 'description' => 'قائمة مجموعات العملاء مع نسبة الخصم وعدد العملاء', 'parameters' => $obj(['query' => $str, 'active' => $bool])]],

            // ── P04-2 أدوات كتابة العملاء ─────────────────────────────────────
            // الصلاحيات هنا canAny (أي واحدة تكفي)؛ والحقول القانونية في
            // update_customer_profile تحتاج customers.approve زيادة (تُفحص
            // في الـ prep). الممنوعات (حذف عميل، تغيير باسورد، تغيير نوع
            // من غير اعتماد) مرفوضة صراحة في assertAiConstraints.
            'create_customer' => ['kind' => ToolKind::Write, 'permissions' => ['customers.create'], 'risk' => 'low', 'declaration' => ['name' => 'create_customer', 'description' => 'إنشاء عميل جديد؛ الباسورد يتولد في السيرفر ويظهر مرة واحدة (يحتاج تأكيد). الجملة والموزع يتسجلون قيد المراجعة.', 'parameters' => $obj(['name' => $str, 'phone' => $str, 'email' => $str, 'type' => ['type' => 'STRING', 'enum' => ['retail', 'wholesale', 'distributor']], 'company_name' => $str, 'tax_number' => $str, 'commercial_register' => $str], ['name', 'phone'])]],
            'update_customer_profile' => ['kind' => ToolKind::Write, 'permissions' => ['customers.update'], 'risk' => 'medium', 'declaration' => ['name' => 'update_customer_profile', 'description' => 'تعديل بيانات عميل (الاسم والإيميل والموبايل والبيانات القانونية فقط؛ النوع والحالة ممنوعان) (يحتاج تأكيد)', 'parameters' => $obj(['customer_id' => $int, 'name' => $str, 'email' => $str, 'phone' => $str, 'company_name' => $str, 'tax_number' => $str, 'commercial_register' => $str], ['customer_id'])]],
            'set_customer_group' => ['kind' => ToolKind::Write, 'permissions' => ['customers.approve'], 'risk' => 'medium', 'declaration' => ['name' => 'set_customer_group', 'description' => 'تعيين مجموعة عميل (يحتاج تأكيد)', 'parameters' => $obj(['customer_id' => $int, 'customer_group_id' => $int], ['customer_id', 'customer_group_id'])]],
            'set_credit_limit' => ['kind' => ToolKind::Write, 'permissions' => ['customers.approve'], 'risk' => 'medium', 'declaration' => ['name' => 'set_credit_limit', 'description' => 'تعيين حد ائتمان عميل (يحتاج تأكيد؛ يبقى عالي الخطورة ويطلب تأكيدًا بالكتابة لو فوق العتبة أو الزيادة أكتر من 100%)', 'parameters' => $obj(['customer_id' => $int, 'credit_limit' => ['type' => 'NUMBER']], ['customer_id', 'credit_limit'])]],
            'review_customer_document' => ['kind' => ToolKind::Write, 'permissions' => ['customers.approve', 'customers.documents.view'], 'risk' => 'low', 'declaration' => ['name' => 'review_customer_document', 'description' => 'مراجعة مستند عميل: توثيق أو رفض (الملاحظة إلزامية عند الرفض) (يحتاج تأكيد)', 'parameters' => $obj(['customer_id' => $int, 'document_id' => $int, 'status' => ['type' => 'STRING', 'enum' => ['verified', 'rejected']], 'note' => $str], ['customer_id', 'document_id', 'status'])]],
            'approve_dealer_application' => ['kind' => ToolKind::Write, 'permissions' => ['customers.approve'], 'risk' => 'high', 'declaration' => ['name' => 'approve_dealer_application', 'description' => 'اعتماد طلب تاجر: يوثّق المستندات المحددة ثم يعتمد في تأكيد واحد (يحتاج تأكيدًا بالكتابة، بلا تراجع تلقائي)', 'parameters' => $obj(['customer_id' => $int, 'verify_document_ids' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']], 'customer_group_id' => $int, 'credit_limit' => ['type' => 'NUMBER'], 'type' => ['type' => 'STRING', 'enum' => ['wholesale', 'distributor']]], ['customer_id'])]],
            'reject_dealer_application' => ['kind' => ToolKind::Write, 'permissions' => ['customers.approve'], 'risk' => 'high', 'declaration' => ['name' => 'reject_dealer_application', 'description' => 'رفض طلب تاجر مع سبب مكتوب بالمصري المهذب (يحتاج تأكيدًا بالكتابة، بلا تراجع تلقائي)', 'parameters' => $obj(['customer_id' => $int, 'reason' => $str, 'reject_document_ids' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']], 'document_notes' => ['type' => 'OBJECT']], ['customer_id', 'reason'])]],
            'suspend_customer' => ['kind' => ToolKind::Write, 'permissions' => ['customers.approve'], 'risk' => 'high', 'declaration' => ['name' => 'suspend_customer', 'description' => 'إيقاف حساب عميل وإنهاء كل جلساته فورًا (يحتاج تأكيدًا بالكتابة)', 'parameters' => $obj(['customer_id' => $int, 'reason' => $str], ['customer_id', 'reason'])]],
            'reactivate_customer' => ['kind' => ToolKind::Write, 'permissions' => ['customers.approve'], 'risk' => 'medium', 'declaration' => ['name' => 'reactivate_customer', 'description' => 'إعادة تفعيل عميل موقوف (يرجع معتمدًا لو كان معتمدًا قبل الإيقاف، وإلا قيد المراجعة) (يحتاج تأكيد)', 'parameters' => $obj(['customer_id' => $int], ['customer_id'])]],
            'restore_customer' => ['kind' => ToolKind::Write, 'permissions' => ['customers.delete'], 'risk' => 'medium', 'declaration' => ['name' => 'restore_customer', 'description' => 'استعادة حساب عميل محذوف (يحتاج تأكيد، بلا تراجع تلقائي)', 'parameters' => $obj(['customer_id' => $int], ['customer_id'])]],
            'create_customer_group' => ['kind' => ToolKind::Write, 'permissions' => ['customer_groups.create'], 'risk' => 'low', 'declaration' => ['name' => 'create_customer_group', 'description' => 'إنشاء مجموعة عملاء جديدة (يحتاج تأكيد، بلا تراجع تلقائي)', 'parameters' => $obj(['name' => $str, 'code' => $str, 'description' => $str, 'discount_percentage' => ['type' => 'NUMBER'], 'is_active' => $bool], ['name', 'code'])]],
            'update_customer_group' => ['kind' => ToolKind::Write, 'permissions' => ['customer_groups.update'], 'risk' => 'medium', 'declaration' => ['name' => 'update_customer_group', 'description' => 'تعديل مجموعة عملاء (يحتاج تأكيد؛ يبقى عالي الخطورة لو الخصم اتغير والمجموعة فيها عملاء)', 'parameters' => $obj(['customer_group_id' => $int, 'name' => $str, 'code' => $str, 'description' => $str, 'discount_percentage' => ['type' => 'NUMBER'], 'is_active' => $bool], ['customer_group_id'])]],

            // ── P03 Settings & Numbering ───────────────────────────────────────────
            'view_settings' => ['kind' => ToolKind::Read, 'permissions' => ['settings.view', 'settings.manage'], 'risk' => 'low', 'declaration' => ['name' => 'view_settings', 'description' => 'عرض إعدادات المؤسسة (هوية الشركة، الضرائب، السياسات، الترقيم)', 'parameters' => $obj(['group' => ['type' => 'STRING', 'enum' => ['company', 'tax', 'policy', 'numbering', 'store', 'purchases', 'ai', 'all']]])]],
            'view_document_numbering' => ['kind' => ToolKind::Read, 'permissions' => ['settings.view', 'settings.manage'], 'risk' => 'low', 'declaration' => ['name' => 'view_document_numbering', 'description' => 'عرض بادئات المستندات وعدّاداتها الحالية وعينة الرقم', 'parameters' => $obj(['type' => ['type' => 'STRING', 'enum' => ['order', 'invoice', 'purchase_order', 'quotation', 'transfer', 'stocktake', 'return', 'purchase_request', 'delivery', 'supplier_invoice', 'supplier_payment', 'shift', 'pos_invoice', 'expense', 'treasury_transfer', 'payment_notice', 'movement', 'all']]])]],
            'update_settings' => ['kind' => ToolKind::Write, 'permissions' => ['settings.manage'], 'risk' => 'high', 'declaration' => ['name' => 'update_settings', 'description' => 'تعديل إعدادات المؤسسة (يحتاج تأكيد). اقرأ الإعدادات الحالية أولاً بـ view_settings.', 'parameters' => $obj(['changes' => $obj(['company_name' => $str, 'company_email' => $str, 'company_phone' => $str, 'company_address' => $str, 'company_logo' => $str, 'commercial_register' => $str, 'tax_enabled' => $bool, 'tax_number' => $str, 'default_tax_rate' => ['type' => 'NUMBER'], 'prices_include_tax' => $bool, 'return_window_days' => $int, 'allow_self_approve_purchases' => $bool])], ['changes'])]],
            'update_document_numbering' => ['kind' => ToolKind::Write, 'permissions' => ['settings.manage'], 'risk' => 'high', 'declaration' => ['name' => 'update_document_numbering', 'description' => 'تعديل بادئة أو عداد أو طول ترقيم مستند (يحتاج تأكيد). العداد لا ينخفض.', 'parameters' => $obj(['type' => ['type' => 'STRING', 'enum' => ['order', 'invoice', 'purchase_order', 'quotation', 'transfer', 'stocktake', 'return', 'purchase_request', 'delivery', 'supplier_invoice', 'supplier_payment', 'shift', 'pos_invoice', 'expense', 'treasury_transfer', 'payment_notice', 'movement']], 'prefix' => $str, 'next_number' => $int, 'padding' => $int], ['type'])]],
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
                'list_customers' => $this->listCustomers($args),
                'get_customer_profile' => $this->getCustomerProfile($args),
                'dealer_queue_summary' => $this->dealerQueueSummary($args),
                'review_dealer_application' => $this->reviewDealerApplication($args),
                'customer_history' => $this->customerHistory($args),
                'find_duplicate_customers' => $this->findDuplicateCustomers($args),
                'list_customer_groups' => $this->listCustomerGroups($args),
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
            // 3.1: الاعتماد والرفض ممنوعان في الجماعي صراحة — كل قرار
            // بيبعت إشعار لعميل حقيقي، فلازم يتراجع لوحده. الرفض هنا
            // برسالة عربية واضحة، مش برسالة Rule::in العامة.
            if (is_array($args['items'] ?? null)) {
                foreach ($args['items'] as $bulkItem) {
                    if (in_array($bulkItem['tool'] ?? null, ['approve_dealer_application', 'reject_dealer_application'], true)) {
                        throw ValidationException::withMessages(['items' => 'الاعتماد والرفض ممنوعان في العمليات الجماعية؛ كل قرار اعتماد أو رفض لازم يتراجع لوحده، لأنه بيبعت إشعار لعميل حقيقي.']);
                    }
                }
            }
            Validator::make($args, ['items' => ['required', 'array', 'min:2', 'max:'.max(1, (int) $this->settings->get('ai_bulk_max_items', 25))], 'items.*.tool' => ['required', Rule::in(['update_role_permissions', 'set_user_status', 'assign_roles', 'revoke_user_sessions', 'set_location_status', 'create_location', 'set_customer_group', 'set_credit_limit', 'review_customer_document', 'suspend_customer', 'reactivate_customer'])], 'items.*.args' => ['required', 'array']])->validate();

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
        if (in_array($name, ['create_customer', 'update_customer_profile'], true) && is_string($args['phone'] ?? null)) {
            // تطبيع الموبايل قبل الـ regex (أرقام عربية، +20/0020) — نفس
            // ما يعمله الـ mutator عند الحفظ، لكن التحقق يسبق الحفظ.
            $args['phone'] = Customer::normalizePhone($args['phone']);
        }
        if ($name === 'create_customer') {
            // دمج قواعد REST لنفس الحقول المعلنة فقط؛ الباسورد مولّد في
            // السيرفر وقت التنفيذ ومش من args أصلًا.
            $customerRules = array_intersect_key((new StoreCustomerRequest)->rules(), array_flip(['name', 'phone', 'email', 'type', 'company_name', 'tax_number', 'commercial_register']));
            $rules = array_replace($rules, $customerRules);
        }
        if ($name === 'update_customer_profile') {
            $customerRules = array_intersect_key((new UpdateCustomerRequest)->rules(), array_flip(['name', 'email', 'phone', 'company_name', 'tax_number', 'commercial_register']));
            // قاعدة REST تتجاهل route('customer') غير الموجود في سياق
            // المساعد — التجاهل هنا هو عميل الأداة نفسه.
            $customerRules['phone'] = ['sometimes', 'string', 'regex:/^01[0125][0-9]{8}$/', Rule::unique('customers', 'phone')->ignore((int) ($args['customer_id'] ?? 0))];
            $rules = array_replace($rules, $customerRules);
        }
        if ($name === 'set_customer_group') {
            $rules['customer_group_id'] = ['required', 'integer', Rule::exists('customer_groups', 'id')];
        }
        if ($name === 'set_credit_limit') {
            $rules['credit_limit'] = ['required', 'numeric', 'min:0', 'max:9999999999.99'];
        }
        if ($name === 'review_customer_document') {
            $rules['note'] = ['nullable', 'string', 'max:500', 'required_if:status,rejected'];
        }
        if ($name === 'approve_dealer_application') {
            $approveRules = array_intersect_key((new ApproveCustomerRequest)->rules(), array_flip(['customer_group_id', 'credit_limit', 'type']));
            $rules = array_replace($rules, $approveRules);
            $rules['verify_document_ids.*'] = ['integer', 'distinct', 'min:1'];
        }
        if ($name === 'reject_dealer_application') {
            $rules['reason'] = ['required', 'string', 'max:2000'];
            $rules['reject_document_ids.*'] = ['integer', 'distinct', 'min:1'];
        }
        if ($name === 'suspend_customer') {
            $rules['reason'] = ['required', 'string', 'max:2000'];
        }
        if ($name === 'create_customer_group') {
            $rules = array_replace($rules, (new StoreCustomerGroupRequest)->rules());
        }
        if ($name === 'update_customer_group') {
            $groupRules = (new UpdateCustomerGroupRequest)->rules();
            // نفس مشكلة route() في UpdateCustomerRequest: التجاهل هو
            // مجموعة الأداة نفسها.
            $groupRules['code'] = ['sometimes', 'string', 'max:64', 'alpha_dash', Rule::unique('customer_groups', 'code')->ignore((int) ($args['customer_group_id'] ?? 0))];
            $rules = array_replace($rules, $groupRules);
        }
        if (in_array($name, ['create_location', 'set_location_status'], true) && isset($rules['code'])) {
            // L-8: كود الموقع max:100 في الـ REST — توحيد الحد بين المسارين.
            $rules['code'] = array_map(static fn ($rule) => $rule === 'max:255' ? 'max:100' : $rule, $rules['code']);
        }
        if ($name === 'create_branch') {
            $rules = array_replace($rules, (new StoreBranchRequest)->rules());
        }
        if ($name === 'create_role') {
            $rules = array_replace($rules, (new StoreRoleRequest)->rules());
        }
        if ($name === 'create_location' && isset($rules['code'])) {
            // L-8: كود الموقع max:100 في REST وmax:255 في المساعد — وحّدها
            // على 100. قاعدة الـ unique الكاملة من StoreLocationRequest لا
            // تُطبَّق هنا لأنها تحتاج route('branch') غير موجود في سياق
            // المساعد؛ التفرد مضمون أصلًا بالـ unique index (branch_id, code)
            // وتحذير prepCreateLocation.
            $rules['code'] = ['required', 'string', 'max:100'];
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
            'customer' => ToolOutcome::data(['results' => $this->resolver->customers($actor, $query)]),
            'customer_group' => ToolOutcome::data(['results' => $this->resolver->customerGroups($query)]),
            'permission' => ToolOutcome::data(['results' => Permission::query()->where('guard_name', 'staff')->when($query !== '', fn ($q) => $q->where('name', 'ilike', SearchTerm::pattern($query)))->orderBy('name')->limit(50)->get()->map(fn ($p) => ['id' => $p->name, 'label' => $p->name, 'meta' => ''])->values()->all()]),
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
            'customer' => ['customers.view'],
            'customer_group' => ['customer_groups.view'],
            'customer_document' => ['customers.documents.view'],
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
            'branch' => $this->resolver->branches($actor, $query), 'user' => $this->resolver->users($actor, $query), 'role' => $this->resolver->roles($query), 'location' => $this->resolver->locations($actor, $query), 'customer' => $this->resolver->customers($actor, $query), 'customer_group' => $this->resolver->customerGroups($query), default => []
        };

        return ToolOutcome::picker(['type' => 'entity_picker', 'entity' => $type, 'purpose' => (string) ($args['purpose'] ?? ''), 'multi' => false, 'options' => $options]);
    }

    private function pickerFor(User $actor, string $entity, string $purpose, string $query = ''): ToolOutcome
    {
        return $this->picker($actor, ['entity_type' => $entity, 'purpose' => $purpose, 'query' => $query]);
    }

    // ── P04 Customers: طبقة القراءة ──────────────────────────────────────────
    // قاعدة ثابتة: أي حقل كتبه التاجر بنفسه لا يدخل نتيجة الأداة إلا
    // مغلّفًا بـ untrusted()، وأي قيمة كاملة (موبايل/ضريبي/سجل) لا تذهب
    // للنموذج أبدًا — تظهر للأدمن فقط داخل الـ UI block، لأن
    // asFunctionResponse يبعت data بس، والـ block يتخزن في ui_blocks.

    /** 1.6: تغليف بيانات كتبها التاجر — لا تُنفَّذ ولا تُعتَبر سبب قرار. */
    private function untrusted(?string $v, int $max = 120): array
    {
        $clean = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $v), 0, $max);

        return ['untrusted_text' => $clean];
    }

    private function findCustomer(array $args): ?Customer
    {
        return Customer::query()->find((int) ($args['customer_id'] ?? 0));
    }

    private function customerSearchQuery(array $args)
    {
        $query = (string) ($args['query'] ?? '');
        $q = Customer::query()->with('customerGroup')
            ->when($args['business_only'] ?? false, fn ($x) => $x->whereIn('type', ['wholesale', 'distributor']))
            ->when(isset($args['type']), fn ($x) => $x->where('type', $args['type']))
            ->when(isset($args['status']), fn ($x) => $x->where('status', $args['status']))
            ->when(isset($args['customer_group_id']), fn ($x) => $x->where('customer_group_id', $args['customer_group_id']))
            ->when(isset($args['pending_older_than_hours']), fn ($x) => $x->where('status', 'pending')->where('created_at', '<', now()->subHours((int) $args['pending_older_than_hours'])));
        if (trim($query) !== '') {
            if (preg_match('/^[0-9٠-٩۰-۹\s().+-]+$/u', trim($query))) {
                $q->whereLike('phone', SearchTerm::prefix(Customer::normalizePhone($query)), caseSensitive: false);
            } else {
                $q->whereLike('search_text', SearchTerm::pattern($query), caseSensitive: false);
            }
        }

        return $q;
    }

    private function listCustomers(array $args): ToolOutcome
    {
        $limit = min(20, max(1, (int) ($args['limit'] ?? 20)));
        $rows = $this->customerSearchQuery($args)->orderBy('id')->limit($limit)->get();
        $data = $rows->map(fn (Customer $c) => [
            'id' => $c->id,
            'name' => $this->untrusted($c->name),
            'company_name' => $this->untrusted($c->company_name),
            'phone_masked' => EntityResolver::maskPhone($c->phone),
            'type' => $c->type->value,
            'status' => $c->status->value,
            'group' => $c->customerGroup?->name,
            'age_hours' => $c->created_at->diffInHours(now()),
        ])->all();

        return ToolOutcome::data(['customers' => $data], ['type' => 'table', 'columns' => ['الاسم', 'الشركة', 'الحالة', 'النوع', 'المجموعة', 'عمر الطلب (ساعة)'], 'rows' => $rows->map(fn (Customer $c) => [$c->name, $c->company_name ?? '—', EntityResolver::statusLabel($c->status->value), EntityResolver::typeLabel($c->type->value), $c->customerGroup?->name ?? '—', $c->created_at->diffInHours(now())])->all()]);
    }

    private function getCustomerProfile(array $args): ToolOutcome
    {
        $customer = $this->findCustomer($args);
        if (! $customer) {
            return ToolOutcome::denied('العميل مش موجود.');
        }
        $customer->load(['customerGroup', 'documents']);
        $counts = ['total' => $customer->documents->count(), 'pending' => 0, 'verified' => 0, 'rejected' => 0];
        foreach ($customer->documents as $doc) {
            $counts[$doc->status->value] = ($counts[$doc->status->value] ?? 0) + 1;
        }
        $used = app(WholesalePaymentNoticeService::class)->outstandingBalance($customer);
        $data = [
            'id' => $customer->id,
            'name' => $this->untrusted($customer->name),
            'company_name' => $this->untrusted($customer->company_name),
            'email' => $this->untrusted($customer->email),
            'phone_masked' => EntityResolver::maskPhone($customer->phone),
            'tax_number_masked' => EntityResolver::maskLegal($customer->tax_number),
            'commercial_register_masked' => EntityResolver::maskLegal($customer->commercial_register),
            'type' => $customer->type->value,
            'status' => $customer->status->value,
            'group' => $customer->customerGroup ? ['id' => $customer->customerGroup->id, 'name' => $customer->customerGroup->name, 'discount_percentage' => $customer->customerGroup->discount_percentage] : null,
            'credit_limit' => (float) $customer->credit_limit,
            'used_credit' => $used,
            'documents_counts' => $counts,
            'pending_age_hours' => $customer->status === CustomerStatus::Pending ? $customer->created_at->diffInHours(now()) : null,
            'created_at' => $customer->created_at,
        ];

        return ToolOutcome::data(['customer' => $data], ['type' => 'profile', 'id' => $customer->id, 'name' => $customer->name, 'company_name' => $customer->company_name, 'email' => $customer->email, 'phone' => $customer->phone, 'tax_number' => $customer->tax_number, 'commercial_register' => $customer->commercial_register, 'type' => $customer->type->value, 'status' => $customer->status->value, 'group' => $customer->customerGroup?->name, 'credit_limit' => (float) $customer->credit_limit, 'used_credit' => $used, 'documents_counts' => $counts]);
    }

    private function dealerQueueSummary(array $args): ToolOutcome
    {
        $sla = max(1, (int) ($args['sla_hours'] ?? $this->settings->get('ai_dealer_review_sla_hours', 48)));
        $pending = Customer::query()->where('status', 'pending')->with('documents')->orderBy('id')->get();
        $breachedAt = now()->subHours($sla);
        $missing = $pending->filter(fn (Customer $c) => ! $c->documents->contains(fn ($d) => $d->status === CustomerDocumentStatus::Verified))->count();
        $ready = $pending->filter(fn (Customer $c) => $this->reviewCustomer($c)['verdict'] === 'ready')->count();
        $data = [
            'pending_count' => $pending->count(),
            'sla_breached_count' => $pending->filter(fn (Customer $c) => $c->created_at < $breachedAt)->count(),
            'missing_documents_count' => $missing,
            'ready_count' => $ready,
            'sla_hours' => $sla,
        ];

        return ToolOutcome::data($data, ['type' => 'table', 'columns' => ['المؤشر', 'العدد'], 'rows' => [['قيد المراجعة', $data['pending_count']], ['متأخر عن المهلة', $data['sla_breached_count']], ['ناقص مستندات', $data['missing_documents_count']], ['جاهز للاعتماد', $data['ready_count']]]]);
    }

    private function reviewDealerApplication(array $args): ToolOutcome
    {
        $customer = $this->findCustomer($args);
        if (! $customer) {
            return ToolOutcome::denied('العميل مش موجود.');
        }
        $review = $this->reviewCustomer($customer);

        return ToolOutcome::data(['customer_id' => $customer->id, 'checks' => $review['checks'], 'verdict' => $review['verdict'], 'next_steps' => $review['next_steps']], ['type' => 'checklist', 'customer_id' => $customer->id, 'verdict' => $review['verdict'], 'checks' => $review['checks']]);
    }

    /**
     * 1.4: نفس فحوصات approve() قبل الاعتماد، زائد تحذيرات.
     * الرسائل قوالب ثابتة — لا تحتوي أي قيمة كتبها التاجر.
     * $treatVerifiedIds (من المرحلة 2): مستندات تُعامل كموثّقة لمحاكاة
     * التوثيق في prepare الخاص بـ approve_dealer_application — أداة
     * القراءة تستدعي من غيرها فيبقى السلوك مطابقًا.
     *
     * @return array{checks:list<array{key:string,ok:bool,severity:string,message:string}>,verdict:string,next_steps:list<string>}
     */
    private function reviewCustomer(Customer $customer, array $treatVerifiedIds = []): array
    {
        $docs = $customer->relationLoaded('documents') ? $customer->documents : $customer->documents()->get();
        // الحالة الفعالة بعد المحاكاة: الموثّق فعلًا + المحدد للتوثيق.
        $effective = $docs->map(fn ($d) => in_array($d->id, $treatVerifiedIds, true) ? CustomerDocumentStatus::Verified : $d->status);
        $sla = max(1, (int) $this->settings->get('ai_dealer_review_sla_hours', 48));
        $legalNames = ['company_name' => 'اسم الشركة', 'tax_number' => 'الرقم الضريبي', 'commercial_register' => 'السجل التجاري'];
        $missingLegal = array_values(array_filter(array_keys($legalNames), fn ($f) => blank($customer->{$f})));
        $presentTypes = $docs->map(fn ($d) => $d->document_type->value)->all();
        $missingTypes = array_values(array_diff([CustomerDocumentType::CommercialRegister->value, CustomerDocumentType::TaxCard->value], $presentTypes));
        $typeNames = [CustomerDocumentType::CommercialRegister->value => 'سجل تجاري', CustomerDocumentType::TaxCard->value => 'بطاقة ضريبية'];
        $duplicates = $this->duplicateCustomers($customer);
        $checks = [
            ['key' => 'legal_fields', 'ok' => $missingLegal === [], 'severity' => 'blocker', 'message' => $missingLegal === [] ? 'البيانات القانونية التلاتة موجودة.' : 'ناقص: '.implode('، ', array_map(fn ($f) => $legalNames[$f], $missingLegal)).'.'],
            ['key' => 'documents_unverified', 'ok' => ! $effective->contains(fn ($s) => $s !== CustomerDocumentStatus::Verified), 'severity' => 'blocker', 'message' => $effective->contains(fn ($s) => $s !== CustomerDocumentStatus::Verified) ? 'في مستندات لسه مش verified.' : 'كل المستندات verified.'],
            ['key' => 'no_verified_document', 'ok' => $effective->contains(fn ($s) => $s === CustomerDocumentStatus::Verified), 'severity' => 'blocker', 'message' => $effective->contains(fn ($s) => $s === CustomerDocumentStatus::Verified) ? 'في مستند verified واحد على الأقل.' : 'مفيش ولا مستند verified.'],
            ['key' => 'status_not_pending', 'ok' => $customer->status === CustomerStatus::Pending, 'severity' => 'blocker', 'message' => $customer->status === CustomerStatus::Pending ? 'الطلب قيد المراجعة.' : 'الحالة الحالية مش pending.'],
            ['key' => 'phone_unverified', 'ok' => $customer->phone_verified_at !== null, 'severity' => 'warning', 'message' => $customer->phone_verified_at !== null ? 'رقم الموبايل مؤكد.' : 'رقم الموبايل لسه مش مؤكد.'],
            ['key' => 'duplicates_found', 'ok' => $duplicates === [], 'severity' => 'warning', 'message' => $duplicates === [] ? 'مفيش عملاء مشابهين.' : 'في عملاء مشابهين — راجعهم قبل الاعتماد.'],
            ['key' => 'sla_breached', 'ok' => ! ($customer->status === CustomerStatus::Pending && $customer->created_at < now()->subHours($sla)), 'severity' => 'warning', 'message' => ($customer->status === CustomerStatus::Pending && $customer->created_at < now()->subHours($sla)) ? "الطلب أقدم من مهلة المراجعة ($sla ساعة)." : 'الطلب لسه جوه مهلة المراجعة.'],
            ['key' => 'document_types_missing', 'ok' => $missingTypes === [], 'severity' => 'info', 'message' => $missingTypes === [] ? 'نوعا المستندات الأساسيين موجودين.' : 'ناقص مستند: '.implode('، ', array_map(fn ($t) => $typeNames[$t], $missingTypes)).'.'],
        ];
        $steps = [
            'legal_fields' => 'كمّل البيانات القانونية الناقصة قبل الاعتماد.',
            'documents_unverified' => 'راجع المستندات المعلقة: اعتمد السليم، وارفض غير السليم بسبب واضح.',
            'no_verified_document' => 'لازم مستند موثّق واحد على الأقل قبل الاعتماد.',
            'status_not_pending' => 'الطلب مش قيد المراجعة؛ راجع حالته الحالية قبل أي قرار.',
            'phone_unverified' => 'اطلب من التاجر تأكيد رقم موبايله.',
            'duplicates_found' => 'راجع العملاء المشابهين وتأكد إن ده مش حساب مكرر.',
            'sla_breached' => 'الطلب متأخر عن مهلة المراجعة؛ اديله أولوية.',
            'document_types_missing' => 'اطلب المستندات الناقصة من التاجر.',
        ];
        $nextSteps = [];
        foreach ($checks as $check) {
            if (! $check['ok']) {
                $nextSteps[] = $steps[$check['key']];
            }
        }
        $verdict = collect($checks)->contains(fn ($c) => $c['severity'] === 'blocker' && ! $c['ok']) ? 'blocked'
            : (collect($checks)->contains(fn ($c) => $c['severity'] === 'warning' && ! $c['ok']) ? 'needs_attention' : 'ready');

        return ['checks' => $checks, 'verdict' => $verdict, 'next_steps' => $nextSteps];
    }

    /**
     * 1.5: كشف التكرار — تطابق تام ضريبي/سجل، تشابه trigram للاسم (> 0.6)،
     * أو نفس إيميل حساب محذوف. أقصى 5 نتائج، وكل نتيجة معاها reason.
     *
     * @return list<array{id:int,name:array,company_name:array,status:string,type:string,reason:string}>
     */
    private function duplicateCustomers(Customer $customer): array
    {
        $found = [];
        $add = function (Customer $other, string $reason) use (&$found): void {
            if (! isset($found[$other->id])) {
                $found[$other->id] = ['customer' => $other, 'reasons' => []];
            }
            if (! in_array($reason, $found[$other->id]['reasons'], true)) {
                $found[$other->id]['reasons'][] = $reason;
            }
        };
        $id = $customer->id;
        if (trim((string) $customer->tax_number) !== '') {
            Customer::query()->whereKeyNot($id)->whereRaw('TRIM(tax_number) = ?', [trim((string) $customer->tax_number)])->get()->each(fn (Customer $o) => $add($o, 'نفس الرقم الضريبي'));
        }
        if (trim((string) $customer->commercial_register) !== '') {
            Customer::query()->whereKeyNot($id)->whereRaw('TRIM(commercial_register) = ?', [trim((string) $customer->commercial_register)])->get()->each(fn (Customer $o) => $add($o, 'نفس السجل التجاري'));
        }
        if (trim((string) $customer->company_name) !== '') {
            Customer::query()->whereKeyNot($id)->whereRaw('similarity(company_name, ?) > 0.6', [trim((string) $customer->company_name)])->orderByRaw('similarity(company_name, ?) DESC', [trim((string) $customer->company_name)])->get()->each(fn (Customer $o) => $add($o, 'اسم شركة مشابه'));
        }
        if ($customer->email) {
            Customer::onlyTrashed()->whereKeyNot($id)->where('email', mb_strtolower(trim($customer->email)))->get()->each(fn (Customer $o) => $add($o, 'نفس الإيميل (حساب محذوف)'));
        }

        return collect($found)->values()->take(5)->map(fn ($entry) => [
            'id' => $entry['customer']->id,
            'name' => $this->untrusted($entry['customer']->name),
            'company_name' => $this->untrusted($entry['customer']->company_name),
            'status' => $entry['customer']->status->value,
            'type' => $entry['customer']->type->value,
            'reason' => implode('، ', $entry['reasons']),
        ])->all();
    }

    private function customerHistory(array $args): ToolOutcome
    {
        $customer = $this->findCustomer($args);
        if (! $customer) {
            return ToolOutcome::denied('العميل مش موجود.');
        }
        $limit = min(30, max(1, (int) ($args['limit'] ?? 20)));
        $rows = CustomerAuditLog::query()->where('customer_id', $customer->id)->with('actor:id,name')
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (CustomerAuditLog $log) => [
                'action' => $log->action,
                'actor' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->name] : null,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at,
            ])->all();

        return ToolOutcome::data(['customer_id' => $customer->id, 'history' => $rows], ['type' => 'table', 'columns' => ['الإجراء', 'الموظف', 'التاريخ'], 'rows' => array_map(fn ($r) => [$r['action'], $r['actor']['name'] ?? '—', (string) $r['created_at']], $rows)]);
    }

    private function findDuplicateCustomers(array $args): ToolOutcome
    {
        $customer = $this->findCustomer($args);
        if (! $customer) {
            return ToolOutcome::denied('العميل مش موجود.');
        }
        $dups = $this->duplicateCustomers($customer);

        return ToolOutcome::data(['customer_id' => $customer->id, 'duplicates' => $dups], ['type' => 'table', 'columns' => ['الاسم', 'الشركة', 'السبب'], 'rows' => array_map(fn ($d) => [$d['name']['untrusted_text'], $d['company_name']['untrusted_text'], $d['reason']], $dups)]);
    }

    private function listCustomerGroups(array $args): ToolOutcome
    {
        $groups = CustomerGroup::query()->withCount('customers')
            ->when(array_key_exists('active', $args), fn ($x) => $x->where('is_active', $args['active']))
            ->when(trim((string) ($args['query'] ?? '')) !== '', fn ($x) => $x->where(fn ($w) => $w
                ->where('name', 'ilike', SearchTerm::pattern((string) $args['query']))
                ->orWhere('code', 'ilike', SearchTerm::pattern((string) $args['query']))))
            ->orderBy('id')->limit(50)->get();
        $data = $groups->map(fn (CustomerGroup $g) => ['id' => $g->id, 'name' => $g->name, 'code' => $g->code, 'discount_percentage' => (float) $g->discount_percentage, 'is_active' => (bool) $g->is_active, 'customers_count' => $g->customers_count])->all();

        return ToolOutcome::data(['groups' => $data], ['type' => 'table', 'columns' => ['الاسم', 'الكود', 'الخصم', 'العملاء'], 'rows' => $groups->map(fn (CustomerGroup $g) => [$g->name, $g->code, $g->discount_percentage.'%', $g->customers_count])->all()]);
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
            $creditUnderUsed = 0;
            $allSetCustomerGroup = $args['items'] !== [];
            foreach ($args['items'] as $item) {
                $outcome = $this->dispatch($actor, $conv, ['name' => $item['tool'], 'args' => $item['args']]);
                if ($outcome->type !== 'proposal') {
                    AiPendingAction::whereIn('id', $ids)->update(['status' => 'cancelled']);

                    return ToolOutcome::denied($outcome->message ?? 'حدد كل العناصر بوضوح قبل تجهيز العملية الجماعية.');
                }
                $id = $outcome->data['pending_action_id'];
                $ids[] = $id;
                $items[] = ['key' => $id, 'label' => $outcome->uiBlock['title'], 'change' => $item['tool'], 'before' => $outcome->uiBlock['before'] ?? null, 'after' => $outcome->uiBlock['after'] ?? null];
                // 3.2: عدّ عناصر الائتمان اللي بتنزل تحت المستخدم — من
                // preview العنصر نفسه (before: used_credit، after:
                // credit_limit)، مش من إعادة حساب قد تتغير بعد الاقتراح.
                if ($item['tool'] === 'set_credit_limit') {
                    $before = $outcome->uiBlock['before'] ?? [];
                    $after = $outcome->uiBlock['after'] ?? [];
                    if (isset($before['used_credit'], $after['credit_limit']) && (float) $after['credit_limit'] < (float) $before['used_credit']) {
                        $creditUnderUsed++;
                    }
                }
                if ($item['tool'] !== 'set_customer_group') {
                    $allSetCustomerGroup = false;
                }
            }
            // 3.2: لو كل عناصر المجموعة بلا تغيير أصلًا (المجموعة القديمة
            // = المستهدفة في كل عنصر، من inverse المقترح نفسه)، مفيش
            // عملية أصلًا — denied، والعناصر الفرعية تُلغى زي أي فشل prep.
            if ($allSetCustomerGroup && $ids !== []) {
                $noChange = AiPendingAction::whereIn('id', $ids)->get()->every(fn (AiPendingAction $child) => array_key_exists('customer_group_id', $child->inverse ?? []) && (int) $child->inverse['customer_group_id'] === (int) ($child->args['customer_group_id'] ?? -1) && (int) ($child->args['customer_group_id'] ?? -1) !== -1);
                if ($noChange) {
                    AiPendingAction::whereIn('id', $ids)->update(['status' => 'cancelled']);

                    return ToolOutcome::denied('مفيش تغيير.');
                }
            }
            $warnings = [];
            // 3.2: تحذير مجمّع في أعلى الكارت لما أكتر من 30% من كل
            // العناصر بتنزل حد الائتمان تحت المستخدم.
            if ($creditUnderUsed > 0 && $ids !== [] && $creditUnderUsed / count($ids) > 0.3) {
                $warnings[] = 'تحذير مجمّع: '.$creditUnderUsed.' من '.count($ids).' عناصر بيقللوا حد الائتمان تحت الرصيد المستخدم — التجار دول هيبقوا فوق الحد فورًا، وطلباتهم الآجلة الجاية هتترفض.';
            }
            $warnings[] = 'كل عنصر بيتنفذ لوحده. راجع التغييرات واستبعد أي عنصر مش مطلوب.';
            $outcome = $this->makeProposal($actor, $conv, 'bulk_actions', ['action_ids' => $ids], [], ['title' => 'مراجعة '.count($ids).' عمليات', 'items' => $items, 'warnings' => $warnings], null, 'high');
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
        // 2.3: أدوات العملاء عالية الخطورة — التأكيد بآخر 4 أرقام من
        // موبايل التاجر (يُشتق من قاعدة البيانات وقت الاقتراح، ويُعاد
        // اشتقاقه وقت التأكيد في confirmLocked).
        $typedHint = null;
        if (in_array($tool, ['approve_dealer_application', 'reject_dealer_application', 'suspend_customer', 'set_credit_limit'], true) && isset($args['customer_id'])) {
            $phone = Customer::withTrashed()->find($args['customer_id'])?->phone;
            $typed = $phone ? substr((string) $phone, -4) : null;
            $typedHint = 'آخر 4 أرقام من موبايل التاجر';
        }
        $block = ['type' => 'confirm_action', 'action_id' => $action->id, 'tool' => $tool, 'risk' => $risk, 'title' => $preview['title'] ?? $tool, 'before' => $preview['before'] ?? null, 'after' => $preview['after'] ?? null, 'warnings' => $preview['warnings'] ?? [], 'expires_at' => $action->expires_at->toIso8601String()];
        if ($risk === 'high' && $typed) {
            $block['typed_confirmation'] = $typed;
            if ($typedHint) {
                // الواجهة تعرض التعليمة بس (من غير الرقم) — القيمة تفضل
                // للمقارنة في حقل typed_confirmation، والزر يتقفل لحد ما
                // المدخل يطابقه.
                $block['typed_confirmation_hint'] = $typedHint;
            }
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
            'create_customer' => $this->prepCreateCustomer($actor, $conv, $args),
            'update_customer_profile' => $this->prepUpdateCustomerProfile($actor, $conv, $args),
            'set_customer_group' => $this->prepSetCustomerGroup($actor, $conv, $args),
            'set_credit_limit' => $this->prepSetCreditLimit($actor, $conv, $args),
            'review_customer_document' => $this->prepReviewCustomerDocument($actor, $conv, $args),
            'approve_dealer_application' => $this->prepApproveDealerApplication($actor, $conv, $args),
            'reject_dealer_application' => $this->prepRejectDealerApplication($actor, $conv, $args),
            'suspend_customer' => $this->prepSuspendCustomer($actor, $conv, $args),
            'reactivate_customer' => $this->prepReactivateCustomer($actor, $conv, $args),
            'restore_customer' => $this->prepRestoreCustomer($actor, $conv, $args),
            'create_customer_group' => $this->prepCreateCustomerGroup($actor, $conv, $args),
            'update_customer_group' => $this->prepUpdateCustomerGroup($actor, $conv, $args),
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

    // ── P04-2: prep أدوات كتابة العملاء ────────────────────────────────────

    private function prepCreateCustomer(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        $type = $args['type'] ?? CustomerType::Retail->value;
        $data = [
            'name' => (string) $args['name'],
            'phone' => (string) $args['phone'],
            'email' => $args['email'] ?? null,
            'type' => $type,
            'company_name' => $args['company_name'] ?? null,
            'tax_number' => $args['tax_number'] ?? null,
            'commercial_register' => $args['commercial_register'] ?? null,
        ];
        $warnings = [];
        if (in_array($type, [CustomerType::Wholesale->value, CustomerType::Distributor->value], true)) {
            $warnings[] = 'هيتسجل قيد المراجعة.';
        }
        $risk = in_array($type, [CustomerType::Wholesale->value, CustomerType::Distributor->value], true) ? 'medium' : 'low';

        return $this->makeProposal($actor, $conv, 'create_customer', $data, [], ['title' => 'إنشاء عميل '.$data['name'], 'after' => $data, 'warnings' => $warnings], null, $risk);
    }

    private function prepUpdateCustomerProfile(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        $customer = $this->findCustomer($args);
        if (! $customer) {
            return ToolOutcome::denied('العميل مش موجود.');
        }
        $data = ['customer_id' => $customer->id];
        $before = [];
        $after = [];
        foreach (['name', 'email', 'phone', 'company_name', 'tax_number', 'commercial_register'] as $field) {
            if (array_key_exists($field, $args) && $args[$field] != $customer->{$field}) {
                $data[$field] = $args[$field];
                $before[$field] = $customer->{$field};
                $after[$field] = $args[$field];
            }
        }
        if ($before === []) {
            return ToolOutcome::denied('مفيش حقول للتعديل.');
        }
        $legalChanged = (bool) array_intersect_key($data, array_flip(['company_name', 'tax_number', 'commercial_register']));
        if ($legalChanged && ! $actor->can('customers.approve')) {
            return ToolOutcome::denied('تعديل البيانات القانونية محتاج صلاحية customers.approve.');
        }
        $warnings = [];
        $credentialsChanged = collect(['email', 'phone'])->contains(fn ($f) => array_key_exists($f, $data));
        if ($credentialsChanged) {
            $warnings[] = 'هيتم تسجيل خروج التاجر من كل الأجهزة وإلغاء توثيق الموبايل.';
        }
        $risk = 'medium';
        if ($legalChanged && $customer->status === CustomerStatus::Approved) {
            $warnings[] = 'تغيير الهوية القانونية لتاجر معتمد.';
            $risk = 'high';
        }

        return $this->makeProposal($actor, $conv, 'update_customer_profile', $data, ['customer:'.$customer->id => $this->customerVersion($customer)], ['title' => 'تعديل بيانات '.$customer->name, 'before' => $before, 'after' => $after, 'warnings' => $warnings], $before, $risk);
    }

    private function prepSetCustomerGroup(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        $customer = $this->findCustomer($args);
        if (! $customer) {
            return ToolOutcome::denied('العميل مش موجود.');
        }
        $group = CustomerGroup::find((int) $args['customer_group_id']);
        if (! $group) {
            return ToolOutcome::denied('المجموعة مش موجودة.');
        }
        $warnings = ! $group->is_active ? ['المجموعة مش مفعلة.'] : [];

        return $this->makeProposal($actor, $conv, 'set_customer_group', ['customer_id' => $customer->id, 'customer_group_id' => $group->id], ['customer:'.$customer->id => $this->customerVersion($customer)], ['title' => 'تعيين مجموعة '.$customer->name, 'before' => ['customer_group' => $customer->customerGroup?->name], 'after' => ['customer_group' => $group->name], 'warnings' => $warnings], ['customer_group_id' => $customer->customer_group_id], 'medium');
    }

    private function prepSetCreditLimit(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        $customer = $this->findCustomer($args);
        if (! $customer) {
            return ToolOutcome::denied('العميل مش موجود.');
        }
        $current = (float) $customer->credit_limit;
        $new = (float) $args['credit_limit'];
        $used = (float) app(WholesalePaymentNoticeService::class)->outstandingBalance($customer);
        $threshold = (float) $this->settings->get('ai_credit_limit_confirm_threshold', 100000);
        $warnings = [];
        if ($new < $used) {
            $warnings[] = 'التاجر هيبقى فوق الحد فورًا، وطلباته الآجلة الجاية هتترفض.';
        }
        $increase = $current > 0 ? ($new - $current) / $current : ($new > 0 ? INF : 0.0);
        $risk = ($new > $threshold || $increase > 1.0) ? 'high' : 'medium';

        return $this->makeProposal($actor, $conv, 'set_credit_limit', ['customer_id' => $customer->id, 'credit_limit' => $args['credit_limit']], ['customer:'.$customer->id => $this->customerVersion($customer)], ['title' => 'تعيين حد ائتمان '.$customer->name, 'before' => ['credit_limit' => $current, 'used_credit' => $used], 'after' => ['credit_limit' => $new, 'used_credit' => $used], 'warnings' => $warnings], ['credit_limit' => $customer->credit_limit], $risk);
    }

    private function prepReviewCustomerDocument(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        $customer = $this->findCustomer($args);
        if (! $customer) {
            return ToolOutcome::denied('العميل مش موجود.');
        }
        $doc = $customer->documents()->whereKey((int) $args['document_id'])->first();
        if (! $doc) {
            return ToolOutcome::denied('المستند مش تابع للعميل ده.');
        }
        $status = (string) $args['status'];

        return $this->makeProposal($actor, $conv, 'review_customer_document', ['customer_id' => $customer->id, 'document_id' => $doc->id, 'status' => $status, 'note' => $args['note'] ?? null], ['customer:'.$customer->id => $this->customerVersion($customer), 'customer_document:'.$doc->id => $this->documentVersion($doc)], ['title' => ($status === CustomerDocumentStatus::Verified->value ? 'توثيق مستند ' : 'رفض مستند ').$customer->name, 'before' => ['status' => $doc->status->value], 'after' => ['status' => $status], 'warnings' => []], ['status' => $doc->status->value], 'low');
    }

    private function prepApproveDealerApplication(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        $customer = $this->findCustomer($args);
        if (! $customer) {
            return ToolOutcome::denied('العميل مش موجود.');
        }
        $customer->load('documents');
        $verifyIds = array_values(array_unique(array_map('intval', $args['verify_document_ids'] ?? [])));
        sort($verifyIds);
        $docs = $customer->documents->whereIn('id', $verifyIds);
        if ($docs->count() !== count($verifyIds)) {
            return ToolOutcome::denied('في مستند مش تابع للعميل ده.');
        }
        $review = $this->reviewCustomer($customer, $verifyIds);
        if ($customer->documents->contains(fn ($d) => $d->status === CustomerDocumentStatus::Rejected && ! in_array($d->id, $verifyIds, true))) {
            return ToolOutcome::denied('فيه مستند مرفوض، لازم يتراجع أو يترفع بديل الأول.', auditable: false);
        }
        $blockers = collect($review['checks'])->filter(fn ($c) => $c['severity'] === 'blocker' && ! $c['ok'])->values();
        if ($blockers->isNotEmpty()) {
            return ToolOutcome::denied('الاعتماد متعطل: '.implode(' ', $blockers->map(fn ($c) => $c['message'])->all()), auditable: false);
        }
        $warnings = collect($review['checks'])->filter(fn ($c) => $c['severity'] === 'warning' && ! $c['ok'])->map(fn ($c) => $c['message'])->values()->all();
        $data = ['customer_id' => $customer->id];
        if ($verifyIds !== []) {
            $data['verify_document_ids'] = $verifyIds;
        }
        if (isset($args['customer_group_id'])) {
            $data['customer_group_id'] = (int) $args['customer_group_id'];
        }
        if (isset($args['credit_limit'])) {
            $data['credit_limit'] = $args['credit_limit'];
        }
        if (isset($args['type'])) {
            $data['type'] = $args['type'];
        }
        $group = isset($data['customer_group_id']) ? CustomerGroup::find($data['customer_group_id']) : $customer->customerGroup;
        $expected = ['customer:'.$customer->id => $this->customerVersion($customer)];
        foreach ($verifyIds as $id) {
            $expected['customer_document:'.$id] = $this->documentVersion($docs->firstWhere('id', $id));
        }
        $company = ['name' => $customer->name, 'company_name' => $customer->company_name, 'tax_number' => $customer->tax_number, 'commercial_register' => $customer->commercial_register, 'phone' => $customer->phone, 'email' => $customer->email];

        return $this->makeProposal($actor, $conv, 'approve_dealer_application', $data, $expected, ['title' => 'اعتماد تاجر '.($customer->company_name ?? $customer->name), 'before' => ['status' => $customer->status->value, 'company' => $company, 'customer_group' => $customer->customerGroup?->name, 'credit_limit' => (float) $customer->credit_limit], 'after' => ['status' => CustomerStatus::Approved->value, 'company' => $company, 'documents_to_verify' => $docs->map(fn ($d) => ['id' => $d->id, 'type' => $d->document_type->value])->values()->all(), 'customer_group' => $group ? ['id' => $group->id, 'name' => $group->name, 'discount_percentage' => $group->discount_percentage] : null, 'credit_limit' => isset($data['credit_limit']) ? (float) $data['credit_limit'] : (float) $customer->credit_limit], 'warnings' => $warnings], null, 'high');
    }

    private function prepRejectDealerApplication(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        $customer = $this->findCustomer($args);
        if (! $customer) {
            return ToolOutcome::denied('العميل مش موجود.');
        }
        $rejectIds = array_values(array_unique(array_map('intval', $args['reject_document_ids'] ?? [])));
        sort($rejectIds);
        if ($rejectIds !== []) {
            $count = $customer->documents()->whereIn('id', $rejectIds)->count();
            if ($count !== count($rejectIds)) {
                return ToolOutcome::denied('في مستند مش تابع للعميل ده.');
            }
        }
        $notes = $args['document_notes'] ?? [];
        foreach ($notes as $note) {
            if (! is_string($note) || mb_strlen($note) > 500) {
                return ToolOutcome::denied('ملاحظة المستند لازم تكون نصًا بحد أقصى 500 حرف.');
            }
        }
        $data = ['customer_id' => $customer->id, 'reason' => $args['reason']];
        if ($rejectIds !== []) {
            $data['reject_document_ids'] = $rejectIds;
        }
        if ($notes !== []) {
            $data['document_notes'] = $notes;
        }
        $expected = ['customer:'.$customer->id => $this->customerVersion($customer)];
        foreach ($rejectIds as $id) {
            $expected['customer_document:'.$id] = $this->documentVersion($customer->documents()->whereKey($id)->firstOrFail());
        }

        return $this->makeProposal($actor, $conv, 'reject_dealer_application', $data, $expected, ['title' => 'رفض طلب '.($customer->company_name ?? $customer->name), 'before' => ['status' => $customer->status->value], 'after' => ['status' => CustomerStatus::Rejected->value, 'rejection_reason' => $args['reason'], 'documents_to_reject' => $rejectIds], 'warnings' => []], null, 'high');
    }

    private function prepSuspendCustomer(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        $customer = $this->findCustomer($args);
        if (! $customer) {
            return ToolOutcome::denied('العميل مش موجود.');
        }

        return $this->makeProposal($actor, $conv, 'suspend_customer', ['customer_id' => $customer->id, 'reason' => $args['reason']], ['customer:'.$customer->id => $this->customerVersion($customer)], ['title' => 'إيقاف حساب '.$customer->name, 'before' => ['status' => $customer->status->value], 'after' => ['status' => CustomerStatus::Suspended->value], 'warnings' => ['هيتم إنهاء كل جلسات التاجر فورًا، والتراجع هيرجّع الحالة بس، مش الجلسات.']], ['suspension_reason' => null, 'previous_status' => $customer->status->value], 'high');
    }

    private function prepReactivateCustomer(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        $customer = $this->findCustomer($args);
        if (! $customer) {
            return ToolOutcome::denied('العميل مش موجود.');
        }
        if ($customer->status !== CustomerStatus::Suspended) {
            return ToolOutcome::denied('الحساب مش موقوف.');
        }

        return $this->makeProposal($actor, $conv, 'reactivate_customer', ['customer_id' => $customer->id], ['customer:'.$customer->id => $this->customerVersion($customer)], ['title' => 'إعادة تفعيل '.$customer->name, 'before' => ['status' => CustomerStatus::Suspended->value], 'after' => ['status' => $customer->approved_at ? CustomerStatus::Approved->value : CustomerStatus::Pending->value], 'warnings' => []], ['suspension_reason' => $customer->suspension_reason], 'medium');
    }

    private function prepRestoreCustomer(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        $customer = Customer::withTrashed()->find((int) $args['customer_id']);
        if (! $customer) {
            return ToolOutcome::denied('العميل مش موجود.');
        }
        if (! $customer->trashed()) {
            return ToolOutcome::denied('الحساب غير محذوف.');
        }

        return $this->makeProposal($actor, $conv, 'restore_customer', ['customer_id' => $customer->id], ['customer:'.$customer->id => $this->customerVersion($customer)], ['title' => 'استعادة حساب '.$customer->name, 'before' => ['deleted' => true], 'after' => ['deleted' => false], 'warnings' => []], null, 'medium');
    }

    private function prepCreateCustomerGroup(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        try {
            $this->branches->assertCentralAccess($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('إدارة مجموعات العملاء محتاجة وصول مركزي.');
        }
        $data = ['name' => (string) $args['name'], 'code' => (string) $args['code']];
        foreach (['description', 'discount_percentage', 'is_active'] as $field) {
            if (array_key_exists($field, $args)) {
                $data[$field] = $args[$field];
            }
        }

        return $this->makeProposal($actor, $conv, 'create_customer_group', $data, [], ['title' => 'إنشاء مجموعة '.$data['name'], 'after' => $data, 'warnings' => []], null, 'low');
    }

    private function prepUpdateCustomerGroup(User $actor, AiConversation $conv, array $args): ToolOutcome
    {
        try {
            $this->branches->assertCentralAccess($actor);
        } catch (\Throwable) {
            return ToolOutcome::denied('إدارة مجموعات العملاء محتاجة وصول مركزي.');
        }
        $group = CustomerGroup::find((int) $args['customer_group_id']);
        if (! $group) {
            return ToolOutcome::denied('المجموعة مش موجودة.');
        }
        $data = ['customer_group_id' => $group->id];
        $before = [];
        $after = [];
        foreach (['name', 'code', 'description', 'discount_percentage', 'is_active'] as $field) {
            if (! array_key_exists($field, $args)) {
                continue;
            }
            $changed = match ($field) {
                'discount_percentage' => (float) $args[$field] !== (float) $group->{$field},
                'is_active' => (bool) $args[$field] !== (bool) $group->{$field},
                default => $args[$field] != $group->{$field},
            };
            if ($changed) {
                $data[$field] = $args[$field];
                $before[$field] = $group->{$field};
                $after[$field] = $args[$field];
            }
        }
        if ($before === []) {
            return ToolOutcome::denied('مفيش حقول للتعديل.');
        }
        $discountChanged = array_key_exists('discount_percentage', $data);
        $hasCustomers = $group->customers()->exists();
        $risk = ($discountChanged && $hasCustomers) ? 'high' : 'medium';
        $warnings = ($discountChanged && $hasCustomers) ? ['المجموعة فيها عملاء — تغيير الخصم هيأثر عليهم.'] : [];

        return $this->makeProposal($actor, $conv, 'update_customer_group', $data, ['customer_group:'.$group->id => $this->customerGroupVersion($group)], ['title' => 'تعديل مجموعة '.$group->name, 'before' => $before, 'after' => $after, 'warnings' => $warnings], $before, $risk);
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
                'create_customer' => $this->execCreateCustomer($actor, $args, $via, $action),
                'update_customer_profile' => $this->execUpdateCustomerProfile($actor, $args, $via, $action),
                'set_customer_group' => $this->execSetCustomerGroup($actor, $args, $via, $action),
                'set_credit_limit' => $this->execSetCreditLimit($actor, $args, $via, $action),
                'review_customer_document' => $this->execReviewCustomerDocument($actor, $args, $via, $action),
                'approve_dealer_application' => $this->execApproveDealerApplication($actor, $args, $via, $action),
                'reject_dealer_application' => $this->execRejectDealerApplication($actor, $args, $via, $action),
                'suspend_customer' => $this->execSuspendCustomer($actor, $args, $via, $action),
                'reactivate_customer' => $this->execReactivateCustomer($actor, $args, $via, $action),
                'restore_customer' => $this->execRestoreCustomer($actor, $args, $via, $action),
                'create_customer_group' => $this->execCreateCustomerGroup($actor, $args, $via, $action),
                'update_customer_group' => $this->execUpdateCustomerGroup($actor, $args, $via, $action),
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
        // 2.2: ممنوعات المساعد الصريحة — لا تعتمد على غياب الأداة من الـ
        // registry، لأن prepareWrite/executeWrite ممكن يتنده عليهم مباشرة.
        if ($action->tool === 'delete_customer') {
            abort(403, 'حذف العملاء من المساعد ممنوع؛ استخدم شاشة العملاء.');
        }
        if ($action->tool === 'update_customer_profile' && (isset($args['password']) || isset($args['type']) || isset($args['status']))) {
            abort(403, 'تغيير الباسورد أو النوع أو الحالة من المساعد ممنوع.');
        }
        if (in_array($action->tool, ['create_customer_group', 'update_customer_group'], true)) {
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
        } elseif (str_starts_with($key, 'customer_document:')) {
            abort_if($this->documentVersion($model) !== $expected, 409, 'حد عدّل السجل ده بعد الاقتراح.');
        } elseif (str_starts_with($key, 'customer_group:')) {
            abort_if($this->customerGroupVersion($model) !== $expected, 409, 'حد عدّل السجل ده بعد الاقتراح.');
        } elseif (str_starts_with($key, 'customer:')) {
            abort_if($this->customerVersion($model) !== $expected, 409, 'حد عدّل السجل ده بعد الاقتراح.');
        } else {
            abort_if((int) $model->lock_version !== $expected, 409, 'حد عدّل السجل ده بعد الاقتراح.');
        }
    }

    private function branchVersion(Branch $branch): string
    {
        return $this->hashArgs($branch->getRawOriginal());
    }

    /**
     * 0.6: بصمة نسخة للعميل — حقول مستقرة فقط. wallet_balance وupdated_at
     * متعمد غيابهما: بيتغيروا مع كل طلب/حركة محفظة، ولو دخلوا في البصمة
     * كل كارت تأكيد كان هيتلغي بـ 409 من غير سبب حقيقي.
     * 2.3: name دخلت مع أدوات الكتابة — update_customer_profile بيكتبها،
     * وتعديلها من REST بين الاقتراح والتأكيد لازم يرفض بـ 409.
     */
    private const CUSTOMER_VERSION_FIELDS = [
        'name', 'status', 'type', 'customer_group_id', 'credit_limit', 'company_name',
        'tax_number', 'commercial_register', 'email', 'phone', 'deleted_at',
        'rejection_reason', 'suspension_reason',
    ];

    private function customerVersion(Customer $c): string
    {
        return $this->hashArgs(Arr::only($c->getRawOriginal(), self::CUSTOMER_VERSION_FIELDS));
    }

    private function documentVersion(CustomerDocument $d): string
    {
        return $this->hashArgs(Arr::only($d->getRawOriginal(), ['status', 'reviewed_at', 'customer_id']));
    }

    private function customerGroupVersion(CustomerGroup $g): string
    {
        return $this->hashArgs(Arr::only($g->getRawOriginal(), ['name', 'code', 'discount_percentage', 'is_active']));
    }

    public function lockTargets(AiPendingAction $action): void
    {
        // 2.3: ترتيب قفل ثابت — العميل أولًا ثم مستنداته (رقميًا) ثم
        // المجموعة — يمنع الـ deadlock بين عمليتين متزامنتين على نفس
        // العميل، وهو نفس الترتيب اللي reviewDocument بيستخدمه.
        $keys = array_keys($action->expected_versions ?? []);
        $rank = ['customer' => 0, 'customer_document' => 1, 'customer_group' => 2, 'branch' => 3, 'user' => 4, 'role' => 5, 'location' => 6];
        usort($keys, function ($a, $b) use ($rank) {
            [$ta, $ia] = explode(':', $a, 2);
            [$tb, $ib] = explode(':', $b, 2);

            return [$rank[$ta] ?? 99, (int) $ia] <=> [$rank[$tb] ?? 99, (int) $ib];
        });
        foreach ($keys as $key) {
            $version = $action->expected_versions[$key];
            [$type, $id] = explode(':', $key, 2);
            $model = match ($type) {
                'branch' => Branch::class, 'user' => User::class, 'role' => Role::class, 'location' => Location::class,
                'customer' => Customer::class, 'customer_document' => CustomerDocument::class, 'customer_group' => CustomerGroup::class,
                default => null
            };
            abort_unless($model, 409);
            $record = $type === 'customer'
                ? Customer::withTrashed()->lockForUpdate()->find($id)
                : $model::query()->lockForUpdate()->find($id);
            abort_unless($record, 409, 'السجل اتحذف.');
            if ($action->status === 'pending') {
                $this->checkVersion($key, $version, $record);
            }
        }
    }

    public function canUndo(AiPendingAction $action): bool
    {
        return $action->inverse && in_array($action->tool, ['set_branch_status', 'update_role_permissions', 'set_user_status', 'assign_roles', 'set_location_status', 'update_customer_profile', 'set_customer_group', 'set_credit_limit', 'review_customer_document', 'suspend_customer', 'reactivate_customer', 'update_customer_group'], true);
    }

    public function targetState(AiPendingAction $action): string
    {
        $args = $action->args;
        if (in_array($action->tool, ['update_customer_profile', 'set_customer_group', 'set_credit_limit', 'suspend_customer', 'reactivate_customer'], true) && isset($args['customer_id'])) {
            return $this->customerVersion(Customer::withTrashed()->findOrFail($args['customer_id']));
        }
        if ($action->tool === 'review_customer_document' && isset($args['document_id'])) {
            return $this->documentVersion(CustomerDocument::findOrFail($args['document_id']));
        }
        if ($action->tool === 'update_customer_group' && isset($args['customer_group_id'])) {
            return $this->customerGroupVersion(CustomerGroup::findOrFail($args['customer_group_id']));
        }
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
        abort_unless((bool) $branch->is_active, 422, 'لا يمكن إنشاء موقع داخل فرع متوقف.');

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
        try {
            // H3: مصدر واحد لقاعدة "لا تعطيل لموقع عليه رصيد" عبر SetLocationStatus.
            $location = app(SetLocationStatus::class)(
                $actor, Location::findOrFail($args['location_id']), (bool) $args['active'], $via);
        } catch (QueryException $e) {
            report($e);

            return ToolOutcome::result(false, 'تعذّر تحديث الموقع؛ حاول مرة أخرى.');
        }

        return ToolOutcome::result(true, $args['active'] ? 'تم تفعيل الموقع.' : 'تم تعطيل الموقع.',
            ['location_id' => $location->id],
            ['type' => 'result', 'ok' => true, 'message' => 'تم تحديث حالة الموقع.',
                'deep_link' => '/branches?branchId='.$location->branch_id]);
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
                // L-6: صفوف الأرصدة تُقفل قبل صف الموقع (ترتيب lockLevels).
                StockLevel::query()->where('location_id', $args['location_id'])
                    ->orderBy('branch_id')
                    ->orderByRaw('location_id ASC NULLS FIRST')
                    ->orderBy('product_variant_id')
                    ->lockForUpdate()
                    ->get();
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
        } catch (QueryException $e) {
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
            'shift', 'pos_invoice', 'expense', 'treasury_transfer', 'payment_notice', 'movement'];

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
            array_keys((new UpdateSettingsRequest)->rules()),
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
        // L-2: فحص ai_agent.settings كان في prep فقط؛ لو الصلاحية
        // اتسحبت خلال الـ TTL (10 دقائق) كان التنفيذ يكمل عادي.
        // إعادة الفحص هنا قبل أي كتابة.
        $aiKeys = array_filter(array_keys($args['changes'] ?? []), fn ($k) => str_starts_with($k, 'ai_'));
        if ($aiKeys !== [] && ! $actor->can('ai_agent.settings')) {
            return ToolOutcome::result(false, 'تعديل إعدادات الذكاء الاصطناعي محتاج صلاحية ai_agent.settings.');
        }
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

    // ── P04-2: exec أدوات كتابة العملاء ─────────────────────────────────────
    // كلها تمر على CustomerManagementService/CustomerGroupService زي
    // الـ REST تمامًا (نفس القواعد، نفس التدقيق، نفس الإشعارات)، وتُنفذ
    // داخل transaction التأكيد — فأي فشل يرجّع كل الخطوات.

    private function execCreateCustomer(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $plain = Str::password(16);
        $data = Arr::only($args, ['name', 'phone', 'email', 'type', 'company_name', 'tax_number', 'commercial_register']);
        $data['password'] = $plain;
        $data['status'] = in_array($data['type'] ?? CustomerType::Retail->value, [CustomerType::Wholesale->value, CustomerType::Distributor->value], true)
            ? CustomerStatus::Pending->value : CustomerStatus::Approved->value;
        $customer = app(CustomerManagementService::class)->create($data, $actor->id, $via);
        $action->forceFill(['inverse' => ['customer_id' => $customer->id]])->save();

        // الباسورد يظهر مرة واحدة في رد التأكيد فقط (يُشال من النتيجة
        // المخزنة ومن حدث المحادثة في confirmLocked، زي create_staff_user).
        return ToolOutcome::result(true, 'تم إنشاء العميل.', ['customer_id' => $customer->id, 'generated_password' => $plain], ['type' => 'result', 'ok' => true, 'message' => 'تم إنشاء العميل. الباسورد المؤقت (يظهر مرة واحدة): '.$plain]);
    }

    private function execUpdateCustomerProfile(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $customer = Customer::findOrFail($args['customer_id']);
        app(CustomerManagementService::class)->update($customer, Arr::only($args, ['name', 'email', 'phone', 'company_name', 'tax_number', 'commercial_register']), $actor->id, $via);

        return ToolOutcome::result(true, 'تم تعديل بيانات العميل.', ['customer_id' => $customer->id], ['type' => 'result', 'ok' => true, 'message' => 'تم تعديل بيانات العميل.']);
    }

    private function execSetCustomerGroup(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $customer = Customer::findOrFail($args['customer_id']);
        app(CustomerManagementService::class)->update($customer, ['customer_group_id' => $args['customer_group_id']], $actor->id, $via);

        return ToolOutcome::result(true, 'تم تعيين مجموعة العميل.', ['customer_id' => $customer->id], ['type' => 'result', 'ok' => true, 'message' => 'تم تعيين مجموعة العميل.']);
    }

    private function execSetCreditLimit(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $customer = Customer::findOrFail($args['customer_id']);
        app(CustomerManagementService::class)->update($customer, ['credit_limit' => $args['credit_limit']], $actor->id, $via);

        return ToolOutcome::result(true, 'تم تعيين حد الائتمان.', ['customer_id' => $customer->id], ['type' => 'result', 'ok' => true, 'message' => 'تم تعيين حد الائتمان.']);
    }

    private function execReviewCustomerDocument(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $customer = Customer::findOrFail($args['customer_id']);
        $doc = $customer->documents()->whereKey($args['document_id'])->firstOrFail();
        app(CustomerManagementService::class)->reviewDocument($customer, $doc, $args['status'], $actor->id, $args['note'] ?? null, $via);

        return ToolOutcome::result(true, 'تمت مراجعة المستند.', ['customer_id' => $customer->id, 'document_id' => $doc->id], ['type' => 'result', 'ok' => true, 'message' => 'تمت مراجعة المستند.']);
    }

    private function execApproveDealerApplication(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $service = app(CustomerManagementService::class);
        $customer = Customer::findOrFail($args['customer_id']);
        foreach ($args['verify_document_ids'] ?? [] as $docId) {
            $doc = $customer->documents()->whereKey($docId)->firstOrFail();
            $service->reviewDocument($customer, $doc, CustomerDocumentStatus::Verified->value, $actor->id, null, $via);
        }
        $data = [];
        foreach (['customer_group_id', 'credit_limit', 'type'] as $field) {
            if (array_key_exists($field, $args)) {
                $data[$field] = $args[$field];
            }
        }
        $service->approve($customer, $data, $actor->id, $via);

        return ToolOutcome::result(true, 'تم اعتماد التاجر.', ['customer_id' => $customer->id], ['type' => 'result', 'ok' => true, 'message' => 'تم اعتماد التاجر.']);
    }

    private function execRejectDealerApplication(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $service = app(CustomerManagementService::class);
        $customer = Customer::findOrFail($args['customer_id']);
        $notes = $args['document_notes'] ?? [];
        foreach ($args['reject_document_ids'] ?? [] as $docId) {
            $doc = $customer->documents()->whereKey($docId)->firstOrFail();
            $note = $notes[$docId] ?? $notes[(string) $docId] ?? null;
            $service->reviewDocument($customer, $doc, CustomerDocumentStatus::Rejected->value, $actor->id, $note, $via);
        }
        $service->reject($customer, $args['reason'], $actor->id, $via);

        return ToolOutcome::result(true, 'تم رفض الطلب.', ['customer_id' => $customer->id], ['type' => 'result', 'ok' => true, 'message' => 'تم رفض الطلب.']);
    }

    private function execSuspendCustomer(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $customer = Customer::findOrFail($args['customer_id']);
        app(CustomerManagementService::class)->suspend($customer, $args['reason'], $actor->id, $via);

        return ToolOutcome::result(true, 'تم إيقاف الحساب.', ['customer_id' => $customer->id], ['type' => 'result', 'ok' => true, 'message' => 'تم إيقاف الحساب وإنهاء جلساته.']);
    }

    private function execReactivateCustomer(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $customer = Customer::findOrFail($args['customer_id']);
        app(CustomerManagementService::class)->reactivate($customer, $actor->id, $via);

        return ToolOutcome::result(true, 'تمت إعادة التفعيل.', ['customer_id' => $customer->id], ['type' => 'result', 'ok' => true, 'message' => 'تمت إعادة تفعيل الحساب.']);
    }

    private function execRestoreCustomer(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $customer = Customer::withTrashed()->findOrFail($args['customer_id']);
        app(CustomerManagementService::class)->restore($customer, $actor->id, $via);

        return ToolOutcome::result(true, 'تمت استعادة الحساب.', ['customer_id' => $customer->id], ['type' => 'result', 'ok' => true, 'message' => 'تمت استعادة الحساب.']);
    }

    private function execCreateCustomerGroup(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $data = Arr::only($args, ['name', 'code', 'description', 'discount_percentage', 'is_active']);
        $group = app(CustomerGroupService::class)->create($data);
        StaffAdministration::audit($actor, 'customer_group.created', 'customer_group', $group->id, array_keys($data), [], $data, $via);

        return ToolOutcome::result(true, 'تم إنشاء المجموعة.', ['customer_group_id' => $group->id], ['type' => 'result', 'ok' => true, 'message' => 'تم إنشاء المجموعة.']);
    }

    private function execUpdateCustomerGroup(User $actor, array $args, array $via, AiPendingAction $action): ToolOutcome
    {
        $group = CustomerGroup::findOrFail($args['customer_group_id']);
        $data = Arr::only($args, ['name', 'code', 'description', 'discount_percentage', 'is_active']);
        app(CustomerGroupService::class)->update($group, $data);
        StaffAdministration::audit($actor, 'customer_group.updated', 'customer_group', $group->id, array_keys($data), $action->inverse ?? [], $data, $via);

        return ToolOutcome::result(true, 'تم تعديل المجموعة.', ['customer_group_id' => $group->id], ['type' => 'result', 'ok' => true, 'message' => 'تم تعديل المجموعة.']);
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
                // H3: التراجع يمر بنفس القاعدة المشتركة (مع قفل الصف داخل الـ action).
                app(SetLocationStatus::class)(
                    $actor, Location::findOrFail($args['location_id']), (bool) $inv['is_active'], $via);

                return ToolOutcome::result(true, 'تم التراجع.');
            }
            // ── P04-2: تراجع أدوات العملاء ───────────────────────────────
            if (in_array($tool, ['update_customer_profile', 'set_customer_group', 'set_credit_limit'], true) && $inv !== []) {
                $c = Customer::findOrFail($args['customer_id']);
                app(CustomerManagementService::class)->update($c, $inv, $actor->id, $via);

                return ToolOutcome::result(true, 'تم التراجع.');
            }
            if ($tool === 'review_customer_document' && isset($inv['status'])) {
                $c = Customer::findOrFail($args['customer_id']);
                $d = $c->documents()->whereKey($args['document_id'])->firstOrFail();
                app(CustomerManagementService::class)->reviewDocument($c, $d, $inv['status'], $actor->id, null, $via);

                return ToolOutcome::result(true, 'تم التراجع.');
            }
            if ($tool === 'suspend_customer') {
                // التراجع عن الإيقاف = إعادة تفعيل (ترجع معتمدًا لو كان
                // معتمدًا قبل الإيقاف، وإلا قيد المراجعة)؛ الجلسات المنهية
                // لا ترجع — التحذير ده كان في كارت التأكيد نفسه.
                $c = Customer::findOrFail($args['customer_id']);
                app(CustomerManagementService::class)->reactivate($c, $actor->id, $via);

                return ToolOutcome::result(true, 'تم التراجع.');
            }
            if ($tool === 'reactivate_customer' && array_key_exists('suspension_reason', $inv)) {
                $c = Customer::findOrFail($args['customer_id']);
                app(CustomerManagementService::class)->suspend($c, $inv['suspension_reason'] ?? 'إيقاف من المساعد (تراجع عن إعادة التفعيل)', $actor->id, $via);

                return ToolOutcome::result(true, 'تم التراجع.');
            }
            if ($tool === 'update_customer_group' && $inv !== []) {
                $g = CustomerGroup::findOrFail($args['customer_group_id']);
                app(CustomerGroupService::class)->update($g, $inv);

                return ToolOutcome::result(true, 'تم التراجع.');
            }

            return ToolOutcome::result(false, 'العملية دي مفيهاش تراجع تلقائي.');
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() === 409) {
                throw $e;
            }

            return ToolOutcome::result(false, $e->getMessage() ?: 'فشل التراجع.');
        } catch (QueryException $e) {
            // فشل تراجع (كود مجموعة اتحجز مثلًا) — فشل مسجَّل بدل 500،
            // والإجراء يفضل executed فيُعاد التراجع بعد حل التعارض.
            return ToolOutcome::result(false, 'تعذّر التراجع؛ القيمة المطلوبة مستخدمة حاليًا.');
        }
    }
}
