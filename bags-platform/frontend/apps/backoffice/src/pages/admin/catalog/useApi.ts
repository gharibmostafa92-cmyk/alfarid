import { useEffect, useState } from 'react';
import { isAxiosCancel } from '@alfarid/api-client';
import { normalizeError, type ApiError } from './errors';

export function useApi<T>(fn: (signal: AbortSignal) => Promise<T>, deps: unknown[], opts?: { keepPreviousData?: boolean }) {
  const [state, set] = useState<{ data?: T; loading: boolean; error?: ApiError }>({ loading: true });
  const [tick, setTick] = useState(0);
  const keepPreviousData = opts?.keepPreviousData ?? true;
  useEffect(() => {
    const ac = new AbortController();
    // Identity-driven callers opt out of stale data (keepPreviousData: false);
    // list screens keep the default to prevent UI flicker during background re-fetching.
    set(s => (keepPreviousData ? { ...s, loading: true, error: undefined } : { loading: true, error: undefined }));
    fn(ac.signal).then(data => { if (!ac.signal.aborted) set({ data, loading: false }); })
      .catch(e => { if (!ac.signal.aborted && !isAxiosCancel(e)) set(s => ({ ...s, loading: false, error: normalizeError(e) })); });
    return () => ac.abort();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick]);
  return { ...state, reload: () => setTick(t => t + 1) };
}
