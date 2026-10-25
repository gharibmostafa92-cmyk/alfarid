import type { Metadata } from 'next';
import { CategoriesContent } from '@/components/categories/CategoriesContent';

export const metadata: Metadata = {
  title: 'Categories & Bag Collection | Sophie',
  description: 'Explore our signature assortment of premium backpacks, satchels, and leather goods.',
};

export default function CategoriesPage() {
  return <CategoriesContent />;
}
