import React, { useLayoutEffect, useEffect, useRef, useState, useMemo } from 'react';
import { createPortal } from 'react-dom';
import styles from './operations-reference.css?inline';
import { useNotifications } from './api/useNotifications';
import { USE_MOCK } from './api/source';
import { ToastHost, Icon, Note, Empty } from './ui';
import { OrdersList, OrderDetail, ReturnsList, ReturnDetail } from './orders';
import { ShippingPage, PricingPage, PriceListDetail, PromosPage } from './commerce';
import { FinancePage, ShiftsPage, WholesalePage } from './finance';
import { ReportsPage, NotificationsPage, NotifDrawer } from './reports';

export interface OperationsRoute {
  m: string;
  id?: number | string;
  sub?: string;
}

export const NAV_GROUPS = [
  {
    key: 'sales',
    label: 'المبيعات',
    items: [
      { m: 'orders', label: 'الطلبات', icon: 'orders' },
      { m: 'returns', label: 'المرتجعات', icon: 'returns' },
      { m: 'wholesale', label: 'الجملة', icon: 'quote' },
      { m: 'shipping', label: 'الشحن', icon: 'truck' },
    ],
  },
  {
    key: 'catalog',
    label: 'التسعير والعروض',
    items: [
      { m: 'pricing', label: 'التسعير ومجموعات العملاء', icon: 'tag' },
      { m: 'promos', label: 'العروض والكوبونات', icon: 'ticket' },
    ],
  },
  {
    key: 'finance',
    label: 'المالية والتقارير',
    items: [
      { m: 'finance', label: 'المالية', icon: 'vault' },
      { m: 'shifts', label: 'مراجعة الورديات', icon: 'clock' },
      { m: 'reports', label: 'التقارير', icon: 'chart' },
    ],
  },
];

export const groupOf = (m: string) => NAV_GROUPS.find((g) => g.items.some((i) => i.m === m))?.key;

export function canViewOperation(page: string, can: (permission: string) => boolean): boolean {
  const permissions: Record<string, string[]> = {
    orders: ['orders.view'], returns: ['returns.view'], shipping: ['shipping.view'],
    pricing: ['price_lists.view', 'customer_groups.view'], promos: ['promotions.view', 'coupons.view'],
    finance: ['treasuries.view', 'expenses.view', 'treasury_transfers.view'],
    shifts: ['pos_shifts.view'], wholesale: ['quotations.view', 'payment_notices.view'], reports: ['reports.view'],
  };
  return !permissions[page] || permissions[page].some(can);
}

export interface OperationsMockPageProps {
  page: string;
  id?: string | number;
  sub?: string;
  darkMode?: boolean;
  theme?: string;
  can?: (perm: string) => boolean;
}

export default function OperationsMockPage({
  page,
  id: initialId,
  sub: initialSub,
  darkMode,
  theme,
  can = () => true,
}: OperationsMockPageProps) {
  const host = useRef<HTMLDivElement>(null);
  const [root, setRoot] = useState<ShadowRoot | null>(null);

  const [route, setRoute] = useState<OperationsRoute>(() => ({
    m: page || 'orders',
    id: initialId,
    sub: initialSub,
  }));

  const notif = useNotifications();
  const { items: notifs, markRead } = notif;
  const [drawer, setDrawer] = useState(false);

  useEffect(() => {
    setRoute({ m: page, id: initialId, sub: initialSub });
  }, [page, initialId, initialSub]);

  // Dark mode detection and synchronization
  const [detectedDark, setDetectedDark] = useState(() => {
    if (typeof darkMode === 'boolean') return darkMode;
    if (typeof theme === 'string') return theme === 'dark';
    try {
      return localStorage.getItem('vortix_theme') === 'dark' || document.documentElement.classList.contains('dark');
    } catch {
      return false;
    }
  });

  const isDark = typeof darkMode === 'boolean' ? darkMode : theme ? theme === 'dark' : detectedDark;

  useLayoutEffect(() => {
    if (host.current) {
      setRoot(host.current.shadowRoot || host.current.attachShadow({ mode: 'open' }));
    }
    const fonts = document.createElement('link');
    fonts.rel = 'stylesheet';
    fonts.href =
      'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Readex+Pro:wght@400;500;600;700&display=swap';
    document.head.append(fonts);
    return () => fonts.remove();
  }, []);

  useEffect(() => {
    if (host.current) {
      host.current.setAttribute('data-theme', isDark ? 'dark' : 'light');
    }
  }, [isDark]);

  useEffect(() => {
    const handleThemeChange = (e: any) => {
      if (typeof e.detail?.darkMode === 'boolean') {
        setDetectedDark(e.detail.darkMode);
      }
    };
    window.addEventListener('vortix:theme-change', handleThemeChange);

    const checkParent = () => {
      const rootNode = host.current?.getRootNode() as ShadowRoot | undefined;
      const parentViewport = host.current?.closest('#app-viewport');
      const target =
        parentViewport ||
        (rootNode?.host?.querySelector?.('#app-viewport') || rootNode?.host) ||
        document.querySelector('#app-viewport') ||
        document.querySelector('[data-vortix-host]') ||
        document.documentElement;
      if (!target) return null;
      const dark = target.classList?.contains('theme-dark') || target.classList?.contains('dark') || target.getAttribute?.('data-theme') === 'dark';
      setDetectedDark(dark);
      return target;
    };

    const target = checkParent();
    let observer: MutationObserver | undefined;
    if (target) {
      observer = new MutationObserver(() => checkParent());
      observer.observe(target, { attributes: true, attributeFilter: ['class', 'data-theme'] });
    }

    return () => {
      window.removeEventListener('vortix:theme-change', handleThemeChange);
      observer?.disconnect();
    };
  }, []);

  const go = (r: OperationsRoute) => {
    setRoute(r);
    let hash = `/${r.m}`;
    if (r.id != null) {
      hash += `/${r.id}`;
    }
    if (r.sub) {
      hash += `?sub=${encodeURIComponent(r.sub)}`;
    }
    window.location.hash = hash;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const unread = notif.unread;
  const curGroup = groupOf(route.m);

  const navGroups = NAV_GROUPS.map(g => ({ ...g, items: g.items.filter(it => canViewOperation(it.m, can)) })).filter(g => g.items.length > 0);
  const activeGroupObj = navGroups.find((g) => g.key === curGroup);

  const pageContent = useMemo(() => {
    const { m, id, sub } = route;
    const numId = id != null ? Number(id) : undefined;
    if (['suppliers', 'purchasing', 'brands'].includes(m)) return <Empty icon="lock" title="هذا القسم متوقف" text="منتجات الفريد من تصنيعنا؛ التجار يُدارون من العملاء والجملة." />;
    if (!canViewOperation(m, can)) return <Empty icon="lock" title="ليس لديك صلاحية عرض هذه الصفحة." />;
    switch (m) {
      case 'orders':
        return numId ? <OrderDetail id={numId} go={go} /> : <OrdersList go={go} />;
      case 'returns':
        return numId ? <ReturnDetail id={numId} go={go} /> : <ReturnsList go={go} />;
      case 'shipping':
        return <ShippingPage />;
      case 'pricing':
        return numId ? <PriceListDetail id={numId} go={go} /> : <PricingPage go={go} />;
      case 'promos':
        return <PromosPage />;
      case 'finance':
        return <FinancePage sub={sub} can={can} />;
      case 'shifts':
        return <ShiftsPage />;
      case 'wholesale':
        return <WholesalePage sub={sub} />;
      case 'reports':
        return <ReportsPage />;
      case 'notifications':
        return <NotificationsPage items={notifs} onRead={markRead} onReadAll={notif.markAllRead} onMore={notif.hasMore ? notif.loadMore : undefined} loading={notif.loading} go={go} />;
      default:
        return <OrdersList go={go} />;
    }
  }, [route, notifs, notif.loading, notif.hasMore, can]);

  return (
    <div
      ref={host}
      data-operations-mock={route.m}
      data-theme={isDark ? 'dark' : 'light'}
      dir="rtl"
    >
      {root &&
        createPortal(
          <>
            <style>{styles}</style>
            <ToastHost portalHost={root}>
              <div className={`operations-mock-container ${isDark ? 'theme-dark dark' : 'theme-light'} max-w-[1400px] mx-auto px-4 sm:px-7 pt-4 pb-12`} dir="rtl" lang="ar">
                {/* Operations Top Sub-Header & Navigation Bar */}
                <div className="flex flex-wrap items-center justify-between gap-4 mb-6 pb-4 border-b border-[var(--border-subtle)]">
                  {/* Secondary Navigation Pills */}
                  <div className="flex items-center gap-2 flex-wrap">
                    <div className="v-pill p-1 flex items-center gap-1 flex-wrap">
                      {navGroups.map((g) => (
                        <button
                          key={g.key}
                          onClick={() => go({ m: g.items[0].m })}
                          className="px-4 py-1.5 rounded-full text-[12.5px] font-medium transition"
                          style={
                            curGroup === g.key
                              ? {
                                  background: 'var(--pill-active-bg)',
                                  color: 'var(--pill-active-text)',
                                  fontWeight: 600,
                                }
                              : { color: 'var(--text-secondary)' }
                          }
                        >
                          {g.label}
                        </button>
                      ))}
                    </div>

                    {/* Sub-items in current group */}
                    {activeGroupObj && activeGroupObj.items.length > 1 && (
                      <div className="flex items-center gap-1.5 v-sub px-3 py-1 rounded-full text-[12px]">
                        {activeGroupObj.items.map((it) => {
                          const active = route.m === it.m;
                          return (
                            <button
                              key={it.m}
                              onClick={() => go({ m: it.m })}
                              className={`px-2.5 py-1 rounded-full transition flex items-center gap-1.5 ${
                                active
                                  ? 'bg-[var(--accent)] text-white font-semibold shadow-xs'
                                  : 'tx2 hover:tx1'
                              }`}
                            >
                              <Icon name={it.icon} size={12} />
                              <span>{it.label}</span>
                            </button>
                          );
                        })}
                      </div>
                    )}
                  </div>

                  {/* Notifications & Quick Controls */}
                  <div className="flex items-center gap-3">
                    <button
                      className="v-pill px-3 py-1.5 flex items-center gap-2 tx2 hover:tx1 text-[12.5px] font-medium relative"
                      title="الإشعارات"
                      onClick={() => setDrawer(true)}
                    >
                      <Icon name="bell" size={15} />
                      <span>الإشعارات</span>
                      {unread > 0 && (
                        <span
                          className="min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-bold text-white flex items-center justify-center num"
                          style={{ background: 'var(--accent)' }}
                        >
                          {unread}
                        </span>
                      )}
                    </button>
                  </div>
                </div>

                {/* Main Active Page Content */}
                <main>{USE_MOCK && <Note tone="orange">وضع البيانات التجريبية مفعّل — لا يتم حفظ أي تغيير.</Note>}{notif.error && <Note tone="red">{notif.error}{notif.usingMock && '؛ الإشعارات المعروضة تجريبية.'}<button className="btn btn-ghost btn-xs" onClick={notif.reload}>إعادة المحاولة</button></Note>}{pageContent}</main>

                {/* Notifications Drawer */}
                <NotifDrawer
                  open={drawer}
                  onClose={() => setDrawer(false)}
                  items={notifs}
                  onRead={markRead}
                  go={go}
                  container={root as unknown as Element}
                />
              </div>
            </ToastHost>
          </>,
          root
        )}
    </div>
  );
}
