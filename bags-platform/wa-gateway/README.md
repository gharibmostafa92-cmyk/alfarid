# تشغيل مساعد واتساب

1. استخدم رقم واتساب مخصص للبوت. في `infrastructure/.env` اضبط `WA_GATEWAY_URL=http://wa-gateway:3310`، وولّد قيمتين عشوائيتين مستقلتين لـ `WA_GATEWAY_SECRET` و`WA_SESSION_KEY`. اضبط `WA_SESSION_DATABASE_URL` على اتصال PostgreSQL يسمح بإنشاء جدول الجلسة. احتفظ بمفتاح الجلسة بين مرات التشغيل.
2. نفّذ ترحيلات Laravel وشغّل `SettingSeeder` و`AiAgentPermissionSeeder` ضمن نشر الإصدار. من مجلد `infrastructure` شغّل `docker compose --profile whatsapp up -d --build wa-gateway whatsapp-worker` وأعد إنشاء خدمات Laravel التي تستقبل الإعدادات الجديدة. الـ gateway متاح داخل شبكة Compose.
3. من إعدادات المساعد افتح تبويب واتساب وامسح QR الجهاز بالرقم المخصص. بعد فك الجهاز، أعد تشغيل الخدمة بـ `docker compose --profile whatsapp restart wa-gateway` لطلب QR جديد.
4. امنح موظفي التجربة صلاحيتَي `ai_agent.use` و`ai_agent.whatsapp`. سجّل كل رقم وطلّع رابط الربط، ثم خلي الموظف يرسل رسالة الربط من واتساب. لو سياسة الـ PIN مفعّلة، عيّن PIN لكل موظف.
5. فعّل واتساب مع `ai_whatsapp_allow_writes=0` وجرّب مع 2–3 أدمنز. تحقق من تجاهل الرقم الغريب، وقراءة نص وصورة، وعزل محادثات الويب، وفك ربط الموظف. بعد التجربة افتح الكتابة للخطورة المنخفضة والمتوسطة. ارفع الحد إلى `high` عند الحاجة وبعد تجربة التأكيد المكتوب والتراجع.

لتشغيل البوابة خارج Compose استخدم Node 20 أو أحدث، ثم `npm ci` و`node --env-file=.env src/server.js`. شغّل عامل Laravel بـ `php artisan queue:work whatsapp --queue=whatsapp-ai --timeout=300` والـ scheduler لإرسال تنبيهات الانقطاع إلى Slack عند تهيئته.
