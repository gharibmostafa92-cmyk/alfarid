'use client';

import React, { useState, useEffect, useCallback, useRef } from 'react';
import './portal.css';
import './auth.css';
import { AppCtx } from './context';
import { authEndpoints, cartEndpoints, wholesaleEndpoints, defaultApiClient } from '@alfarid/api-client';
import { useApi } from './data/useApi';
import { DataBoundary } from './data/DataBoundary';
import { Async, ErrorState } from './ui-primitives';
import { apiError } from './data/helpers';
import { mapAccount, mapCart } from './data/mappers';
import { fmt } from './data/format';
import {
  Dashboard,
  Catalog,
  QuickOrder,
  Cart,
  Checkout,
  OrderSuccess,
} from './buy-screens';
import {
  Orders,
  OrderDetail,
  Quotes,
  QuoteForm,
  QuoteDetail,
  Returns,
  ReturnForm,
  ReturnDetail,
} from './orders-screens';
import {
  Invoices,
  Statement,
  NoticeForm,
  Notices,
  NoticeDetail,
  Wallet,
  Notifications,
  Account,
} from './finance-screens';
import {
  ApplicationStatus,
  ForgotPassword,
  ResetPassword,
} from './auth-screens';

const NAV_GROUPS = [
  {
    title: 'نظرة عامة',
    items: [
      { id: 'dashboard', label: 'لوحة التحكم', icon: 'fa-gauge-high' },
    ],
  },
  {
    title: 'الطلبات والمشتريات',
    items: [
      { id: 'catalog', label: 'الكتالوج', icon: 'fa-table-cells-large' },
      { id: 'quick', label: 'طلب سريع', icon: 'fa-bolt' },
      { id: 'cart', label: 'سلة المشتريات', icon: 'fa-bag-shopping', badge: 'cart' },
      { id: 'orders', label: 'الطلبات', icon: 'fa-box' },
      { id: 'quotes', label: 'عروض الأسعار', icon: 'fa-file-signature' },
      { id: 'returns', label: 'المرتجعات', icon: 'fa-rotate-left' },
    ],
  },
  {
    title: 'المالية والحساب',
    items: [
      { id: 'invoices', label: 'الفواتير', icon: 'fa-file-lines' },
      { id: 'statement', label: 'كشف الحساب', icon: 'fa-file-invoice-dollar' },
      { id: 'notices', label: 'إشعارات السداد', icon: 'fa-money-check-dollar' },
      { id: 'wallet', label: 'المحفظة', icon: 'fa-wallet' },
    ],
  },
  {
    title: 'الإعدادات',
    items: [
      { id: 'account', label: 'بيانات الحساب', icon: 'fa-building' },
      { id: 'notifications', label: 'الإشعارات', icon: 'fa-bell', badge: 'notifs' },
      { id: 'status', label: 'حالة الحساب', icon: 'fa-shield-halved' },
    ],
  },
];

const readRoute = (initialScreen) => {
  if (typeof window === 'undefined') return { screen: initialScreen, id: null };
  const p = new URLSearchParams(window.location.search);
  return { screen: p.get('screen') || initialScreen, id: p.get('id') ? Number(p.get('id')) : null };
};

/**
 * @param {{ initialScreen?: string, initialId?: number|null, onLogout?: (() => unknown)|null, onEnter?: (() => unknown)|null, onForbidden?: (() => unknown)|null, onForgotSubmit?: ((login: string) => Promise<unknown>)|null, onResetSubmit?: ((login: string, token: string, password: string, confirmation: string) => Promise<unknown>)|null, brandName?: string }} props
 */
export function WholesalePortal({
  initialScreen = 'dashboard',
  initialId = null,
  onLogout = null,
  onEnter = null,
  onForbidden = null,
  onForgotSubmit = null,
  onResetSubmit = null,
  brandName = 'SOPHIE',
}) {
  const [route, setRoute] = useState(() => {
    const r = readRoute(initialScreen);
    return initialId != null && r.id == null ? { screen: r.screen, id: initialId } : r;
  });
  const routeRef = useRef(route);
  routeRef.current = route;
  const isAuthScreen = ['status', 'forgot', 'reset'].includes(route.screen);
  const [lastOrder, setLastOrder] = useState(null);
  const [unread, setUnread] = useState(0);
  const [toasts, setToasts] = useState([]);

  const toast = useCallback((msg) => {
    const id = Date.now() + Math.random();
    setToasts((prev) => [...prev, { id, msg }]);
    setTimeout(() => {
      setToasts((prev) => prev.filter((t) => t.id !== id));
    }, 3500);
  }, []);

  const forbidden = useCallback(() => {
    if (routeRef.current.screen !== 'status' && typeof onForbidden === 'function') onForbidden();
  }, [onForbidden]);

  useEffect(() => {
    const interceptor = defaultApiClient.interceptors.response.use((r) => r, (e) => {
      if (apiError(e).status === 403 && e.config?.url?.startsWith('/api/v1/wholesale/')) forbidden();
      return Promise.reject(e);
    });
    return () => defaultApiClient.interceptors.response.eject(interceptor);
  }, [forbidden]);

  const accountApi = useApi(async (signal) => {
    const [me, dash] = await Promise.all([authEndpoints.me(), wholesaleEndpoints.dashboard(signal)]);
    return { account: mapAccount(me.data, dash), dash };
  }, [], !isAuthScreen);
  const account = accountApi.data?.account;
  const dash = accountApi.data?.dash;
  const refreshAccount = accountApi.reload;
  const cartApi = useApi(() => cartEndpoints.get(null).then(mapCart), [], !isAuthScreen);
  const cart = cartApi.data;
  const setCartState = cartApi.setData;

  const loadCart = useCallback(async () => {
    try {
      const c = await cartEndpoints.get(null);
      setCartState(mapCart(c));
    } catch (e) {
      if (apiError(e).status === 403) forbidden();
    }
  }, [forbidden, setCartState]);

  const addToCart = useCallback(async (lines) => {
    try {
      let last = null;
      for (const l of lines) last = await cartEndpoints.add(l.variant_id, l.qty, null);
      if (last) setCartState(mapCart(last));
      toast('تمت الإضافة إلى سلة المشتريات بنجاح');
    } catch (e) { toast(apiError(e).message); }
  }, [toast, setCartState]);

  const applyCartResponse = useCallback((c) => {
    if (c) setCartState(mapCart(c));
  }, [setCartState]);

  useEffect(() => {
    if (isAuthScreen) return;
    const tick = () => wholesaleEndpoints.notifications(1).then((r) => setUnread(r.meta.unread_count)).catch(() => {});
    tick();
    const t = setInterval(tick, 60000);
    return () => clearInterval(t);
  }, [isAuthScreen]);

  const go = useCallback((screen, id = null) => {
    const p = new URLSearchParams();
    p.set('screen', screen);
    if (id != null) p.set('id', id);
    if (typeof window !== 'undefined') {
      window.history.pushState(null, '', `${window.location.pathname}?${p}`);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    setRoute({ screen, id });
  }, []);

  useEffect(() => {
    const onPop = () => setRoute(readRoute(initialScreen));
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, [initialScreen]);

  const logout = useCallback(() => {
    if (onLogout) onLogout();
    else toast('تم تسجيل الخروج');
  }, [onLogout, toast]);

  const cartTotalQty = cart?.lines.reduce((s, l) => s + l.qty, 0) ?? 0;

  const contextValue = {
    go,
    route,
    cart,
    cartApi,
    accountApi,
    loadCart,
    addToCart,
    applyCartResponse,
    cartTotalQty,
    lastOrder,
    setLastOrder,
    unread,
    setUnread,
    account,
    dash,
    refreshAccount,
    toast,
    logout,
  };

  // Screen router
  const renderScreen = () => {
    const { screen, id } = route;
    switch (screen) {
      case 'dashboard':
        return <Dashboard />;
      case 'catalog':
        return <Catalog />;
      case 'quick':
        return <QuickOrder />;
      case 'cart':
        return <Cart />;
      case 'checkout':
        return <Checkout />;
      case 'success':
      case 'orderSuccess':
        return <OrderSuccess />;
      case 'orders':
        return <Orders />;
      case 'order':
        return <OrderDetail id={id} />;
      case 'quotes':
        return <Quotes />;
      case 'quoteNew':
        return <QuoteForm />;
      case 'quoteEdit':
        return <QuoteForm id={id} />;
      case 'quote':
        return <QuoteDetail id={id} />;
      case 'returns':
        return <Returns />;
      case 'returnNew':
        return <ReturnForm orderId={id} />;
      case 'return':
        return <ReturnDetail id={id} />;
      case 'invoices':
        return <Invoices />;
      case 'statement':
        return <Statement />;
      case 'notices':
        return <Notices />;
      case 'noticeNew':
        return <NoticeForm />;
      case 'notice':
        return <NoticeDetail id={id} />;
      case 'wallet':
        return <Wallet />;
      case 'notifications':
        return <Notifications />;
      case 'account':
        return <Account />;
      case 'status':
        return <ApplicationStatus onLogout={logout} onEnter={onEnter || (() => go('dashboard'))} />;
      case 'forgot':
        return <ForgotPassword onBack={() => go('dashboard')} onGoReset={() => go('reset')} onForgotSubmit={onForgotSubmit} />;
      case 'reset':
        return <ResetPassword onBack={() => go('dashboard')} onForgot={() => go('forgot')} onResetSubmit={onResetSubmit} />;
      default:
        return <Dashboard />;
    }
  };

  if (isAuthScreen) return (
    <AppCtx.Provider value={contextValue}>
      <div className="wholesale-portal-app" dir="rtl">{renderScreen()}</div>
    </AppCtx.Provider>
  );

  if (!account) {
    if (accountApi.state === 'error') return (
      <div className="wholesale-portal-app" dir="rtl"><ErrorState onRetry={refreshAccount} /></div>
    );
    return (
      <div className="wholesale-portal-app" dir="rtl">
        <div className="wp-layout">
          <aside className="wp-side"><div className="sk" style={{ height: 18, width: '60%', margin: 16 }} /><div className="sk" style={{ height: 12, width: '80%', margin: 16 }} /><div className="sk" style={{ height: 12, width: '70%', margin: 16 }} /></aside>
          <main className="wp-main"><div className="wp-wrap"><div className="sk" style={{ height: 28, width: '40%', marginBottom: 16 }} /><div className="sk" style={{ height: 120, width: '100%' }} /></div></main>
        </div>
      </div>
    );
  }

  return (
    <AppCtx.Provider value={contextValue}>
      <div className="wholesale-portal-app" dir="rtl">
        {/* Sticky Header */}
        <header className="wp-header">
          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <button className="wp-logo" onClick={() => go('dashboard')}>
              <span>{brandName}</span>
              <span className="wp-logo-tag">WHOLESALE</span>
            </button>
          </div>

          <div className="wp-head-right">
            <div className="wp-credit-chip" title="الحد الائتماني والمتاح">
              <span>الرصيد المتاح:</span>
              <strong className="num">{fmt(account.available_credit ?? 0, 0)} EGP</strong>
            </div>

            <button
              className="wp-icon-btn"
              onClick={() => go('notifications')}
              title="الإشعارات"
            >
              <i className="fa-regular fa-bell" />
              {unread > 0 && <span className="wp-count">{unread}</span>}
            </button>

            <button
              className="wp-icon-btn"
              onClick={() => go('cart')}
              title="سلة المشتريات"
            >
              <i className="fa-solid fa-bag-shopping" />
              {cartTotalQty > 0 && <span className="wp-count">{cartTotalQty}</span>}
            </button>

            <button
              className="wp-avatar"
              onClick={() => go('account')}
              title="الملف التجاري"
            >
              {String(account.name || '').split(' ').map((s) => s[0]).slice(0, 2).join('')}
            </button>
          </div>
        </header>

        {/* Standard portal layout */}
          <div className="wp-layout">
            {/* Sidebar */}
            <aside className="wp-side">
              <div className="wp-side-tag">B2B PORTAL</div>

              {NAV_GROUPS.map((group) => (
                <div key={group.title} className="wp-side-group">
                  <div className="wp-side-group-title">{group.title}</div>
                  {group.items.map((item) => {
                    const isActive =
                      route.screen === item.id ||
                      (item.id === 'orders' && route.screen === 'order') ||
                      (item.id === 'quotes' && ['quote', 'quoteNew', 'quoteEdit'].includes(route.screen)) ||
                      (item.id === 'returns' && ['return', 'returnNew'].includes(route.screen)) ||
                      (item.id === 'notices' && ['notice', 'noticeNew'].includes(route.screen));
                    const badgeVal =
                      item.badge === 'cart' && cartTotalQty > 0
                        ? cartTotalQty
                        : item.badge === 'notifs' && unread > 0
                        ? unread
                        : null;

                    return (
                      <button
                        key={item.id}
                        className={`wp-side-link ${isActive ? 'is-active' : ''}`}
                        onClick={() => go(item.id)}
                      >
                        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                          <span className="wp-side-dot" />
                          <i
                            className={`fa-solid ${item.icon}`}
                            style={{ width: 18, textAlign: 'center', fontSize: 13 }}
                          />
                          <span>{item.label}</span>
                        </div>
                        {badgeVal && <span className="wp-side-badge num">{badgeVal}</span>}
                      </button>
                    );
                  })}
                </div>
              ))}

              <div className="wp-side-foot">
                <button
                  className="wp-side-link"
                  onClick={logout}
                  style={{ color: '#be123c' }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <i
                      className="fa-solid fa-arrow-right-from-bracket"
                      style={{ width: 18, textAlign: 'center' }}
                    />
                    <span>تسجيل الخروج</span>
                  </div>
                </button>
              </div>
            </aside>

            {/* Main Content Area */}
            <main className="wp-main">
              <div className="wp-wrap">
                {route.screen === 'dashboard' ? (
                  <DataBoundary api={accountApi}><Async rows={3} cols={4}>{renderScreen()}</Async></DataBoundary>
                ) : renderScreen()}
              </div>
            </main>
          </div>
        {/* Floating Toasts */}
        {toasts.length > 0 && (
          <div
            style={{
              position: 'fixed',
              bottom: 24,
              left: '50%',
              transform: 'translateX(-50%)',
              zIndex: 9999,
              display: 'flex',
              flexDirection: 'column',
              gap: 8,
            }}
          >
            {toasts.map((t) => (
              <div
                key={t.id}
                className="toast"
                style={{ position: 'relative', bottom: 'auto', left: 'auto', transform: 'none' }}
              >
                <i className="fa-solid fa-circle-check" />
                <span>{t.msg}</span>
              </div>
            ))}
          </div>
        )}
      </div>
    </AppCtx.Provider>
  );
}

export default WholesalePortal;
