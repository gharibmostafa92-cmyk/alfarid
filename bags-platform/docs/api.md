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

محرك التسعير `PricingService::resolve(variant, customer?, qty, branchId?)` يطبق شجرة أولويات: سعر خاص بالعميل ← سعر مجموعته ← قائمة نوعه (wholesale/distributor للمعتمدين فقط، وإلا retail) ← قائمة retail عند غياب سعر قناة الجملة ← `retail_price` الأساسي. خصم المجموعة يُطبَّق فوق سعر القناة أو الأساسي، ولا يُضاف إلى السعر الخاص بالعميل أو سعر قائمة مجموعته. غياب السعر الأساسي والقوائم المطابقة يرجع 422. داخل كل مستوى: قائمة الفرع المخصص أولًا، ثم أعلى شريحة كمية محققة، ثم priority، ثم السعر الأقل.

| المسار | الصلاحية | الوصف |
|---|---|---|
| `CRUD /customer-groups` | `customer_groups.*` | مجموعات عملاء (الحذف ممنوع مع عملاء أو قوائم مرتبطة) |
| `CRUD /price-lists` | `price_lists.*` | قوائم أسعار — النوع customer_specific يتطلب customer_id، وcustomer_group يتطلب customer_group_id، وغير مسموح بمالك لأي نوع آخر |
| `CRUD /price-lists/{id}/items` | `price_lists.*` | شرائح كميات — ممنوع تكرار (variant + min_qty) في نفس القائمة |
| `POST /price-lists/{id}/import` | `price_lists.import` | استيراد CSV (أعمدة التصدير نفسها) بـ upsert على دفعات، ويرجع تقرير created/updated/skipped/errors |
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

- **نطاق قوائم الأسعار (M4):** الموظف المقيد بفرع لا يرى ولا يعدّل إلا قوائم فرعه (`branch_id` = فرعه)؛ القوائم العامة وقوائم الفروع الأخرى خارج نطاقه (404)، وإنشاء قائمة يربطها بفرعه تلقائيًا. مجموعات العملاء كيان مركزي؛ تعديلها أو إنشاؤها أو حذفها يتطلب Super Admin أو `inventory.all_branches` بالإضافة إلى صلاحية العملية.
- **ترتيب شرائح الأسعار (L8):** داخل مستوى التسعير الواحد، قائمة الفرع المخصص تتقدم على الشريحة العامة الأعلى كمية — هذا مقصود بالتصميم (خصوصية الفرع قبل شريحة الكمية).
- **رصيد المعطّلات (M5):** تعطيل متغير/موقع لا يحبس رصيده: عمليات الإخراج وتخفيض الجرد والتحويل الصادر تعمل على المعطّل. كل إدخال فعلي موجب يتطلب متغيرًا غير محذوف وموقعًا نشطين، بما فيه زيادة الجرد واستلام التحويل.
- **حد إعادة الطلب (M6):** الحد الفعّال = `COALESCE(NULLIF(stock_levels.reorder_level, 0), product_variants.reorder_level, 0)` في فلتر `low_stock` وحقل `is_low_stock`.
- **تكلفة الحركات (M7):** `unit_cost` في ردود الحركات لا يظهر إلا بصلاحية `inventory.view_cost`.
- **التواريخ (M8):** التخزين UTC دائمًا. التواريخ المُدخلة (API وCSV) تُقبل بصيغ صارمة فقط (Y-m-d، Y-m-d H:i:s، ISO-8601 مع إزاحة) وتُفسَّر القيم بلا إزاحة بمنطقة العمل `BUSINESS_TIMEZONE` (افتراضي Africa/Cairo). فلاتر `from_date`/`to_date` للحركات بحدود أيام العمل. تصدير CSV يكتب التواريخ بصيغة ISO-8601 مع إزاحة لضمان إعادة استيراد بلا انزياح.
- **العملة (M9):** `price_lists.currency` مقصورة على EGP حاليًا (validation + قيد CHECK) حتى دعم تعدد العملات فعليًا في المحرك.
- **ترقيم الحركات (M10):** `GET /inventory/movements` يستخدم cursor pagination بلا COUNT أو OFFSET؛ استخدم `meta.next_cursor` أو `links.next` للتنقل، ولا تعتمد على إجمالي الصفوف أو أرقام الصفحات.
- **قيود قاعدة البيانات (M12):** ‏0 ≤ received ≤ shipped ≤ approved ≤ requested على عناصر التحويل، ومنع تكرار سطر التحويل (نفس الصنف والموقعين) أو صنف الجرد داخل الجلسة، وعدّ الجرد لا يكون سالبًا — كلها قيود قاعدة (تتطلب PostgreSQL 15+ لخاصية NULLS NOT DISTINCT).
- **مراجع الدفتر (L5):** `reference_type` يُخزَّن بأسماء قصيرة عبر morph map (`stock_transfer`, `stocktake`)؛ الصفوف القديمة باسم الكلاس الكامل تظل مقروءة.
- **أقفال المخزون (L6):** انتظار القفل محدود بـ 5 ثوانٍ لكل معاملة؛ التنافس الأطول يرجع 409 «المخزون مشغول» بدل تعليق الطلب.

## تحديثات مراجعة الجودة (جولة 5)

- **إغلاق تصعيد قوائم الفرع (C-1):** موظف الفرع لا يستطيع تحويل قائمته لقائمة عامة بأي قيمة `branch_id` غير فرعه — بما فيها `null` صراحة (403، والقائمة لا تتغير). فحوص الوصول لقوائم الأسعار تُعاد على الصف المقفول داخل المعاملة، وقوائم خارج النطاق ترجع 404 موحدًا في كل العمليات.
- **تزامن التحويلات (H-1):** كل تعديل يرفع `version`، بما فيه استبدال البنود وحده وفي نفس الثانية. أرسل `expected_version` من آخر رد مع الاعتماد/الشحن/الاستلام؛ اختلافه يرجع 409 مع `errors.current_version`. يدعم `expected_updated_at` أيضًا بمقارنة اللحظة الزمنية، وطابعه يتقدم على الأقل ثانية عند كل تعديل لحماية العملاء القدامى. الحقلان اختياريان للتوافق، لكن أي عميل ينفذ مراجعة ثم اعتمادًا يجب أن يرسل أحدهما؛ من دونهما يُنفَّذ على أحدث نسخة مقفولة.
- **سقوط قنوات الجملة (M-1):** عميل الجملة/الموزع الذي لا يوجد له عنصر في قائمة قناته يُسعَّر من قوائم retail النشطة قبل الرجوع للسعر الأساسي — لا يدفع أكثر من عميل التجزئة لمجرد غياب قائمة قناته.
- **ازدحام الأقفال (M-2):** أخطاء انتظار القفل (55P03) و deadlock ‏(40P01) في مسارات المخزون ترجع 409 «المخزون مشغول» لا 500.
- **تحديث جزئي لعناصر الجرد (M-4):** ‏`PATCH /stocktakes/{stocktake}/items` يحدّث/ينشئ العناصر المرسلة فقط دون حذف الغائبة (نفس قواعد اللقطة: التحديث مرتبط بتغيّر العد أو `recount`)، فيمكن تسجيل العد صنفًا صنفًا وبناء جلسات أكبر من حد الاستبدال الكامل (500).
- **سياسة اعتماد الجرد (M-5):** قابلة للضبط عبر `STOCKTAKE_STALE_POLICY`: الافتراضي `reject` يرفض أي حركة على الرصيد الفعلي بعد لقطة العد مع `recount_item_ids`/`recount_variant_ids`. الوضع الاختياري `apply_difference` يطبق الفرق على الرصيد الحالي ويحفظ المبيعات والحركات البينية. الوضعان يرفضان تسوية جرد أخرى بعد اللقطة لمنع تكرار نفس العجز في جلسات متداخلة. اللقطة ورقم آخر حركة يُقرآن تحت قفل الأرصدة. فحص المحجوز والتالف يُطبق على الرصيد النهائي المتوقع.
- **ترقيم الحركات بالمؤشر (M-7):** ‏`GET /inventory/movements` يستخدم cursor pagination — الرد فيه `meta.next_cursor` و`links.next` بلا `total`، والتجوال عبر `?cursor=...`. لا يمكن القفز لرقم صفحة محدد.
- **مطابقة الدفتر (M-8):** أمر `php artisan inventory:reconcile` (خيار `--branch_id=`) يقارن كل رصيد في `stock_levels` بآخر `balance_after` في الدفتر لكل وعاء، ويتحقق من اتصال تسلسل الأرصدة، ويرجع كود فشل عند أي فرق — يُجدول دوريًا لكشف أي كتابة مباشرة على الأرصدة.
- **حذف متغير/منتج له رصيد (M-9):** ممنوع (422) — الحذف الناعم كان يحبس الرصيد. أرصدة قديمة محبوسة على متغيرات محذوفة (قبل هذا الحارس) يمكن تصفيتها بعمليات الإخراج، والإدخال عليها ممنوع.
- **عدم تكرار إنشاء التحويلات والجرد (L-7):** ‏`POST /transfers` و`POST /stocktakes` يقبلان `Idempotency-Key` مثل التسويات: إعادة المحاولة بنفس المفتاح والحمولة تعيد النموذج المنشأ، ونفس المفتاح بحمولة مختلفة 409. بصمة الحمولة تطابق الصور الرقمية المختلفة ("5" = 5 = 5.0). أمر `inventory:prune-idempotency-keys` يحذف المفاتيح الأقدم من 7 أيام (يُجدول يوميًا).
- **استيراد CSV (L-5/M-6):** الكتابة مجمّعة (upsert على دفعات) بدل صف بصف؛ مطابقة SKU لا حساسة لحالة الأحرف؛ وفشل التصدير أثناء البث يُسجَّل في السجلات.
- **مخزون منخفض (L-4):** الصفوف بلا حد إعادة طلب فعّال (> 0) لا تظهر في فلتر `low_stock` ولا تُعلَّم `is_low_stock` — صفوف صفرية ينشئها قفل المخزون كأثر جانبي ليست إنذارات.
- **نشاط الإدخال (L-3):** الحارس مركزي في `InventoryService`؛ زيادة الجرد واستلام التحويل يخضعان لنفس شرط نشاط المتغير والموقع. فشل الاستلام يتراجع عن فك رصيد الطريق وكل حركاته في المعاملة نفسها.

## إغلاق المراجعة وتشغيلها

- شغّل `php artisan migrate --force` عند النشر. يتطلب المخزون PostgreSQL 15 أو أحدث. الترحيل الأخير يضيف نسخة التحويل، رقم حركة لقطة الجرد، وفهرس تنظيف مفاتيح التكرار ودالة ترقيم متزامنة. جلسات الجرد القديمة ذات لقطة بلا رقم حركة تحتاج إعادة عد (`recount: true`) عند رفض الاعتماد.
- حسابات المكتب الرئيسي التي ليست Super Admin تحتاج منح `inventory.all_branches` صراحة. `inventory.view_cost` مستقلة؛ ترحيل الصلاحيات لا يمنح امتيازات تلقائية للموظفين.
- إرسال `Idempotency-Key` ثابت لنفس المحاولة مطلوب من أي عميل ينفذ retries للتسويات أو إنشاء تحويل/جرد. بعد تغيير الحمولة استخدم مفتاحًا جديدًا. المفتاح اختياري في عقد API الحالي؛ الطلب بلا مفتاح عملية جديدة. ملفات خدمات المخزون بالواجهة ما زالت scaffolds؛ لا توجد فيها دورة إرسال فعلية لتعديلها بعد.
- إعادة الشحن أو الاستلام المكتملين تعيد الحالة والكميات المحفوظة بلا حركات جديدة. إرسال كميات مختلفة لنفس المرحلة المكتملة يرجع 409. صلاحيات الفرع تُفحص أيضًا عند إعادة الردود المحفوظة.
- مطابقة الدفتر مجدولة 02:00 والتنظيف 03:00 بتوقيت العمل. يجب تشغيل Laravel scheduler (`php artisan schedule:run` كل دقيقة، أو `schedule:work` للتطوير). فشل المطابقة أو المهمة يسجل خطأ للتنبيه عبر نظام مراقبة السجلات. المطابقة تستخدم لقطة SQL واحدة، وتكشف انقطاع التسلسل وصفوف الأرصدة المحذوفة أيضًا، وتعرض عينة محدودة من الفروق.
- تصدير CSV يعيد `X-Row-Count` لتمكين العميل من اكتشاف الملف المبتور؛ أخطاء البث مسجلة. التواريخ ISO المعادة من API (بما فيها Z والكسور) قابلة لإعادة الإرسال، والتاريخ بلا وقت يبدأ عند منتصف ليل منطقة العمل.
- ترقيم الحركات لا يستخدم `ALTER SEQUENCE` في التطبيق؛ قفل قصير داخل دالة قاعدة البيانات ينسق تخصيص الرقم ورفع الحد الأدنى، ويُفك قبل انتهاء معاملة المخزون. يتطلب مستخدم التطبيق USAGE وSELECT وUPDATE على التسلسل وEXECUTE على الدالة، ولا يتطلب ملكية التسلسل.
- قرارات السياسة الحالية محفوظة: قائمة الفرع تتقدم على الشريحة العامة؛ خصم المجموعة فوق سعر القناة؛ القناة الصريحة تتقدم على retail حتى لو كانت أعلى سعرًا؛ لا فرض لفصل المنشئ عن المعتمد؛ العنصر الغائب في حمولة المرحلة يستخدم كامل كميته الافتراضية. نقل المواقع داخل نفس الفرع غير مدعوم ضمن هذا المسار. تكلفة الحركة هي تكلفة الكتالوج الحالية، وليست متوسط تكلفة محاسبيًا.

## المرحلة 5: العملاء ومصادقة المتجر واعتماد الجملة

ثلاث قنوات على جدول `customers` واحد: المتجر (`api/v1/store`) والجملة (`api/v1/wholesale`) والإدارة (`api/v1/admin`).

### مصادقة المتجر القطاعي

التسجيل العام يبدأ بإثبات ملكية المحمول: `POST /store/auth/phone-verification/send` مع `phone` ثم `POST /store/auth/phone-verification/verify` مع `phone` و`code` (ستة أرقام). الرد الثاني يرجع `phone_verification_token` يُرسل مع تسجيل القطاعي أو الجملة. الرمز صالح عشر دقائق وله خمس محاولات، والإثبات صالح عشر دقائق ومربوط بالرقم ويُستهلك مرة واحدة. `CUSTOMER_REQUIRE_PHONE_VERIFICATION=true` افتراضيًا؛ لا يُعطَّل في الإنتاج. الحسابات السابقة لا تُعتبر موثقة تلقائيًا.

المحمول المصري يُحفظ بصيغة محلية `01[0125]xxxxxxxx`، مع توحيد `+20` و`0020` والأرقام العربية والفارسية والمسافات. الدخول يفصل البريد عن الهاتف صراحةً. التسجيل القطاعي والجملة يتشاركان حدًا قابلًا للضبط: افتراضيًا 20 طلبًا/دقيقة و500 طلب/24 ساعة لكل IP، مراعاةً لشبكات CGNAT. طلبات إرسال OTP محدودة أيضًا، ولا يُرسل أكثر من خمس رسائل تحقق للرقم يوميًا.

- `POST /store/auth/register` {name, phone*, email?, password(min 8)} — يُنشئ عميلًا `retail/approved` دائمًا (أي type/status في الطلب يُتجاهل) ويرجع 201 مع `token` (تسجيل دخول تلقائي).
- `POST /store/auth/login` {login (بريد أو هاتف), password} — الحساب الموقوف يرجع 403 مع سبب الإيقاف؛ بيانات خاطئة 401. حد محاولات: 5/دقيقة و30/ساعة و100/يوم للمُعرّف، و120/دقيقة للـ IP افتراضيًا؛ الحدود الطويلة وحدود IP قابلة للضبط.
- `POST /store/auth/logout` (auth:sanctum + auth.customer) — يحذف التوكن الحالي فقط.
- `POST /store/auth/forgot-password` {email أو phone، واحد فقط} — يضع نفس مهمة الطابور للحساب المعروف وغير المعروف. العامل يولّد رمزًا مُجزّأ بمفتاح `customer:{id}` في `password_reset_tokens` ويرسله عبر القناة المطلوبة (mail للبريد، SMS للهاتف)، ويستبعد الحساب الموقوف، مع مهلة 60 ثانية بين الإرسالات. الرد واحد دائمًا حتى لا يُستدل على وجود الحساب.
- `POST /store/auth/reset-password` {email أو phone، token (64 حرفًا)، password، password_confirmation} — صلاحية الرمز 60 دقيقة؛ يُستهلك مرة واحدة داخل معاملة، مع تغيير كلمة المرور وإلغاء جميع جلسات العميل. الرمز غير الصالح/المنتهي أو الحساب الموقوف يرجع 422.
- طلب الاستعادة محدود حسب IP والمعرّف الموحّد معًا. حد الإرسال داخل العامل خمس رسائل لكل حساب في اليوم بتوقيت العمل، عبر البريد والهاتف معًا، ومهلة دقيقة بين الإرسالات. تأكيد reset-password له حد مستقل. مهام الاستعادة والتحقق تُشفّر في `jobs` و`failed_jobs` بواسطة مفتاح التطبيق.
- `CUSTOMER_MAX_TOKENS=10`: إصدار توكن جديد ينظف المنتهي ويزيل أقدم جلسة تتجاوز الحد. `SANCTUM_TRACK_LAST_USED=false` يمنع كتابة `last_used_at` مع كل تصفح؛ يمكن تفعيله إذا كانت تتبعات الاستخدام مطلوبة.
- التشغيل: اضبط مزود البريد و`CUSTOMER_SMS_URL`/`CUSTOMER_SMS_TOKEN` (HTTPS gateway يستقبل JSON `to` و`message` مع Bearer authentication). اتصال طابور الاستعادة `CUSTOMER_PASSWORD_RESET_QUEUE_CONNECTION=database` مستقل عن اتصال الطابور العام. شغّل `php artisan queue:work database` لمعالجة الطلبات؛ اتصال `sync` مخصص للاختبارات.

### تسجيل الجملة ومتابعة الحالة

- `POST /wholesale/auth/register` (multipart) {name, phone*, email?, password, company_name*, tax_number*, commercial_register*, documents[]* (pdf/jpg/png ≤ 5MB للملف، حتى 5 ملفات), document_types[]? موازية} — يُنشئ حساب `wholesale/pending` ومستنداته في **تخزين خاص** `storage/app/private/customer_documents/{id}` (لا روابط عامة)، ويرجع توكنًا لمتابعة الحالة.
- `GET /wholesale/auth/application-status` (auth:sanctum + auth.customer، بدون wholesale.approved) — الحالة الحالية و`documents` و`rejected_documents`؛ الحساب المرفوض مسموح له بمتابعة السبب والخروج فقط، والحساب الموقوف يُرفض.

### إدارة العملاء في لوحة الإدارة (صلاحيات customers.*)

- `GET /admin/customers` فلاتر: `type`, `status`, `customer_group_id`, `search` (اسم/هاتف/بريد/سجل تجاري/اسم الشركة/رقم ضريبي)، و`trashed=only` لعرض المحذوفين. البحث مدعوم بعمود مولّد وفهرس GIN من امتداد PostgreSQL `pg_trgm`.
- `POST /admin/customers` — الحالة الافتراضية حسب النوع (قطاعي approved، جملة/موزع pending) ما لم تُرسل صراحة.
- `GET /admin/customers/{id}` — مع `documents` (لكل مستند `download_url` محمي) و`customer_group` و`addresses`.
- `PUT /admin/customers/{id}` — تغيير `type` أو `status` ممنوع. إرسال `credit_limit` أو `customer_group_id` أثناء الإنشاء أو التعديل يتطلب `customers.approve` بالإضافة لصلاحية العملية. لا يمكن مسح بيانات الجملة الإلزامية لحساب جملة/موزع معتمد.
- تغيير كلمة المرور أو البريد أو الهاتف يتطلب `customers.approve` أيضًا لمنع تغيير وسيلة الاستعادة والاستيلاء على الحساب. تغيير بيانات الدخول يُنهي الجلسات، ويدوّر remember_token ويحذف رموز الاستعادة. الإيقاف والحذف يحذفان رموز الاستعادة كذلك.
- `DELETE /admin/customers/{id}` — حذف ناعم + إنهاء الجلسات. `POST /admin/customers/{id}/restore` بصلاحية `customers.delete` يستعيد الحساب بنفس نوعه وحالته وتاريخه؛ الاستعادة لا تعيد توكناته السابقة.
- `POST /admin/customers/{id}/approve` {customer_group_id?, credit_limit?, type?: wholesale|distributor، company_name?, tax_number?, commercial_register?} — بيانات الشركة والضريبة والسجل مطلوبة قبل اعتماد الجملة/الموزع، ويمكن استكمالها في نفس الطلب. يضبط `approved_by/approved_at` ويمسح أسباب الرفض/الإيقاف. اعتماد مكرر بنفس النوع يرجع 422، وترقية النوع تمر بنفس التحقق.
- `POST /admin/customers/{id}/reject` {reason*} — للطلبات `pending` فقط؛ الرفض **لا** يقتل توكن التاجر حتى يطّلع على السبب. إيقاف حساب معتمد يتم عبر suspend.
- `POST /admin/customers/{id}/suspend` {reason*} — يوقف الحساب و**يحذف كل توكناته فورًا**.
- `GET /admin/customers/{customer}/documents/{document}/download` — تنزيل مستند من التخزين الخاص (الربط مقيّد: مستند عميل آخر يرجع 404).
- `PATCH /admin/customers/{customer}/documents/{document}` {status: pending|verified|rejected} — مراجعة مستند بصلاحية `customers.approve` والربط المقيّد نفسه. تنزيل ملف مفقود يرجع 404 واسم التنزيل يُطهَّر.
- التنزيل يتطلب صلاحية مستقلة `customers.documents.view`؛ صلاحية عرض العملاء وحدها لا تتيح مستندات الهوية والضريبة. مراجعة المستندات تتطلب customers.approve وcustomers.documents.view معًا، والتنزيل يسجل في التدقيق. اعتماد غير القطاعي يحتاج مستندًا موثّقًا واحدًا على الأقل، مع توثيق كل المستندات المرفقة واكتمال بيانات الشركة والضريبة والسجل.
- جدول `customer_audit_logs` يسجل الموظف والوقت والعملية للإنشاء والتعديل والاعتماد والرفض والإيقاف والحذف والاستعادة ومراجعة المستندات وتغيير بيانات الدخول؛ لا يخزن كلمات مرور أو توكنات. المستندات تحمل `reviewed_by` و`reviewed_at`.

حالات العميل: `pending/approved/rejected/suspended` (حلت suspended محل blocked من المرحلة 1 في المرحلة 5). الهاتف فريد وإلزامي، والبريد اختياري وغير حساس لحالة الأحرف؛ يظل بيانات تواصل حتى توثيق الملكية (`email_verified_at`). التسجيل لا يحجز البريد غير الموثّق، ولا يسمح باستخدامه للدخول أو الاستعادة. الفهرس الفريد يطبق على العناوين الموثّقة فقط. لا يوجد مسار عام لتوثيق البريد في هذه المرحلة؛ الهاتف هو المعرّف المتاح للحسابات الجديدة، ولا تفترض الهجرة توثيق العناوين السابقة. البريد يُطبَّع إلى lowercase وتُحذف المسافات المحيطة بالهاتف والبريد. هوية المحذوف تبقى محجوزة لحسابه الأصلي ويمكن استعادته؛ لا يُنشأ حساب جديد بنفس الهوية.

## المرحلة 6: واجهة المتجر للقراءة (Storefront Read APIs)

كل روابط التصفح عامة (بلا مصادقة). إن أرسل عميل معتمد Bearer Token تُحسب أسعاره الشخصية (خصم مجموعة/قائمة خاصة/قناة جملة)؛ بقية الحالات والزائر يرون أسعار القطاعي. المنتجات المعروضة منشورة، قناتها retail أو both، أقسامها وأسلافها نشطة، وعلامتها إن وجدت نشطة. `products.is_active` مولّد من حالة النشر، و«متغير نشط» = `is_active` مع استبعاد المحذوف ناعمًا.

- `GET /store/home` — رد مجمّع: `banners` (نشطة وداخل نافذتها الزمنية، بترتيب sort_order)، `latest_products` (أحدث 12)، `best_sellers` (بـ `sales_count`،‏ 12)، `discounted_products` (متغير بـ `compare_at_price > base_price` أو عنصر في قائمة أسعار سارية أرخص من الأساسي، 12). كاش `store_home_data` ‏300 ثانية يخزن **المعرّفات فقط** — الأسعار تُحسب لكل طلب فلا تتسرب بين العملاء.
- `GET /store/categories` — شجرة الأقسام النشطة (3 مستويات) مع `products_count` للمنشور فقط في كل عقدة.
- `products_count` يشمل منتجات الأبناء النشطين، و`direct_products_count` يحفظ عدد المنتجات المباشرة. روابط pagination تحتفظ بالفلاتر في المتجر والإدارة. `page` في كتالوج المتجر محدود بـ500.
- `GET /store/brands` — النشطة بترتيب sort_order ثم الاسم. `GET /store/cities` — مدن الشحن النشطة مع `shipping_cost`.
- `GET /store/products` — فلاتر: `category` (id أو slug، يشمل الفروع)، `brand` (id/slug أو مصفوفة `brand[]` باستعلام واحد)، `color` (اسم اللون عربي/إنجليزي)، `price_min/price_max` والترتيب بالسعر على **أقل سعر نهائي معروض للعميل**، `in_stock=1`، و`sort`: ‏`newest|best_selling|price_asc|price_desc`. غير المسعّر يظهر أخيرًا في ترتيبي السعر. الترقيم 20 للصفحة. البطاقة: `price` النهائي، `old_price` مرجع نسبة الخصم (الأكبر من السعر الأساسي وcompare_at_price عند وجود خصم)، و`discount_percentage` و`in_stock`؛ لا تظهر كميات المخزون الدقيقة للعامة، لا في البطاقة ولا في المتغيرات. أسعار المتغيرات تُحمّل مجمعة بقواعد PricingService نفسها ولا يُعاد الاستعلام لكل متغير.
- المتاح أونلاين داخليًا (`store_available`) = مجموع `available` ‏(on_hand − reserved − damaged) عبر **الفروع النشطة فقط**، باستعلام فرعي مجمّع واحد — مخزون فرع معطّل لا يُحتسب.
- `GET /store/products/{slug}` — المنشور فقط (غيره 404): الوصف والصور وكل متغير نشط بسعره النهائي و`old_price` ومتاحه وحالة توفره. متغير غير مُسعَّر يظهر بسعر null بدل كسر الصفحة.
- `GET /store/products/{slug}/similar` — حتى 8: نفس القسم الفرعي أولًا ثم نفس العلامة، والمتوفر مخزونيًا أولًا، ويستبعد المنتج نفسه وغير المنشور.
- جداول/حقول جديدة: `banners` ‏(link_type/product|category|brand|external|none، position/main_slider|middle_banner|popup، نافذة زمنية)، `products.sales_count` (يُحدَّث مع مبيعات الطلبات في مرحلة الطلبات)، `product_variants.compare_at_price`.

## المرحلة 7: السلة والطلبات والتوزيع (الأساس + خدمة التوزيع)

- جداول: `carts` (cart_token uuid فريد للزائر + customer_id اختياري — دمج سلة الزائر عند الدخول يتم في طبقة السلة) و`cart_items` (قيد فريد: سلة + متغير)، و`orders` و`order_items` (لقطات اسم/‏SKU وسعر، وallocated_branch_id على البند لدعم تقسيم الطلب) و`order_status_histories` (changed_by morph: موظف/عميل/نظام).
- الطلب: `order_number` من SettingService (‏ORD- + بادئة/حشو الإعدادات)، `channel` ‏store|wholesale|pos|admin، حالة `App\Enums\OrderStatus` ‏(pending/confirmed/processing/shipped/delivered/cancelled/returned) بقيد CHECK، و`payment_method` ‏cod|card|wallet|bank_transfer|credit و`payment_status` ‏unpaid|paid|failed|refunded. المعادلة الإلزامية: grand_total = (subtotal − discount_amount) + shipping_cost + tax_amount. `coupon_id` و`city_id` بلا FK (الأول حتى مرحلة الكوبونات، والثاني لأن تراجع هجرة 5.1 يُسقط cities — الطلب يحتفظ بالمعرّف كمرجع تاريخي).
- قاعدة صارمة: أي تغيير حالة يمر عبر `OrderService::changeStatus()` فقط — تسجل التغيير في `order_status_histories` بنفس الـ transaction، وعند الانتقال إلى `confirmed` تطلق التوزيع والحجز تلقائيًا.
- `OrderAllocationService::allocate(order, forcedBranchId?, performedBy?)`: فرع مُجبر (يُتحقق من نشاطه وتغطيته) ← أول فرع نشط يغطي الطلب كاملًا (أولوية لفرع نفس مدينة العميل بمقارنة branches.city مع cities.name_ar) ← كل صنف من الفرع الأعلى متاحًا (الصنف كاملًا من فرع واحد). داخل الفرع قد يتوزع الصنف على مواقع متعددة (الأعلى متاحًا أولًا، والمواقع المعطّلة مستبعدة). الحجز بحركات reserve في الدفتر (reference: order) عبر InventoryService حصرًا؛ أي نقص يُلغي العملية كلها بلا حجز متسرب، وإعادة توزيع طلب مُوزّع تُرفض.
- `OrderService::createFromCart`: لقطات اسم/‏SKU/سعر من PricingService (الخصم التسعيري في بنود الطلب، وخصم الكوبون على مستوى الطلب)، شحن = cities.shipping_cost، ضريبة من الإعدادات (tax_enabled وdefault_tax_rate=14%، صفر عند التعطيل أو شمول الأسعار)، إفراغ السلة، وأول سجل حالة pending. `changeStatus` بآلة حالات صارمة (لا إلغاء بعد الشحن/التسليم): الشحن يفك الحجز ويخصم sale فعليًا من نفس صفوف الحجز ويزيد products.sales_count، وإلغاء confirmed/processing يفك الحجز. حركات العميل/النظام تُنسب تنفيذيًا لأول Super Admin (الدفتر يتطلب موظفًا).
- كوبونات: جدول `coupons` (code فريد، percentage|fixed، حد أدنى/أقصى خصم، حد استخدام ونافذة زمنية) و`CouponService::validateAndApply` — أساس فقط بلا CRUD إداري بعد.
- روابط المتجر: `GET|POST|PUT|DELETE /store/cart/items` (زائر بهيدر `X-Cart-Session` أو عميل بتوكن؛ الأسعار لحظية والكمية محكومة بمتاح المتجر)، `POST /store/cart/coupon`، `POST /store/checkout/shipping-options` (خيار قياسي بتكلفة المدينة)، `POST /store/checkout` (زائر/مسجل؛ payment_url = null لعدم ربط بوابة دفع بعد)، `GET /store/orders/{number}/track` (عام برقم هاتف الطلب للتحقق)، `GET /store/account/orders` و`POST /store/account/orders/{id}/cancel` (بـ auth.customer؛ إلغاء من pending/confirmed فقط). سلة الزائر تُدمج في سلة الحساب عند تسجيل الدخول أو إنشاء الحساب.
- روابط الإدارة (صلاحيات orders.view/update/allocate): `GET /admin/orders` (فلاتر حالة/فرع/دفع/تاريخ/بحث) و`GET /admin/orders/{id}` و`PATCH /admin/orders/{id}/status` (عبر changeStatus) و`POST /admin/orders/{id}/allocate` (تلقائي أو branch_id مُجبر؛ لطلب confirmed/processing فقط).

## المرحلة 8: الشحن والعروض والكوبونات

- جداول الشحن: `shipping_companies` ‏(code فريد، api_credentials مشفرة بـ cast ولا تُعرض في أي رد — مؤشر has_api_credentials فقط)، `shipping_zones` + وسيط `shipping_zone_cities`، `shipping_rates` (سعر فريد شركة×منطقة: base_cost + free_shipping_threshold + cod_fee + أيام تقديرية)، و`shipments` (بوليصة: awb فريد، حالة ShipmentStatus، تكلفة، مبلغ COD، raw_response).
- إدارة (صلاحيات shipping.*): ‏CRUD ‏`shipping-companies|shipping-zones|shipping-rates` (المناطق تُزامن مدنها بـ city_ids).
- `POST /admin/orders/{order}/shipment` (صلاحية orders.update): ينشئ بوليصة عبر ShipmentService — تكلفتها من سعر الشركة لمنطقة مدينة الطلب (مجانية فوق حد السعر، وإلا تكلفة الطلب)، وCOD = إجمالي الطلب عند الدفع عند الاستلام، ورقم البوليصة داخلي بصيغة CODE- عشوائي ما لم يُمرّر — ثم يحول الطلب إلى shipped عبر OrderService (فك حجز + بيع). فشل الانتقال يُلغي البوليصة.
- العروض والكوبونات (صلاحيات promotions.* وcoupons.*): ‏CRUD ‏`promotions` (أنواع percentage_discount/fixed_discount/buy_x_get_y/free_shipping واستهداف all/categories/brands/variants بـ target_ids — تُدار وتُخزن حاليًا، وتطبيقها التلقائي عند الشراء خطوة لاحقة) وCRUD ‏`coupons`.
- ترقية الكوبونات فوق أساس 7.3: نوع `free_shipping` (يعفي من شحن المدينة عند الشراء ويُسجل المُعفى)، و`usage_limit_per_customer` (افتراضي 1، و0 = بلا حد فردي)، وجدول `coupon_usages` (كوبون/عميل/طلب/قيمة) يُكتب عند كل شراء مع تحديث used_count. خصم النسبة = min(subtotal × value/100, max_discount_amount). أكواد الكوبونات تُخزن بأحرف كبيرة دائمًا.

## المرحلة 8.3: الويب هوكس وروابط منفصلة

- مجموعة `api/v1/webhooks` (name: webhooks.) في bootstrap/app.php بلا أي مصادقة — الحماية بالتوقيع:
  - `POST /webhooks/payment/{gateway}`: بوابات معرفة في config/webhooks.php (paymob/stripe/moyasar — سر HMAC من البيئة). توقيع HMAC-SHA256 لنص الطلب الخام في هيدر X-Webhook-Signature (قابل للضبط لكل بوابة). نجاح الدفع: payment_status=paid ثم تأكيد تلقائي للطلب pending عبر OrderService (حجز وتوزيع فوري).
  - `POST /webhooks/shipping/{company}`: السر من api_credentials.webhook_secret للشركة (أو api_key أو سر بيئة افتراضي). تُبحث البوليصة بـ awb_number؛ delivered تحوّل الطلب المشحون إلى delivered وتثبت دفع COD بـ payment_status=paid. مرادفات حالات الشركات الشائعة مدعومة (picked/collected/transit/rto...).
- كل طلب يُسجل أولًا في `webhook_logs` (provider, event_id, payload, signature_valid, status, error, ip) قبل المعالجة؛ حدث بنفس (provider, event_id) سبق معالجته يُعلَّم duplicate ولا تُعاد معالجته. توقيع فاسد = 403، بوابة/شركة غير معروفة = 404، أخطاء عمل (طلب/بوليصة غير موجودة) = 200 مع received=false لتفادي إعادة إرسال المزود.
- توفيق روابط مع النص الرسمي: تحديث/حذف بند السلة صار بـ {item} (معرّف البند يُعرض في رد السلة)، checkout اسمه checkout.store والمتحكم CheckoutController@store، وطلبات المتجر (تتبع/حساب/إلغاء) في Store\OrderController، واسم رابط حالة الأدمن orders.update-status.
### التشغيل والحماية من الضغط

- قراءة الكتالوج محدودة افتراضيًا بـ600 طلب/دقيقة لكل IP، وطلبات فلترة/ترتيب السعر بـ20 طلبًا/دقيقة لكل IP و200 طلب/دقيقة للموقع كله. القيم قابلة للضبط من config/auth.php ومتغيرات STORE_*. الحدين يشتركان في ملفات المسارات العامة. فلترة السعر وترتيبه يستخدمان نتيجة `LEFT JOIN LATERAL` واحدة لكل منتج بدل تكرار تعبير السعر لكل شرط. عدّ paginate واستعلام البيانات منفصلان؛ ترتيب السعر دون حدود سعرية يستخدم count خاليًا من حساب الأسعار مع الحفاظ على إجمالي الصفحات؛ الترتيب بسعر شخصي يظل بحاجة إلى فحص خطة التنفيذ على حجم الإنتاج، والاختبارات لا تمثل اختبار ضغط لـ50 ألف منتج.
- كاش معرّفات الرئيسية يستخدم Cache::flexible: فترة جديدة 300 ثانية وفترة قديمة قابلة للعرض حتى 1800 ثانية، والتحديث المؤجّل تحت قفل؛ القفل المنتظر يستخدم فقط عند البداية الباردة. الأسعار الشخصية تُحسب لكل طلب، وتُرسل `Cache-Control: private, no-store` و`Vary: Authorization` للطلبات ذات التوكن.
- تقريب الأسعار في PHP يستخدم الحساب العشري الدقيق وHalfUp ليطابق تقريب PostgreSQL numeric. نسب الخصم الأقل من 1% بعد التقريب تُعرض null ويُستبعد المنتج من قسم التخفيضات.
- شغّل عامل الطابور السابق وLaravel Scheduler (`php artisan schedule:run` كل دقيقة عبر مشغّل النظام). الجدولة تنظف توكنات Sanctum يوميًا، والوظائف الفاشلة الأقدم من أسبوع، وملفات staging الأقدم من ساعة والتحديات المنتهية دوريًا. `queue:monitor` يعمل كل خمس دقائق ويكتب خطأ في السجل عند تجاوز عمق الطابور 100؛ اربط سجلات التشغيل بنظام التنبيه المستخدم عند النشر.
- اضبط حدود حجم جسم الطلب ووقت الرفع في خادم الويب وPHP؛ حدود Laravel تمنع التخزين وإنشاء الحسابات بعد تجاوز المعدل، لكنها لا تمنع وصول جسم الرفع إلى خادم الويب نفسه.

### ترحيل البيانات القائمة

الهجرات القديمة التي تضيف أعمدة مولّدة مخزّنة (`112512_add_storefront_active_flag_to_products` و`115322_normalize_customer_identity`) قد تعيد كتابة الجداول. تطبيقها محليًا على بيانات صغيرة لا يثبت صلاحيتها للنشر دون توقف على جداول كبيرة؛ إن لم تُطبّق بالإنتاج بعد، تحتاج نافذة صيانة أو خطة backfill على دفعات وفهارس CONCURRENTLY قبل تشغيلها. لا تُعِد تشغيل هجرة سبق تطبيقها. امتداد `pg_trgm` يجب أن يكون متاحًا ويُجهّزه مسؤول قاعدة البيانات إن لم يملك مستخدم التطبيق صلاحية إنشائه.

الهجرة الجديدة تستخدم `NOT VALID` لقيود المحمول والحد الائتماني وبيانات الجملة، فتمنع المخالفات الجديدة دون مسح شامل أثناء الإضافة؛ والافتراضي في القاعدة pending لجميع الإدخالات الخام، بينما النموذج يفرض approved للقطاعي. حذف فهرس البريد الزائد في هجرة مستقلة خارج المعاملة مع lock_timeout قصير، وتستبدل الهجرة 141204 فهرس LOWER(email) بفهرس فريد جزئي للبريد الموثّق، خارج المعاملة وبـ CONCURRENTLY. الرجوع عن هذه الهجرة يتطلب إزالة تعارضات البريد غير الموثّق قبل استعادة التفرد القديم.

افحص الهواتف القديمة بـ `php artisan customers:normalize-phones`، ثم `--apply` لتطبيق خطة خالية من التصادمات على دفعات. التصادمات أو الأرقام غير الصالحة تتطلب إصلاحًا يدويًا دون دمج حسابات آلي. بعد استكمال بيانات الجملة القديمة وتصحيح الائتمان شغّل `php artisan customers:validate-domains`. القيم `legacy-{id}` القديمة مسموحة كقيم انتقالية لكنها ليست أرقامًا قابلة لتسجيل الدخول أو الاستعادة. فشل اكتساب قفل الهجرة يستلزم إعادة المحاولة في وقت مناسب.


- ميزانية SMS الدائمة عالمية ولكل نافذة داخل معاملة claim نفسها: `CUSTOMER_SMS_HOURLY_LIMIT=100` و`CUSTOMER_SMS_DAILY_LIMIT=1000`، بالإضافة لميزانية المستلم. رسائل استعادة البريد لا تخصم من ميزانية SMS. الوصول إلى 80% يسجل warning تشغيليًا مرة لكل نافذة؛ عند استنفاد الميزانية تتوقف الرسائل حتى انتهاء النافذة. حد اليوم بتوقيت العمل، وحد الساعة ساعة تقويمية.
- كل حدود IP للعملاء قابلة للضبط عبر `auth.customer_limits`؛ لا تستبدل الميزانية الدائمة بحدود IP. إثبات الهاتف الساري لا يُلغى بطلب إرسال جديد. رموز الإثبات والاستعادة العشوائية تستخدم SHA-256 مع دعم التجزئات القديمة حتى انتهائها؛ OTP يظل محميًا بالتجزئة البطيئة والمحاولات المحدودة.
- عقد بوابة SMS: تستقبل HTTPS JSON `to` و`message` وتعيد 2xx مع `{"success":true}` عند القبول؛ `success=false` أو رد ناقص يعتبر فشلًا. مهام OTP والاستعادة لها محاولة واحدة وtimeout=30 ثانية. يسجل نجاح/فشل OTP لمتابعة نسبة الفشل؛ الإرسال الفاشل يحتفظ بحجز الميزانية احترازيًا لأن فشل الشبكة قد يأتي بعد قبول المزود، وإعادة الطلب تخضع للمهلة المعتادة.
- إعداد الإنتاج `TRUSTED_PROXIES` إلزامي: عناوين أو CIDRs الموازن الفعلية فقط، مثل `10.0.0.10,10.0.1.0/24`. استخدام `none` إقرار صريح بوصول الطلبات مباشرةً. `*` ممنوع في الإنتاج. يجب أن يستبدل الموازن رؤوس forwarding القادمة من العميل؛ لا تثق بقيم أرسلها العميل نفسه.
- تغيير `company_name/tax_number/commercial_register` يتطلب `customers.approve` ويسجل `business_identity_changed`. إنشاء العميل يقبل pending/approved فقط؛ اعتماد القطاعي يثبت approved_at. يسجل التدقيق أيضًا التسجيل الذاتي، الدخول الناجح والفاشل وإصدار التوكنات دون أسرار خام.
- التنظيف الدوري يزيل مجلدات المستندات النهائية اليتيمة بعد ساعة، ويحافظ على ملفات العملاء المحذوفين ناعمًا. تطبيع الهواتف يستخدم خطة مؤقتة في PostgreSQL وكشف التصادمات بـ GROUP BY ودفعات 500 بدل تحميل كل الهواتف في ذاكرة PHP. أخطاء VALIDATE CONSTRAINT غير 23514 لا تُعرض كأخطاء بيانات.
- المخزون السالب ممنوع أصلًا بقيد stock_levels_valid_balances (`on_hand >= reserved + damaged`)، لذلك لا يلزم تغيير معنى الجمع. النصوص العربية ورسائل SMS الأصلية محفوظة بصيغة UTF-8. لا يوجد مسار لإعادة تقديم طلب جملة مرفوض؛ الرقم يبقى محجوزًا، ويمكن للموظف متابعة معالجة الحساب الأصلي. `credit_limit/customer_group_id` في رد العميل الحالي مستمران كجزء من عقد حساب الجملة.

## جولة مراجعة المرحلة 8: الأقفال والذرّية وحماية الدفع

- (C1) ‏OrderService::changeStatus وOrderAllocationService::allocate يعيدان قراءة الطلب بـ lockForUpdate داخل المعاملة — النسخ القديمة بيد المتصلين لا تُمرَّر، والانتقالات على نفس الطلب تتسلسل.
- (H1) ‏ShipmentService يقفل الطلب ويتحقق: الحالة Confirmed/Processing فقط، ولا بوليصة نشطة (غير failed/returned) — إعادة الشحن بعد فشل مسموحة بسياسة صريحة.
- (H2) ‏createFromCart يقفل صف السلة ويقرأ بنودها طازجة — نقرة مكررة تجد سلة فارغة (422) بدل طلب مكرر.
- (H3) عند الشراء يُقفل صف الكوبون حتى نهاية المعاملة (التحقق والعدّ والتسجيل ذرّية)، وحد العميل الواحد يُفرض على الزائر بهاتفه الموحد الصيغة (Customer::normalizePhone) عبر طلباته غير الملغاة — تبديل cart_token لا يتجاوزه. فحص السلة قبل الشراء للزائر يظل بالحد الكلي فقط (لا هاتف بعد).
- (H4) معالجة الويب هوك كلها داخل DB::transaction تبدأ بـ pg_advisory_xact_lock على (provider, event)، ومفتاح الحدث عند غياب معرّف حقيقي هو sha256 لجسم الطلب.
- (H5) حدث paid يُتحقق من مبلغه وعملته مقابل grand_total (مبالغ القروش تُقسم على 100) وعدم التطابق يُسجل amount mismatch بلا أي تغيير؛ لا رجوع من paid إلى failed؛ حدث Paymob المعلّق (pending=true) ليس فشلًا؛ دفع على طلب ملغي يُثبت الدفع ويرفع علم orders.requires_refund (يظهر في رد الأدمن) ولا يُحيي الطلب.
- (H6) مفتاح حدث الشحن: event_id حقيقي فقط (id الشحنة الثابت لا يصلح)، وإلا awb|status|timestamp، وإلا sha256. وحالات البوليصة مرتبة: لا تراجع في سلسلة created→picked_up→in_transit→out_for_delivery→delivered، والحالتان delivered/returned نهائيتان، والفشل قابل للاستئناف.
- (H7) هجرة فهارس للمفاتيح الساخنة: carts.customer_id، orders.customer_id/allocated_branch_id، order_items.order_id/product_variant_id/allocated_branch_id، shipments.order_id، coupon_usages.order_id.
- (H8) ‏DELETE /store/cart/coupon يزيل الكوبون، وفشل الكوبون عند إتمام الشراء يرجع 422 مع code=coupon_invalid في جسم الرد.
- (H9) طرق دفع المتجر من config/store.php ‏(STORE_PAYMENT_METHODS، الافتراضي cod فقط) — وcredit متاح برمجيًا لعملاء الجملة/التوزيع المعتمدين فقط، ولا تُفتح طريقة إلكترونية قبل ربط بوابتها.
- (H10) ‏reserveItemFromBranch يقفل صفوف الأرصدة بـ lockForUpdate وبترتيب id ثابت؛ وفحص canBranchFulfillFullOrder استشاري — الحارس النهائي هو إعادة تحقق recordMovement تحت قفله.

## جولة تقوية المتجر والسلة والويب هوك (M1–M10)

- (M1) توسيع أعمدة shipping_address_line/shipping_notes/order_status_histories.notes/tracking_url_template إلى varchar(500) لتوافق تحقق max:500 — قيم 256–500 لم تعد تنهار 22001.
- (M2) ‏X-Cart-Session غير الـ UUID يُتجاهل، وتوكن أي سلة جديدة يُولّد على الخادم دائمًا.
- (M3) قراءة السلة (GET cart/items وخيارات الشحن) لا تحفظ صفًا — سلة فارغة افتراضية، والإنشاء عند أول إضافة. محددات مسماة: throttle:cart ‏(60/دقيقة)، checkout ‏(10/دقيقة)، order-tracking ‏(10/دقيقة)، webhooks ‏(120/دقيقة).
- (M4) التحقق من التوقيع قبل تخزين حمولة الويب هوك؛ الرفض يُسجل صفًا مختصرًا بلا payload؛ حد جسم 256KB (يرجع 413)؛ ‏webhooks:prune-logs مجدول يوميًا باحتفاظ 90 يومًا.
- (M5) تتبع الطلب: لا notes في السجل المعروض للعامة، ومقارنة الهاتف بصيغة موحدة الطرفين، وحد 10/دقيقة لكل IP.
- (M6) قيد فريد carts.customer_id ودمج سلة الزائر داخل transaction بقفل صف — لا سلال مكررة ولا كميات مضاعفة مع الطلبات المتوازية.
- (M7) أنماط رقمية لمعاملات item/order في المتجر وorder/coupon/promotion/shipping_* في الأدمن — غير الرقمي 404.
- (M8) ‏null صريح لحقول NOT NULL في الكوبونات/العروض يُحذف من حمولة الحفظ في المتحكم (الأعمدة القابلة للإفراغ فعلًا مثل min_order_amount تظل قابلة للتصفير).
- (M9) حذف شركة شحن لها بوليصات أو كوبون له استخدامات يرجع 422 (التعطيل هو البديل)، وحذف كوبون غير مستخدم يفك carts.coupon_id أولًا.
- (M10) تعديل شركة الشحن بـ api_credentials=null لا يمحو الأسرار، وسر توقيع الويب هوك هو webhook_secret الخاص بالشركة فقط — بلا رجوع api_key ولا سر افتراضي.
