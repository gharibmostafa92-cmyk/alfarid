# نظام حزم Repomix العمودية الشاملة للتدقيق البرمجي والأمني
## (10 End-to-End Vertical Subsystems Architecture)

يوفر هذا المجلد معمارية متكاملة رأسية شاملة بنسبة **100% دون أي استثناء** لتقسيم منصة **الفريد (Alfarid Platform)** إلى **10 أنظمة فرعية عمودية كاملة ومستقلة (End-to-End Vertical Subsystems)**.

> [!IMPORTANT]
> **المبدأ المعماري الصارم: كل ما يتفاعل معاً يسكن في نفس الملف.**
> لا يتم عزل قواعد البيانات أو الواجهات في جزر منعزلة؛ كل نظام فرعي يضم طبقة الواجهة الأمامية (UI)، ومسارات الـ API والـ Controllers، وخدمات النطاق (Domain Services)، والنماذج (Models)، و**كافة ملفات الترحيل وقواعد البيانات (DDL Migrations)** الخاصة به بما تشمله من فهارس (Indexes) وأقفال تزامن (Locks) وقيود تسلسلية (Sequences).
>
> **استثناء ملفات الاختبارات:** تطبيقاً للأمر الصارم، تم استبعاد كافة ملفات الاختبارات (`tests/**`) ليبقى سياق التدقيق مركزاً بنسبة 100% على الكود الفعلي الإنتاجي والمخططات.

---

## 🏛️ خريطة الأنظمة الفرعية الـ 10 (The 10 Subsystems)

| # | النظام الفرعي (Subsystem) | ملف التكوين (Config) | المخرجات (Output Bundle) | المكونات المتفاعلة معاً في نفس الملف |
|---|---|---|---|---|
| **01** | **نقطة البيع، الكاشير والورديات** | `repomix.subsystem-01-pos-terminal.json` | `repomix-subsystem-01-pos-terminal.xml` | تطبيق React 19 POS بالكامل + PosService + TreasuryService + ورديات + مبيعات معلقة + ترحيلات الـ POS |
| **02** | **دورة الطلبات، المرتجعات ورد الأموال** | `repomix.subsystem-02-orders-returns.json` | `repomix-subsystem-02-orders-returns.xml` | شاشات المرتجعات + Order/Return Controllers + ReturnService + مرفقات خاصة R2 + فهارس الـ FK والترقيم الموحد |
| **03** | **دفتر أستاذ المخزون، التحويلات والجرد** | `repomix.subsystem-03-inventory-ledger.json` | `repomix-subsystem-03-inventory-ledger.xml` | TransferService + StocktakeService + فروع ومواقع + أقفال التزامن الذري + ترحيلات حركات المخزون الـ 11+ |
| **04** | **تجارة الجملة B2B وسداد ديون FIFO** | `repomix.subsystem-04-wholesale-b2b.json` | `repomix-subsystem-04-wholesale-b2b.xml` | بوابة الجملة UI + محرك عروض الأسعار Quotations + مطابقة إيصالات البنوك ذكياً + سداد الديون FIFO + ترحيلات الجملة |
| **05** | **وكيل التاجر الذكي وبوابة واتساب** | `repomix.subsystem-05-ai-trader-gateway.json` | `repomix-subsystem-05-ai-trader-gateway.xml` | خادم Node.js Baileys + محرك الذكاء الاصطناعي AgentOrchestrator + أدوات Gemini ToolRegistry + معالجة الـ Webhooks |
| **06** | **متجر العملاء، الكتالوج والشحن** | `repomix.subsystem-06-storefront-catalog.json` | `repomix-subsystem-06-storefront-catalog.xml` | تطبيق Next.js 15 Storefront + الكتالوج وفهارس Trigram + السلة والكوبونات + حساب أسعار الشحن وشركات التوصيل |
| **07** | **الخزينة، المحاسبة المزدوجة والمصروفات** | `repomix.subsystem-07-finance-treasury.json` | `repomix-subsystem-07-finance-treasury.xml` | لوحة القيادة المالية UI + Treasury double-entry ledger + إدارة المصروفات والمحافظ + سكريبت المطابقة المالية اليومية |
| **08** | **المشتريات، بوابة الموردين وإدخال البضاعة** | `repomix.subsystem-08-procurement-suppliers.json` | `repomix-subsystem-08-procurement-suppliers.xml` | بوابة الموردين UI + أوامر الشراء Purchase Orders + أذون استلام البضائع + فواتير الموردين ومدفوعاتهم |
| **09** | **الأمان والتحكم، الصلاحيات ولوحة الإدارة** | `repomix.subsystem-09-security-backoffice.json` | `repomix-subsystem-09-security-backoffice.xml` | لوحة React 19 Backoffice + نظام المصادقة متعدد الحراس + صلاحيات الموظفين Staff Actions + محرك الإشعارات |
| **10** | **البنية التحتية، الأداء العالي و DevOps** | `repomix.subsystem-10-infra-devops.json` | `repomix-subsystem-10-infra-devops.xml` | حاويات Docker + موازن HAProxy + مجمع اتصالات PgBouncer + عنقود Redis + اختبارات ضغط K6 + أقفال وأزمنة PostgreSQL |

---

## 🔍 تدقيق تغطية قواعد البيانات (100% Migration Audit)

تم فحص ومطابقة جميع ملفات الترحيل في قاعدة البيانات:
```powershell
Auditing database migration coverage across the 10 vertical subsystems...
Migration Coverage: 156 / 156 (100%)
SUCCESS: 100% of all 156 migrations are included in the subsystems!
```
كل ملف Migration من الـ 156 تم إسناده إلى مجاله العمودي لضمان فحص الأداء والفهارس مع الكود البرمجي التابع له.

---

## 🚀 دليل تشغيل السكريبت الموحد (CLI Usage)

سكريبت التشغيل يقع في: `scripts/repomix-pack.ps1`

### 1. تدقيق تغطية الترحيلات فقط:
```powershell
powershell -ExecutionPolicy Bypass -File scripts/repomix-pack.ps1 -VerifyMigrations
```

### 2. حزم نظام فرعي محدد:
```powershell
# نقطة البيع والكاشير
powershell -ExecutionPolicy Bypass -File scripts/repomix-pack.ps1 -Domain pos

# الطلبات ومرتجعات المبيعات
powershell -ExecutionPolicy Bypass -File scripts/repomix-pack.ps1 -Domain orders

# دفتر أستاذ المخزون والتحويلات
powershell -ExecutionPolicy Bypass -File scripts/repomix-pack.ps1 -Domain inventory

# تجارة الجملة وسداد الديون
powershell -ExecutionPolicy Bypass -File scripts/repomix-pack.ps1 -Domain wholesale

# الذكاء الاصطناعي وبوابة واتساب
powershell -ExecutionPolicy Bypass -File scripts/repomix-pack.ps1 -Domain ai

# متجر العملاء والكتالوج
powershell -ExecutionPolicy Bypass -File scripts/repomix-pack.ps1 -Domain storefront

# الخزينة والمالية والمصروفات
powershell -ExecutionPolicy Bypass -File scripts/repomix-pack.ps1 -Domain finance

# المشتريات وبوابة الموردين
powershell -ExecutionPolicy Bypass -File scripts/repomix-pack.ps1 -Domain procurement

# الحماية ولوحة تحكم الإدارة
powershell -ExecutionPolicy Bypass -File scripts/repomix-pack.ps1 -Domain security

# البنية التحتية وأداء قاعدة البيانات
powershell -ExecutionPolicy Bypass -File scripts/repomix-pack.ps1 -Domain infra
```

### 3. توليد كافة الحزم الـ 10 دفعة واحدة:
```powershell
powershell -ExecutionPolicy Bypass -File scripts/repomix-pack.ps1 -All
```

### 4. تنظيف المخرجات السابقة:
```powershell
powershell -ExecutionPolicy Bypass -File scripts/repomix-pack.ps1 -Clean
```
