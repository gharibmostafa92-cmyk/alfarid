'use client';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { RequireActor } from '@/components/auth/RequireActor';
import { logoutWeb } from '@/services/auth.service';

const links = [
  { href: '/supplier/password', label: 'تغيير كلمة المرور' },
  { href: '/supplier', label: 'لوحة التحكم' },
  { href: '/supplier/purchase-orders', label: 'أوامر الشراء' },
  { href: '/supplier/deliveries', label: 'التوريدات' },
  { href: '/supplier/statement', label: 'كشف الحساب' },
  { href: '/supplier/profile', label: 'الملف' },
];

export default function SupplierLayout({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  if (process.env.NEXT_PUBLIC_SUPPLIER_PORTAL_ENABLED !== 'true') {
    return <main className="mx-auto max-w-3xl px-5 py-12" dir="rtl"><h1 className="text-xl font-semibold">بوابة الموردين غير متاحة حاليًا</h1><p className="mt-3">يرجى التواصل مع فريق المشتريات لمتابعة أوامر الشراء والمدفوعات.</p></main>;
  }
  return (
    <RequireActor allow={['supplier']}>
      <div className="min-h-screen bg-[#f3f5f7]" dir="rtl">
        <nav className="border-b border-black/10 bg-white" aria-label="Supplier portal">
          <div className="mx-auto flex max-w-5xl flex-wrap items-center gap-4 px-5 py-3 text-sm">
            {links.map((link) => {
              const active = link.href === '/supplier' ? pathname === link.href : pathname.startsWith(link.href);
              return (
                <Link key={link.href} href={link.href} aria-current={active ? 'page' : undefined}
                  className={active ? 'font-semibold text-black' : 'text-black/60 hover:text-black'}>
                  {link.label}
                </Link>
              );
            })}
            <button type="button" onClick={() => void logoutWeb()} className="ms-auto text-black/60 hover:text-black">
              تسجيل الخروج
            </button>
          </div>
        </nav>
        {children}
      </div>
    </RequireActor>
  );
}
