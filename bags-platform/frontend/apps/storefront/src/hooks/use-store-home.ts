'use client';

import { useEffect, useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { storeEndpoints } from '@alfarid/api-client';
import { useAuthStore } from '@/store/auth.store';

type RequestError = { response?: { status?: number; headers?: Record<string, unknown> } };

export function useStoreHome() {
  const token = useAuthStore((state) => state.token);
  const [hydrated, setHydrated] = useState(false);
  useEffect(() => {
    const unsubscribe = useAuthStore.persist.onFinishHydration(() => setHydrated(true));
    setHydrated(useAuthStore.persist.hasHydrated());
    return unsubscribe;
  }, []);

  return useQuery({
    queryKey: ['store', 'home', token ?? 'guest'],
    queryFn: storeEndpoints.home,
    enabled: hydrated,
    staleTime: 60_000,
    placeholderData: keepPreviousData,
    retry: (count, error: RequestError) => count < 3 && (!error.response || error.response.status === 503),
    retryDelay: (_attempt, error: RequestError) => {
      const seconds = Number(error.response?.headers?.['retry-after'] ?? 2);
      return Number.isFinite(seconds) ? Math.max(0, Math.min(seconds, 60)) * 1000 : 2000;
    },
  });
}
