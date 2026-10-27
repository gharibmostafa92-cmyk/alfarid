'use client';

import { useEffect, useState } from 'react';
import { storeEndpoints } from '@alfarid/api-client';
import { toMegaDepartments, type MegaDepartment } from '@/lib/sophie-mappers';

let cache: { at: number; data: Record<string, MegaDepartment> } | null = null;
let inflight: Promise<Record<string, MegaDepartment>> | null = null;
const TTL = 10 * 60 * 1000;

/** Prefetch categories into the shared cache (e.g. on requestIdleCallback after load). */
export function prefetchMegaMenu() {
  if ((cache && Date.now() - cache.at < TTL) || inflight) return;
  inflight = storeEndpoints.categories()
    .then((tree) => {
      const data = toMegaDepartments(tree);
      cache = { at: Date.now(), data };
      return data;
    })
    .finally(() => { inflight = null; });
  inflight.catch(() => {});
}

export function useMegaMenu() {
  const [departments, set] = useState<Record<string, MegaDepartment> | null>(cache?.data ?? null);
  const [error, setError] = useState(false);
  const [revision, setRevision] = useState(0);

  useEffect(() => {
    let mounted = true;
    const refresh = () => {
    if (cache && Date.now() - cache.at < TTL) { if (mounted) set(cache.data); return; }
    inflight ??= storeEndpoints.categories()
      .then((tree) => {
        const data = toMegaDepartments(tree);
        cache = { at: Date.now(), data };
        return data;
      })
      .finally(() => { inflight = null; });
    inflight.then((data) => { if (mounted) { set(data); setError(false); } }).catch(() => { if (mounted) setError(true); });
    };
    refresh();
    const timer = setInterval(refresh, TTL);
    const visible = () => { if (document.visibilityState === 'visible') refresh(); };
    document.addEventListener('visibilitychange', visible);
    return () => { mounted = false; clearInterval(timer); document.removeEventListener('visibilitychange', visible); };
  }, [revision]);

  return { departments, loading: !departments && !error, error, retry: () => { setError(false); setRevision((v) => v + 1); } };
}
