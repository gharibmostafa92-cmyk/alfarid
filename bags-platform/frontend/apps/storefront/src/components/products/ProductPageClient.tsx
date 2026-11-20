'use client';

import { useRouter, useSearchParams } from 'next/navigation';
import type { StoreProduct } from '@alfarid/api-client';
import { useStoreProduct } from '@/hooks/use-store-catalog';
import { useAuthHydrated } from '@/hooks/use-auth-hydrated';
import { useAuthStore } from '@/store/auth.store';
import { ProductExperience } from './ProductExperience';

export function ProductPageClient({ product }: { product: StoreProduct }) {
  const router = useRouter();
  const search = useSearchParams();
  const current = useStoreProduct(product.slug);
  const hydrated = useAuthHydrated();
  const token = useAuthStore((state) => state.token);
  if (hydrated && !current.data && (token || current.isError)) {
    return <main id="main-content" role="status">
      {current.isError ? <button onClick={() => current.refetch()}>Retry loading product</button> : 'Loading product…'}
    </main>;
  }
  return <ProductExperience
    key={product.slug + ':' + (search.get('variant') ?? '')}
    product={current.data ?? product}
    purchasingReady={hydrated && !!current.data}
    initialColor={search.get('color')}
    initialSize={search.get('size')}
    initialVariantId={search.get('variant')}
    variantLinks
    onBack={() => router.push('/shop' + (search.toString() ? '?' + search.toString() : ''))}
  />;
}
