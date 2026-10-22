// أرقام عربية/فارسية → لاتيني (لما المستخدم يكتب ٣ في خانة الترتيب)
export const toLatinDigits = (s) =>
  String(s ?? '')
    .replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))
    .replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));

// تطبيع للبحث: يشيل التشكيل والتطويل ويوحّد الألف والياء والتاء المربوطة
export const normalizeSearch = (s) =>
  String(s ?? '')
    .toLowerCase()
    .replace(/[\u064B-\u0652\u0640]/g, '')
    .replace(/[أإآ]/g, 'ا')
    .replace(/ى/g, 'ي')
    .replace(/ة/g, 'ه')
    .trim();

// الاسم المعروض مع fallback لو لغة فاضية
export const displayName = (n, lang = 'ar') =>
  (lang === 'ar' ? n.ar || n.en : n.en || n.ar) || '—';

// slug تلقائي من الإنجليزي فقط (لو الناتج فاضي → نسيبه null والباك يولّد)
export const autoSlug = (en) =>
  String(en ?? '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
