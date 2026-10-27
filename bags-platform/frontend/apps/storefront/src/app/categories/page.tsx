import type { Metadata } from 'next';
import { Suspense } from 'react';
import { CategoriesContent } from '@/components/categories/CategoriesContent';

export const metadata: Metadata = {
  title: 'Categories & Bag Collection | Sophie',
  description: 'Explore our signature assortment of premium backpacks, satchels, and leather goods.',
};

export default function CategoriesPage() {
  return <Suspense fallback={null}><CategoriesContent /></Suspense>;
}
