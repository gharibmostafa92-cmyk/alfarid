# خطة ومواصفات مساعد الإدارة الذكي (Gemini Super Admin Chat Agent)

## 1. نظرة عامة والهدف (Overview & Objectives)
تمكين **المدير العام (Super Admin)** عبر واجهة محادثة ذكية (AI Chat Interface) في لوحة الإدارة (`Backoffice / FinancialDashboard`) من إدارة النظام وأركانه الأساسية باللغة الطبيعية (عربي / إنجليزي)، مع الاعتماد على **Google Gemini** (عبر `App\Services\Ai\GeminiClient`) و**تقنية استدعاء الأدوات (Function Calling / Tool Execution)** لإجراء العمليات الحساسة بأمان كامل:
1. **إدارة الفروع (Branches)**: إضافة فرع جديد، تعديل بيانات فرع، تعطيل أو تفعيل فرع.
2. **إدارة الأدوار والصلاحيات (Roles & Permissions)**: إنشاء دور جديد، إسناد أو تعديل الصلاحيات، عرض كتالوج الصلاحيات.
3. **إدارة المستخدمين والموظفين (Staff & Users)**: إضافة مستخدم جديد، إسناد الأدوار، ربطه بفرع، تعطيل أو تفعيل حساب.

---

## 2. مواءمة التصميم وواجهات المستخدم (UI Design Alignment)
النظام يحتوي مسبقاً على:
- تصميم الشات المدمج في لوحة التحكم الإدارية: [FinancialDashboard.jsx](file:///d:/programming/programming/projects/alfarid/bags-platform/frontend/packages/ui/src/financial-dashboard/FinancialDashboard.jsx) مع قنوات المحادثة (`chats`), سجل الرسائل (`chat-messages-feed`), حقل الإدخال (`chat-composer-input`).
- واجهة المحادثة المتقدمة: [ai-chat.html](file:///d:/programming/programming/projects/alfarid/ai-chat.html) بستايل عصري (Tailwind + Markdown + بطاقات الإجراءات وأكواد المهام).

### دورة عمل المحادثة في الواجهة:
1. يكتب الـ Super Admin طلبه: "أضف فرع جديد اسمه فرع المعادي كوده BR-MAADI نوعه retail في القاهرة هاتف 01012345678".
2. يقوم الذكاء الاصطناعي (Gemini) بفهم النية واستخراج البارامترات المنظمة واستدعاء أداة `create_branch`.
3. للعمليات الحساسة (مثل: تعطيل فرع، حذف دور، تعطيل موظف): تُعرض بطاقة تأكيد تفاعلية (Confirmation Card) تحتوي على تفاصيل العملية وزر **«تأكيد التنفيذ»** وزر **«إلغاء»**.
4. عند التأكيد يتم إرسال طلب التنفيذ إلى الـ API وإرجاع بطاقة نجاح منسقة مع تحديث فوري لبيانات النظام.

---

## 3. كتالوج أدوات الذكاء الاصطناعي (Gemini Function Calling Tools)

### أ. أدوات الفروع (Branch Management Tools)
- `create_branch(name: string, code: string, type: 'retail'|'warehouse'|'office', phone?: string, email?: string, city?: string, address?: string)`
  - تستدعي منطق `StoreBranchRequest` و`BranchController::store`.
- `toggle_branch_status(code_or_id: string|number, is_active: boolean)`
  - تفعيل أو تعطيل فرع (يتطلب تأكيد مسبق إذا كان التعطيل).
- `list_branches(city?: string, type?: string, active_only?: boolean)`
  - عرض الفروع الحالية للـ Super Admin.

### ب. أدوات الأدوار والصلاحيات (Roles & Permissions Tools)
- `list_permissions()`
  - جلب كتالوج الصلاحيات المتاحة في النظام (`users.view`, `branches.manage`, `orders.view`, إلخ).
- `create_role(name: string, display_name: string, permissions: string[])`
  - إنشاء دور جديد وربط الصلاحيات المحددة عبر `StoreRoleRequest`.
- `update_role_permissions(role_name_or_id: string|number, add_permissions?: string[], remove_permissions?: string[])`
  - تعديل صلاحيات دور قائم.
- `list_roles()`
  - جلب قائمة الأدوار وموظفيها وصلاحياتها.

### ج. أدوات المستخدمين والموظفين (User & Staff Management Tools)
- `create_staff_user(name: string, email: string, phone: string, role: string, branch_id?: number, password?: string)`
  - إضافة مستخدم جديد للنظام وتعيين دوره وفرعه عبر `StoreUserRequest` و`StaffAdministration`.
- `toggle_user_status(user_id_or_email: string|number, is_active: boolean)`
  - تفعيل أو إيقاف وصول مستخدم.
- `assign_role_to_user(user_id_or_email: string|number, role: string)`
  - تغيير دور الموظف مع تسجيل الحدث في `StaffAuthenticationAudit`.
- `list_staff_users(branch_id?: number, role?: string)`
  - استعراض الموظفين الحاليين.

---

## 4. البنية البرمجية الخلفية (Backend Architecture)
1. **المسار (Route)**:
   - `POST /api/v1/admin/ai/chat` (محمي بـ `auth:sanctum` و `auth.staff` والتأكد من دور `super-admin`).
2. **الخدمة (Service)**:
   - `App\Services\Ai\SuperAdminAgentService`:
     - حقن `GeminiClient`.
     - بناء الـ System Prompt متضمناً هوية المساعد وقواعد الحوكمة.
     - تعريف Tool Definitions بتنسيق Google Gemini API Function Declarations.
     - معالجة `functionCall` وتنفيذ العمليات داخل `DB::transaction`.
     - تسجيل العمليات في سجل التدقيق `StaffAuthenticationAudit`.

---

## 5. قواعد الحماية وتدقيق العمليات (Security & Audit)
- التحقق الإجباري من هوية المنفّذ: يجب أن يمتلك صلاحية `super-admin`.
- أي تعديل على المستخدمين أو الأدوار أو الفروع يسجل تلقائياً عبر `AuditStaffAdministration`.
- تقييد نطاق الفروع (`BranchAccess`) والتكلفة (`CostAccess`) يظل مطبقاً على باقي المستخدمين.
