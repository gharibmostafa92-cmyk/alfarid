// Preview shim only — في المشروع الحقيقي الملف ده موجود بالفعل في admin-mock/api.
const labels: Record<string, string> = {
  'users.view': 'عرض الموظفين',
  'users.create': 'إضافة موظف',
  'users.update': 'تعديل بيانات موظف',
  'users.delete': 'حذف موظف',
  'roles.manage': 'إدارة الأدوار',
  'branches.view': 'عرض الفروع',
  'branches.update': 'تعديل الفروع',
  'settings.update': 'تعديل إعدادات النظام',
  'reports.export': 'تصدير التقارير',
  'inventory.adjust': 'تسوية المخزون',
  'ai_agent.execute': 'تنفيذ عمليات المساعد',
};
const sensitive = new Set(['users.delete', 'roles.manage', 'settings.update', 'ai_agent.execute']);
export const permLabel = (p: string) => labels[p] ?? p;
export const isSensitive = (p: string) => sensitive.has(p);
