import { StaffPasswordPage } from '../../components/auth/StaffPasswordPage';
import { useEffect, type ReactNode } from 'react';
import { useAuthStore } from '../../stores/auth.store';
import { useCan } from '../../hooks/useCan';
import StocktakeVoiceCount from './StocktakeVoiceCount';
import { PosApp, ToastHost, pos } from '@alfarid/pos-core';
import tokensCss from '@alfarid/pos-core/styles/tokens.css?inline';
import posCss from '@alfarid/pos-core/styles/pos.css?inline';

const GATE_STYLES = `
.pos-gate-shell {
  min-height: 100dvh;
  display: grid;
  place-items: center;
  padding: max(16px, env(safe-area-inset-top)) 16px max(20px, env(safe-area-inset-bottom));
  background: #f3f1f4;
  color: #18151c;
}
.theme-dark .pos-gate-shell {
  background: #0f0d12;
  color: #f5f3f7;
}
.pos-gate-card {
  width: min(420px, 100%);
  background: #ffffff;
  border: 1px solid rgba(24, 21, 28, 0.08);
  border-radius: 24px;
  padding: 28px 22px 22px;
  box-shadow: 0 20px 48px -16px rgba(24, 21, 28, 0.14), 0 2px 6px rgba(24, 21, 28, 0.04);
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 14px;
}
.theme-dark .pos-gate-card {
  background: #18151b;
  border-color: rgba(255, 255, 255, 0.08);
  box-shadow: 0 20px 48px -12px rgba(0, 0, 0, 0.6);
}
.pos-gate-badge {
  width: 56px;
  height: 56px;
  border-radius: 18px;
  display: grid;
  place-items: center;
  background: #fdebe5;
  color: #e0491f;
}
.theme-dark .pos-gate-badge {
  background: rgba(236, 90, 47, 0.16);
  color: #ec5a2f;
}
.pos-gate-card h1 {
  margin: 0;
  font-family: 'Readex Pro', 'IBM Plex Sans Arabic', sans-serif;
  font-size: 20px;
  font-weight: 600;
  line-height: 1.35;
  letter-spacing: -0.01em;
}
.pos-gate-card p {
  margin: 0;
  font-size: 14px;
  line-height: 1.65;
  color: #6f6a76;
  max-width: 34ch;
}
.theme-dark .pos-gate-card p {
  color: #a39eaa;
}
.pos-gate-actions {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 8px;
}
.pos-gate-btn {
  width: 100%;
  min-height: 48px;
  padding: 0 18px;
  border-radius: 14px;
  border: 1px solid transparent;
  font-size: 14px;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  cursor: pointer;
  touch-action: manipulation;
  transition: transform 100ms ease-out, background-color 150ms ease, opacity 150ms ease;
}
.pos-gate-btn:active {
  transform: scale(0.98);
}
.pos-gate-btn.primary {
  background: #e0491f;
  color: #ffffff;
  box-shadow: 0 8px 20px -10px rgba(224, 73, 31, 0.65);
}
.pos-gate-btn.primary:hover {
  background: #c93d16;
}
.pos-gate-btn.secondary {
  background: #f6f4f7;
  border-color: rgba(24, 21, 28, 0.12);
  color: #18151c;
}
.theme-dark .pos-gate-btn.secondary {
  background: #242029;
  border-color: rgba(255, 255, 255, 0.12);
  color: #f5f3f7;
}
.pos-gate-user {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 12px;
  border-radius: 99px;
  background: #f6f4f7;
  border: 1px solid rgba(24, 21, 28, 0.08);
  font-size: 12.5px;
  font-weight: 500;
  color: #6f6a76;
  max-width: 100%;
}
.theme-dark .pos-gate-user {
  background: #242029;
  border-color: rgba(255, 255, 255, 0.08);
  color: #a39eaa;
}
@media (prefers-reduced-motion: reduce) {
  .pos-gate-btn { transition: none; transform: none !important; }
}
`;

function PosGateNotice({
  icon,
  title,
  message,
  accountLabel,
  showDashboardLink,
  onLogout,
}: {
  icon: ReactNode;
  title: string;
  message: string;
  accountLabel?: string;
  showDashboardLink: boolean;
  onLogout: () => void;
}) {
  return (
    <main className="pos-gate-shell" dir="rtl">
      <style>{GATE_STYLES}</style>
      <section className="pos-gate-card" role="status" aria-live="polite">
        <div className="pos-gate-badge" aria-hidden="true">{icon}</div>
        {accountLabel && <div className="pos-gate-user">{accountLabel}</div>}
        <h1>{title}</h1>
        <p>{message}</p>
        <div className="pos-gate-actions">
          {showDashboardLink && (
            <button
              type="button"
              className="pos-gate-btn primary"
              onClick={() => window.location.assign('/dashboard')}
            >
              العودة للوحة الإدارة
            </button>
          )}
          <button
            type="button"
            className={`pos-gate-btn ${showDashboardLink ? 'secondary' : 'primary'}`}
            onClick={onLogout}
          >
            تسجيل الخروج وتبديل الحساب
          </button>
        </div>
      </section>
    </main>
  );
}

/** Point of Sale page for authorized cashiers and stock counters. */
export function PosPage() {
  const user = useAuthStore((s) => s.user);
  const destination = useAuthStore((s) => s.destination);
  const logoutRemote = useAuthStore((s) => s.logoutRemote);
  const canPos = useCan('pos.access');
  const canCount = useCan('stocktakes.update');
  const canCreateSales = useCan('pos.sales.create');

  const branchId = user?.branch_id ?? user?.branch?.id;
  const cashierOnly = destination === '/pos';
  const mountFullPos = Boolean(branchId && canPos && canCreateSales);
  const accountLabel = user?.name || user?.email || undefined;

  useEffect(() => {
    const prevTitle = document.title;
    document.title = 'VARYA | نقطة البيع';
    return () => { document.title = prevTitle; };
  }, []);

  useEffect(() => {
    if (!mountFullPos) return;
    pos.syncActor(user);
  }, [user, mountFullPos]);

  useEffect(() => {
    // The voice-count view below renders its own inline styles: only inject
    // the heavy POS bundle when the full POS app actually mounts.
    if (!mountFullPos) return;
    const styleEl = document.createElement('style');
    styleEl.setAttribute('data-pos-styles', '');
    styleEl.textContent = `${tokensCss}\n${posCss}`;
    document.head.appendChild(styleEl);

    return () => {
      styleEl.remove();
      // Read the host theme at cleanup time because the dashboard may switch
      // themes while POS is mounted. A render-time snapshot can go stale.
      const root = document.documentElement;
      const currentTheme = root.getAttribute('data-theme');
      const viewportIsDark = document.getElementById('app-viewport')?.classList.contains('theme-dark') ?? false;
      const shouldBeDark = currentTheme === 'dark' || (currentTheme !== 'light' && viewportIsDark);
      root.classList.toggle('theme-dark', shouldBeDark);
    };
  }, [mountFullPos]);

  if (!branchId) {
    return (
      <PosGateNotice
        icon={
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
            <path d="M3 9l2-5h14l2 5" />
            <path d="M3 9v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9" />
            <path d="M9 21v-6a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v6" />
          </svg>
        }
        title="حسابك غير مربوط بفرع"
        message="لا يمكن استخدام نقطة البيع دون ربط الحساب بفرع محدد. تواصل مع المسؤول لربط حسابك أو بدّل لحساب كاشير."
        accountLabel={accountLabel}
        showDashboardLink={!cashierOnly}
        onLogout={() => void logoutRemote('unified')}
      />
    );
  }

  if (!canPos) {
    return (
      <PosGateNotice
        icon={
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
            <rect x="3" y="11" width="18" height="10" rx="2" />
            <path d="M7 11V7a5 5 0 0 1 10 0v4" />
          </svg>
        }
        title="غير مصرح"
        message="تحتاج صلاحية دخول نقطة البيع للوصول إلى هذه الشاشة."
        showDashboardLink={!cashierOnly}
        onLogout={() => void logoutRemote('unified')}
      />
    );
  }

  if (!canCreateSales && !canCount) {
    return (
      <PosGateNotice
        icon={
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 8v4" />
            <path d="M12 16h.01" />
          </svg>
        }
        title="نقطة البيع"
        message="حسابك يقدر يدخل نقطة البيع، لكن لبدء وردية أو إجراء عملية بيع اطلب صلاحية البيع من المسؤول."
        showDashboardLink={!cashierOnly}
        onLogout={() => void logoutRemote('unified')}
      />
    );
  }

  // Dedicated blind voice-count view for stocktake-only staff (e.g. non-cashier inventory counter)
  if (canCount && !canCreateSales) {
    return (
      <div style={{ padding: '16px', maxWidth: 640, margin: '0 auto' }} dir="rtl">
        <h1 style={{ fontSize: 20, marginBottom: 12 }}>نقطة البيع</h1>
        <details style={{ marginBottom: 16 }}><summary>تغيير كلمة المرور</summary><StaffPasswordPage channel="pos" embedded /></details>
        <StocktakeVoiceCount />
      </div>
    );
  }

  return (
    <ToastHost>
      <PosApp renderPasswordSettings={(cashier) => <StaffPasswordPage channel="pos" embedded posDialog profileVersion={cashier.version} />} onLogout={() => logoutRemote('unified')} />
    </ToastHost>
  );
}

export default PosPage;
