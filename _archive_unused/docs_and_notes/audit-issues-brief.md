# Audit Findings & Required Fixes Specification

## High Severity Issues (H1 - H3)

### H1 — تجاوز صلاحيات عبر update_customer_profile يؤدي للاستيلاء على الحساب [HIGH]
- **المشكلة:**
  - في REST، الكلاس `UpdateCustomerRequest::authorize()` يشترط `customers.approve` لأي تغيير في `email` أو `phone` أو `password` أو البيانات القانونية (`company_name`, `tax_number`, `commercial_register`).
  - في المساعد، `prepUpdateCustomerProfile` لا يشترط `customers.approve` إلا للبيانات القانونية فقط.
  - و`assertAiConstraints` تمنع `password`/`type`/`status` فقط.
  - وصلاحية الأداة في `definitions()` هي `['customers.update']`.
  - **الشروط:** موظف عنده `customers.update` و`ai_agent.execute` وليس عنده `customers.approve`، مع تفعيل `ai_customer_tools_enabled`.
- **الأثر:**
  - الموظف يغيّر موبايل تاجر جملة معتمد إلى رقمه هو.
  - الكود يحذف التوكنز ويصفّر `phone_verified_at`.
  - الموظف يطلب "نسيت كلمة المرور" بالموبايل الجديد، فيصله التوكن بـ SMS.
  - `resetPassword` تقبل التوكن، فيستولي على حساب له حد ائتمان ويضع طلبات آجلة باسم التاجر (insider privilege escalation).
  - ثغرة أخف من نفس النوع: REST يشترط `customers.approve` لإنشاء نوع `distributor`، بينما `create_customer` في المساعد لا يشترطه.
  - ثغرة إضافية في توقيت الفحص: فحص البيانات القانونية يحدث وقت الـ `prepare` فقط. فلو سُحبت الصلاحية خلال صلاحية الكارت (حتى 1440 دقيقة)، يتم التنفيذ رغم ذلك.
- **الموقع:** `ToolRegistry::prepUpdateCustomerProfile` و`assertAiConstraints`.
- **الإصلاح:** نقل الفحص إلى `assertAiConstraints`، لأنها تُستدعى في `prepareWrite` و`executeWrite` و`executeUndo` معاً، لتطابق قاعدة REST حرفياً.

### H2 — dealerQueueSummary وwelcomeBlock تكلفتها O(N × مسح كامل للجدول) [HIGH]
- **المشكلة:**
  - `dealerQueueSummary` تحمّل كل العملاء pending بلا حد، ثم تستدعي `reviewCustomer($c)` لكل واحد.
  - `reviewCustomer` بدورها تستدعي `duplicateCustomers`، وفيها:
    - `whereRaw('TRIM(tax_number) = ?')` و`TRIM(commercial_register)`: شرط non-sargable، أي لا يستخدم أي index، فيعمل sequential scan.
    - `similarity(company_name, ?) > 0.6`: الدالة `similarity()` لا تستخدم GIN trigram index إطلاقاً (الذي يستخدمه هو المعامل `%`)، فيعمل seq scan مع حساب trigram لكل صف.
  - `AiWelcomeController` يستدعيها مع كل تحميل لصفحة المساعد، لكل موظف عنده `customers.approve`.
  - مع 300 تاجر pending و100 ألف عميل، النتيجة حوالي 1200 مسح كامل لكل فتح صفحة.
- **الأثر:** بطء شديد وضغط CPU على PostgreSQL يؤثر على النظام بالكامل (POS والمتجر)، وثغرة DoS من الداخل.
- **الإصلاح:**
  - استخدام `%` مع `similarity` في نفس الشرط حتى يُستخدم الـ index.
  - إنشاء expression indexes على `TRIM(tax_number)` و`TRIM(commercial_register)`.
  - كاش 60 ثانية للملخص.

### H3 — bulk_child غير مسموح بها في CHECK constraint [HIGH]
- **المشكلة:**
  - المايجريشن الأصلية `2026_10_30_000003_create_ai_agent_tables.php` تعرّف:
    `enum('status', ['pending','confirmed','executed','failed','cancelled','expired','undone'])`. على PostgreSQL هذا يتحول إلى varchar مع CHECK constraint.
  - `prepareBulk` تنفّذ `update(['status' => 'bulk_child'])`.
- **الأثر:** كل استدعاء `bulk_actions` يطلق CHECK violation، ويلتقطه `catch (\Throwable)` في `dispatchAllowed` فيرجع "حصل خطأ أثناء تنفيذ الأداة" وتفشل الميزة بالكامل.
- **الإصلاح:** مايجريشن جديدة تعدل CHECK constraint لجدول `ai_pending_actions` لتشمل `'bulk_child'`.

---

## Medium Severity Issues (M1 - M13)

### M1 — كتل catch مكررة في confirmLocked لا تُنفَّذ أبداً [MEDIUM]
- **الموقع:** `PendingActionService::confirmLocked` (الأسطر 219–272).
- **المشكلة:** نفس كتلة `try` فيها `catch (AuthorizationException|ModelNotFoundException|RoleAlreadyExists)` مرتين، و`catch (QueryException)` مرتين. PHP يطابق أول catch مناسب فقط، فالكتل الثانية dead code، والكتلة الأولى تعيد رمي الخطأ فيلتقطه الـ catch الخارجي ويرجع 500 بدل رسالة مفهومة.
- **الإصلاح:** دمج وترتيب كتل الـ catch في كتلة واحدة منسقة تسجل الخطأ كـ `markFailed` برسالة واضحة وترجع خطأ مناسب.

### M2 — reactivate يعتمد على DB constraint ويرجع 500 في REST [MEDIUM]
- **الموقع:** `CustomerManagementService::reactivate`.
- **المشكلة:** يعتمد على constraint `customers_approved_business` لصد البيانات الناقصة دون فحص مسبق، فيرمي CheckViolation (23514) غير ملتقط ويرى العميل خطأ 500.
- **الإصلاح:** فحص صريح قبل الحفظ يرمي `BusinessException`، ويبقى DB constraint خط دفاع أخير.

### M3 — لا يوجد تحقق من تكرار البريد عند الإنشاء والتعديل من الإدارة أو المساعد [MEDIUM]
- **الموقع:** `StoreCustomerRequest` و`UpdateCustomerRequest` (`create_customer` و`update_customer_profile`).
- **المشكلة:** الحقل `email` لا يستخدم `UniqueCustomerEmail` أو `EmailNotUsedByOtherAccountType`. الـ unique الوحيد المتبقي هو جزئي (`WHERE email_verified_at IS NOT NULL`) و`setEmailAttribute` يصفّر `email_verified_at` عند التعديل، مما يسبب تكرار الإيميل وتعطل تسجيل الدخول بـ `findForLogin`.
- **الإصلاح:** إضافة معامل `ignoreId` للـ rule وتطبيقها في `StoreCustomerRequest` و`UpdateCustomerRequest`.

### M4 — الأحداث تُطلق داخل الـ transaction في approve وreject [MEDIUM]
- **المشكلة:** `approve()` و`reject()` تنفذان `WholesaleApplicationReviewed::dispatch(...)` داخل `DB::transaction`. في مسار AI، تكون transaction متداخلة قد يحدث لها rollback لاحقاً بعد خروج إشعار الموافقة.
- **الإصلاح:** جعل الحدث `WholesaleApplicationReviewed` ينفذ `ShouldDispatchAfterCommit`.

### M5 — حد زمن الدورة لا يُطبَّق داخل الخطوة الواحدة، والقفل العام لعدد الجلسات قد يتسرب [MEDIUM]
- **المشكلة:** الشرط `MAX_CYCLE_SECONDS` يُفحص في بداية كل خطوة فقط، بينما الخطوة تسمح بـ 8 أدوات (منها `inspect_customer_document` التي تطلب Gemini حتى 45 ثانية مع retry). قد تصل الخطوة لـ 6 دقائق وينتهي قفل الـ slot (TTL = 300s) أثناء العمل مما يكسر سقف `ai_max_concurrent_sessions`.
- **الإصلاح:** فحص الوقت المنقضي قبل كل استدعاء أداة، وتحديد سقف استدعاء واحد فقط لـ `inspect_customer_document` في الخطوة الواحدة.

### M6 — بيانات عملاء حساسة محفوظة في ui_blocks وpreview ومتاحة لمن لا يملك customers.view [MEDIUM]
- **المشكلة:** `getCustomerProfile` تبني `customer_card` فيها الموبايل الكامل والرقم الضريبي والسجل والبريد، وتُحفظ في `ai_messages.ui_blocks`. كما تحفظ `prepApproveDealerApplication` رقم الموبايل كاملاً في `preview`.
- **الإصلاح:** إظهار الأرقام مقنّعة (`maskPhone`/`maskLegal`) في الـ blocks المحفوظة، واشتراط `customers.view` في `authorizeView` لمن يعرض محادثات غيره، وإزالة phone من `before.company` في كارت الاعتماد.

### M7 — suspend_customer مسموحة في الـ bulk لكنها تفشل دائماً [MEDIUM]
- **المشكلة:** `suspend_customer` دائماً `risk => high` وتتطلب typed confirmation (آخر 4 أرقام من الهاتف)، بينما `confirmBulk` تستدعي `confirm($actor, $child, null)` فيرمي 422 ويفشل دائماً.
- **الإصلاح:** إزالة `suspend_customer` من أدوات الـ bulk المسموحة، أو رفض الـ bulk وقت الـ prepare إذا كان أي عنصر `risk === 'high'`.

### M8 — NormalizeCustomerPhones تلغي التقسيم إلى batches بلفّها في transaction واحدة [MEDIUM]
- **المشكلة:** أمر `NormalizeCustomerPhones::handle()` يغلّف كل العمليات في `DB::transaction` واحدة كبيرة، فتحتفظ بأقفال الصفوف حتى نهاية التشغيل وتعطل عمليات تسجيل الدخول والتعديل.
- **الإصلاح:** حساب خطة التعديل أولاً، ثم تنفيذ كل دفعة (batch) في transaction مستقلة قصيرة.

### M9 — تسجيلان متزامنان بنفس الموبايل يرجعان 500 [MEDIUM]
- **المشكلة:** التحقق في `FormRequest` هو على مستوى التطبيق، والطلبات المتزامنة قد تمر كلاهما فيرمي unique constraint violation من قاعدة البيانات غير ملتقط كـ 500.
- **الإصلاح:** التقاط `UniqueConstraintViolationException` وتحويله إلى `ValidationException` على حقل `phone`.

### M10 — استنزاف ميزانية الـ SMS العامة (SMS pumping) [MEDIUM]
- **المشكلة:** حدود الإرسال تعتمد على IP كامل ويمكن تجاوزه بتدوير الـ IPs مما يستنزف ميزانية الـ SMS (600 رسالة يومياً).
- **الإصلاح:** استخدام مفتاح شبكة على مستوى subnet (`/24` لـ IPv4 و`/64` لـ IPv6)، ووضع حد بالساعة لكل subnet، وخفض عتبة التنبيه إلى 50%.

### M11 — Admin/CustomerResource لا يُرجع commercial_register ويُرجع حقولاً غير موجودة [MEDIUM]
- **المشكلة:** حذف أو إهمال حقول مثل `commercial_register` أو إرجاع حقول وهمية مثل `business_license` و`tax_card`، وعدم إرجاع `deleted_at` لتمييز السجلات المحذوفة في `trashed=only`.
- **الإصلاح:** تصحيح الـ Resource بإرجاع `commercial_register` و`deleted_at` وإزالة الحقول غير الموجودة.

### M12 — reactivate والتراجع عن الإيقاف يعيدان التاجر المرفوض إلى طابور المراجعة [MEDIUM]
- **المشكلة:** إيقاف عميل (`suspend`) مسموح من أي حالة ومنها `Rejected`. وعند عمل `reactivate` أو التراجع `executeUndo` للإيقاف، يتم فحص `approved_at ? Approved : Pending` فيعود المرفوض إلى حالة `Pending` في طابور المراجعة وتضيع حالته السابقة.
- **الإصلاح:** استعادة الحالة السابقة المحفوظة في `inverse['previous_status']` ومنع إعادة التاجر المرفوض إلى `Pending`.

### M13 — ثغرات في سجل التدقيق للبيانات المالية [MEDIUM]
- **المشكلة:** `update()` يسجل فقط أسماء الحقول المعدلة دون القيم السابقة والجديدة لـ `credit_limit` و`customer_group_id`. كما أن `CustomerGroupService::update` لا يسجل في مسار REST.
- **الإصلاح:** تسجيل `before`/`after` للحقول المالية الحساسة في `CustomerAuditLog`، وتدقيق تعديلات المجموعات عبر `StaffAdministration::audit`.

---

## Low Severity Issues & Notices (L1 - L11 & INFO)

- **L1:** قواعد كلمة المرور في `StoreCustomerRequest` و`UpdateCustomerRequest` تسمح بكلمات مرور ضعيفة وتفتقر إلى `PasswordByteLimit` (max:72 byte لـ bcrypt) و12 حرفاً للجملة.
- **L2:** في `CustomerAuthService::login`، عند إعادة التشفير (hash rehash)، التنافس المتزامن قد يُرجع 401 رغم صحة كلمة المرور. يجب تجاهل تحديث الـ hash والمتابعة.
- **L3:** استخدام SHA-256 لأرقام الهواتف المصرية بدون salt/key يسهل كسره بالتخمين؛ التوحيد على HMAC كما في `identifierKey`.
- **L4:** تنظيف سجلات التدقيق `customer_audit_logs` يعمل full scan دوري؛ إضافة partial index على `(created_at) WHERE action IN (...)`.
- **L5:** بحث `mentions` بالحرف والحرفين ILIKE لا يستفيد من trigram/btree؛ فرض حد أدنى 3 أحرف واستخدام مطابقة case-sensitive للأرقام.
- **L6:** استعلام `Branch::find` في `EnsureStaffAuthenticated` يتكرر مع كل طلب موظف؛ كاش أو eager load.
- **L7:** `GeminiClient` يرمي TypeError لو لم تكن `candidates.0` مصفوفة، وbackoff مهلة 250ms على 429 غير كافية.
- **L8:** العملاء القدامى برقم `legacy-N` لا يستطيعون الدخول بسبب حذف الشرطة في `normalizePhone`.
- **L9:** تضارب كشف وجود الحساب بين `send()` الصامتة و`Rule::unique` في التسجيل.
- **L10:** حقول `$fillable` في Customer تحتوي حقولاً حساسة مثل `status`, `approved_by`, `approved_at`, `credit_limit`.
- **L11:** موديل Gemini مثبت hardcoded في سجل المحادثة؛ التحديث لموديل احتياطي ديناميكي.
- **INFO:** `StaffAdministration::lock()` تسلسل العمليات على مستوى النظام، تغيير `APP_KEY` يبطل الإجراءات المعلقة، فارق التوقيت بين `today()` وتوقيت الفرع.
