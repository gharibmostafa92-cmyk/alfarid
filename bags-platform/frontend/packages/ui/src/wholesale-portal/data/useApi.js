import { useState, useEffect, useCallback, useMemo, useRef } from 'react';

const idle = { state: 'idle', data: null, error: null };
const loading = { state: 'loading', data: null, error: null };

function shallowEqualArray(a, b) {
  if (Object.is(a, b)) return true;
  if (!Array.isArray(a) || !Array.isArray(b) || a.length !== b.length) return false;
  for (let i = 0; i < a.length; i += 1) {
    if (!Object.is(a[i], b[i])) return false;
  }
  return true;
}

export function useApi(fetcher, deps = [], enabled = true) {
  const [result, setResult] = useState(null);
  const [tick, setTick] = useState(0);
  const fetcherRef = useRef(fetcher);
  fetcherRef.current = fetcher;

  const depsRef = useRef(deps);
  const depsVersionRef = useRef(0);
  if (!shallowEqualArray(depsRef.current, deps)) {
    depsRef.current = deps;
    depsVersionRef.current += 1;
  }
  const depsVersion = depsVersionRef.current;

  const request = useMemo(() => ({ enabled, tick, depsVersion }), [enabled, tick, depsVersion]);

  useEffect(() => {
    if (!request.enabled) return;
    const controller = new AbortController();
    const { signal } = controller;
    Promise.resolve().then(() => signal.aborted ? undefined : fetcherRef.current(signal))
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
