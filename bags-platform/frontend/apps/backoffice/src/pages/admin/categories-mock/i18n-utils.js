// Normalizes Arabic-Indic and Eastern digits to standard ASCII numerals (e.g. when typing ٣ in sort order)
export const toLatinDigits = (s) =>
  String(s ?? '')
    .replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))
    .replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));

// Normalizes Arabic search text: strips diacritics, tatweel, and unifies alef, yaa, and taa marbuta
export const normalizeSearch = (s) =>
  String(s ?? '')
    .toLowerCase()
    .replace(/[\u064B-\u0652\u0640]/g, '')
    .replace(/[أإآ]/g, 'ا')
    .replace(/ى/g, 'ي')
    .replace(/ة/g, 'ه')
    .trim();

// Resolves display name with fallback to alternate locale if primary is empty
export const displayName = (n, lang = 'ar') =>
  (lang === 'ar' ? n.ar || n.en : n.en || n.ar) || '—';

// Generates URL slug from English name; leaves null if empty so backend auto-generates
export const autoSlug = (en) =>
  String(en ?? '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
