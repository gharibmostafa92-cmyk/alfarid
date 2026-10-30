import { useState, useEffect, useCallback } from 'react';

export function useApi(fetcher, deps = [], enabled = true) {
  const [state, setState] = useState('loading'); // loading | ready | error
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    if (!enabled) return;
    const ctrl = new AbortController();
    setState('loading');
    setError(null);
    Promise.resolve().then(() => fetcher(ctrl.signal))
      .then((d) => { if (!ctrl.signal.aborted) { setData(d); setState('ready'); } })
      .catch((e) => { if (!ctrl.signal.aborted) { setError(e); setState('error'); } });
    return () => ctrl.abort();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick, enabled]);

  const reload = useCallback(() => setTick((t) => t + 1), []);
  return { state, data, error, reload, retry: reload, setData };
}
