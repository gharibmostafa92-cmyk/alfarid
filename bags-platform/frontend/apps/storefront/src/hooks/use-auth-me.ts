'use client';

import { useQuery } from '@tanstack/react-query';
import { authEndpoints } from '@alfarid/api-client';
import type { Destination } from '@/types/auth';

export interface AuthMeResponse {
  actor?: { type?: string };
  redirect?: { destination?: Destination; path?: string };
  data?: Record<string, unknown>;
}

export const authMeQueryKey = (token: string | null) => ['auth', 'me', token] as const;

export function useAuthMe(token: string | null, enabled = true) {
  return useQuery<AuthMeResponse, { response?: { status?: number } }>({
    queryKey: authMeQueryKey(token),
    queryFn: ({ signal }) => authEndpoints.me(signal) as Promise<AuthMeResponse>,
    enabled: Boolean(enabled && token),
    staleTime: 60_000,
    retry: false,
  });
}
