import React from 'react';
import { Icon } from '../ui/Icons';
import { isMockPos } from '../../services/pos-data-source';
import type { AppScreen, Branch, CashierUser, PosShift } from '../../types/pos.types';

interface PosHeaderProps {
  currentScreen: AppScreen;
  onScreenChange: (s: AppScreen) => void;
  branch: Branch | null;
  cashier: CashierUser | null;
  shift: PosShift | null;
  onOpenCloseShift: () => void;
  isDark: boolean;
  onToggleDark: () => void;
  onLogout?: () => void;
  accountSettings?: React.ReactNode;
  incomingOrders?: React.ReactNode;
  /** F-15: driven by the app's online listener — never a hardcoded "online". */
  isOnline?: boolean;
}

export function PosHeader({
  currentScreen,
  onScreenChange,
  branch,
  cashier,
  shift,
  onOpenCloseShift,
  isDark,
  onToggleDark,
  onLogout,
  accountSettings,
  incomingOrders,
  isOnline = true,
}: PosHeaderProps) {
  return (
    <header className="ph">
      <div className="brand-mark">
        <Icon n="bag" s={20} w={2} />
      </div>
      <div className="ph-meta">
        <b>VARYA</b>
        <span>{branch?.name || 'الفرع'}</span>
      </div>
      <div className="ph-sep" />
      <nav className="ph-nav" aria-label="شاشات نقطة البيع">
        <button
          aria-current={currentScreen === 'terminal' ? 'page' : undefined}
          onClick={() => onScreenChange('terminal')}
        >
          <Icon n="pos" s={16} />
          <span className="lbl">نقطة البيع</span>
        </button>
        <button
          aria-current={currentScreen === 'stock' ? 'page' : undefined}
          onClick={() => onScreenChange('stock')}
        >
          <Icon n="box" s={16} />
          <span className="lbl">المخزون</span>
        </button>
        <button
          aria-current={currentScreen === 'transfers' ? 'page' : undefined}
          onClick={() => onScreenChange('transfers')}
        >
          <Icon n="truck" s={16} />
          <span className="lbl">التحويلات</span>
        </button>
        <button
          aria-current={currentScreen === 'stocktake' ? 'page' : undefined}
          onClick={() => onScreenChange('stocktake')}
        >
          <Icon n="stock" s={16} />
          <span className="lbl">الجرد</span>
        </button>
      </nav>
      {incomingOrders}

      <div className="ms-auto row gap-3">
        {/* F-15: live connection state. F-18: mock builds are badged so QA never mistakes them for production. */}
        <span
          className={isOnline ? 'conn' : 'conn off'}
          title={isOnline ? 'متصل بالسيرفر' : 'غير متصل بالسيرفر'}
          aria-label={isOnline ? 'متصل بالسيرفر' : 'غير متصل بالسيرفر'}
          role="status"
          aria-live="polite"
        >
          <i
            style={{
              width: 7,
              height: 7,
              borderRadius: 99,
              background: isOnline ? 'var(--ok)' : 'var(--bad)',
              boxShadow: isOnline ? '0 0 0 2px var(--ok-bg)' : '0 0 0 2px var(--bad-bg)',
            }}
          />
          <span className="hide-mobile">{isOnline ? 'متصل' : 'غير متصل'}</span>
        </span>
        {isMockPos && (
          <span className="badge b-warn" title="بيانات تجريبية — ليست بيئة الإنتاج">
            وضع تجريبي
          </span>
        )}

        {shift && (
          <button
            className="shift-pill"
            onClick={onOpenCloseShift}
            title="انقر لإغلاق الوردية وجرد النقدية"
          >
            <span className="live" />
            <span>وردية #{shift.shift_number}</span>
            <Icon n="lock" s={13} className="t-3" />
          </button>
        )}

        <button
          className="icon-btn"
          onClick={onToggleDark}
          aria-label={isDark ? 'الوضع النهاري' : 'الوضع الليلي'}
          title={isDark ? 'الوضع النهاري' : 'الوضع الليلي'}
        >
          <Icon n={isDark ? 'sun' : 'moon'} s={17} />
        </button>

        <div className="ph-sep" />

        {accountSettings}
        <div className="row gap-2" style={{ fontSize: 13 }}>
          <span
            className="avatar"
            style={{ width: 32, height: 32, fontSize: 12, background: 'var(--bg-sunken)' }}
          >
            {cashier?.initials || 'أس'}
          </span>
          <div className="col hide-mobile" style={{ lineHeight: 1.2 }}>
            <span className="fw-6">{cashier?.name || 'الكاشير'}</span>
            <span className="fs-12 t-3">{cashier?.role || 'كاشير أول'}</span>
          </div>
          {onLogout && (
            <button
              id="side-logout-btn"
              className="icon-btn"
              onClick={onLogout}
              aria-label="تسجيل الخروج"
              title="تسجيل الخروج"
            >
              <Icon n="logout" s={17} />
            </button>
          )}
        </div>
      </div>
    </header>
  );
}
