// App shell: one sidebar (grouped, collapsible), slim topbar, mobile tab bar.
const NAV = [
  { group: null, items: [
    { id: 'overview', label: 'الرئيسية', icon: 'home' },
    { id: 'assistant', label: 'المساعد الذكي', icon: 'spark' },
  ] },
  { group: 'المبيعات', items: [
    { id: 'orders', label: 'الطلبات', icon: 'orders', count: 12 },
    { id: 'dealers', label: 'التجار والموزعون', icon: 'dealer', count: 2, countMuted: true },
    { id: 'pos', label: 'نقطة البيع', icon: 'pos' },
  ] },
  { group: 'الكتالوج', items: [
    { id: 'products', label: 'المنتجات', icon: 'box' },
    { id: 'categories', label: 'الأقسام', icon: 'tree' },
  ] },
  { group: 'المخزون', items: [
    { id: 'inventory', label: 'المخزون', icon: 'stock', children: [
      { id: 'stock', label: 'الأرصدة' },
      { id: 'transfers', label: 'التحويلات' },
      { id: 'counts', label: 'الجرد' },
      { id: 'moves', label: 'الحركات' },
      { id: 'branches', label: 'الفروع والمخازن' },
    ] },
    { id: 'production', label: 'استلام الإنتاج', icon: 'factory' },
  ] },
  { group: 'المالية', items: [
    { id: 'finance', label: 'الخزائن والمصروفات', icon: 'wallet' },
    { id: 'reports', label: 'التقارير', icon: 'chart' },
  ] },
  { group: 'الإدارة', items: [
    { id: 'users', label: 'المستخدمون', icon: 'users' },
    { id: 'roles', label: 'الأدوار والصلاحيات', icon: 'shield' },
    { id: 'settings', label: 'الإعدادات', icon: 'gear' },
  ] },
];
const ALL_ROUTES = NAV.flatMap(g => g.items.flatMap(i => i.children ? i.children.map(c => ({ ...c, parent: i, group: g.group })) : [{ ...i, group: g.group }]));
const routeMeta = id => ALL_ROUTES.find(r => r.id === id) || (id === 'product' ? { id, label: 'تعديل منتج', group: 'الكتالوج', back: 'products' } : id === 'transfer' ? { id, label: 'تفاصيل التحويل', group: 'المخزون', back: 'transfers' } : { id, label: id });

function Sidebar({ route, go, collapsed, setCollapsed, dark, setDark }) {
  const inInv = ['stock', 'transfers', 'transfer', 'counts', 'moves', 'branches'].includes(route);
  const [invOpen, setInvOpen] = useState(true);
  const activeId = route === 'product' ? 'products' : route === 'transfer' ? 'transfers' : route;
  return <aside className="side" aria-label="التنقل الرئيسي">
    <div className="side-brand">
      <div className="brand-mark"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"><path d="M5 5l7 14 7-14"/></svg></div>
      <div className="brand-text grow"><div className="brand-name">الفريد</div><div className="brand-sub">لوحة الإدارة</div></div>
      <button className="icon-btn sm brand-text collapse-btn" onClick={() => setCollapsed(c => !c)} aria-label="طي القائمة" title="طي القائمة"><Icon n="panel" s={16} /></button>
    </div>
    <button className="side-search" onClick={() => window.dispatchEvent(new Event('open-cmdk'))} title="بحث سريع"><Icon n="search" s={16} /><span>بحث سريع…</span><kbd>⌘K</kbd></button>
    <div className="side-scroll">
      {NAV.map((g, gi) => <nav key={gi} className="nav-group" aria-label={g.group || 'عام'}>
        {g.group && <div className="nav-group-label">{g.group}</div>}
        {g.items.map(it => it.children ? <div key={it.id}>
          <button className={cx('nav-item', invOpen && 'open')} aria-expanded={invOpen} title={it.label}
            onClick={() => { if (collapsed) { go('stock'); return; } setInvOpen(o => !o); if (!inInv) go('stock'); }}
            aria-current={collapsed && inInv ? 'page' : undefined}>
            <Icon n={it.icon} s={18} /><span>{it.label}</span><Icon n="chevLeft" s={14} className="chev" />
          </button>
          {invOpen && <div className="nav-sub">{it.children.map(c => <button key={c.id} className="nav-item" aria-current={activeId === c.id ? 'page' : undefined} onClick={() => go(c.id)}><span>{c.label}</span></button>)}</div>}
        </div> : <button key={it.id} className="nav-item" title={it.label} aria-current={activeId === it.id ? 'page' : undefined} onClick={() => go(it.id)}>
          <Icon n={it.icon} s={18} /><span>{it.label}</span>{it.count && <span className={cx('nav-count', it.countMuted && 'muted')}>{fmt(it.count)}</span>}
        </button>)}
      </nav>)}
    </div>
    <div className="side-foot">
      <Menu align="start" up trigger={({ toggle }) => <button className="user-btn" onClick={toggle} title="الحساب">
        <span className="avatar">م ع</span>
        <span className="user-meta grow" style={{ minWidth: 0 }}><span className="fs-13 fw-6 truncate" style={{ display: 'block' }}>محمد علي</span><span className="fs-12 t-3 truncate" style={{ display: 'block' }}>مدير النظام</span></span>
        <Icon n="dots" s={16} className="user-meta t-3" />
      </button>} items={[
        { label: dark ? 'الوضع الفاتح' : 'الوضع الداكن', icon: dark ? 'sun' : 'moon', onClick: () => setDark(!dark) },
        { label: 'إعدادات الحساب', icon: 'gear' }, '-',
        { label: 'تسجيل الخروج', icon: 'logout', danger: true },
      ]} />
    </div>
  </aside>;
}

function Topbar({ route, go, dark, setDark, onMenu }) {
  const meta = routeMeta(route);
  const [scrolled, setScrolled] = useState(false);
  useEffect(() => { const h = () => setScrolled(window.scrollY > 4); window.addEventListener('scroll', h); return () => window.removeEventListener('scroll', h); }, []);
  return <header className={cx('topbar', scrolled && 'scrolled')}>
    <div className="brand-mark mobile-top-brand" style={{ width: 30, height: 30, borderRadius: 9 }}><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"><path d="M5 5l7 14 7-14"/></svg></div>
    <nav className="crumbs" aria-label="مسار التنقل">
      {meta.group && <span className="crumb-parent">{meta.group}</span>}
      {meta.group && <Icon n="chevLeft" s={12} />}
      {meta.parent && <><button className="crumb-parent" onClick={() => go('stock')}>{meta.parent.label}</button><Icon n="chevLeft" s={12} /></>}
      {meta.back && <><button className="crumb-parent" onClick={() => go(meta.back)}>{routeMeta(meta.back).label}</button><Icon n="chevLeft" s={12} /></>}
      <b className="truncate">{meta.label}</b>
    </nav>
    <div className="top-tools">
      <button className="icon-btn" aria-label="بحث" title="بحث (⌘K)" onClick={() => window.dispatchEvent(new Event('open-cmdk'))}><Icon n="search" s={18} /></button>
      <button className="icon-btn" aria-label="الإشعارات" title="الإشعارات"><Icon n="bell" s={18} /><span className="notif-dot" /></button>
      <button className="icon-btn hide-mobile" aria-label={dark ? 'الوضع الفاتح' : 'الوضع الداكن'} title={dark ? 'الوضع الفاتح' : 'الوضع الداكن'} onClick={() => setDark(!dark)}><Icon n={dark ? 'sun' : 'moon'} s={18} /></button>
      <span className="divider hide-mobile" />
      <button className="btn btn-primary btn-sm hide-mobile" onClick={() => go('pos')}><Icon n="pos" s={15} />فتح نقطة البيع</button>
    </div>
  </header>;
}

function MobileBar({ route, go, openMore }) {
  const items = [['overview', 'الرئيسية', 'home'], ['orders', 'الطلبات', 'orders'], ['stock', 'المخزون', 'stock'], ['products', 'المنتجات', 'box']];
  const primary = items.some(([id]) => id === route || (id === 'stock' && ['transfers', 'transfer', 'counts', 'moves', 'branches'].includes(route)) || (id === 'products' && route === 'product'));
  return <nav className="mobile-bar" aria-label="التنقل السفلي">
    {items.map(([id, l, ic]) => {
      const on = id === route || (id === 'stock' && ['transfers', 'transfer', 'counts', 'moves', 'branches'].includes(route)) || (id === 'products' && route === 'product');
      return <button key={id} aria-current={on ? 'page' : undefined} onClick={() => go(id)}><Icon n={ic} s={22} /><span>{l}</span></button>;
    })}
    <button aria-current={!primary ? 'page' : undefined} onClick={openMore}><Icon n="dots" s={22} /><span>المزيد</span></button>
  </nav>;
}

function MoreSheet({ open, onClose, go, dark, setDark }) {
  if (!open) return null;
  return ReactDOM.createPortal(<div className={dark ? 'theme-dark' : ''} dir="rtl">
    <div className="overlay" onClick={onClose} />
    <div className="sheet" role="dialog" aria-label="كل الأقسام">
      <div className="sheet-grip" />
      {NAV.filter(g => g.group).map(g => <div key={g.group} style={{ marginBottom: 14 }}>
        <div className="fs-12 fw-6 t-3" style={{ padding: '0 4px 8px' }}>{g.group}</div>
        <div className="grid" style={{ gridTemplateColumns: 'repeat(3, 1fr)', gap: 8 }}>
          {g.items.map(it => <button key={it.id} className="sunken col gap-2" style={{ alignItems: 'center', padding: '14px 6px', fontSize: 12, fontWeight: 500 }} onClick={() => { onClose(); go(it.children ? 'stock' : it.id); }}><Icon n={it.icon} s={22} />{it.label}</button>)}
        </div>
      </div>)}
      <div className="row gap-2" style={{ marginTop: 8 }}>
        <button className="btn btn-secondary grow" onClick={() => setDark(!dark)}><Icon n={dark ? 'sun' : 'moon'} s={16} />{dark ? 'الوضع الفاتح' : 'الوضع الداكن'}</button>
        <button className="btn btn-danger grow"><Icon n="logout" s={16} />تسجيل الخروج</button>
      </div>
    </div>
  </div>, document.body);
}

function CommandK({ go, dark }) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const [idx, setIdx] = useState(0);
  useEffect(() => {
    const o = () => { setOpen(true); setQ(''); setIdx(0); };
    const k = e => { if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); o(); } };
    window.addEventListener('open-cmdk', o); window.addEventListener('keydown', k);
    return () => { window.removeEventListener('open-cmdk', o); window.removeEventListener('keydown', k); };
  }, []);
  useEsc(() => setOpen(false), open);
  if (!open) return null;
  const pages = ALL_ROUTES.filter(r => r.label.includes(q)).map(r => ({ k: 'p' + r.id, label: r.label, sub: r.group || 'عام', icon: r.icon || r.parent?.icon || 'arrowUpLeft', run: () => go(r.id) }));
  const prods = q ? MOCK.products.filter(p => p.ar.includes(q) || p.code.toLowerCase().includes(q.toLowerCase())).slice(0, 4).map(p => ({ k: 'x' + p.id, label: p.ar, sub: p.code, icon: 'box', run: () => go('product', { id: p.id }) })) : [];
  const ords = q ? MOCK.orders.filter(o => o.id.toLowerCase().includes(q.toLowerCase()) || o.customer.includes(q)).slice(0, 3).map(o => ({ k: 'o' + o.id, label: o.id + ' — ' + o.customer, sub: 'طلب', icon: 'orders', run: () => go('orders') })) : [];
  const list = [...prods, ...ords, ...pages];
  const run = it => { setOpen(false); it?.run(); };
  return ReactDOM.createPortal(<div className={dark ? 'theme-dark' : ''} dir="rtl">
    <div className="overlay" onClick={() => setOpen(false)} />
    <div className="modal" style={{ top: '14vh', transform: 'translateX(-50%)', width: 'min(600px, calc(100vw - 24px))' }} role="dialog" aria-label="بحث سريع">
      <div className="pop">
        <div className="row gap-3" style={{ padding: '0 16px', height: 56, borderBottom: '1px solid var(--line)' }}>
          <Icon n="search" s={18} className="t-3" />
          <input autoFocus value={q} onChange={e => { setQ(e.target.value); setIdx(0); }} placeholder="ابحث عن صفحة أو منتج أو رقم طلب…" className="grow" style={{ border: 0, outline: 'none', background: 'transparent', fontSize: 15, height: '100%' }}
            onKeyDown={e => { if (e.key === 'ArrowDown') { e.preventDefault(); setIdx(i => Math.min(list.length - 1, i + 1)); } if (e.key === 'ArrowUp') { e.preventDefault(); setIdx(i => Math.max(0, i - 1)); } if (e.key === 'Enter') run(list[idx]); }} />
          <kbd className="fs-12 t-3" style={{ border: '1px solid var(--line-strong)', borderRadius: 5, padding: '0 6px' }}>Esc</kbd>
        </div>
        <div style={{ maxHeight: 380, overflow: 'auto', padding: 6 }}>
          {list.length === 0 && <div className="t-3 fs-13" style={{ padding: 24, textAlign: 'center' }}>لا نتائج لـ «{q}»</div>}
          {list.map((it, i) => <button key={it.k} onMouseEnter={() => setIdx(i)} onClick={() => run(it)} className="row gap-3" style={{ width: '100%', height: 44, padding: '0 10px', borderRadius: 10, background: i === idx ? 'var(--bg-hover)' : 'transparent', textAlign: 'start' }}>
            <span className="kpi-icon" style={{ width: 28, height: 28, borderRadius: 8 }}><Icon n={it.icon} s={15} /></span>
            <span className="grow fs-13 fw-5 truncate">{it.label}</span><span className="fs-12 t-3">{it.sub}</span>
          </button>)}
        </div>
      </div>
    </div>
  </div>, document.body);
}

function Placeholder({ route }) {
  const m = routeMeta(route);
  return <div className="page-enter">
    <PageHead title={m.label} sub="هذه الشاشة خارج نطاق النماذج المرفقة؛ تتبع نفس القوالب (رأس الصفحة، شريط الفلاتر، الجدول) عند تنفيذها." />
    <Card><Empty icon={m.icon || m.parent?.icon || 'info'} title="تتبع نفس النظام" body="رأس صفحة واحد، بطاقة واحدة تحتوي شريط أدوات + جدول + ترقيم. لا توجد مكونات خاصة بهذه الصفحة." /></Card>
  </div>;
}

Object.assign(window, { NAV, ALL_ROUTES, routeMeta, Sidebar, Topbar, MobileBar, MoreSheet, CommandK, Placeholder });
