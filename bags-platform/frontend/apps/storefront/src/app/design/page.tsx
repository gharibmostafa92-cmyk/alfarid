import type { Metadata } from 'next';
import { Suspense } from 'react';
import { DesignShowcaseContent } from '@/components/design/DesignShowcaseContent';
import { getServerBranding } from '@/lib/core/server-branding';

export async function generateMetadata(): Promise<Metadata> {
  const { name } = await getServerBranding();
  const base = process.env.STOREFRONT_PUBLIC_URL?.replace(/\/$/, '');
  return {
    title: `عرض الشنط التفاعلي | ${name}`,
    description: `استعرض تفاصيل وتصميمات الشنط المتاحة لدى ${name}.`,
    robots: { index: false, follow: true },
    alternates: base ? { canonical: base + '/showcase' } : undefined,
  };
}

export default function DesignPage() {
  return <Suspense fallback={null}><DesignShowcaseContent /></Suspense>;
}
