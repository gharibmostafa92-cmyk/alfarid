import { useState, useEffect, useCallback, useMemo } from 'react';

const idle = { state: 'idle', data: null, error: null };
const loading = { state: 'loading', data: null, error: null };

export function useApi(fetcher, deps = [], enabled = true) {
  const [result, setResult] = useState(null);
  const [tick, setTick] = useState(0);
  // The caller's dependencies identify the request. Inline fetchers can change
  // reference on every render, including when the request itself resolves.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  const request = useMemo(() => ({ fetcher, enabled }), [...deps, tick, enabled]);

  useEffect(() => {
    if (!request.enabled) return;
    const controller = new AbortController();
    const { signal } = controller;
    Promise.resolve().then(() => signal.aborted ? undefined : request.fetcher(signal))
      .then(data => { if (!signal.aborted) setResult({ request, state: 'ready', data, error: null }); })
      .catch(error => { if (!signal.aborted) setResult({ request, state: 'error', data: null, error }); });
    return () => controller.abort();
  }, [request]);

  const reload = useCallback(() => setTick((t) => t + 1), []);
  const setData = useCallback(update => {
    setResult(current => {
      if (current?.request !== request || current.state !== 'ready') return current;
      const data = typeof update === 'function' ? update(current.data) : update;
      return { ...current, data };
    });
  }, [request]);

  // Mask the previous result on the first render after dependencies change,
  // before effect cleanup and the new fetch can run.
  const current = !enabled ? idle : result?.request === request ? result : loading;
  const { state, data, error } = current;
  return { state, data, error, reload, retry: reload, setData };
}
