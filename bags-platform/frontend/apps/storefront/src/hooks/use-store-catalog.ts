'use client';

import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { storeEndpoints, type StoreProductListParams } from '@alfarid/api-client';
import { useAuthStore } from '@/store/auth.store';
import { useAuthHydrated } from './use-auth-hydrated';
import { storeRetry, storeRetryDelay } from '@/lib/query-retry';
import { viewerKey } from '@/lib/viewer-key';

const common = { retry: storeRetry, retryDelay: storeRetryDelay };
// F22: لا يُخزَّن توكن Bearer الخام في مفاتيح الاستعلام — يظهر في DevTools
// وفي أي تخزين لكاش React Query. بصمة غير عكسية تكفي كمفتاح تمييز.
const useViewer = () => viewerKey(useAuthStore((s) => s.token)); // الأسعار مخصّصة لكل عميل

export function useStoreCategories() {
  return useQuery({ queryKey: ['store', 'categories'], queryFn: ({ signal }) => storeEndpoints.categories(signal), staleTime: 5 * 60_000, ...common });
}

export function useCatalogFilters(params: StoreProductListParams) {
  const viewer = useViewer(); const hydrated = useAuthHydrated();
  return useQuery({ queryKey: ['store', 'filters', viewer, params], queryFn: ({ signal }) => storeEndpoints.filters(params, signal),
    enabled: hydrated, staleTime: 60_000,
    placeholderData: (previous, query) => query?.queryKey[2] === viewer ? previous : undefined, ...common });
}

export function useStoreProducts(params: StoreProductListParams) {
  const viewer = useViewer(); const hydrated = useAuthHydrated();
  return useInfiniteQuery({
    queryKey: ['store', 'products', viewer, params],
    queryFn: ({ pageParam, signal }) => storeEndpoints.products({ ...params, page: pageParam }, signal),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.current_page < Math.min(last.meta.last_page, 500) ? last.meta.current_page + 1 : undefined),
    enabled: hydrated, staleTime: 30_000,
    placeholderData: (previous, query) => query?.queryKey[2] === viewer ? previous : undefined, ...common,
  });
}

export function useStoreProduct(slug: string | null) {
  const viewer = useViewer(); const hydrated = useAuthHydrated();
  return useQuery({ queryKey: ['store', 'product', viewer, slug], queryFn: ({ signal }) => storeEndpoints.product(slug!, signal),
    enabled: hydrated && !!slug, staleTime: 60_000, ...common });
}

export function useSimilarProducts(slug: string | null) {
  const viewer = useViewer(); const hydrated = useAuthHydrated();
  return useQuery({ queryKey: ['store', 'similar', viewer, slug], queryFn: ({ signal }) => storeEndpoints.similar(slug!, signal),
    enabled: hydrated && !!slug, staleTime: 60_000, ...common });
}

export function useStoreShowcase() {
  const viewer = useViewer(); const hydrated = useAuthHydrated();
  return useQuery({ queryKey: ['store', 'showcase', viewer], queryFn: ({ signal }) => storeEndpoints.showcase(signal),
    enabled: hydrated, staleTime: 60_000, ...common });
}
