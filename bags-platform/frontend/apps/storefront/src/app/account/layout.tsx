'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { RequireActor } from '@/components/auth/RequireActor';
import { useStoreLang } from '@/providers/store-lang-provider';

const sections = [
  { href: '/account', en: 'My profile', ar: 'حسابي' },
  { href: '/account/orders', en: 'Orders', ar: 'طلباتي' },
  { href: '/account/wallet', en: 'Wallet', ar: 'المحفظة' },
  { href: '/account/returns', en: 'Returns', ar: 'المرتجعات' },
  { href: '/security/password', en: 'Change password', ar: 'تغيير كلمة المرور' },
];

export default function AccountLayout({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const { isAr } = useStoreLang();
  return (
    <RequireActor allow={['retail', 'wholesale']}>
      <div className="store-account">
        <nav className="store-account-nav" aria-label={isAr ? 'أقسام الحساب' : 'Account sections'}>
          {sections.map(({ href, en, ar }) => (
            <Link key={href} href={href} aria-current={pathname === href ? 'page' : href !== '/account' && pathname.startsWith(href + '/') ? 'location' : undefined}>
              {isAr ? ar : en}
            </Link>
          ))}
        </nav>
        {children}
      </div>
    </RequireActor>
  );
}
