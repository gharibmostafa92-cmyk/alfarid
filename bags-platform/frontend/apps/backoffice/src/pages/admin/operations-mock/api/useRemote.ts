import { useCallback, useEffect, useRef, useState, type DependencyList } from 'react';
import { isAxiosCancel, parseApiError, type ParsedApiError } from '@alfarid/api-client';
import { USE_MOCK, FALLBACK_ON_ERROR } from './source';

export interface RemoteState<T> {
  data: T | null;
  loading: boolean;
  error: ParsedApiError | null;
  usingMock: boolean;
  reload: () => void;
  setData: React.Dispatch<React.SetStateAction<T | null>>;
}

export function useRemote<T>(
  fetcher: (signal: AbortSignal) => Promise<T>,
  deps: DependencyList,
  opts: { enabled?: boolean; mock?: () => T; onError?: (e: ParsedApiError) => void } = {},
): RemoteState<T> {
  const { enabled = true, mock, onError } = opts;
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState<boolean>(enabled);
  const [error, setError] = useState<ParsedApiError | null>(null);
  const [usingMock, setUsingMock] = useState(false);
  const [tick, setTick] = useState(0);
  const onErrorRef = useRef(onError);
  onErrorRef.current = onError;

  useEffect(() => {
    if (!enabled) { setData(null); setError(null); setLoading(false); return; }
    if (USE_MOCK) { setData(mock?.() ?? null); setUsingMock(true); setError(null); setLoading(false); return; }
    const ctrl = new AbortController();
    setLoading(true);
    setError(null);
    setData(null);
    setUsingMock(false);
    fetcher(ctrl.signal)
      .then((d) => { if (!ctrl.signal.aborted) { setData(d); setUsingMock(false); } })
      .catch((e) => {
        if (ctrl.signal.aborted || isAxiosCancel(e)) return;
        const pe = parseApiError(e);
        setError(pe);
        onErrorRef.current?.(pe);
        if (FALLBACK_ON_ERROR && mock && (pe.status === null || pe.status >= 500)) {
          setData(mock()); setUsingMock(true);
        }
      })
      .finally(() => { if (!ctrl.signal.aborted) setLoading(false); });
    return () => ctrl.abort();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick, enabled]);

  const reload = useCallback(() => setTick((t) => t + 1), []);
  return { data, loading, error, usingMock, reload, setData };
}

/** تأخير قيمة البحث 350ms */
export function useDebounced<T>(value: T, ms = 350): T {
  const [v, setV] = useState(value);
  useEffect(() => { const t = setTimeout(() => setV(value), ms); return () => clearTimeout(t); }, [value, ms]);
  return v;
}

/** تنفيذ mutation مع حالة busy وtoast */
export function useAction() {
  const [busy, setBusy] = useState(false);
  const inFlight = useRef(false);
  const run = useCallback(async <R,>(
    fn: () => Promise<R>,
    h: { toast: (t: string, tone?: 'accent' | 'green' | 'red') => void; success?: string; onFieldErrors?: (f: Record<string, string>) => void },
  ): Promise<R | undefined> => {
    if (USE_MOCK) { h.toast('وضع تجريبي — لم يتم الحفظ', 'accent'); return undefined; }
    if (inFlight.current) return undefined;
    inFlight.current = true;
    setBusy(true);
    try {
      const r = await fn();
      if (h.success) h.toast(h.success, 'green');
      return r;
    } catch (e) {
      const pe = parseApiError(e);
      if (pe.status === 422 && Object.keys(pe.fieldErrors).length && h.onFieldErrors) h.onFieldErrors(pe.fieldErrors);
      h.toast(pe.message, 'red');
      return undefined;
    } finally {
      inFlight.current = false;
      setBusy(false);
    }
  }, []);
  return { busy, run };
}
