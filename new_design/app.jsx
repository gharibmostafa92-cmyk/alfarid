// ============ App shell (Vortix header + floating pill sidebar, RTL) ============

const INV_NAV = [
  { id: 'branches',  label: 'الفروع والمخازن', icon: 'store',     match: ['branches', 'locations'] },
  { id: 'stock',     label: 'الأرصدة',         icon: 'box',       match: ['stock'] },
  { id: 'adjust',    label: 'التسوية',         icon: 'sliders',   match: ['adjust'] },
  { id: 'moves',     label: 'الحركات',         icon: 'history',   match: ['moves'] },
  { id: 'transfers', label: 'التحويلات',       icon: 'swap',      match: ['transfers', 'transfer', 'transfer-edit'] },
  { id: 'counts',    label: 'الجرد',           icon: 'clipboard', match: ['counts', 'count'] },
  { id: 'production',label: 'استلام الإنتاج',  icon: 'factory',   match: ['production'] },
  { id: 'banners',   label: 'إدارة المتجر',    icon: 'storefront',match: ['banners'] },
];

function InvHeader({ route, go, theme, role }) {
  return (
    <header className="flex items-center justify-between gap-4 mb-6">
      <button onClick={() => go('stock')} className="v-pill-surface rounded-full p-1.5 pl-5 flex items-center gap-2.5 shadow-xs hover:opacity-95 transition">
        <div className="w-9 h-9 rounded-full flex items-center justify-center shadow-inner" style={{ background: 'radial-gradient(circle at 30% 25%, #ff8a65 0%, #e0491f 75%)' }}>
          <svg className="w-4 h-4 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"><path d="M5 5l7 14 7-14" /></svg>
        </div>
        <span className="font-display font-bold text-[15px] tracking-tight c-primary" style={{ fontFamily: 'Plus Jakarta Sans' }}>Vortix</span>
        <span className="text-[11px] c-muted hidden sm:inline">| المخزون</span>
      </button>

      <nav className="v-pill-surface rounded-full p-1.5 hidden xl:flex items-center gap-1 shadow-xs">
        {INV_NAV.map(n => {
          const on = n.match.includes(route);
          return (
            <button key={n.id} onClick={() => go(n.id === 'branches' ? 'branches' : n.id)}
              className={`px-3.5 py-2 rounded-full text-[12.5px] transition-colors duration-150 ${on ? 'font-semibold shadow-xs' : 'font-medium'}`}
              style={{ background: on ? 'var(--pill-active-bg)' : 'transparent', color: on ? 'var(--pill-active-text)' : 'var(--text-secondary)' }}>{n.label}</button>
          );
        })}
      </nav>

      <div className="flex items-center gap-3">
        <div className="v-pill-surface rounded-full px-4 py-2.5 flex items-center gap-4 shadow-xs c-primary">
          <Icon n="search" s={17} w={2} />
          <span className="relative"><Icon n="bell" s={17} w={2} /><span className="absolute -top-0.5 -left-0.5 w-2 h-2 rounded-full bg-[#e0491f]" style={{ boxShadow: '0 0 0 2px var(--bg-card)' }} /></span>
          <Icon n="help" s={17} w={2} />
        </div>
        <div className="v-pill-surface rounded-full p-1.5 pl-4 flex items-center gap-2.5 shadow-xs">
          <div className="w-9 h-9 rounded-full overflow-hidden flex items-center justify-center bg-[#f4e3d7] shrink-0">
            <svg viewBox="0 0 64 64" className="w-full h-full">
              <rect width="64" height="64" fill="#f3dfd1" /><path d="M12 64c2-11 10-16 20-16s18 5 20 16H12z" fill="#18151c" /><path d="M27 48l5 8 5-8z" fill="#fff" />
              <circle cx="32" cy="28" r="11" fill="#e0b193" /><path d="M21 26c0-8 5-13 11-13s11 5 11 13c-2-3-5-4-11-4s-9 1-11 4z" fill="#231c18" />
              <path d="M22.5 30c.5 6 4.5 10 9.5 10s9-4 9.5-10c-2.5 1.5-5.5 2.5-9.5 2.5s-7-1-9.5-2.5z" fill="#231c18" />
            </svg>
          </div>
          <div className="leading-tight hidden sm:block">
            <div className="text-[13px] font-semibold c-primary">أحمد سمير</div>
            <div className="text-[11px] c-secondary">{INV_ROLES[role].label}</div>
          </div>
        </div>
      </div>
    </header>
  );
}

function InvSidebar({ route, go, theme, setTheme }) {
  return (
    <aside className="flex flex-col items-center gap-4 shrink-0 pt-1 sticky top-5">
      <div className="v-pill-surface rounded-full p-1.5 flex flex-col items-center gap-1.5 shadow-xs w-[44px]">
        {[['light', 'sun'], ['dark', 'moon']].map(([k, ic]) => (
          <button key={k} onClick={() => setTheme(k)} className="w-8 h-8 rounded-full flex items-center justify-center"
            style={{ background: theme === k ? 'var(--theme-active-circle)' : 'transparent', color: theme === k ? 'var(--text-primary)' : 'var(--text-secondary)' }}>
            <Icon n={ic} s={16} />
          </button>
        ))}
      </div>
      <div className="v-pill-surface rounded-full p-1.5 flex flex-col items-center gap-2 shadow-xs w-[44px] mt-4">
        {INV_NAV.map(n => {
          const on = n.match.includes(route);
          return (
            <button key={n.id} title={n.label} onClick={() => go(n.id)} className="w-8 h-8 rounded-full flex items-center justify-center transition-colors"
              style={{ background: on ? 'var(--pill-active-bg)' : 'transparent', color: on ? 'var(--pill-active-text)' : 'var(--text-secondary)' }}>
              <Icon n={n.icon} s={16} />
            </button>
          );
        })}
      </div>
      <div className="v-pill-surface rounded-full p-1.5 flex flex-col items-center gap-1.5 shadow-xs w-[44px] mt-10">
        <button className="w-8 h-8 rounded-full flex items-center justify-center c-secondary"><Icon n="info" s={16} /></button>
        <button className="w-8 h-8 rounded-full flex items-center justify-center c-secondary"><Icon n="logout" s={16} /></button>
      </div>
    </aside>
  );
}

function InvApp() {
  const [t, setTweak] = useTweaks(window.INV_TWEAK_DEFAULTS);
  const [nav, setNav] = useState(() => {
    try { return JSON.parse(localStorage.getItem('vortix-inv-route')) || { route: 'stock', params: {} }; } catch { return { route: 'stock', params: {} }; }
  });
  const [db, setDb] = useState(() => ({ branches: INV_BRANCHES, locations: INV_LOCATIONS, transfers: INV_TRANSFERS, counts: INV_COUNTS, production: INV_PRODUCTION }));
  const [toastMsg, setToastMsg] = useState(null);
  const [reloadKey, setReloadKey] = useState(0);
  const tRef = useRef();

  const go = (route, params = {}) => { setNav({ route, params }); window.scrollTo({ top: 0, behavior: 'smooth' }); };
  useEffect(() => { localStorage.setItem('vortix-inv-route', JSON.stringify(nav)); }, [nav]);
  const toast = text => { setToastMsg({ text }); clearTimeout(tRef.current); tRef.current = setTimeout(() => setToastMsg(null), 2400); };

  const ctx = { db, setDb, toast, role: t.role, demoState: t.demoState, reloadKey, reload: () => { setReloadKey(k => k + 1); if (t.demoState === 'error') setTweak('demoState', 'live'); } };

  const screens = {
    branches: BranchesScreen, locations: LocationsScreen, stock: StockScreen, adjust: AdjustScreen, moves: MovesScreen,
    transfers: TransfersScreen, transfer: TransferDetail, 'transfer-edit': TransferEdit, counts: CountsScreen, count: CountDetail, production: ProductionScreen, banners: BannersScreen,
  };
  const Screen = screens[nav.route] || StockScreen;
  const screenKey = nav.route + JSON.stringify(nav.params);
  const label = (INV_NAV.find(n => n.match.includes(nav.route)) || {}).label;

  return (
    <InvCtx.Provider value={ctx}>
      <div id="app-viewport" className={t.theme === 'dark' ? 'theme-dark' : 'theme-light'}>
        <div className="max-w-[1440px] mx-auto px-4 sm:px-7 pt-5 pb-10">
          <InvHeader route={nav.route} go={go} theme={t.theme} role={t.role} />
          <div className="flex gap-6 items-start">
            <InvSidebar route={nav.route} go={go} theme={t.theme} setTheme={v => setTweak('theme', v)} />
            <main className="flex-1 min-w-0" data-screen-label={label}>
              <Screen key={screenKey} go={go} params={nav.params} />
            </main>
          </div>
        </div>
        <Toast toast={toastMsg} />
      </div>

      <div dir="ltr">
      <TweaksPanel title="Tweaks">
        <TweakSection label="الصلاحية">
          <TweakRadio label="الدور" value={t.role} onChange={v => setTweak('role', v)}
            options={[{ value: 'admin', label: 'مدير' }, { value: 'storekeeper', label: 'أمين مخزن' }, { value: 'viewer', label: 'عرض فقط' }]} />
        </TweakSection>
        <TweakSection label="حالة البيانات">
          <TweakSelect label="محاكاة الاستجابة" value={t.demoState} onChange={v => setTweak('demoState', v)}
            options={[{ value: 'live', label: 'بيانات فعلية' }, { value: 'loading', label: 'تحميل' }, { value: 'empty', label: 'فارغ' }, { value: 'error', label: 'خطأ من السيرفر' }]} />
        </TweakSection>
        <TweakSection label="المظهر">
          <TweakRadio label="الوضع" value={t.theme} onChange={v => setTweak('theme', v)} options={[{ value: 'light', label: 'فاتح' }, { value: 'dark', label: 'داكن' }]} />
        </TweakSection>
      </TweaksPanel>
      </div>
    </InvCtx.Provider>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<InvApp />);
