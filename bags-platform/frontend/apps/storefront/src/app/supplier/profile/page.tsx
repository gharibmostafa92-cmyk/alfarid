'use client';
import { useAuthStore } from '@/store/auth.store';

export default function SupplierProfilePage() {
  const user = useAuthStore(state => state.user);
  return <main id="main-content" className="mx-auto max-w-5xl px-5 py-8" dir="rtl"><h1 className="mb-5 text-2xl font-semibold">الملف</h1><div className="rounded-xl bg-white p-5"><p>الاسم: {String(user?.name ?? '—')}</p><p>البريد الإلكتروني: {String(user?.email ?? '—')}</p><p className="mt-3">لتعديل البيانات تواصل مع فريق المشتريات.</p></div></main>;
}
