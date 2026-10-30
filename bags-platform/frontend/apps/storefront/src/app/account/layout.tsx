'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';

const sections = [
  { href: '/account', label: 'My profile' },
  { href: '/account/orders', label: 'Orders' },
  { href: '/account/wallet', label: 'Wallet' },
  { href: '/account/returns', label: 'Returns' },
];

export default function AccountLayout({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  return (
    <div className="store-account">
      <nav className="store-account-nav" aria-label="Account sections">
        {sections.map(({ href, label }) => (
          <Link key={href} href={href} aria-current={pathname === href ? 'page' : undefined}>
            {label}
          </Link>
        ))}
      </nav>
      {children}
    </div>
  );
}
