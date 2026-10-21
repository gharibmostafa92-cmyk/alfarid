import { useEffect, useRef } from 'react';
import { authEndpoints, defaultApiClient } from '@alfarid/api-client';
import { useAuthStore } from '../../stores/auth.store';
import { toStaffUser } from '../../stores/mappers';

export default function AuthCallbackPage() {
  const login = useAuthStore((s) => s.login);
  const ran = useRef(false); // React StrictMode بيشغّل الـ effect مرتين، والكود بيُستخدم مرة واحدة بس

  useEffect(() => {
    if (ran.current) return;
    ran.current = true;
    // M7: الكود يصل في الـ fragment (لا يُرسل للخادم ولا في الـ Referer) —
    // مع دعم الـ query مؤقتًا لروابط قديمة، ثم مسحه فورًا من الشريط.
    const fromHash = new URLSearchParams(window.location.hash.replace(/^#/, '')).get('code');
    const fromQuery = new URLSearchParams(window.location.search).get('code');
    const code = fromHash ?? fromQuery;
    window.history.replaceState(null, '', window.location.pathname);
    if (!code) {
      window.location.replace(`${import.meta.env.VITE_WEB_URL}/auth/login`);
      return;
    }
    authEndpoints
      .exchangeHandoff(code)
      .then((res) => {
        // M9: جلسة باك أوفيس سابقة توكنها يبقى صالحًا بعد الاستبدال —
        // أبطل القديم (best-effort) بعد نجاح الجديد.
        const prev = localStorage.getItem('backoffice_token');
        login(res.token, toStaffUser(res.data));
        if (prev && prev !== res.token) {
          defaultApiClient.post('/api/v1/admin/auth/logout', {}, {
            headers: { Authorization: `Bearer ${prev}` },
          }).catch(() => {});
        }
        window.location.replace(res.redirect.path);
      })
      .catch(() => window.location.replace(`${import.meta.env.VITE_WEB_URL}/auth/login?error=handoff`));
  }, []);

  return <div style={{ padding: 40, textAlign: 'center' }}>جارٍ تسجيل الدخول…</div>;
}
