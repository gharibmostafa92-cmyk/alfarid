# عينات الربط الفعلي للمتجر

الملفات `categories.json` و`cities.json` و`cart-items.json` و`shipping-options.json` هي ردود JSON فعلية من Laravel HTTP kernel، التُقطت باستخدام بيانات factories في قاعدة PostgreSQL الاختبارية `alfarid_testing`، وليست أمثلة مكتوبة يدويًا. ملف `checkout-shipping-options.json` نسخة من عينة الشحن لتوافق الاسم السابق.

العينة تشمل سلة بمنتجين، كوبون شحن مجاني، ضريبة 14%، ورسوم استلام 12 جنيهًا. معاينة الإجمالي 468 جنيهًا طابقت الطلب المحفوظ، وتكرار الطلب بنفس المفتاح أعاد الطلب نفسه بعد إفراغ السلة.

لإعادة الالتقاط من مجلد `backend` في PowerShell:

```powershell
$env:CAPTURE_STOREFRONT_SAMPLES='1'
php artisan test --compact tests/Feature/Orders/StoreOrderFlowTest.php --filter=test_shipping_preview_matches_committed_order_and_replay_after_cart_is_empty
Copy-Item -LiteralPath '../docs/api-samples/shipping-options.json' -Destination '../docs/api-samples/checkout-shipping-options.json'
Remove-Item Env:CAPTURE_STOREFRONT_SAMPLES
```

عقود تم التحقق منها:

- قائمة المنتجات تقبل `category` و`per_page`، و`STORE_ROUTES.shop({category})` يولّد `/shop?category=...`.
- تطبيق الكوبون يستقبل `{code}` ويرجع `{data:{cart,coupon_discount}}`؛ عميل API يفك `data` قبل تسليم السلة للـstore.
- المدن والسلة ومعاينة الشحن ترجع جميعها `{data:...}`.
- `shipping-options` يحسب الخصم والشحن والضريبة ورسوم الاستلام على الخادم. `payment_methods` من نفس سياسة التحقق عند الشراء.
- مدة التوصيل تكون `null` عند غياب تسعيرة تحددها؛ لا تُعرض مدة مفترضة.
- تفاصيل الطلب المالية قد تأتي كـdecimal strings. الواجهة تحولها لأرقام للتنسيق باستخدام `formatStorePrice` بالجنيه المصري.
- تتبع طلب زائر يحتاج رقم موبايل الطلب. رقم الطلب وحده لا يكشف بياناته.

فحص HTTP على السيرفر المحلي بتاريخ 2026-10-26: الأقسام 200، المدن 200 بقائمة فارغة، السلة 200؛ معاينة الشحن لمدينة غير موجودة 422. يلزم إعداد المدن وتسعير الشحن قبل تجربة شراء باستخدام بيانات التشغيل المحلية. لم تُضف مدن أو تُنشأ طلبات في قاعدة التشغيل أثناء التحقق.
