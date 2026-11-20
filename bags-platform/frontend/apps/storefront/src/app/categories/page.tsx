import type { Metadata } from 'next';
import { Suspense } from 'react';
import { CategoriesContent } from '@/components/categories/CategoriesContent';
import { getServerBranding } from '@/lib/core/server-branding';

export async function generateMetadata(): Promise<Metadata> {
  const { name } = await getServerBranding();
  return {
    title: `تشكيلة الشنط والحقائب | ${name}`,
    description: `تصفح تصنيفات الشنط والحقائب المتاحة من ${name}.`,
  };
}

export default function CategoriesPage() {
  return <Suspense fallback={null}><CategoriesContent /></Suspense>;
}
