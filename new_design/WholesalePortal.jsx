/* Wholesale Portal Shell - Ties together all screens from new_design */
const { useState, useEffect, useCallback, useMemo } = React;

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

function WholesalePortal({ initialScreen = 'dashboard', onLogout }) {
  const [route, setRoute] = useState({ screen: initialScreen, id: null });
  const [cart, setCart] = useState([
    {
      ...ALL_VARIANTS[0],
      product: PRODUCTS[0],
      qty: 6,
    },
    {
      ...ALL_VARIANTS[3],
      product: PRODUCTS[1],
      qty: 4,
    },
  ]);
  const [notifs, setNotifs] = useState(NOTIFS);
  const [orders, setOrders] = useState(ORDERS);
  const [lastOrder, setLastOrder] = useState(ORDERS[0]);
  const [toasts, setToasts] = useState([]);

  const toast = useCallback((msg) => {
    const id = Date.now() + Math.random();
    setToasts((prev) => [...prev, { id, msg }]);
    setTimeout(() => {
      setToasts((prev) => prev.filter((t) => t.id !== id));
    }, 3500);
  }, []);

  const go = useCallback((screen, id = null) => {
    setRoute({ screen, id });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }, []);

  const addToCart = useCallback((variant, qty = 1) => {
    setCart((prev) => {
      const existing = prev.find((item) => item.sku === variant.sku);
      if (existing) {
        return prev.map((item) =>
          item.sku === variant.sku ? { ...item, qty: item.qty + qty } : item
        );
      }
      const prod = PRODUCTS.find((p) => p.id === variant.productId) || PRODUCTS[0];
      return [...prev, { ...variant, product: prod, qty }];
    });
    toast('تمت الإضافة إلى سلة المشتريات بنجاح');
  }, [toast]);

  const placeOrder = useCallback((payload) => {
    const newId = orders.length + 1;
    const newOrder = {
      id: newId,
      number: `ORD-2026-0${140 + newId}`,
      date: new Date().toISOString().split('T')[0],
      status: 'pending',
      pay: payload.payment_method === 'credit' ? 'unpaid' : 'paid',
      method: payload.payment_method || 'credit',
      subtotal: payload.subtotal || 25000,
      discount: payload.discount || 0,
      tax: payload.tax || 3500,
      shipping: payload.shipping || 0,
      total: payload.total || 28500,
      items: cart.map((it) => ({
        name: it.product?.name_ar || it.name || 'حقيبة',
        sku: it.sku,
        qty: it.qty,
        price: it.price || 1500,
        color: it.color || 'أسود',
      })),
      history: [
        ['pending', 'الآن', 'تم استلام الطلب وبانتظار المراجعة والتأكيد'],
      ],
      po: payload.po_number || null,
      address: payload.shipping_address || 'المخزن الرئيسي',
      notes: payload.notes || null,
    };
    setOrders((prev) => [newOrder, ...prev]);
    setLastOrder(newOrder);
    setCart([]);
    toast(`تم تسجيل الطلب ${newOrder.number} بنجاح`);
    return newOrder;
  }, [cart, orders.length, toast]);

  const logout = useCallback(() => {
    if (onLogout) onLogout();
    else toast('تم تسجيل الخروج');
  }, [onLogout, toast]);

  const unreadNotifsCount = useMemo(() => notifs.filter((n) => n.unread).length, [notifs]);
  const cartTotalQty = useMemo(() => cart.reduce((sum, it) => sum + (it.qty || 0), 0), [cart]);

  const contextValue = useMemo(() => ({
    go,
    route,
    cart,
    setCart,
    addToCart,
    placeOrder,
    lastOrder,
    notifs,
    setNotifs,
    orders,
    setOrders,
    toast,
    account: ACCOUNT,
    logout,
  }), [go, route, cart, placeOrder, lastOrder, notifs, orders, toast, logout]);

  // Render subscreen
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
        return <ApplicationStatus onBack={() => go('dashboard')} onEnter={() => go('dashboard')} />;
      case 'forgot':
        return <ForgotPassword onBack={() => go('dashboard')} onGoReset={() => go('reset')} />;
      case 'reset':
        return <ResetPassword onBack={() => go('dashboard')} onForgot={() => go('forgot')} />;
      default:
        return <Dashboard />;
    }
  };

  const isAuthScreen = ['status', 'forgot', 'reset'].includes(route.screen);

  return (
    <AppCtx.Provider value={contextValue}>
      <div className="wholesale-portal-app" dir="rtl">
        {/* Sticky Header */}
        <header className="wp-header">
          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <button className="wp-logo" onClick={() => go('dashboard')}>
              <span>SOPHIE</span>
              <span className="wp-logo-tag">WHOLESALE</span>
            </button>
          </div>

          <div className="wp-head-right">
            <div className="wp-credit-chip" title="الحد الائتماني والمتاح">
              <span>الرصيد المتاح:</span>
              <strong className="num">{fmt(ACCOUNT.available_credit, 0)} EGP</strong>
            </div>

            <button
              className="wp-icon-btn"
              onClick={() => go('notifications')}
              title="الإشعارات"
            >
              <i className="fa-regular fa-bell" />
              {unreadNotifsCount > 0 && <span className="wp-count">{unreadNotifsCount}</span>}
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
              {ACCOUNT.name.split(' ').map(s => s[0]).slice(0, 2).join('')}
            </button>
          </div>
        </header>

        {/* Auth full page or standard layout */}
        {isAuthScreen ? (
          <div className="wp-main" style={{ padding: 0 }}>
            {renderScreen()}
          </div>
        ) : (
          <div className="wp-layout">
            {/* Sidebar */}
            <aside className="wp-side">
              <div className="wp-side-tag">B2B PORTAL</div>

              {NAV_GROUPS.map((group) => (
                <div key={group.title} className="wp-side-group">
                  <div className="wp-side-group-title">{group.title}</div>
                  {group.items.map((item) => {
                    const isActive = route.screen === item.id ||
                      (item.id === 'orders' && route.screen === 'order') ||
                      (item.id === 'quotes' && ['quote', 'quoteNew', 'quoteEdit'].includes(route.screen)) ||
                      (item.id === 'returns' && ['return', 'returnNew'].includes(route.screen)) ||
                      (item.id === 'notices' && ['notice', 'noticeNew'].includes(route.screen));
                    const badgeVal =
                      item.badge === 'cart' && cartTotalQty > 0
                        ? cartTotalQty
                        : item.badge === 'notifs' && unreadNotifsCount > 0
                        ? unreadNotifsCount
                        : null;

                    return (
                      <button
                        key={item.id}
                        className={`wp-side-link ${isActive ? 'is-active' : ''}`}
                        onClick={() => go(item.id)}
                      >
                        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                          <span className="wp-side-dot" />
                          <i className={`fa-solid ${item.icon}`} style={{ width: 18, textAlign: 'center', fontSize: 13 }} />
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
                    <i className="fa-solid fa-arrow-right-from-bracket" style={{ width: 18, textAlign: 'center' }} />
                    <span>تسجيل الخروج</span>
                  </div>
                </button>
              </div>
            </aside>

            {/* Main Content Area */}
            <main className="wp-main">
              <div className="wp-wrap">
                {renderScreen()}
              </div>
            </main>
          </div>
        )}

        {/* Floating Toasts */}
        {toasts.length > 0 && (
          <div style={{ position: 'fixed', bottom: 24, left: '50%', transform: 'translateX(-50%)', zIndex: 9999, display: 'flex', flexDirection: 'column', gap: 8 }}>
            {toasts.map((t) => (
              <div key={t.id} className="toast" style={{ position: 'relative', bottom: 'auto', left: 'auto', transform: 'none' }}>
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

Object.assign(window, { WholesalePortal });
