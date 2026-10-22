import { useEffect } from 'react';
import { createApiClient, resolveServiceUrl } from '@alfarid/api-client';

// Only session presence crosses origins. Credentials stay in the backoffice.
export default function SessionProbePage() {
  useEffect(() => {
    if (window.parent === window) return;
    const origin = new URL(resolveServiceUrl(import.meta.env.VITE_WEB_URL ?? 'http://localhost:3000')).origin;
    const nonce = new URLSearchParams(window.location.hash.slice(1)).get('nonce');
    if (!nonce) return;
    let disposed = false;
    let generation = 0;
    const check = async () => {
      const request = ++generation;
      const token = localStorage.getItem('backoffice_token');
      let authenticated = false;
      if (token) {
        try {
          await createApiClient({
            baseURL: resolveServiceUrl(import.meta.env.VITE_API_URL ?? 'http://localhost:8000'),
            getToken: () => token,
            onUnauthorized: () => {},
          }).get('/api/v1/admin/me', { timeout: 4000 });
          authenticated = localStorage.getItem('backoffice_token') === token;
        } catch { /* Expired or unavailable sessions never trigger a redirect. */ }
      }
      if (!disposed && request === generation) {
        window.parent.postMessage({ type: 'alfarid:staff-session', nonce, authenticated }, origin);
      }
    };
    const changed = (event: StorageEvent) => {
      if (event.storageArea === localStorage && (event.key === 'backoffice_token' || event.key === null)) void check();
    };
    window.addEventListener('storage', changed);
    void check();
    return () => { disposed = true; window.removeEventListener('storage', changed); };
  }, []);
  return null;
}
