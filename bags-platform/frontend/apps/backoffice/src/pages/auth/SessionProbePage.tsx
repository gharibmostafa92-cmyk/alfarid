import { useEffect, useState } from 'react';
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
  const [failed, setFailed] = useState(false);
  const [attempt, setAttempt] = useState(0);

  useEffect(() => {
    const topLevel = window.parent === window;
    const hashParams = new URLSearchParams(window.location.hash.slice(1));
    const origin = resolveProbeTargetOrigin(hashParams);
    const nonce = hashParams.get('nonce');
    if (!nonce) {
      if (topLevel) window.location.replace('/login');
      return;
    }
    let disposed = false;
    let generation = 0;
    const check = async () => {
      const request = ++generation;
      let token: string | null;
      try {
        token = localStorage.getItem('backoffice_token');
      } catch {
        if (topLevel) setFailed(true);
        return;
      }
      let unavailable = false;
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
          else unavailable = true;
        } catch (error) {
          const response = (error as { response?: { status?: number; data?: { code?: string } } }).response;
          unavailable = response?.status !== 401 && response?.status !== 403;
          if (response?.data?.code === 'password_change_required') {
            try {
              const { data } = await client.get('/api/v1/admin/me', { timeout: 1200 });
              if (data?.data?.must_change_password === true) path = '/dashboard';
              else unavailable = true;
            } catch { unavailable = true; }
          }
        }
        authenticated = path !== null && localStorage.getItem('backoffice_token') === token;
      }
      if (!disposed && request === generation) {
        if (topLevel) {
          if (authenticated && path) {
            window.location.replace(path);
          } else if (unavailable || localStorage.getItem('backoffice_token') !== token) {
            setFailed(true);
          } else {
            const page = hashParams.get('page') ?? '';
            const login = new URL(['/auth/login', '/auth/register', '/auth/forgot-password', '/auth/reset-password'].includes(page)
              ? page : '/auth/login', origin);
            login.search = new URLSearchParams(hashParams.get('search') ?? '').toString();
            if (nonce !== 'backoffice-login') {
              login.hash = new URLSearchParams({ 'staff-guest': nonce }).toString();
            }
            window.location.replace(login.href);
          }
          return;
        }
        window.parent.postMessage({ type: 'alfarid:staff-session', nonce, authenticated, path: authenticated ? path : null }, origin);
      }
    };
    const changed = (event: StorageEvent) => {
      if (event.storageArea === localStorage && (event.key === 'backoffice_token' || event.key === null)) void check();
    };
    window.addEventListener('storage', changed);
    void check();
    return () => { disposed = true; window.removeEventListener('storage', changed); };
  }, [attempt]);
  if (window.parent !== window) return null;

  return (
    <main dir="rtl" className="grid min-h-screen place-content-center p-6 text-center">
      {failed ? (
        <div role="alert">
          <p>تعذر الاتصال، يرجى المحاولة مرة أخرى.</p>
          <button type="button" onClick={() => { setFailed(false); setAttempt((n) => n + 1); }}>
            إعادة المحاولة
          </button>
        </div>
      ) : <p role="status">Loading...</p>}
    </main>
  );
}
