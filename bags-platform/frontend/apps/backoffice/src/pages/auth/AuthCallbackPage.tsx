import { useEffect, useRef } from 'react';
import { authEndpoints } from '@alfarid/api-client';
import { useAuthStore } from '../../stores/auth.store';
import { toStaffUser } from '../../stores/mappers';

const WEB_URL = (import.meta.env.VITE_WEB_URL as string | undefined) ?? 'http://localhost:3000';

export default function AuthCallbackPage() {
  const login = useAuthStore((s) => s.login);
  const ran = useRef(false); // React StrictMode بيشغّل الـ effect مرتين، والكود بيُستخدم مرة واحدة بس

  useEffect(() => {
    if (ran.current) return;
    ran.current = true;
    // M7: الكود يصل في الـ fragment (لا يُرسل للخادم ولا في الـ Referer) —
    // يُمسح فورًا من الشريط؛ روابط الـ query القديمة لم تعد مقبولة.
    const fromHash = new URLSearchParams(window.location.hash.replace(/^#/, '')).get('code');
    const code = fromHash;
    window.history.replaceState(null, '', window.location.pathname);
    if (!code) {
      window.location.replace(`${WEB_URL}/auth/login?error=handoff`);
      return;
    }
    authEndpoints
      .exchangeHandoff(code)
      .then(async (res) => {
        // H1: أبطل التوكن السابق قبل تفعيل الجديد، وبعميل خام بلا interceptors
        // حتى لا يستبدل الـ interceptor الـ Authorization الصريح بالتوكن الجديد
        // فيحذف الخادم التوكن الجديد بدل القديم.
        const prev = localStorage.getItem('backoffice_token');
        if (prev && prev !== res.token) {
          const baseURL =
            (import.meta.env.VITE_API_URL as string | undefined) ?? 'http://localhost:8000';
          const ctrl = new AbortController();
          const timer = setTimeout(() => ctrl.abort(), 10000);
          try {
            await fetch(`${baseURL}/api/v1/admin/auth/logout`, {
              method: 'POST',
              headers: { Authorization: `Bearer ${prev}`, 'Content-Type': 'application/json' },
              signal: ctrl.signal,
            });
          } catch {
            // best-effort: التوكن القديم قد يكون منتهيًا أصلًا
          } finally {
            clearTimeout(timer);
          }
        }
        login(res.token, toStaffUser(res.data));
        window.location.replace(res.redirect.path);
      })
      .catch(() => window.location.replace(`${WEB_URL}/auth/login?error=handoff`));
  }, []);

  return <div style={{ padding: 40, textAlign: 'center' }}>جارٍ تسجيل الدخول…</div>;
}
