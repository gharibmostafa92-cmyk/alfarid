/** true = استخدم data.ts بدل الـ API بالكامل */
export const USE_MOCK = import.meta.env.VITE_USE_MOCK_OPERATIONS === 'true';
/** true = إذا فشل الطلب (5xx/شبكة) اعرض بيانات data.ts مع تنبيه */
export const FALLBACK_ON_ERROR = import.meta.env.VITE_OPERATIONS_FALLBACK_ON_ERROR === 'true';
