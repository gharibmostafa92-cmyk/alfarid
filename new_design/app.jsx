/* ============ App shell ============ */
const NAV_GROUPS = [
  { key: 'sales', label: 'المبيعات', items: [
    { m: 'orders', label: 'الطلبات', icon: 'orders' },
    { m: 'returns', label: 'المرتجعات', icon: 'returns' },
    { m: 'wholesale', label: 'الجملة', icon: 'quote' },
    { m: 'shipping', label: 'الشحن', icon: 'truck' },
  ] },
  { key: 'catalog', label: 'التسعير والعروض', items: [
    { m: 'pricing', label: 'التسعير ومجموعات العملاء', icon: 'tag' },
    { m: 'promos', label: 'العروض والكوبونات', icon: 'ticket' },
    { m: 'brands', label: 'البراندات', icon: 'star' },
  ] },
  { key: 'supply', label: 'المشتريات', items: [
    { m: 'suppliers', label: 'الموردون', icon: 'factory' },
    { m: 'purchasing', label: 'المشتريات', icon: 'cart' },
  ] },
  { key: 'finance', label: 'المالية', items: [
    { m: 'finance', label: 'المالية', icon: 'vault' },
    { m: 'shifts', label: 'مراجعة الورديات', icon: 'clock' },
    { m: 'reports', label: 'التقارير', icon: 'chart' },
  ] },
];
const groupOf = m => NAV_GROUPS.find(g => g.items.some(i => i.m === m))?.key;

function App() {
  const [route, setRoute] = useState(() => { try { return JSON.parse(localStorage.getItem('vx-route')) || { m: 'orders' }; } catch { return { m: 'orders' }; } });
  const [dark, setDark] = useState(() => localStorage.getItem('vx-dark') === '1');
  const [notifs, setNotifs] = useState(NOTIFS);
  const [drawer, setDrawer] = useState(false);
  const [k, setK] = useState(0);

  useEffect(() => { localStorage.setItem('vx-route', JSON.stringify(route)); }, [route]);
  useEffect(() => { document.documentElement.classList.toggle('dark', dark); localStorage.setItem('vx-dark', dark ? '1' : '0'); }, [dark]);

  const go = r => { setRoute(r); setK(x => x + 1); window.scrollTo({ top: 0 }); };
  const markRead = id => setNotifs(ns => ns.map(n => n.id === id ? { ...n, read_at: 'now' } : n));
  const unread = notifs.filter(n => !n.read_at).length;

  const switchTheme = (toDark, e) => {
    const target = toDark === dark ? !dark : toDark;
    if (!document.startViewTransition) { setDark(target); return; }
    const r = e.currentTarget.getBoundingClientRect();
    const x = r.left + r.width / 2, y = r.top + r.height / 2;
    const rad = Math.hypot(Math.max(x, innerWidth - x), Math.max(y, innerHeight - y));
    const t = document.startViewTransition(() => { ReactDOM.flushSync(() => setDark(target)); });
    t.ready.then(() => document.documentElement.animate({ clipPath: [`circle(0px at ${x}px ${y}px)`, `circle(${rad}px at ${x}px ${y}px)`] },
      { duration: 660, easing: 'cubic-bezier(.45,0,.2,1)', pseudoElement: '::view-transition-new(root)' }));
  };

  const page = (() => {
    const { m, id, sub } = route;
    switch (m) {
      case 'orders': return id ? <OrderDetail id={id} go={go} /> : <OrdersList go={go} />;
      case 'returns': return id ? <ReturnDetail id={id} go={go} /> : <ReturnsList go={go} />;
      case 'shipping': return <ShippingPage />;
      case 'pricing': return id ? <PriceListDetail id={id} go={go} /> : <PricingPage go={go} />;
      case 'promos': return <PromosPage />;
      case 'suppliers': return id ? <SupplierDetail id={id} go={go} /> : <SuppliersList go={go} />;
      case 'purchasing': return <PurchasingPage sub={sub} />;
      case 'finance': return <FinancePage sub={sub} />;
      case 'shifts': return <ShiftsPage />;
      case 'wholesale': return <WholesalePage sub={sub} />;
      case 'reports': return <ReportsPage />;
      case 'brands': return <BrandsPage />;
      case 'notifications': return <NotificationsPage items={notifs} onRead={markRead} go={go} />;
      default: return <OrdersList go={go} />;
    }
  })();
  const curGroup = groupOf(route.m);

  return <div className="max-w-[1400px] mx-auto px-4 sm:px-7 pt-5 pb-10">
    {/* header */}
    <header className="flex items-center justify-between gap-4 mb-6">
      <button className="v-pill p-1.5 ps-1.5 pe-5 flex items-center gap-2.5" onClick={() => go({ m: 'orders' })}>
        <div className="w-9 h-9 rounded-full flex items-center justify-center" style={{ background: 'radial-gradient(circle at 30% 25%, #ff8a65 0%, #e0491f 75%)' }}>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"><path d="M5 5l7 14 7-14" /></svg>
        </div>
        <span className="font-display font-bold text-[15px] tx1" style={{ fontFamily: 'Plus Jakarta Sans' }}>Vortix</span>
        <span className="text-[11px] tx3 hidden sm:inline">· الإدارة</span>
      </button>
      <nav className="v-pill p-1.5 hidden lg:flex items-center gap-1">
        {NAV_GROUPS.map(g => <button key={g.key} onClick={() => go({ m: g.items[0].m })} className="px-5 py-2 rounded-full text-[13px] font-medium transition"
          style={curGroup === g.key ? { background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)', fontWeight: 600 } : { color: 'var(--text-secondary)' }}>{g.label}</button>)}
      </nav>
      <div className="flex items-center gap-3">
        <div className="v-pill px-4 py-2.5 flex items-center gap-4">
          <button className="tx1 hover:opacity-70" title="بحث"><Icon name="search" size={17} sw={2} /></button>
          <button className="tx1 hover:opacity-70 relative" title="الإشعارات" onClick={() => setDrawer(true)}>
            <Icon name="bell" size={17} sw={2} />
            {unread > 0 && <span className="absolute -top-2 -end-2.5 min-w-[17px] h-[17px] px-1 rounded-full text-[9.5px] font-bold text-white flex items-center justify-center num" style={{ background: 'var(--accent)', boxShadow: '0 0 0 2px var(--bg-card)' }}>{unread}</span>}
          </button>
          <button className="tx1 hover:opacity-70" title="المساعدة"><Icon name="help" size={17} sw={2} /></button>
        </div>
        <button className="v-pill p-1.5 pe-4 flex items-center gap-2.5 text-start">
          <Avatar name="أحمد ناصر" tone={1} size={36} />
          <div className="leading-tight hidden sm:block"><div className="text-[13px] font-semibold tx1">أحمد ناصر</div><div className="text-[11px] tx2">مدير العمليات</div></div>
          <Icon name="chevD" size={14} className="tx2 ms-1" />
        </button>
      </div>
    </header>

    <div className="flex gap-6 items-start">
      {/* sidebar */}
      <aside className="flex flex-col items-center gap-3 shrink-0 pt-1 sticky top-5">
        <div className="v-pill p-1.5 flex flex-col items-center gap-1.5 w-[44px]">
          <button onClick={e => switchTheme(false, e)} className="w-8 h-8 rounded-full flex items-center justify-center side-btn" style={!dark ? { background: 'var(--theme-active-circle)', color: 'var(--text-primary)' } : { color: 'var(--text-secondary)' }}><Icon name="sun" size={17} /><span className="tip">الوضع الفاتح</span></button>
          <button onClick={e => switchTheme(true, e)} className="w-8 h-8 rounded-full flex items-center justify-center side-btn" style={dark ? { background: 'var(--theme-active-circle)', color: 'var(--text-primary)' } : { color: 'var(--text-secondary)' }}><Icon name="moon" size={16} /><span className="tip">الوضع الداكن</span></button>
        </div>
        {NAV_GROUPS.map(g => <div key={g.key} className="v-pill p-1.5 flex flex-col items-center gap-1.5 w-[44px]">
          {g.items.map(it => { const on = route.m === it.m; return <button key={it.m} onClick={() => go({ m: it.m })} className="side-btn w-8 h-8 rounded-full flex items-center justify-center transition"
            style={on ? { background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' } : { color: 'var(--text-secondary)' }}><Icon name={it.icon} size={16} /><span className="tip">{it.label}</span></button>; })}
        </div>)}
        <div className="v-pill p-1.5 flex flex-col items-center gap-1.5 w-[44px] mt-2">
          <button onClick={() => go({ m: 'notifications' })} className="side-btn w-8 h-8 rounded-full flex items-center justify-center relative" style={route.m === 'notifications' ? { background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' } : { color: 'var(--text-secondary)' }}>
            <Icon name="bell" size={16} />{unread > 0 && <span className="absolute top-1 end-1 w-1.5 h-1.5 rounded-full" style={{ background: 'var(--accent)' }}></span>}<span className="tip">كل الإشعارات</span></button>
          <button className="side-btn w-8 h-8 rounded-full flex items-center justify-center tx2"><Icon name="logout" size={16} style={{ transform: 'scaleX(-1)' }} /><span className="tip">تسجيل الخروج</span></button>
        </div>
      </aside>

      <main className="flex-1 min-w-0" key={k} data-screen-label={route.m + (route.id ? ' detail' : '')}>{page}</main>
    </div>
    <NotifDrawer open={drawer} onClose={() => setDrawer(false)} items={notifs} onRead={markRead} go={go} />
  </div>;
}

ReactDOM.createRoot(document.getElementById('root')).render(<ToastHost><App /></ToastHost>);
