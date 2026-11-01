# Audit Round 2 Findings & Required Fixes Specification

## High Severity Issues (H-1 - H-2)

### H-1: رفض مستند واحد يمنع اعتماد التاجر نهائياً
- **الموقع:** `CustomerManagementService::approve` (السطر 185 تقريباً) ودالة `uploadDocument` وفي `routes/api/wholesale.php`.
- **السبب:**
  - دالة `approve()` ترفض الاعتماد بشرط:
    `$customer->documents()->where('status', '!=', 'verified')->exists()`
    وهذا يشمل المستندات المرفوضة (`status === 'rejected'`).
  - دالة `uploadDocument` تحسب إجمالي كل المستندات ضمن حد الخمسة (`count() >= 5`) حتى لو كانت مرفوضة.
  - لا يوجد مسار لحذف مستند، ولا مسار لإعادة الرفع في `wholesale.php` لتاجر حالته `pending`.
  - **السيناريو:** المراجع يرفض صورة غير واضحة (يضع `review_note` و`rejected_documents`). التاجر يرفع بديلاً إن لم يتجاوز الـ 5، لكن عند الاعتماد يفشل دائماً لوجود المستند المرفوض القديم.
- **الأثر:** استحالة اعتماد التاجر نهائياً إلا إذا زوّر المراجع المستند المرفوض وجعله `verified`، مما يكسر سلامة سجل التدقيق.
- **الإصلاح:**
  - المستندات المرفوضة مستبعدة وليست معلقة: يُمنع الاعتماد فقط عند وجود مستند `pending`، مع اشتراط وجود مستند واحد `verified` على الأقل.
  - حد الرفع يحسب المستندات غير المرفوضة (`where('status', '!=', 'rejected')->count() < 5`)، مع سقف إجمالي تراكمي (مثلاً 10) لمنع الإغراق.
  - توفير مسار إعادة رفع مستند بديل في `wholesale.php` للتاجر المعلق.

### H-2: سقف SMS العام يمكن استنفاده فيتوقف التسجيل كله
- **الموقع:** `CustomerMessageBudget::claim` و`CustomerMessageBudget::subnetKey` و`CustomerPhoneVerificationService::send`.
- **السبب:**
  - حصة التحقق اليومية = `floor(1000 * 0.6) = 600` رسالة على مستوى النظام ككل.
  - الحد لكل شبكة هو 10 يومياً و3 بالساعة. مفتاح الشبكة لـ IPv6 يتم تقسيمه على `/64` (`substr($bin, 0, 8)`). أي VPS يحصل على `/48` أو `/56` (آلاف الـ `/64`).
  - الإرسال لأرقام غير مسجلة لا يتطلب مصادقة مسبقة.
- **الأثر:** استهلاك كامل حصة الـ SMS اليومية خلال دقائق، وتعطل كل التسجيل الحقيقي حتى منتصف الليل بتوقيت القاهرة، واستغلال المسار في SMS pumping.
- **الإصلاح:**
  - تجميع IPv6 على مستوى `/48` (`substr($bin, 0, 6).str_repeat("\0", 10)`).
  - التأكد من استخراج وتمرير `networkKey` دائماً من الـ Controller.
  - إضافة حماية Turnstile / CAPTCHA أو Rate Limiting صارم على endpoint إرسال التحقق.

---

## Medium Severity Issues (M-1 - M-7)

### M-1: تفرّد البريد كمعرّف دخول للتجار غير مضمون على مستوى قاعدة البيانات
- **المشكلة:**
  - الفهرس الوحيد هو `customers_verified_email_unique` الجزئي: `WHERE email_verified_at IS NOT NULL`.
  - `findForLogin` و`EmailNotUsedByOtherAccountType` يعاملان بريد تاجر الجملة كمعرف دخول حتى قبل التحقق.
  - `SerializesAccountEmail` يتخطى القفل الاستشاري للبريد غير الموثق.
  - ثغرة `restore()`: عند استعادة عميل محذوف كان بريده قد استُخدم بواسطة تاجر آخر، يحدث تكرار لنفس البريد في حسابين نشطين، مما يعطل تسجيل الدخول بـ `findForLogin` (ترجع null عند وجود أكثر من حساب).
- **الإصلاح:** إضافة partial unique index لبريد تجار الجملة، واستخدام advisory lock وإعادة الفحص في `create` و`update` و`restore`.

### M-2: `approve()` تقبل حساباً موقوفاً وتتجاوز مسار إعادة التفعيل
- **المشكلة:** في `CustomerManagementService::approve`، الفحص الوحيد هو "معتمد بنفس النوع". الحساب الموقوف `Suspended` يمكن اعتماده مباشرة، وتبقى `pre_suspension_status` قيمة قديمة لأن `approve` لا تصفرها، مما يتجاوز آلة الحالات (State Machine) ويسقط تدقيقات `reactivate`.
- **الإصلاح:** رفض الحسابات ذات الحالة `Suspended` داخل `approve` صراحة، وتصفير `pre_suspension_status`.

### M-3: الوكيل يستطيع تعديل حواجزه هو عبر `update_settings`
- **المشكلة:** `prepUpdateSettings` و`execUpdateSettings` تسمحان بمفاتيح `ai_*` (مثل `ai_disabled_tools`, `ai_daily_token_budget_per_user`, `ai_credit_limit_confirm_threshold`, `allow_self_approve_purchases`). مع وجود بيانات يتحكم بها العميل، يمكن عبر Prompt Injection إضعاف القيود الأمنية. كما أن تحقق `ai_disabled_tools.*` يُستبعد في مسار الوكيل.
- **الإصلاح:** منع مفاتيح `ai_*` و`allow_self_approve_purchases` في أداة الوكيل نهائياً، وقصر تعديلها على لوحة الإدارة REST.

### M-4: `dealerQueueSummary` غير محدود وفيه cache stampede
- **المشكلة:** يحمل كل العملاء `Pending` مع مستنداتهم في الذاكرة وينفذ `reviewCustomer` لكل واحد (استعلامات O(N)). استخدام `Cache::remember` بدون قفل ذري يسبب Cache Stampede عند انتهاء صلاحية الكاش لجميع الأدمنز في نفس اللحظة.
- **الإصلاح:** حساب العدادات الأساسية باستعلام تجميعي (SQL aggregate)، وحصر `ready_count` على أقدم 200 طلب فقط، واستخدام قفل ذري أو `Cache::flexible`.

### M-5: المايجريشن `2026_11_05_000001_apply_audit_fixes` تبني فهارس بدون CONCURRENTLY
- **المشكلة:** إنشاء فهارس GIN trigram وفهارس تعبيرية على جدول `customers` بدون `CONCURRENTLY` وبدون `$withinTransaction = false`، مما يحجز SHARE lock يمنع كل الكتابة والتسجيل وتحديث التشفير وقت تشغيل المايجريشن.
- **الإصلاح:** استخدام `CREATE INDEX CONCURRENTLY` مع `$withinTransaction = false` كما في باقي مايجريشنز المشروع.

### M-6: الـ SSE يحجز عمال PHP-FPM لمدة تصل إلى حوالي 4 دقائق
- **المشكلة:** `MAX_CYCLE_SECONDS = 240` مع `ignore_user_abort(true)` وإمكانية رفع `ai_max_concurrent_sessions` حتى 100، مما قد يستهلك عمال FPM ويسبب تجويع باقي الـ APIs (POS وبوابة الجملة).
- **الإصلاح:** تخصيص pool منفصل لمسارات الذكاء الاصطناعي أو وضع سقف منخفض لـ `ai_max_concurrent_sessions`.

### M-7: `WholesaleDemoSeeder` محمي بـ `isProduction()` فقط
- **المشكلة:** ينشئ تاجراً معتمداً بحد ائتمان 250,000 وكلمة مرور مكشوفة `password` على بيئات staging/demo.
- **الإصلاح:** استخدام `app()->environment(['local', 'testing'])` وتعيين كلمة المرور من متغيرات البيئة كما في `UnifiedLoginDemoSeeder`.

---

## Low Severity Issues (L-1 - L-10)

- **L-1:** حذف مجموعة العملاء يتجاهل العملاء المحذوفين بـ SoftDeletes، مما يصفر `customer_group_id` لديهم بصمت.
- **L-2:** البحث في `CustomerController::index` يستخدم `ILIKE '%x%'` بلا حد أدنى للطول (3 أحرف) مما يسبب Full Table Scan.
- **L-3:** القيمة الافتراضية لـ `status` في قاعدة البيانات هي `'approved'`؛ الأصح أن تكون `'pending'`.
- **L-4:** تعديل أو إنشاء عميل أو مجموعة بنفس الموبايل أو الكود يرمي `UniqueConstraintViolationException` كـ 500 في REST؛ تحويلها إلى ValidationException (422).
- **L-5:** رمز استعادة كلمة المرور عبر SMS طوله 64 حرفاً؛ استبداله برابط مباشر يحمل التوكن لتفادي UCS-2 متعدد الرسائل.
- **L-6:** `CustomerMessageBudget::usage()` يجمع بـ UTC بينما الميزانية تُحسب بيوم القاهرة.
- **L-7:** إنشاء وحذف مجموعات العملاء لا يسجل في سجل التدقيق المالي.
- **L-8:** إمكانية تعيين مجموعة عملاء غير مفعلة (`is_active = false`) في `approve` و`update`.
- **L-9:** أرقام هواتف العملاء المحذوفين تبقى محجوزة ودالة `send()` تسكت عنها بلا خطأ توضيحي.
- **L-10:** رفع الملفات إلى R2 في التسجيل قبل التحقق من صلاحية `phone_verification_token`؛ تقديم التحقق غير المستهلك قبل الرفع.

---

## Architecture & Security Notices (INFO)

- `typed_confirmation` هي حماية من الخطأ البشري وليست ضابطاً أمنياً مشفراً.
- نمط إرجاع `HttpException` من داخل الـ transaction يحتاج حذر لعدم ترك إجراء معلق بعد كتابة جزئية.
- قفل `StaffAdministration::lock()` الاستشاري العام قد يصبح عنق زجاجة مع زيادة العمليات.
- إرسال المستندات لـ Google Gemini يحتاج موافقة خصوصية للمنشآت.
- إزالة الفهرس المكرر `customers_phone_index` لوجود `customers_phone_unique` و`customers_phone_pattern_index`.
- أقفال الـ Orchestrator تتطلب cache store موحداً (Redis) في البيئات متعددة الخوادم.
