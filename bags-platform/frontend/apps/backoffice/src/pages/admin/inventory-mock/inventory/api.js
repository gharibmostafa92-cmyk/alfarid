// Pure inventory API helpers (no JSX, no InvCtx imports — no import cycles).
// Screens use these together with catalog/useApi + @alfarid/api-client.
import { branchesApi, locationsApi } from '@alfarid/api-client';

// The branch/location index endpoints expose no search/type/active filters
// (only per_page), so list screens page through everything (per_page=100)
// and filter client-side. Branch counts are small, so this is acceptable.
export async function fetchAllPages(listFn, signal) {
  const all = [];
  let page = 1;
  let last = 1;
  do {
    const res = await listFn(page, signal);
    all.push(...(res.data || []));
    last = (res.meta && res.meta.last_page) || 1;
    page += 1;
  } while (page <= last);
  return all;
}

export const fetchAllBranches = (signal) =>
  fetchAllPages((page, s) => branchesApi.list({ per_page: 100, page }, s), signal);

export const fetchAllLocations = (branchId, signal) =>
  fetchAllPages((page, s) => locationsApi.list(branchId, { per_page: 100, page }, s), signal);

// Map a catalog/useApi query state to the Phase names used by inventory screens:
// loading | ready | empty | error | denied (403) | notfound (404) | conflict (409).
export function apiPhase(query, isEmpty) {
  if (query.loading && query.data === undefined) return { phase: 'loading', error: undefined };
  if (query.error) {
    const status = query.error.status;
    if (status === 403) return { phase: 'denied', error: query.error };
    if (status === 404) return { phase: 'notfound', error: query.error };
    if (status === 409) return { phase: 'conflict', error: query.error };
    return { phase: 'error', error: query.error };
  }
  return { phase: isEmpty ? 'empty' : 'ready', error: undefined };
}
