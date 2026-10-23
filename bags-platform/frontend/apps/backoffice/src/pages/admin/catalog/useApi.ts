import { useEffect, useState } from 'react';
import { isAxiosCancel } from '@alfarid/api-client';
import { normalizeError, type ApiError } from './errors';

export function useApi<T>(fn: (signal: AbortSignal) => Promise<T>, deps: unknown[]) {
  const [state, set] = useState<{ data?: T; loading: boolean; error?: ApiError }>({ loading: true });
  const [tick, setTick] = useState(0);
  useEffect(() => {
    const ac = new AbortController();
    set(s => ({ ...s, loading: true, error: undefined }));   // خلي الداتا القديمة ظاهرة أثناء التحميل
    fn(ac.signal).then(data => { if (!ac.signal.aborted) set({ data, loading: false }); })
      .catch(e => { if (!ac.signal.aborted && !isAxiosCancel(e)) set(s => ({ ...s, loading: false, error: normalizeError(e) })); });
    return () => ac.abort();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick]);
  return { ...state, reload: () => setTick(t => t + 1) };
}
