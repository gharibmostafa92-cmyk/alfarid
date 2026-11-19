import { useEffect } from 'react';
import { createApiClient, resolveServiceUrl } from '@alfarid/api-client';

function isLoopbackHost(hostname: string): boolean {
  return hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '[::1]';
}

function resolveProbeTargetOrigin(hashParams: URLSearchParams): string {
  const configured = new URL(resolveServiceUrl(import.meta.env.VITE_WEB_URL ?? 'http://localhost:3000'));
  const candidate = hashParams.get('origin');
  if (candidate && isLoopbackHost(configured.hostname)) {
    try {
      const parsed = new URL(candidate);
      if (parsed.protocol === 'http:' && isLoopbackHost(parsed.hostname)) {
        return parsed.origin;
      }
    } catch {
      // Fall back to the configured storefront origin.
    }
  }
  return configured.origin;
}

// Only session presence crosses origins. Credentials stay in the backoffice.
export default function SessionProbePage() {
  useEffect(() => {
    if (window.parent === window) return;
    const hashParams = new URLSearchParams(window.location.hash.slice(1));
    const origin = resolveProbeTargetOrigin(hashParams);
    const nonce = hashParams.get('nonce');
    if (!nonce) return;
    let disposed = false;
    let generation = 0;
    const check = async () => {
      const request = ++generation;
      const token = localStorage.getItem('backoffice_token');
      let authenticated = false;
      let path: '/dashboard' | '/pos' | null = null;
      if (token) {
        const client = createApiClient({
          baseURL: resolveServiceUrl(import.meta.env.VITE_API_URL ?? 'http://localhost:8000'),
          getToken: () => token,
          onUnauthorized: () => {},
        });
        try {
          const { data } = await client.get('/api/v1/auth/me', { timeout: 3000 });
          const destination = data?.redirect?.path;
          if (data?.actor?.type === 'staff' && (destination === '/dashboard' || destination === '/pos')) path = destination;
        } catch (error) {
          const response = (error as { response?: { data?: { code?: string } } }).response;
          if (response?.data?.code === 'password_change_required') {
            try {
              const { data } = await client.get('/api/v1/admin/me', { timeout: 1200 });
              if (data?.data?.must_change_password === true) path = '/dashboard';
            } catch { /* Only a verified rotation session can continue. */ }
          }
        }
        authenticated = path !== null && localStorage.getItem('backoffice_token') === token;
      }
      if (!disposed && request === generation) {
        window.parent.postMessage({ type: 'alfarid:staff-session', nonce, authenticated, path: authenticated ? path : null }, origin);
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
