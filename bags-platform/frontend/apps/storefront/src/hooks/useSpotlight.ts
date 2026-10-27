'use client';

import { useQuery } from '@tanstack/react-query';
import { storeEndpoints } from '@alfarid/api-client';
import { toCardVM } from '@/lib/catalog-mappers';
import { useAuthStore } from '@/store/auth.store';
import { viewerKey } from '@/lib/viewer-key';
import { useAuthHydrated } from './use-auth-hydrated';
import { storeRetry, storeRetryDelay } from '@/lib/query-retry';

/** Top-discounted in-stock product of a category — spotlight card for the active mega tab. */
export function useSpotlight(slug?: string, enabled = true) {
  const viewer = viewerKey(useAuthStore((s) => s.token));
  const hydrated = useAuthHydrated();
  return useQuery({
    queryKey: ['store', 'spotlight', viewer, slug],
    queryFn: async ({ signal }) => {
      const res = await storeEndpoints.products({ category: slug, per_page: 8 }, signal);
      return res.data.map(toCardVM).filter((c) => c.inStock && c.price !== null)
        .sort((a, b) => (b.discount ?? 0) - (a.discount ?? 0))[0] ?? null;
    },
    enabled: enabled && hydrated && !!slug, staleTime: 60_000,
    retry: storeRetry, retryDelay: storeRetryDelay,
  });
}
