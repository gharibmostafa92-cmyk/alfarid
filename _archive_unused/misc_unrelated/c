C1 — خصم POS يُطبَّق مرتين — CRITICAL
الموقع: PosService::createSale وresolveItems.

المشكلة: resolveItems يحسب line_total = round(unitPrice*qty - discount, 2)، أي أن الخصم مطروح فعلًا. بعد ذلك تحسب createSale:

Copy$subtotal = $items->sum('line_total');          // صافٍ بعد الخصم
$discountTotal = $items->sum('discount_amount');
$grandTotal = $subtotal - $discountTotal;        // الخصم يُطرح مرة ثانية
الشرط: أي بيع فيه discount_amount > 0.

الأثر:

خسارة إيراد مباشرة في كل فاتورة مخفضة.
subtotal المخزّن قيمة صافية فيُضلل التقارير.
إذا كان الخصم أكبر من نصف قيمة السطر يصبح الإجمالي سالبًا. مثال: سعر 100 وخصم 60 يعطي سطرًا بـ 40 وإجماليًا بـ −20. عندها يصبح cash_amount الافتراضي −20 ولا يُسجَّل في الخزينة، لكن closeShift يجمعه فيُنقص النقدية المتوقعة ويغطي اختلاسًا.
الإصلاح: في القسم 9. احسب subtotal من السعر في الكمية، واجعل الإجمالي Σ line_total. قارن بالقروش كأعداد صحيحة، وارفض المبالغ السالبة.

لماذا ينجح: تعريف واحد للخصم في مكان واحد.

المقايضة: لا توجد. الطلبات التاريخية تحتاج سكربت تصحيح للتقارير.

C2 — مرتجع POS قابل للتكرار بلا حد — CRITICAL
الموقع: PosService::processReturn.

المشكلة: الفحص الوحيد هو $ret['qty'] > $originalItem->qty، وكل طلب مرتجع يُفحص وحده. وفوق ذلك:

لا يوجد قفل على الطلب الأصلي، ولا مجموع للكميات المرتجعة سابقًا.
يمكن تمرير نفس المتغير في سطرين داخل الطلب نفسه، وكل سطر يمر من الفحص منفردًا.
لا يوجد فحص لحالة الطلب الأصلي. طلبات المرتجع نفسها channel='pos' ولها بنود، فيمكن "إرجاع المرتجع" فيُرد النقد مرة أخرى ويُضاف مخزون مرة أخرى.
قيمة الرد unit_price * qty تتجاهل خصم السطر، فالرد يتجاوز ما دفعه العميل.
الرد دائمًا نقدي من الدرج حتى لو كان البيع بالبطاقة، فتخرج نقدية لم تدخل الدرج أصلًا.
مسار الاستبدال معطّل: cash_amount => max(0, 0 - $returnValue) يساوي صفرًا دائمًا، وcard_amount=0، وcreateSale يشترط أن يساوي مجموعهما الإجمالي. النتيجة أن أي استبدال بقيمة موجبة يفشل، ويسقط معه المرتجع كله.
firstWhere('product_variant_id') يتجاهل الطلبات التي فيها سطران لنفس المتغير.
الشرط: كاشير لديه وردية مفتوحة. لا يحتاج أي تواطؤ.

الأثر: سحب نقدية غير محدود من الدرج، وتضخيم وهمي للمخزون عبر حركات SaleReturn متكررة، ودفاتر خزينة ومخزون صحيحة شكليًا لكنها خاطئة فعليًا.

الإصلاح: في القسم 9:

قفل الطلب الأصلي.
اشتراط status = Delivered وأن الطلب ليس مرتجعًا (عمود return_of_order_id).
عمود returned_qty على order_items مع قيد CHECK (returned_qty <= qty).
تجميع الأسطر المكررة قبل الفحص.
الرد بالسعر الصافي line_total/qty.
سقف للرد النقدي يساوي النقد المدفوع ناقص المردود سابقًا.
إزالة الاستبدال المحسوب داخليًا: يصبح بيعًا مستقلًا يرسله العميل بمبالغه.
لماذا ينجح: قيد في القاعدة مع قفل صف يجعل التجاوز مستحيلًا حتى مع التزامن.

المقايضة: مايجريشن، ومسار مختلف لرد مبالغ البطاقات.

C3 — فك الحجز غير صافٍ: بيع مزدوج وسرقة حجوزات — CRITICAL
الموقع: OrderService::reserveMovements وshipOrder وreleaseReservations، وOrderAllocationService::deallocate.

المشكلة: الدوال الثلاث تقرأ كل حركات type = Reserve للطلب، ثم تُصدر لكل حركة ReleaseReserve (ومعها Sale عند الشحن) بكامل الكمية. ولا تطرح ما فُك سابقًا.

allocate يمنع إعادة التوزيع فقط بفحص allocated_branch_id !== null، وdeallocate يصفّر هذا العمود. لذلك هذا التسلسل مسموح:

allocate ← deallocate ← allocate ← createShipment

الأثر: عند الشحن تُفك الحجوزات القديمة والجديدة معًا، ويُخصم Sale مرتين من on_hand.

إذا لم يكن على نفس صف الرصيد حجوزات لطلبات أخرى، تفشل العملية ويصبح الطلب غير قابل للشحن.
إذا وُجدت حجوزات لطلبات أخرى، تنجح العملية وتفك حجوزاتها بصمت، فتنكشف تلك الطلبات للبيع المزدوج (oversell). وفوق ذلك ينخفض on_hand بضعف الكمية المشحونة.
الشيء نفسه يحدث عند الإلغاء أو deallocate ثانٍ بعد إعادة التوزيع. القيود في القاعدة لا تكشف ذلك، لأن الرصيد الإجمالي يبقى غير سالب.

الإصلاح: استخدم مصدرًا واحدًا هو "الحجز القائم الصافي" لكل (متغير، فرع، موقع) بتجميع SUM(quantity) على وعاء reserved المرتبط بالطلب. واقفل الصفوف مرتبة عبر lockLevels قبل التنفيذ. الكود في القسم 9.

لماذا ينجح: الحجز القائم يُحسب من الدفتر نفسه، فلا يُفك أي حجز إلا مرة واحدة مهما تكرر التوزيع.

المقايضة: استعلام تجميعي بدل قراءة صفوف، وتكلفته مهملة.

C4 — تعديل السعر والخصم بلا صلاحية — CRITICAL (مشروط)
الموقع: PosService::resolveItems.

المشكلة: التعليق يقول "يدوي (بشرط الصلاحية)" لكن الكود لا يحتوي أي فحص:

Copyif ($overridePrice && isset($item['unit_price'])) { $unitPrice = (float) $item['unit_price']; }
والخصم discount_amount يُقبل كما هو بشرط واحد هو line_total >= 0.

متحكم البيع غير موجود في اللقطة. إذا لم يفحص صلاحية مثل pos.override_price (والسلسلة pos.override غير موجودة في أي مكان في الكود الذي رأيته)، فأي كاشير يبيع بسعر صفر أو بخصم 100%.

التحقق: ابحث في متحكم بيع POS عن can( قبل استدعاء createSale.

الإصلاح: نفّذ الفحص داخل الخدمة، وهي نقطة الدخول الوحيدة، بصلاحية للسعر وأخرى للخصم مع سقف نسبة من الإعدادات (القسم 9).

المقايضة: تمرير الفاعل (actor) إلى الخدمة.

C5 — استلام الإنتاج بلا صلاحية — CRITICAL (مشروط)
الموقع: ProductionReceiptController::middleware() يحمي options وindex فقط بـ can:inventory.view. أما store فلا يحميه شيء في المتحكم، ومسار POST production-receipts في admin.php بلا middleware صلاحية.

الأثر: إذا كانت StoreProductionReceiptRequest::authorize() تعيد true كباقي الطلبات، فأي موظف نشط (كاشير مثلًا) يستطيع:

إضافة مخزون في فرعه بلا مقابل فعلي.
تغيير cost_price (المتوسط المرجح) للمتغير على مستوى كل الفروع، فتفسد تقارير الربح والتقييم.
الإصلاح:

Copynew Middleware('can:inventory.production_receive', only: ['store']),
مع إضافة الصلاحية في InventoryPermissionSeeder.

H1 — اعتماد ورفض تحويل الخزينة بلا قفل — HIGH
الموقع: TreasuryService::approveTransfer وrejectTransfer.

المشكلة: فحص status !== Pending يتم خارج المعاملة وعلى نسخة غير مقفولة.

نقرتان متزامنتان على "اعتماد" تمران من الفحص، فيُنفَّذ الخصم والإضافة مرتين.
"اعتماد" و"رفض" متزامنان يتركان الأرصدة منقولة بينما الحالة النهائية Rejected.
rejectTransfer لا يستخدم معاملة أصلًا.
الإصلاح: lockForUpdate على التحويل داخل المعاملة، ثم إعادة فحص الحالة، ثم قفل الخزينتين بترتيب id (القسم 9).

المقايضة: لا توجد.

H2 — آلة حالات الوردية بلا ضمانات تزامن — HIGH
الموقع: PosService::openShift وcloseShift وapproveShift وcreateSale، والمايجريشن create_pos_and_treasury_tables.

المشكلة:

openShift يستخدم lockForUpdate()->exists() على صفوف غير موجودة، وهذا لا يقفل شيئًا. وفهارس ['user_id','status'] و['treasury_id','status'] غير فريدة. النتيجة أن طلبين متزامنين يفتحان ورديتين على نفس الدرج أو لنفس الكاشير.
closeShift وapproveShift يفحصان الحالة خارج المعاملة وبلا قفل. الاعتماد المزدوج يستدعي settleShiftToMainSafe مرتين، وثاني ترحيل ينقل ما تبقى في الدرج (العهدة النقدية أو مبالغ وردية أخرى).
سباق بين البيع والإغلاق: createSale يفحص Open على نسخة الـ middleware ثم يكمل. إذا اعتمد بيع بعد أن حسب closeShift مجاميعه، يدخل النقد الخزينة لكنه يغيب عن expected_closing_cash، فيظهر فرق وهمي.
الإصلاح:

فهارس فريدة جزئية: UNIQUE (user_id) WHERE status='open' وUNIQUE (treasury_id) WHERE status='open'.
lockForUpdate على الوردية في close وapprove وcreateSale وprocessReturn وrecordExpense، مع إعادة فحص الحالة بعد القفل.
المقايضة: البيوع المتزامنة على نفس الوردية تتسلسل. هذا مقبول لأن الوردية الواحدة يعمل عليها كاشير واحد.

H3 — دفتر الخزينة يخلق نقدًا من العدم ولا يسوّي العجز — HIGH
الموقع: openShift (تسجيل OpeningBalance) وsettleShiftToMainSafe.

المشكلة:

openShift يضيف opening_cash إلى رصيد الدرج كدخل جديد بلا مصدر مقابل (لا خصم من الخزنة الرئيسية).
عند الاعتماد يُرحَّل actual_closing_cash، وهو يشمل العهدة، إلى الخزنة الرئيسية.
النتيجة: الخزنة الرئيسية تكبر بقيمة العهدة مع كل وردية.
فرق العجز (cash_difference) لا يُرحَّل كحركة. يبقى في رصيد الدرج كنقد وهمي يتراكم، وmin(drawerBalance, requested) يخفي المشكلة بدل أن يكشفها.
الأثر: رصيد الخزائن الدفتري لا يطابق النقد الفعلي، وتقارير النقدية غير موثوقة.

الإصلاح:

العهدة تكون تحويلًا من الخزنة الرئيسية إلى الدرج، أو يُتحقق منها فقط مقابل رصيد الدرج القائم دون إضافة.
عند الاعتماد يُرحَّل cash_difference كحركة CashShortage أو CashOverage حتى يعود الدرج للصفر أو لقيمة العهدة.
المقايضة: نوعا حركة جديدان، وتعديل على enum TreasuryTransactionType.

H4 — غياب نطاق الفروع عن الخزينة والورديات والتقارير — HIGH
الموقع:

TreasuryTransferController: كل الدوال.
TreasuryController.
PosShiftAdminController: index وshow وapprove.
ReportController: كل التقارير.
Admin\ReturnController::index.
المشكلة: باقي النظام يفرض BranchAccess بنموذج fail-closed، لكن هذه المتحكمات لا تفرضه. مدير فرع لديه treasury_transfers.approve يستطيع نقل أموال بين خزائن أي فرعين. ومن لديه reports.view يرى مبيعات وأرباح كل الفروع.

الإصلاح: طبّق restrictedBranch في الفهارس، وassertBranch على الكيان في show وapprove. للخزائن ذات branch_id = null (خزائن مركزية) استخدم assertCentralAccess.

المقايضة: لا توجد.

H5 — بيع POS بلا idempotency — HIGH
الموقع: PosService::createSale، ولا وجود لكلمة "idempotency" في الخدمة.

الأثر: انقطاع الشبكة ثم إعادة المحاولة من الكاشير يعني فاتورتين، وخصم مخزون مضاعف، ونقد مسجل مرتين في الخزينة.

الإصلاح: لفّ العملية بـ OperationIdempotencyService::run('pos_sale', $user, $key, $payload, fn () => ...) وهي جاهزة في الكود، مع ترويسة Idempotency-Key إلزامية.

H6 — الشراء يقفل كل صفوف أرصدة المتغير في كل الفروع — HIGH (أداء وتوافر)
الموقع: OrderAllocationService::lockStockRows.

المشكلة: الدالة تقفل FOR UPDATE كل صفوف stock_levels للمتغيرات المطلوبة في كل الفروع والمواقع، داخل معاملة الشراء نفسها (لأن الحجز يحدث عند الإنشاء).

الأثر: على منتج رائج، كل عمليات الشراء ومبيعات POS والتحويلات في كل الفروع تتسلسل على نفس الأقفال. تحت حمل حملة ترويجية يُرفض جزء كبير من الطلبات بـ 409 بسبب مهلة الـ 5 ثوانٍ.

الإصلاح:

احسب خريطة التغطية بلا قفل.
اختر الفرع المرشح.
اقفل صفوف ذلك الفرع فقط.
إذا فشل recordMovement لنقص الرصيد، جرّب المرشح التالي (عدد محاولات محدود).
recordMovement يعيد فحص المتاح تحت القفل، فالصحة محفوظة.

المقايضة: منطق إعادة محاولة بسيط بدل قفل عالمي.

H7 — Returned يدوي بعد RMA جزئي — HIGH (مشروط بثابت TRANSITIONS)
الموقع: OrderService::changeStatus وreturnSalesToStock.

المشكلة: إذا سمحت TRANSITIONS بالانتقال من Delivered إلى Returned عبر updateStatus، فإن returnSalesToStock يعيد كل حركات البيع إلى المخزون، حتى لو كان جزء منها استُلم فعلًا عبر ReturnService::receive. ومسار المحفظة يعكس المبلغ كاملًا (order_reversal) فوق الرد الجزئي السابق.

الإصلاح: امنع الانتقال اليدوي إلى Returned إذا وُجد أي ReturnRequest غير ملغى أو مرفوض. أو احسب الصافي: المبيع ناقص sale_return المرتبط بمرتجعات الطلب.

M1 — فلتر low_stock في InventoryController::index يسبب خطأ 500 — MEDIUM
->join('product_variants', ...) ثم ->orderBy('id') بلا تأهيل، وعمود id موجود في الجدولين، فيرفض PostgreSQL الاستعلام بخطأ "column reference id is ambiguous". الإصلاح أن تؤهّل كل الأعمدة: stock_levels.id وstock_levels.branch_id وغيرها، في where وorderBy.

M2 — المسح التراكمي في الجرد يُبطل كشف اللقطة القديمة — MEDIUM
incrementItemCount يستدعي upsertItems بكمية جديدة في كل مسح، فيتحقق شرط countChanged ويُعاد أخذ system_qty وsnapshot_movement_id مع كل مسح.

النتيجة: الوحدات التي عُدّت في أول مسح ثم بيعت قبل آخر مسح تظهر "مكسبًا" وهميًا عند الاعتماد، وسياسة reject لا تكشف ذلك. الإصلاح أن تحفظ اللقطة من أول عدّ للصنف ولا تعيد أخذها إلا مع recount صريح.

M3 — أحداث تُطلق داخل المعاملات ومستمعون غير مؤجلين لما بعد الاعتماد — MEDIUM
StockLowDetected وTransferPendingApproval تُطلق داخل DB::transaction، والمستمعون ShouldQueue بلا ShouldHandleEventsAfterCommit. مع Redis تظهر تنبيهات لمعاملات تراجعت، أو يحدث ModelNotFoundException لتحويل لم يُعتمد بعد. الإصلاح أن تضيف الواجهة، أو تضبط after_commit => true في إعدادات الطابور.

M4 — POS ومرتجعات RMA تعمل على صف "بلا موقع" فقط — MEDIUM
resolveItems يقرأ whereNull('location_id')، وcreateSale يخصم بلا موقع.
ReturnService::receive يُدخل البضاعة إلى location_id = null.
في فرع يستخدم المواقع (والجرد يرفض الجرد الإجمالي هناك عبر assertScopedLocation)، تفشل مبيعات POS، وتختفي المرتجعات عن الجرد. الإصلاح أن تضيف موقعًا افتراضيًا للبيع والاستلام في الفرع (مثل Receiving أو موقع للعرض)، أو تفرض اختياره.

M5 — دقة التقارير — MEDIUM
الربح: تكلفة المبيعات تُحسب بـ COALESCE(sm.unit_cost, pv.cost_price)، لكن حركات البيع في createSale وshipOrder لا تحفظ unit_cost. النتيجة أن تقارير الربح التاريخية تتغير مع كل تغيّر في المتوسط المرجح. والحل أن تحفظ unit_cost لحظة البيع.
المرتجعات: تقرير الربح لا يطرح sale_return، وطلب POS الأصلي يبقى Delivered، فالمبيعات مضخمة بعد المرتجعات.
التوقيت: الحدود الزمنية $from.' 00:00:00' بتوقيت UTC، بينما الدفتر يستخدم business_timezone، فيحدث انزياح ساعتين أو ثلاث بتوقيت القاهرة.
النطاق: لا يوجد حد لمدى التاريخ، فطلب على 50 سنة يعني مسحًا كاملًا للجداول. ضع سقفًا مثل 366 يومًا.
M6 — تحويل مشحون إلى فرع عُطّل يعلق للأبد — MEDIUM
receive يرفض الاستلام إذا كان الفرع المستلم غير نشط، وcancel يرفض الإلغاء من حالة Shipped، وBranchController::update يسمح بتعطيل فرع لديه بضاعة في الطريق. الإصلاح أن ترفض تعطيل فرع لديه in_transit > 0 أو تحويلات بحالة Shipped واردة إليه.

M7 — سلامة الخزائن — MEDIUM
TreasuryController::update يسمح بتغيير type. تحويل pos_drawer إلى bank_account يتجاوز حماية الرصيد السالب، ويسمح بتغيير branch_id لدرج عليه وردية مفتوحة.
حماية الرصيد السالب موجودة في التطبيق فقط، بلا CHECK في القاعدة.
لا يوجد فصل بين المنشئ والمعتمد: منشئ التحويل يستطيع اعتماده بنفسه.
record() يحسب المال بـ float.
الإصلاح: اجعل type غير قابل للتعديل، وأضف CHECK (type='bank_account' OR current_balance >= 0)، وامنع أن يكون approved_by هو created_by.

M8 — ترتيب الأقفال في الشحن والفك وبيع POS — MEDIUM
shipOrder وreleaseReservations وcreateSale وprocessReturn تستدعي recordMovement لكل صنف على حدة، بترتيب id الحركة أو ترتيب الإدخال وليس بالترتيب العام. عمليتان متداخلتان قد تقعان في deadlock (40P01)، ومعاملات هذه المسارات بلا attempts. الإصلاح أن تستدعي lockLevels مسبقًا على كل المفاتيح، ثم تمرر الصف المقفول كما يفعل TransferService::move.

M9 — حبس المخزون عبر طلبات زوار وهمية — MEDIUM
الحجز يحدث عند الإنشاء، والحد 20 طلبًا يوميًا لكل رقم هاتف غير متحقق منه و10 في الدقيقة لكل IP. مهاجم يغيّر الأرقام ويستخدم IPv6 يستطيع حبس المخزون المتاح لمدة 15 دقيقة في كل دورة. الإصلاح: حد يومي إجمالي لكل IP أو شبكة /64، أو مهلة حجز أقصر للزوار في الدفع عند الاستلام.

M10 — مبالغ الدفع المختلط بلا قيود إشارة — MEDIUM
createSale يقبل cash_amount = -10 وcard_amount = 110 لفاتورة بقيمة 100، والنقد السالب يُنقص النقدية المتوقعة في closeShift. الإصلاح أن تفرض في الخدمة أن تكون المبالغ أكبر من أو تساوي صفرًا، وأن تساوي بالقروش الصحيحة، بدل تسامح 0.01.

LOW
L1: فهرسا stock_movements_stale_check_index وstock_movements_reconcile_index شبه مكررين. الفهرس (branch_id, location_id, product_variant_id, affected_bucket, id) يخدم الاستعلامين معًا، لأن كلها مساواة. والفهرس المنفرد على branch_id صار زائدًا. الجدول الأكثر كتابة يدفع ثمن أربعة فهارس متداخلة.
L2: inventory:reconcile يمسح الدفتر كاملًا بدوال نافذة (window functions) كل ليلة، ويحتفظ بلقطة طويلة تعطل الـ vacuum. اجعله تراكميًا بنقطة تحقق عند آخر id تمت مطابقته.
L3: reserveItemFromBranch يحسب $remaining -= $take بـ float، فقد يبقى باقٍ ضئيل جدًا (1e-17 > 0) ويرمي "كمية غير كافية". استخدم وحدات صحيحة كما في recordMovement.
L4: صلاحية رؤية التكلفة باسمين مختلفين: inventory.view_cost في الحركات وproducts.view_cost في الإنتاج.
L5: توكن الإدارة في localStorage. لا يوجد dangerouslySetInnerHTML، وهذا جيد، لكن أي XSS مستقبلي يسرق التوكن. ضع CSP صارمًا كحد أدنى.
L6: reserveMovements يبحث بـ getMorphClass() فقط، والتعليقات تقول إن الصفوف القديمة مخزنة باسم الكلاس الكامل. استخدم whereIn([alias, FQCN]) كما في TransferService::delete.
L7: ProcurementService يحسب المتوسط المرجح من on_hand فقط، بلا in_transit وبحساب float، وهذا يخالف ProductionReceiptService. الأثر منخفض لأن مسارات purchase-* تعيد 410 عبر ManufacturingBusiness، بشرط أن يكون هذا الـ middleware مسجلًا عالميًا. لم أرَ مكان تسجيله، فتحقق منه.
L8: scan يبحث بالباركود بلا شرط تفرّد وبلا فحص is_active.
INFO
مدة الاحتفاظ بمفاتيح idempotency قبل الحذف الليلي يجب أن تتجاوز أطول نافذة إعادة محاولة للعميل.
StockLevel::$fillable يشمل الأرصدة. المطابقة الليلية تكشف الكتابة المباشرة، لكن إزالة الأرصدة من fillable تمنعها من الأصل.