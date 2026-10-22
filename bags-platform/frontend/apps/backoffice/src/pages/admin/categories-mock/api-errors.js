const FIELD_MAP = {
  name_ar: 'ar', name_en: 'en', slug: 'slug',
  description_ar: 'desc_ar', description_en: 'desc_en',
  sort_order: 'order', parent_id: 'parentId', is_active: 'status', image: 'img',
};

const FALLBACK = {
  0:   'تعذّر الاتصال بالخادم، تأكد من الإنترنت وحاول تاني',
  403: 'ليس لديك صلاحية لتنفيذ هذا الإجراء',
  404: 'القسم غير موجود أو تم حذفه',
  409: 'البيانات اتغيرت من مستخدم تاني، حدّث الصفحة',
  422: 'البيانات غير صالحة',
  429: 'محاولات كتير، استنى شوية وحاول تاني',
  500: 'حصل خطأ في الخادم',
};

export function parseApiError(err) {
  const status = err?.response?.status ?? 0;
  const data = err?.response?.data ?? {};
  const fieldErrors = {};
  Object.entries(data.errors ?? {}).forEach(([k, msgs]) => {
    const key = FIELD_MAP[k] ?? (k.startsWith('items') ? 'items' : k);
    fieldErrors[key] = Array.isArray(msgs) ? msgs[0] : String(msgs);
  });
  return {
    status,
    message: data.message || FALLBACK[status] || FALLBACK[500],
    fieldErrors,
    isValidation: status === 422 && Object.keys(fieldErrors).length > 0,
  };
}
