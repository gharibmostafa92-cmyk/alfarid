import { useEffect } from 'react';
import { useAuthStore } from '../../stores/auth.store';
import { useCan } from '../../hooks/useCan';
import StocktakeVoiceCount from './StocktakeVoiceCount';
import { PosApp } from './components/PosApp';
import { ToastHost } from './components/ui/SharedUI';
import { pos as posMockService } from './services/pos-data-source';
import tokensCss from './styles/tokens.css?inline';
import posCss from './styles/pos.css?inline';

/** Point of Sale page: restricted to cashier roles or pos.access permission. */
export function PosPage() {
  const user = useAuthStore((s) => s.user);
  const logoutRemote = useAuthStore((s) => s.logoutRemote);
  const canPos = useCan('pos.access');
  const canCount = useCan('stocktakes.update');
  const canCreateSales = useCan('pos.sales.create');

  useEffect(() => {
    posMockService.syncActor(user);
  }, [user]);

  useEffect(() => {
    let fonts = document.querySelector('[data-pos-fonts]');
    if (!fonts) {
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href =
        'https://fonts.googleapis.com/css2?family=Readex+Pro:wght@400;500;600;700&display=swap';
      link.setAttribute('data-pos-fonts', '');
      document.head.appendChild(link);
      fonts = link;
    }

    const styleEl = document.createElement('style');
    styleEl.setAttribute('data-pos-styles', '');
    styleEl.textContent = `${tokensCss}\n${posCss}`;
    document.head.appendChild(styleEl);

    return () => {
      styleEl.remove();
      document.documentElement.classList.remove('theme-dark');
    };
  }, []);

  if ((user as any)?.must_change_password) {
    return (
      <div style={{ padding: 40, textAlign: 'center' }} dir="rtl">
        <h1>تغيير كلمة المرور مطلوب</h1>
        <p>يجب تغيير كلمة المرور للمتابعة إلى نقطة البيع.</p>
        <button
          className="btn btn-primary"
          style={{ marginTop: 16 }}
          onClick={() => {
            window.location.hash = '#/change-password';
          }}
        >
          تغيير كلمة المرور الآن
        </button>
      </div>
    );
  }

  const branchId = user?.branch_id ?? user?.branch?.id;
  if (!branchId) {
    return (
      <div style={{ padding: 40, textAlign: 'center' }} dir="rtl">
        <h1>حسابك غير مربوط بفرع</h1>
        <p>لا يمكن استخدام نقطة البيع دون ربط الحساب بفرع محدد.</p>
      </div>
    );
  }

  if (!canPos) {
    return (
      <div style={{ padding: 40, textAlign: 'center' }} dir="rtl">
        <h1>غير مصرح</h1>
        <p>صفحة نقطة البيع متاحة للمستخدمين المصرح لهم فقط (صلاحية pos.access).</p>
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
      <PosApp onLogout={logoutRemote} />
    </ToastHost>
  );
}

export default PosPage;
