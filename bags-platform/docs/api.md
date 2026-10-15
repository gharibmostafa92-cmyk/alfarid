# API Notes

## Admin Categories (`/api/v1/admin/categories`)

- `GET /categories` — list with pagination (`per_page`, default 15, max 100), filters: `search`, `parent_id` (`root` for top-level), `is_active`. Use `?tree=1` for the full nested tree without pagination.
- `GET /categories/{id}` — single category with parent, children and products count.
- `POST /categories` — create (201). `slug` is generated automatically from `name_en` when omitted.
- `PUT /categories/reorder` — body: `items: [{id, parent_id, sort_order}]`. Every item must explicitly include `parent_id`; use `null` for a root. The final tree is validated atomically, regardless of item order.
- `DELETE /categories/{id}` — fails with 422 when the category has children or linked products, including soft-deleted references.
- Maximum category depth is 3 levels; violations return 422.

## Admin Brands (`/api/v1/admin/brands`)

- Same pattern as categories, without parent/tree/reorder. Logo is uploaded in the `logo` field
  (jpg, jpeg, png, webp; SVG is rejected) and returned as `logo_url`.
- Deleting a brand linked to products (including soft-deleted products) fails with 422.

## Admin Products (`/api/v1/admin/products`)

- `GET /products` — filters: `search` (names, `code`, variant `sku`/`barcode`), `category_id`
  (add `include_children=1` to include subcategories), `brand_id`, `status`, `sales_channel`,
  `is_featured`; `sort`: `latest` (default), `oldest`, `name`. Response includes
  `variants_count`, `min_retail_price`, `max_retail_price`.
- `POST /products` — creates a `draft` and auto-assigns `code` (`P00001`…). If supplied, `status` must be `draft`; upload media after creation and then publish via update.
  Optional `variants: [...]` can be sent with creation; a variant without `barcode` gets an
  internal EAN-13 barcode (prefix `2`), and without `sku` gets `{product_code}-{n}`.
- Publishing (`status: published`) requires the `products.publish` permission and a publishable
  product: at least one active variant, retail/wholesale prices on all active variants according
  to `sales_channel`, at least one image, and an active category with active ancestors. Violations return 422 with
  `errors.status` listing every problem; missing publish permission returns 403.
- Moving into or out of `published` requires `products.publish`, including unpublishing and archiving a published product.
- `cost_price` is only visible to users with the `products.view_cost` permission.
- `DELETE /products/{id}` — soft-deletes the product and its variants.
- `supplier_id` is accepted as a positive integer but is **not** checked against any table yet —
  the suppliers foreign key arrives in a later phase, and existing values must be audited/cleaned
  before that FK migration can run.
- List filters are validated: a malformed or unknown filter value (e.g. `category_id=abc` or a
  non-existent category) returns 422, and non-numeric ids in paths return 404. Search is
  case-insensitive and treats `%`/`_` literally.
- New media files use UUID paths (`/storage/{uuid}/...`). Existing files at numeric paths
  remain readable and deletable without moving files. These are public URLs; UUIDs do not
  provide authorization for private assets.

## Product Variants (`/api/v1/admin/products/{product}/variants`)

- Nested and scoped: a variant id under the wrong product returns 404.
- `GET` returns all variants without pagination. Create/update/delete require `products.update`
  (editing variants is editing the product); read requires `products.view`.
- The combination (color_name_en, size, capacity) must be unique per product — duplicates return 422.
- A variant without `sku` gets `{product_code}-{NN}` (e.g. `P00001-01`); without `barcode` it gets
  an internal EAN-13 barcode (prefix `2`).
- The last active variant of a published product cannot be deleted or deactivated (422).
- Sending `cost_price` without the `products.view_cost` permission is silently ignored on write.
  This also applies to nested variants when creating a product.
- Manual barcodes matching `2` followed by 12 digits are reserved for system generation.
  Updating a variant may resend its own current generated barcode.
- Prices accept at most two decimal places; excess precision returns 422. Empty barcode on update regenerates the internal barcode.
- Slugs use lowercase ASCII, and SKUs use uppercase ASCII. Uniqueness also checks legacy mixed-case values and soft-deleted records.
- Color combinations compare English color name, size and capacity without case sensitivity.
  English color name is required when Arabic color name or hex is supplied. Partial updates
  can retain the existing English name, but cannot clear it while other color details remain.

## Product Media (`/api/v1/admin/products/{product}/media`)

- `GET` lists the product's media **and** its variants' media ordered by `order_column`
  (variant media items carry `variant_id`).
- `POST` with `collection=images` + `files[]` (max 10, jpg/png/webp, 5MB each), optionally with
  `variant_id` to attach the images to a variant instead. With `collection=video` + `file`
  (mp4/webm/quicktime, 50MB) — video is product-only; sending it with `variant_id` returns 422.
- `PUT /media/reorder` with `ids: [...]` in the desired order. Media of soft-deleted variants is excluded from listing, reorder, and delete.
- `DELETE /media/{media}` — the last image of a published product cannot be deleted (422).
- Originals and conversions from failed uploads are removed on rollback. Replacing or deleting
  existing media removes its files only after the database transaction commits.
- Images have a maximum width and height of 6000 pixels. Batch limits: 100 nested variants,
  500 category reorder items, 200 media reorder ids, and 10 uploaded images. Oversized batches
  are rejected before per-item validation queries. Reorder ids must be distinct.
- Published product edits and variant mutations recheck publishability inside a product lock.
  To remove a required price, change the product to draft first. Category deactivation is
  rejected while that category or a descendant contains published products.
- Invalid pagination (`per_page < 1`) returns 422; values above 100 are capped at 100.
  Catalog path ids accept 1–18 digits; malformed or oversized path ids return 404.

### Updating with a file upload (important)

PHP does not parse uploaded files from `multipart/form-data` when the request method is `PUT`.
Therefore, to update a category (or any resource) **with an image**, the frontend must send a
`POST` request to `/categories/{id}` with `_method=PUT` included in the form body.
Laravel converts it to a `PUT` request automatically (method spoofing), and validation,
authorization and file handling then work as expected.

### Existing media layout maintenance

Run php artisan media:backfill-path-layouts once for existing uploads. It persists numeric/UUID layout markers without moving or deleting files, so future URL generation does not probe remote storage. The command is idempotent.

## المخزون والتحويلات والجرد (المرحلة 3)

قاعدة صارمة: أرصدة المخزون لا تُعدَّل إلا عبر `InventoryService::recordMovement` — حركة دفتر أولًا ثم تحديث الرصيد، في نفس الـ transaction. `available = on_hand - reserved - damaged`.

| المسار | الصلاحية | الوصف |
|---|---|---|
| `GET /inventory` | `inventory.view` | أرصدة حالية (فلاتر: branch_id, location_id, product_id, product_variant_id, low_stock=1) |
| `GET /inventory/movements` | `inventory.view` | دفتر الحركات من الأحدث (فلاتر: branch/product/type/from_date/to_date) |
| `POST /inventory/adjustments` | `inventory.adjust` | تسوية يدوية: items[{product_variant_id, adjustment_type: increase\|decrease\|mark_damaged, quantity, reason}] — الكل في transaction واحدة |
| `CRUD /transfers` | `transfers.*` | تحويلات بين الفروع (التعديل/الحذف للمسودة والملغي فقط) |
| `POST /transfers/{id}/approve` | `transfers.approve` | اعتماد + حجز الكميات عند المرسل (اختياري: items[{id, approved_qty}]) |
| `POST /transfers/{id}/ship` | `transfers.ship` | فك الحجز + إخراج فعلي + in_transit عند المستلم (اختياري: items[{id, shipped_qty}]) |
| `POST /transfers/{id}/receive` | `transfers.receive` | استلام: تسوية in_transit وإضافة المستلم؛ العجز يُسجَّل حركة adjustment_out موثقة + في الملاحظات (اختياري: items[{id, received_qty}]) |
| `POST /transfers/{id}/cancel` | `transfers.update` | إلغاء (المسودة مباشرة، والمعتمد بعد فك الحجز، والمشحون لا يُلغى) |
| `CRUD /stocktakes` | `stocktakes.*` | جلسات جرد — system_qty لقطة تلقائية وdifference محسوب في القاعدة |
| `POST /stocktakes/{id}/approve` | `stocktakes.approve` | اعتماد: فروق الجرد تتحول لحركات stocktake_gain/stocktake_loss وتتقفل الجلسة |

ملاحظات:
- سجل `stock_movements` غير قابل للتعديل أو الحذف برمجيًا وبنيويًا (بدون updated_at).
- جرد بموقع (location_id) نطاقه هو صف ذلك الموقع فقط؛ من غير موقع نطاقه صف "بدون موقع".
- ترقيم المستندات من الإعدادات: الحركات `MOV-`، التحويلات `TRF-`، الجرد `STK-`.

## التسعير ومجموعات العملاء (المرحلة 4)

محرك التسعير `PricingService::resolve(variant, customer?, qty, branchId?)` بيطبق شجرة أولويات: سعر خاص بالعميل ← سعر مجموعته ← قائمة نوعه (wholesale/distributor للمعتمدين فقط، وإلا retail) ← السعر الأساسي للمتغير حسب القناة (`retail_price`/`wholesale_price` — لا يوجد عمود base_price). داخل كل مستوى: قائمة الفرع المخصص أولًا، ثم أعلى شريحة كمية محققة، ثم priority، ثم السعر الأقل. ملحوظة: حقل `discount_percentage` على المجموعة بيانات مُدخلة حاليًا ولا يدخل في حساب المحرك (الشجرة مطبقة كما هي).

| المسار | الصلاحية | الوصف |
|---|---|---|
| `CRUD /customer-groups` | `customer_groups.*` | مجموعات عملاء (الحذف ممنوع مع عملاء أو قوائم مرتبطة) |
| `CRUD /price-lists` | `price_lists.*` | قوائم أسعار — النوع customer_specific يتطلب customer_id، وcustomer_group يتطلب customer_group_id، وغير مسموح بمالك لأي نوع آخر |
| `CRUD /price-lists/{id}/items` | `price_lists.*` | شرائح كميات — ممنوع تكرار (variant + min_qty) في نفس القائمة |
| `POST /price-lists/{id}/import` | `price_lists.import` | استيراد CSV (أعمدة التصدير نفسها) بـ updateOrCreate، ويرجع تقرير created/updated/skipped/errors |
| `GET /price-lists/{id}/export` | `price_lists.export` | تصدير CSV: sku, variant_id, product_name, min_qty, price, starts_at, ends_at |

## تحديثات مراجعة الجودة (جولة 3)

- **لقطة الجرد (C1):** `system_qty` تُؤخذ عند تسجيل `counted_qty` أو تغييره فقط؛ إعادة الحفظ بنفس الأعداد لا تجددها. لتجديد لقطة صنف صراحة أرسل `recount: true` مع عنصره في تحديث الجرد. اعتماد جرد فيه أصناف تغيّر رصيدها بعد العد يرجع 422 مع `errors.recount_item_ids` و`errors.recount_variant_ids` (الأصناف المحتاجة إعادة عد فقط).
- **نطاق الفروع (H1):** الوصول لكل الفروع امتياز صريح: دور Super Admin أو صلاحية `inventory.all_branches`. موظف بلا فرع وبلا الامتياز يُرفض (403) في كل عمليات المخزون/التحويلات/الجرد بدل رؤية كل الفروع.
- **عدم تكرار التسويات (H2):** `POST /inventory/adjustments` يقبل ترويسة `Idempotency-Key` (حتى 128 حرفًا). نفس المفتاح ونفس الحمولة يعيدان رد أول تنفيذ بلا حركات جديدة، ونفس المفتاح بحمولة مختلفة يرجع 409.
- **خصم المجموعة (H3):** `discount_percentage` يُطبق فوق سعر القناة (قائمة النوع إن وُجدت وإلا السعر الأساسي)، ولا يُخصم من سعر خاص بالعميل أو سعر قائمة المجموعة نفسها.
- **صنف غير مُسعَّر (M3):** متغير بلا `retail_price` ولا قائمة مطابقة يرمي 422 «الصنف غير مُسعَّر» من محرك التسعير بدل سعر صفر.
- **حمولات التحويل (M1):** أفعال الاعتماد/الشحن/الاستلام ترفض 409 أي معرّف عنصر لا يتبع التحويل وقت التنفيذ، وتقبل `expected_updated_at` اختياريًا (409 عند اختلافه عن آخر تعديل فعلي).
- **حذف التحويلات (M2):** ممنوع حذف تحويل له حركات في دفتر المخزون (مثل معتمد ثم ملغي)؛ الإلغاء هو الحفظ الصحيح لأثر التدقيق.

## تحديثات مراجعة الجودة (جولة 4)

- **نطاق قوائم الأسعار (M4):** الموظف المقيد بفرع لا يرى ولا يعدّل إلا قوائم فرعه (`branch_id` = فرعه)؛ القوائم العامة وقوائم الفروع الأخرى خارج نطاقه (404/403)، وإنشاء قائمة يربطها بفرعه تلقائيًا. مجموعات العملاء كيان مركزي بلا فرع بالتصميم.
- **ترتيب شرائح الأسعار (L8):** داخل مستوى التسعير الواحد، قائمة الفرع المخصص تتقدم على الشريحة العامة الأعلى كمية — هذا مقصود بالتصميم (خصوصية الفرع قبل شريحة الكمية).
- **رصيد المعطّلات (M5):** تعطيل متغير/موقع لا يحبس رصيده: عمليات الإخراج (decrease، التالف وشطبه، الجرد، التحويل الصادر) تعمل على المعطّل، والإدخال (increase) وحده يتطلب التفعيل.
- **حد إعادة الطلب (M6):** الحد الفعّال = `COALESCE(NULLIF(stock_levels.reorder_level, 0), product_variants.reorder_level, 0)` في فلتر `low_stock` وحقل `is_low_stock`.
- **تكلفة الحركات (M7):** `unit_cost` في ردود الحركات لا يظهر إلا بصلاحية `inventory.view_cost`.
- **التواريخ (M8):** التخزين UTC دائمًا. التواريخ المُدخلة (API وCSV) تُقبل بصيغ صارمة فقط (Y-m-d، Y-m-d H:i:s، ISO-8601 مع إزاحة) وتُفسَّر القيم بلا إزاحة بمنطقة العمل `BUSINESS_TIMEZONE` (افتراضي Africa/Cairo). فلاتر `from_date`/`to_date` للحركات بحدود أيام العمل. تصدير CSV يكتب التواريخ بصيغة ISO-8601 مع إزاحة لضمان إعادة استيراد بلا انزياح.
- **العملة (M9):** `price_lists.currency` مقصورة على EGP حاليًا (validation + قيد CHECK) حتى دعم تعدد العملات فعليًا في المحرك.
- **ترقيم الحركات (M10):** `GET /inventory/movements` يستخدم simple pagination — لا `meta.total` (يوجد `next_page_url`)، لأن الدفتر append-only وسيكبر جدًا.
- **قيود قاعدة البيانات (M12):** ‏0 ≤ received ≤ shipped ≤ approved ≤ requested على عناصر التحويل، ومنع تكرار سطر التحويل (نفس الصنف والموقعين) أو صنف الجرد داخل الجلسة، وعدّ الجرد لا يكون سالبًا — كلها قيود قاعدة (تتطلب PostgreSQL 15+ لخاصية NULLS NOT DISTINCT).
- **مراجع الدفتر (L5):** `reference_type` يُخزَّن بأسماء قصيرة عبر morph map (`stock_transfer`, `stocktake`)؛ الصفوف القديمة باسم الكلاس الكامل تظل مقروءة.
- **أقفال المخزون (L6):** انتظار القفل محدود بـ 5 ثوانٍ لكل معاملة؛ التنافس الأطول يرجع 409 «المخزون مشغول» بدل تعليق الطلب.

## تحديثات مراجعة الجودة (جولة 5)

- **إغلاق تصعيد قوائم الفرع (C-1):** موظف الفرع لا يستطيع تحويل قائمته لقائمة عامة بأي قيمة `branch_id` غير فرعه — بما فيها `null` صراحة (403، والقائمة لا تتغير). فحوص الوصول لقوائم الأسعار تُعاد على الصف المقفول داخل المعاملة، وقوائم خارج النطاق ترجع 404 موحدًا في كل العمليات.
- **طابع التزامن في التحويلات (H-1):** استبدال بنود التحويل وحده يحرّك `updated_at` الآن (`touch`)، فاعتماد/شحن/استلام بطابع `expected_updated_at` قديم يُرفض 409 حتى لو لم تتغير قيم الرأس. ملاحظة: دقة الطابع ثانية واحدة في قاعدة البيانات؛ الحل الأمتن (عمود `version` متزايد) متاح عند الحاجة.
- **سقوط قنوات الجملة (M-1):** عميل الجملة/الموزع الذي لا يوجد له عنصر في قائمة قناته يُسعَّر من قوائم retail النشطة قبل الرجوع للسعر الأساسي — لا يدفع أكثر من عميل التجزئة لمجرد غياب قائمة قناته.
- **ازدحام الأقفال (M-2):** أخطاء انتظار القفل (55P03) و deadlock ‏(40P01) في مسارات المخزون ترجع 409 «المخزون مشغول» لا 500.
- **تحديث جزئي لعناصر الجرد (M-4):** ‏`PATCH /stocktakes/{stocktake}/items` يحدّث/ينشئ العناصر المرسلة فقط دون حذف الغائبة (نفس قواعد اللقطة: التحديث مرتبط بتغيّر العد أو `recount`)، فيمكن تسجيل العد صنفًا صنفًا وبناء جلسات أكبر من حد الاستبدال الكامل (500).
- **سياسة اعتماد الجرد (M-5):** قابلة للضبط عبر `STOCKTAKE_STALE_POLICY`: الافتراضي `reject` — أي حركة بعد العد ترفض الاعتماد مع `recount_item_ids`/`recount_variant_ids` (يمنع أيضًا تطبيق نفس الفرق مرتين عند تداخل جلستي جرد). الوضع الاختياري `apply_difference` يطبّق الفرق (المعدود − لقطة لحظة العد) على الرصيد الحالي فتُحفظ الحركات البينية ولا تُحبس جلسات الفروع النشطة — يُفعَّل فقط عندما لا تتداخل جلسات الجرد على نفس الأصناف. حارس «المعدود أقل من المحجوز والتالف» يعمل في الوضعين.
- **ترقيم الحركات بالمؤشر (M-7):** ‏`GET /inventory/movements` يستخدم cursor pagination — الرد فيه `meta.next_cursor` بلا `total` وبلا `next_page_url`، والتجوال بالصفحات عبر `?cursor=...`. لا يمكن القفز لرقم صفحة محدد.
- **مطابقة الدفتر (M-8):** أمر `php artisan inventory:reconcile` (خيار `--branch_id=`) يقارن كل رصيد في `stock_levels` بآخر `balance_after` في الدفتر لكل وعاء، ويتحقق من اتصال تسلسل الأرصدة، ويرجع كود فشل عند أي فرق — يُجدول دوريًا لكشف أي كتابة مباشرة على الأرصدة.
- **حذف متغير/منتج له رصيد (M-9):** ممنوع (422) — الحذف الناعم كان يحبس الرصيد. أرصدة قديمة محبوسة على متغيرات محذوفة (قبل هذا الحارس) يمكن تصفيتها بعمليات الإخراج، والإدخال عليها ممنوع.
- **عدم تكرار إنشاء التحويلات والجرد (L-7):** ‏`POST /transfers` و`POST /stocktakes` يقبلان `Idempotency-Key` مثل التسويات: إعادة المحاولة بنفس المفتاح والحمولة تعيد النموذج المنشأ، ونفس المفتاح بحمولة مختلفة 409. بصمة الحمولة تطابق الصور الرقمية المختلفة ("5" = 5 = 5.0). أمر `inventory:prune-idempotency-keys` يحذف المفاتيح الأقدم من 7 أيام (يُجدول يوميًا).
- **استيراد CSV (L-5/M-6):** الكتابة مجمّعة (upsert على دفعات) بدل صف بصف؛ مطابقة SKU لا حساسة لحالة الأحرف؛ وفشل التصدير أثناء البث يُسجَّل في السجلات.
- **مخزون منخفض (L-4):** الصفوف بلا حد إعادة طلب فعّال (> 0) لا تظهر في فلتر `low_stock` ولا تُعلَّم `is_low_stock` — صفوف صفرية ينشئها قفل المخزون كأثر جانبي ليست إنذارات.
- **تدفقات تصحيحية (L-3):** فرض الجرد (StocktakeGain) واستلام التحويلات (TransferIn) على متغير/موقع معطّل مقصود بالتصميم: قاعدة «الإدخال يتطلب نشطًا» تحكم التسويات اليدوية فقط، وحجب التدفقات التصحيحية يحبس الرصيد — وهو ما عالجته M5/M-9.
