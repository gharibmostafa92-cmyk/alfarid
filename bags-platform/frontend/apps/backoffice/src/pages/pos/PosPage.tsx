import { useEffect } from 'react';
import { useAuthStore } from '../../stores/auth.store';
import { useCan } from '../../hooks/useCan';
import StocktakeVoiceCount from './StocktakeVoiceCount';
import { PosApp, ToastHost, pos } from '@alfarid/pos-core';
import tokensCss from '@alfarid/pos-core/styles/tokens.css?inline';
import posCss from '@alfarid/pos-core/styles/pos.css?inline';

/** Point of Sale page for authorized cashiers and stock counters. */
export function PosPage() {
  const user = useAuthStore((s) => s.user);
  const logoutRemote = useAuthStore((s) => s.logoutRemote);
  const canPos = useCan('pos.access');
  const canCount = useCan('stocktakes.update');
  const canCreateSales = useCan('pos.sales.create');

  const branchId = user?.branch_id ?? user?.branch?.id;
  const mountFullPos = Boolean(branchId && canPos && canCreateSales);

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
      <div style={{ padding: 40, textAlign: 'center' }} dir="rtl">
        <h1>حسابك غير مربوط بفرع</h1>
        <p>لا يمكن استخدام نقطة البيع دون ربط الحساب بفرع محدد.</p>
      </div>
    );
  }

  if (!canPos || (!canCreateSales && !canCount)) {
    return (
      <div style={{ padding: 40, textAlign: 'center' }} dir="rtl">
        <h1>غير مصرح</h1>
        <p>تحتاج صلاحية دخول نقطة البيع وصلاحية البيع أو الجرد.</p>
      </div>
    );
  }

  // Dedicated blind voice-count view for stocktake-only staff (e.g. non-cashier inventory counter)
  if (canCount && !canCreateSales) {
    return (
      <div style={{ padding: 40 }} dir="rtl">
        <h1>نقطة البيع</h1>
        <StocktakeVoiceCount />
      </div>
    );
  }

  return (
    <ToastHost>
      <PosApp onLogout={() => logoutRemote('unified')} />
    </ToastHost>
  );
}

export default PosPage;
