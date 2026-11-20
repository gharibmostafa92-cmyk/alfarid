import type { Metadata } from 'next';
import { Suspense } from 'react';
import { CategoriesContent } from '@/components/categories/CategoriesContent';
import { getServerBranding } from '@/lib/core/server-branding';

export async function generateMetadata(): Promise<Metadata> {
  const { name } = await getServerBranding();
  return {
    title: `شنط وحقائب أونلاين في مصر | ${name}`,
    description: `تصفح تشكيلة الشنط والحقائب المتاحة من ${name}.`,
  };
}

export default function ShopPage() {
  return <Suspense fallback={null}><CategoriesContent /></Suspense>;
}
