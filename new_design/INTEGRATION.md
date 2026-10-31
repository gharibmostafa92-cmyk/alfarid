# مساعد الفريد — دمج التصميم الجديد

انسخ الملفات دي فوق `apps/backoffice/src/pages/admin/assistant/`:

| الملف | الحالة |
|---|---|
| `AssistantPage.tsx` | إعادة كتابة — نفس `can` prop، + `theme?` و `initialConversationId?` (اختياريين) |
| `AssistantBlock.tsx` | إعادة كتابة — نفس الـ Props بالظبط |
| `AssistantComposer.tsx` | إعادة كتابة — نفس الـ Props + `seed?` و `hint?` (اختياريين) |
| `AssistantAdministration.tsx` | إعادة كتابة — نفس `can` prop. **بقت تبويب جوه الصفحة** |
| `assistant.css` | جديد بالكامل (prefix `.as-*`) |
| `icons.tsx` / `RichText.tsx` | ملفات جديدة |
| `api.ts` / `stream.ts` | **من غير أي تعديل** |

`admin-mock/api/permission-catalog.ts` اللي هنا مجرد shim للمعاينة — **متنسخهوش**.

## ملاحظات
- **الأمان (H6):** لسه مفيش `dangerouslySetInnerHTML`. `RichText` بيبني React elements من Markdown بسيط.
- **الارتفاع:** `.assistant` واخد `calc(100dvh - 2rem)` كافتراضي. تقدر تغيّره من الـ layout الأب بـ `--as-height: 100%`.
- **التجاوب:** معمول بـ container queries على `.assistant` نفسه، فبيشتغل صح جوه الـ backoffice أياً كان عرض الـ sidebar بتاعه.
- **الثيم:** بيتبع `prefers-color-scheme`، أو مرّر `theme="dark" | "light"`.
- **أخطاء 429/423/403:** بتتصنف من `response.status`. لو الـ api-client بيرمي الأخطاء من غير `status`، هتظهر كخطأ مؤقت مع زر «إعادة المحاولة».
- **QUICK_COMMANDS** و **STARTERS** معرّفين أعلى `AssistantPage.tsx` — عدّلهم براحتك.
- الخط: IBM Plex Sans Arabic بيتحمّل من Google Fonts. لو المنصة عندها خط خاص بيها، شيل الـ `@import` وغيّر `--as-font`.
