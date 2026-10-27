import type { Metadata } from 'next';
import { Suspense } from 'react';
import { CategoriesContent } from '@/components/categories/CategoriesContent';

export const metadata: Metadata = {
  title: 'Shop All Bags & Collections | Sophie',
  description: 'Shop curated artisanal backpacks, totes, and leather bags.',
};

export default function ShopPage() {
  return <Suspense fallback={null}><CategoriesContent /></Suspense>;
}
