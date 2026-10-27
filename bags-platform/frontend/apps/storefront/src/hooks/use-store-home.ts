'use client';

import { useQuery } from '@tanstack/react-query';
import { storeEndpoints } from '@alfarid/api-client';
import { useAuthStore } from '@/store/auth.store';
import { useAuthHydrated } from './use-auth-hydrated';
import { storeRetry, storeRetryDelay } from '@/lib/query-retry';
import { viewerKey } from '@/lib/viewer-key';

export function useStoreHome() {
  const token = useAuthStore((state) => state.token);
  const hydrated = useAuthHydrated();
  const viewer = viewerKey(token);

  return useQuery({
    queryKey: ['store', 'home', viewer],
    queryFn: storeEndpoints.home,
    enabled: hydrated,
    staleTime: 60_000,
    placeholderData: (previous, query) => query?.queryKey[2] === viewer ? previous : undefined,
    retry: storeRetry,
    retryDelay: storeRetryDelay,
  });
}
