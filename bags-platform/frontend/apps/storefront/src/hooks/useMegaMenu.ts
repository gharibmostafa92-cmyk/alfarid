'use client';

import { useMemo } from 'react';
import type { QueryClient } from '@tanstack/react-query';
import { storeEndpoints } from '@alfarid/api-client';
import { toMegaDepartments } from '@/lib/catalog/sophie-mappers';
import { useStoreCategories } from './use-store-catalog';

const MEGA_MENU_QUERY_KEY = ['store', 'categories'] as const;

/** Prefetch categories into the shared React Query cache (e.g. on requestIdleCallback after load). */
export function prefetchMegaMenu(queryClient: QueryClient) {
  return queryClient.prefetchQuery({
    queryKey: MEGA_MENU_QUERY_KEY,
    queryFn: ({ signal }) => storeEndpoints.categories(signal),
    staleTime: 5 * 60_000,
  });
}

export function useMegaMenu() {
  const query = useStoreCategories();
  const departments = useMemo(
    () => (query.data ? toMegaDepartments(query.data) : null),
    [query.data],
  );

  return {
    departments,
    loading: query.isPending && !departments,
    error: query.isError,
    retry: () => void query.refetch(),
  };
}
