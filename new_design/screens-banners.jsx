// ============ Store management · Banners & Slider ============

const BN_PLACEMENTS = {
  slider: { label: 'السلايدر الرئيسي',  icon: 'slides', ratio: '16 / 6', hint: 'صور تتقلّب في أعلى واجهة المتجر — كل صورة بانر مستقل' },
  middle: { label: 'بانرات وسط الصفحة', icon: 'mid',    ratio: '16 / 5', hint: 'صور إعلانية بين أقسام الصفحة الرئيسية' },
  popup:  { label: 'النوافذ المنبثقة',   icon: 'popup',  ratio: '4 / 5',  hint: 'تظهر للزائر فوق محتوى المتجر' },
};
const BN_LINK_TYPES = {
  none:     { label: 'بدون رابط',   icon: 'slash' },
  product:  { label: 'منتج',        icon: 'box' },
  category: { label: 'قسم',         icon: 'grid' },
  brand:    { label: 'براند',       icon: 'tag' },
  external: { label: 'رابط خارجي',  icon: 'external' },
};
const BN_CATEGORIES = [
  { id: 'cat1', name: 'ملابس رجالي' }, { id: 'cat2', name: 'ملابس حريمي' }, { id: 'cat3', name: 'إكسسوارات' }, { id: 'cat4', name: 'تخفيضات الموسم' }, { id: 'cat5', name: 'وصل حديثًا' },
];
const BN_BRANDS = [ { id: 'br1', name: 'Oxford Line' }, { id: 'br2', name: 'Nile Denim' }, { id: 'br3', name: 'Cairo Leather' }, { id: 'br4', name: 'Linen House' } ];

// Placeholder creatives (CSS-rendered) — uploaded images replace these
const BN_ART = {
  a1: { bg: 'radial-gradient(circle at 78% 42%, #f48668 0%, #e7582f 48%, #dc4318 100%)', fg: '#fff', kicker: 'خصم حتى ٤٠٪', line: 'مجموعة الشتاء' },
  a2: { bg: 'linear-gradient(145deg, #221f26 0%, #151318 100%)', fg: '#fff', kicker: 'جديد', line: 'جاكيت الجينز' },
  a3: { bg: 'linear-gradient(135deg, #efe6dc 0%, #dccbb8 100%)', fg: '#3a2c20', kicker: 'كتان طبيعي', line: 'صُنع في مصر' },
  a4: { bg: 'linear-gradient(135deg, #dfe6f1 0%, #b9c7de 100%)', fg: '#1d2a44', kicker: 'شحن مجاني', line: 'على الطلبات فوق ٨٠٠ ج.م' },
  a5: { bg: 'repeating-linear-gradient(-45deg, #e0491f 0, #e0491f 6px, #f37c59 6px, #f37c59 12px)', fg: '#fff', kicker: 'الجمعة البيضاء', line: 'قريبًا' },
  a6: { bg: 'linear-gradient(160deg, #2b2530 0%, #4a3a35 100%)', fg: '#f6d6c8', kicker: 'أحزمة جلد', line: 'Cairo Leather' },
  a7: { bg: 'linear-gradient(135deg, #f5f2f6 0%, #e4dde8 100%)', fg: '#18151c', kicker: 'اشترك', line: 'واحصل على ١٠٪ خصم' },
};

const BN_SEED = [
  { id: 'bn1', title: 'مجموعة الشتاء — خصم ٤٠٪', placement: 'slider', art: 'a1', linkType: 'category', linkId: 'cat4', url: '', sort: 1, start: '2026-10-01T00:00', end: '2026-11-30T23:59', active: true },
  { id: 'bn2', title: 'إطلاق جاكيت الجينز', placement: 'slider', art: 'a2', linkType: 'product', linkId: 'p3', url: '', sort: 2, start: '', end: '', active: true },
  { id: 'bn3', title: 'الجمعة البيضاء', placement: 'slider', art: 'a5', linkType: 'category', linkId: 'cat4', url: '', sort: 3, start: '2026-11-20T00:00', end: '2026-11-29T23:59', active: true },
  { id: 'bn4', title: 'كتان طبيعي — صنع في مصر', placement: 'slider', art: 'a3', linkType: 'brand', linkId: 'br4', url: '', sort: 4, start: '2026-08-01T00:00', end: '2026-09-30T23:59', active: true },
  { id: 'bn5', title: 'عرض العيد القديم', placement: 'slider', art: 'a6', linkType: 'none', linkId: null, url: '', sort: 5, start: '', end: '2026-12-31T23:59', active: false },
  { id: 'bn6', title: 'شحن مجاني فوق ٨٠٠', placement: 'middle', art: 'a4', linkType: 'external', linkId: null, url: 'https://vortix.eg/shipping', sort: 1, start: '2026-10-10T09:00', end: '', active: true },
  { id: 'bn7', title: 'أحزمة Cairo Leather', placement: 'middle', art: 'a6', linkType: 'brand', linkId: 'br3', url: '', sort: 2, start: '', end: '', active: true },
  { id: 'bn8', title: 'اشترك في النشرة', placement: 'popup', art: 'a7', linkType: 'none', linkId: null, url: '', sort: 1, start: '', end: '', active: true },
];

const BN_NOW = new Date('2026-10-25T12:00');
function bnStatus(b) {
  if (!b.active) return 'disabled';
  if (b.start && new Date(b.start) > BN_NOW) return 'scheduled';
  if (b.end && new Date(b.end) < BN_NOW) return 'expired';
  return 'live';
}
const BN_STATUS = {
  live:      { label: 'ساري',  cls: 'b-green' },
  scheduled: { label: 'مجدول', cls: 'b-blue'  },
  expired:   { label: 'منتهي', cls: 'b-amber' },
  disabled:  { label: 'معطّل', cls: 'b-gray'  },
};
const bnDate = s => {
  if (!s) return null;
  const d = new Date(s);
  return { d: d.toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' }), t: d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) };
};
function bnTarget(b) {
  switch (b.linkType) {
    case 'product':  return INV_PRODUCTS.find(p => p.id === b.linkId)?.name;
    case 'category': return BN_CATEGORIES.find(c => c.id === b.linkId)?.name;
    case 'brand':    return BN_BRANDS.find(x => x.id === b.linkId)?.name;
    case 'external': return b.url;
    default: return null;
  }
}

// ---------- Thumbnail ----------
function BannerArt({ b, ratio, big }) {
  if (b.image) return <img src={b.image} alt="" className="w-full h-full object-cover" />;
  const a = BN_ART[b.art] || BN_ART.a7;
  return (
    <div className="w-full h-full relative overflow-hidden flex flex-col justify-center" style={{ background: a.bg, color: a.fg, padding: big ? '6% 7%' : '0 9%' }}>
      <div className="absolute -left-[12%] -bottom-[40%] w-[55%] aspect-square rounded-full" style={{ background: 'rgba(255,255,255,.12)' }} />
      <div className="relative font-semibold opacity-80" style={{ fontSize: big ? 13 : 8 }}>{a.kicker}</div>
      <div className="relative font-display font-bold leading-tight" style={{ fontSize: big ? 26 : 12 }}>{a.line}</div>
    </div>
  );
}

// ---------- Main screen ----------
function BannersScreen({ go }) {
  const { toast, demoState } = useInv();
  const can = useCan();
  const canEdit = can('store.manage');
  const [tab, setTab] = useState(() => localStorage.getItem('vortix-bn-tab') || 'slider');
  useEffect(() => localStorage.setItem('vortix-bn-tab', tab), [tab]);
  const phase = useLoad('bn' + tab);
  const [items, setItems] = useState(BN_SEED);
  const [q, setQ] = useState('');
  const [sf, setSf] = useState('all');
  const [form, setForm] = useState({ open: false, initial: null });
  const [del, setDel] = useState(null);
  const [delBusy, setDelBusy] = useState(false);
  const [busyToggle, setBusyToggle] = useState(null);

  const inTab = items.filter(b => b.placement === tab);
  const rows = inTab.filter(b => (!q || b.title.includes(q)) && (sf === 'all' || bnStatus(b) === sf)).sort((a, b) => a.sort - b.sort);
  const tabCount = k => items.filter(b => b.placement === k).length;
  const stCount = k => inTab.filter(b => bnStatus(b) === k).length;

  const save = f => {
    const isNew = !f.id;
    setItems(xs => isNew ? [...xs, { ...f, id: 'bn' + Date.now() }] : xs.map(x => x.id === f.id ? f : x));
    setForm({ open: false, initial: null });
    if (f.placement !== tab) setTab(f.placement);
    toast(isNew ? 'تمت إضافة البانر بنجاح' : 'تم حفظ التعديلات');
  };
  const toggle = b => {
    setBusyToggle(b.id);
    setTimeout(() => { setItems(xs => xs.map(x => x.id === b.id ? { ...x, active: !x.active } : x)); setBusyToggle(null); toast(b.active ? 'تم تعطيل البانر' : 'تم تفعيل البانر'); }, 450);
  };
  const doDelete = () => {
    setDelBusy(true);
    setTimeout(() => { setItems(xs => xs.filter(x => x.id !== del.id)); setDelBusy(false); setDel(null); toast('تم حذف البانر'); }, 700);
  };
  const openNew = () => setForm({ open: true, initial: null });

  const P = BN_PLACEMENTS[tab];
  const liveInTab = inTab.filter(b => bnStatus(b) === 'live').sort((a, b) => a.sort - b.sort);

  return (
    <section className="space-y-5">
      <PageHead title="إدارة المتجر" sub="إدارة الصور والإعلانات الظاهرة في المتجر ومواعيد عرضها."
        actions={canEdit
          ? <button className="btn btn-dark" onClick={openNew}><Icon n="plus" s={14} w={2.2} /> إضافة بانر</button>
          : <span className="inline-flex items-center gap-1.5 text-[12px] c-muted px-3.5 py-2 rounded-full v-pill-surface"><Icon n="eye" s={13} /> صلاحية مشاهدة فقط</span>} />

      {/* Placement tabs */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3.5 stagger-item stagger-2">
        {Object.entries(BN_PLACEMENTS).map(([k, p]) => {
          const on = tab === k;
          return (
            <button key={k} onClick={() => { setTab(k); setSf('all'); setQ(''); }}
              className={`text-right rounded-[20px] p-4 flex items-center gap-3.5 transition ${on ? 'shadow-xs' : 'hover:opacity-90'}`}
              style={{ background: on ? 'var(--pill-active-bg)' : 'var(--bg-card)', color: on ? 'var(--pill-active-text)' : 'var(--text-primary)', border: '1px solid var(--border-subtle)' }}>
              <span className="w-10 h-10 rounded-full flex items-center justify-center shrink-0" style={{ background: on ? 'var(--accent-orange)' : 'var(--icon-well-bg)', color: on ? '#fff' : 'var(--text-secondary)' }}><Icon n={p.icon} s={17} /></span>
              <span className="flex-1 min-w-0">
                <span className="block text-[14px] font-semibold">{p.label}</span>
                <span className="block text-[11px] truncate" style={{ opacity: .65 }}>{p.hint}</span>
              </span>
              <span className="num font-display text-[22px] font-bold">{phase === 'ready' || !on ? tabCount(k) : '—'}</span>
            </button>
          );
        })}
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-12 gap-5 items-start">
        <Card className="xl:col-span-8" stagger={3}>
          <CardHead title={P.label} meta={phase === 'ready' ? `${rows.length} من ${inTab.length}` : null}
            right={<>
              <div className="seg">
                <button className={sf === 'all' ? 'on' : ''} onClick={() => setSf('all')}>الكل</button>
                {Object.entries(BN_STATUS).map(([k, s]) => <button key={k} className={sf === k ? 'on' : ''} onClick={() => setSf(k)}>{s.label}{phase === 'ready' && <span className="num c-muted mr-1">{stCount(k)}</span>}</button>)}
              </div>
              <SearchPill value={q} onChange={setQ} placeholder="بحث بالعنوان" w="w-32" />
            </>} />

          {!canEdit && phase === 'ready' && <div className="mb-3"><InlineAlert kind="blue" icon="eye" title="وضع المشاهدة">يمكنك استعراض البانرات فقط. الإضافة والتعديل والحذف والتفعيل تتطلب صلاحية إدارة المتجر.</InlineAlert></div>}

          {phase === 'loading' && <BannerSkeleton />}
          {phase === 'error' && <ErrorState msg="تعذّر تحميل البانرات من الخادم." code="GET /admin/banners · 500" />}
          {(phase === 'empty' || (phase === 'ready' && inTab.length === 0)) && (
            <EmptyState icon={P.icon} title={`لا توجد بانرات في «${P.label}»`} sub={P.hint + '. أضف أول صورة لتظهر في المتجر.'}
              action={canEdit && <button className="btn btn-dark btn-sm" onClick={openNew}><Icon n="plus" s={13} /> إضافة أول بانر</button>} />
          )}
          {phase === 'ready' && inTab.length > 0 && rows.length === 0 && <EmptyState icon="filter" title="لا توجد بانرات تطابق البحث أو الفلتر" sub="غيّر كلمة البحث أو اختر حالة أخرى." />}

          {phase === 'ready' && rows.length > 0 && (
            <div className="space-y-2.5">
              {rows.map(b => {
                const st = bnStatus(b); const tgt = bnTarget(b); const L = BN_LINK_TYPES[b.linkType];
                const s = bnDate(b.start), e = bnDate(b.end);
                return (
                  <div key={b.id} className="v-subcard p-3 flex items-center gap-4 fade-in" style={{ opacity: st === 'disabled' ? .72 : 1 }}>
                    <div className="num w-7 h-7 rounded-full flex items-center justify-center text-[12px] font-bold shrink-0" title="ترتيب الظهور" style={{ background: 'var(--bg-card)', color: 'var(--text-primary)' }}>{b.sort}</div>
                    <div className="rounded-xl overflow-hidden shrink-0" style={{ width: tab === 'popup' ? 72 : 168, aspectRatio: P.ratio, boxShadow: '0 0 0 1px var(--border-subtle)' }}>
                      <BannerArt b={b} />
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-semibold text-[13.5px] c-primary truncate">{b.title}</span>
                        <Badge cls={BN_STATUS[st].cls} dot>{BN_STATUS[st].label}</Badge>
                      </div>
                      <div className="flex items-center gap-1.5 mt-1.5 text-[12px] min-w-0">
                        <span className="c-muted shrink-0"><Icon n={L.icon} s={13} /></span>
                        {b.linkType === 'none' ? <span className="c-muted">بدون رابط</span>
                          : <><span className="c-secondary shrink-0">{L.label}:</span><span className={`truncate ${b.linkType === 'external' ? 'mono text-[11.5px] c-orange' : 'c-primary font-medium'}`}>{tgt || '—'}</span></>}
                      </div>
                      <div className="flex items-center gap-2 mt-1.5 text-[11.5px] c-secondary">
                        <Icon n="calendar" s={12} className="c-muted" />
                        {s ? <span className="num">{s.d} <span className="c-muted">{s.t}</span></span> : <span className="c-muted">بدون موعد بداية</span>}
                        <span className="c-muted">←</span>
                        {e ? <span className="num">{e.d} <span className="c-muted">{e.t}</span></span> : <span className="c-muted">بدون موعد نهاية</span>}
                      </div>
                    </div>
                    <div className="flex items-center gap-3 shrink-0">
                      <div className="flex flex-col items-center gap-1">
                        {busyToggle === b.id
                          ? <span className="w-9 h-5 flex items-center justify-center"><span className="w-3.5 h-3.5 rounded-full border-2 border-[var(--accent-orange)] border-t-transparent animate-spin" /></span>
                          : <Toggle on={b.active} disabled={!canEdit} onChange={() => toggle(b)} />}
                        <span className="text-[10px] c-muted">{b.active ? 'مفعّل' : 'معطّل'}</span>
                      </div>
                      {canEdit ? (
                        <div className="flex items-center gap-0.5">
                          <button className="icon-btn" title="تعديل" onClick={() => setForm({ open: true, initial: b })}><Icon n="edit" s={15} /></button>
                          <button className="icon-btn" title="حذف" onClick={() => setDel(b)}><Icon n="trash" s={15} /></button>
                        </div>
                      ) : <span className="icon-btn" title="لا تملك الصلاحية"><Icon n="lock" s={14} /></span>}
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </Card>

        {/* Live preview */}
        <Card className="xl:col-span-4 xl:sticky xl:top-5" stagger={4}>
          <div className="flex items-center justify-between mb-3">
            <h3 className="font-display text-[15px] font-semibold c-primary">معاينة الواجهة</h3>
            <span className="text-[11px] c-muted">الساري الآن · <span className="num">{phase === 'ready' ? liveInTab.length : 0}</span></span>
          </div>
          <StorePreview tab={tab} live={phase === 'ready' ? liveInTab : []} />
          <div className="text-[11px] c-muted mt-3 flex items-start gap-1.5"><Icon n="info" s={12} className="mt-0.5 shrink-0" /> تظهر فقط البانرات المفعّلة داخل فترة عرضها، مرتبة حسب «ترتيب الظهور».</div>
        </Card>
      </div>

      <BannerForm open={form.open} initial={form.initial} defaultPlacement={tab} onClose={() => setForm({ open: false, initial: null })} onSave={save} demoState={demoState} />

      <Modal open={!!del} onClose={() => !delBusy && setDel(null)} title="حذف البانر؟" width="max-w-md"
        footer={<>
          <button className="btn btn-orange" onClick={doDelete} disabled={delBusy}>{delBusy && <span className="w-3.5 h-3.5 rounded-full border-2 border-current border-t-transparent animate-spin" />} حذف نهائي</button>
          <button className="btn btn-soft" onClick={() => setDel(null)} disabled={delBusy}>تراجع</button>
        </>}>
        {del && <>
          <div className="rounded-2xl overflow-hidden mb-3" style={{ aspectRatio: BN_PLACEMENTS[del.placement].ratio === '4 / 5' ? '16 / 7' : BN_PLACEMENTS[del.placement].ratio, boxShadow: '0 0 0 1px var(--border-subtle)' }}><BannerArt b={del} big /></div>
          <div className="font-semibold c-primary text-[14px]">{del.title}</div>
          <div className="text-[12px] c-secondary mt-0.5">{BN_PLACEMENTS[del.placement].label} · ترتيب <span className="num">{del.sort}</span></div>
          <div className="text-[12.5px] c-secondary mt-3">سيختفي البانر من المتجر فورًا ولا يمكن استرجاعه. لإخفائه مؤقتًا استخدم مفتاح التعطيل بدلًا من الحذف.</div>
        </>}
      </Modal>
    </section>
  );
}

function BannerSkeleton() {
  return (
    <div className="space-y-2.5">
      {[0, 1, 2, 3].map(i => (
        <div key={i} className="v-subcard p-3 flex items-center gap-4" style={{ opacity: 1 - i * .15 }}>
          <div className="sk w-7 h-7 !rounded-full" />
          <div className="sk w-[168px] h-[63px] !rounded-xl" />
          <div className="flex-1 space-y-2"><div className="sk h-3.5 w-1/2" /><div className="sk h-3 w-1/3" /><div className="sk h-3 w-2/5" /></div>
          <div className="sk w-9 h-5 !rounded-full" />
        </div>
      ))}
    </div>
  );
}

function StorePreview({ tab, live }) {
  const [i, setI] = useState(0);
  useEffect(() => { setI(0); }, [tab, live.length]);
  useEffect(() => {
    if (tab !== 'slider' || live.length < 2) return;
    const t = setInterval(() => setI(x => (x + 1) % live.length), 2600);
    return () => clearInterval(t);
  }, [tab, live.length]);
  const cur = live[i];
  const frame = (children) => (
    <div className="v-subcard p-2.5" style={{ borderRadius: 18 }}>
      <div className="rounded-[12px] overflow-hidden" style={{ background: 'var(--bg-card)', boxShadow: '0 0 0 1px var(--border-subtle)' }}>
        <div className="flex items-center gap-1.5 px-3 py-2 v-border-b">
          <span className="w-2 h-2 rounded-full" style={{ background: 'var(--badge-gray-bg)' }} /><span className="w-2 h-2 rounded-full" style={{ background: 'var(--badge-gray-bg)' }} /><span className="w-2 h-2 rounded-full" style={{ background: 'var(--badge-gray-bg)' }} />
          <span className="flex-1 mx-2 h-4 rounded-full" style={{ background: 'var(--bg-subcard)' }} />
        </div>
        {children}
      </div>
    </div>
  );
  const none = <div className="rounded-lg flex items-center justify-center text-[11px] c-muted hatched" style={{ aspectRatio: '16 / 6' }}>لا يوجد بانر ساري</div>;
  const blocks = n => <div className="grid grid-cols-4 gap-1.5 p-2.5">{Array.from({ length: n }).map((_, k) => <div key={k} className="aspect-square rounded-md" style={{ background: 'var(--bg-subcard)' }} />)}</div>;

  if (tab === 'slider') return frame(<>
    <div className="p-2.5 pb-0">
      {cur ? <div className="rounded-lg overflow-hidden relative" style={{ aspectRatio: '16 / 6' }}><div key={cur.id} className="absolute inset-0 fade-in"><BannerArt b={cur} /></div></div> : none}
      <div className="flex items-center justify-center gap-1 py-2">
        {live.map((b, k) => <button key={b.id} onClick={() => setI(k)} className="h-1.5 rounded-full transition-all" style={{ width: k === i ? 16 : 6, background: k === i ? 'var(--accent-orange)' : 'var(--badge-gray-bg)' }} />)}
      </div>
    </div>
    {blocks(4)}
  </>);
  if (tab === 'middle') return frame(<>
    {blocks(4)}
    <div className="px-2.5 space-y-1.5">{live.length ? live.map(b => <div key={b.id} className="rounded-lg overflow-hidden" style={{ aspectRatio: '16 / 5' }}><BannerArt b={b} /></div>) : none}</div>
    {blocks(4)}
  </>);
  return frame(
    <div className="relative">
      {blocks(8)}
      <div className="absolute inset-0 flex items-center justify-center" style={{ background: 'rgba(0,0,0,.35)' }}>
        {live[0] ? <div className="w-[46%] rounded-lg overflow-hidden relative shadow-2xl" style={{ aspectRatio: '4 / 5' }}>
          <BannerArt b={live[0]} />
          <span className="absolute top-1.5 left-1.5 w-4 h-4 rounded-full bg-white/90 text-black flex items-center justify-center"><Icon n="x" s={9} w={2.5} /></span>
        </div> : <span className="text-[11px] text-white/80">لا توجد نافذة سارية</span>}
      </div>
    </div>
  );
}

// ---------- Form ----------
function BannerForm({ open, initial, defaultPlacement, onClose, onSave, demoState }) {
  const blank = { title: '', image: null, art: null, placement: defaultPlacement, linkType: 'none', linkId: null, url: '', sort: '', start: '', end: '', active: true };
  const [f, setF] = useState(blank);
  const [err, setErr] = useState({});
  const [upl, setUpl] = useState({ state: 'idle', pct: 0, name: '', msg: '' });
  const [busy, setBusy] = useState(false);
  const [srvErr, setSrvErr] = useState(null);
  const fileRef = useRef();
  const failNext = useRef(false);

  useEffect(() => {
    if (!open) return;
    setF(initial ? { ...initial, sort: String(initial.sort) } : blank);
    setErr({}); setUpl({ state: 'idle', pct: 0, name: '', msg: '' }); setBusy(false); setSrvErr(null);
  }, [open]);
  const set = (k, v) => { setF(p => ({ ...p, [k]: v })); if (err[k]) setErr(e => ({ ...e, [k]: null })); };

  const startUpload = file => {
    if (!file) return;
    if (!/^image\/(png|jpe?g|webp)$/.test(file.type)) { setUpl({ state: 'error', pct: 0, name: file.name, msg: 'صيغة غير مدعومة. استخدم PNG أو JPG أو WEBP.' }); return; }
    if (file.size > 5 * 1024 * 1024) { setUpl({ state: 'error', pct: 0, name: file.name, msg: 'حجم الصورة أكبر من 5 ميجابايت.' }); return; }
    const willFail = demoState === 'error' || failNext.current; failNext.current = false;
    const reader = new FileReader();
    reader.onload = () => {
      let pct = 0;
      setUpl({ state: 'uploading', pct: 0, name: file.name, msg: '' });
      const t = setInterval(() => {
        pct += 9 + Math.random() * 14;
        if (willFail && pct > 62) { clearInterval(t); setUpl({ state: 'error', pct: 62, name: file.name, msg: 'انقطع الاتصال أثناء الرفع. (POST /admin/uploads · 502)' }); return; }
        if (pct >= 100) { clearInterval(t); setUpl({ state: 'done', pct: 100, name: file.name, msg: '' }); set('image', reader.result); return; }
        setUpl(u => ({ ...u, pct }));
      }, 160);
    };
    reader.readAsDataURL(file);
  };

  const hasImage = f.image || f.art;
  const validate = () => {
    const e = {};
    if (!f.title.trim()) e.title = 'العنوان مطلوب';
    if (!hasImage) e.image = 'ارفع صورة للبانر';
    if (f.linkType === 'product' && !f.linkId) e.linkId = 'اختر المنتج';
    if (f.linkType === 'category' && !f.linkId) e.linkId = 'اختر القسم';
    if (f.linkType === 'brand' && !f.linkId) e.linkId = 'اختر البراند';
    if (f.linkType === 'external') {
      if (!f.url.trim()) e.url = 'أدخل الرابط';
      else if (!/^https?:\/\/\S+\.\S+/.test(f.url.trim())) e.url = 'الرابط يجب أن يبدأ بـ https:// ويكون صالحًا';
    }
    if (f.sort === '' ) e.sort = 'أدخل ترتيب الظهور';
    if (f.start && f.end && new Date(f.end) <= new Date(f.start)) e.end = 'نهاية العرض يجب أن تكون بعد البداية';
    return e;
  };
  const submit = () => {
    if (busy || upl.state === 'uploading') return;
    const e = validate(); setErr(e);
    if (Object.keys(e).length) return;
    setBusy(true); setSrvErr(null);
    setTimeout(() => {
      if (demoState === 'error' && !initial) { setBusy(false); setSrvErr('تعذّر حفظ البانر. حاول مرة أخرى. (422 · image_id غير صالح)'); return; }
      onSave({ ...f, sort: parseInt(f.sort, 10), linkId: ['none', 'external'].includes(f.linkType) ? null : f.linkId, url: f.linkType === 'external' ? f.url.trim() : '' });
    }, 900);
  };

  const P = BN_PLACEMENTS[f.placement];
  const targetField = () => {
    const opts = f.linkType === 'product' ? INV_PRODUCTS.map(p => ({ value: p.id, label: p.name }))
      : f.linkType === 'category' ? BN_CATEGORIES.map(c => ({ value: c.id, label: c.name }))
      : f.linkType === 'brand' ? BN_BRANDS.map(c => ({ value: c.id, label: c.name })) : null;
    if (f.linkType === 'none') return null;
    if (f.linkType === 'external') return (
      <Field label="الرابط الخارجي" req error={err.url}>
        <div className="relative">
          <input className={`inp mono text-left pl-9 ${err.url ? 'err' : ''}`} dir="ltr" value={f.url} onChange={e => set('url', e.target.value)} placeholder="https://" />
          <span className="absolute left-3 top-1/2 -translate-y-1/2 c-muted"><Icon n="external" s={14} /></span>
        </div>
      </Field>
    );
    return (
      <Field label={'اختر ال' + BN_LINK_TYPES[f.linkType].label} req error={err.linkId}>
        <Sel value={f.linkId} onChange={v => set('linkId', v)} placeholder="اختر…" options={opts} className={err.linkId ? 'err' : ''} />
      </Field>
    );
  };

  return (
    <Modal open={open} onClose={() => !busy && onClose()} width="max-w-3xl" title={initial ? 'تعديل بانر' : 'إضافة بانر'} sub={initial ? initial.title : `سيُضاف إلى «${P.label}»`}
      footer={<>
        <button className="btn btn-dark min-w-[110px]" onClick={submit} disabled={busy || upl.state === 'uploading'}>
          {busy ? <><span className="w-3.5 h-3.5 rounded-full border-2 border-current border-t-transparent animate-spin" /> جارٍ الحفظ…</> : 'حفظ'}
        </button>
        <button className="btn btn-soft" onClick={onClose} disabled={busy}>إلغاء</button>
        <span className="flex-1" />
        {upl.state === 'uploading' && <span className="text-[11.5px] c-muted">انتظر اكتمال رفع الصورة</span>}
      </>}>
      <div className="space-y-5">
        {srvErr && <InlineAlert kind="red" title="لم يتم الحفظ">{srvErr}</InlineAlert>}

        <Field label="العنوان" req error={err.title} hint="للاستخدام الداخلي ولوصف الصورة">
          <input className={`inp ${err.title ? 'err' : ''}`} value={f.title} onChange={e => set('title', e.target.value)} placeholder="مثال: مجموعة الشتاء — خصم ٤٠٪" />
        </Field>

        {/* Image */}
        <div>
          <span className="field-label">الصورة <span className="c-orange">*</span><span className="c-muted font-normal">· صورة واحدة · PNG / JPG / WEBP حتى 5MB</span></span>
          <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={e => { startUpload(e.target.files[0]); e.target.value = ''; }} />
          {upl.state === 'uploading' ? (
            <div className="v-subcard p-5 flex items-center gap-4" style={{ aspectRatio: P.ratio === '4 / 5' ? '16 / 6' : P.ratio, maxHeight: 220 }}>
              <div className="flex-1">
                <div className="flex items-center justify-between text-[12.5px] mb-2"><span className="c-primary font-medium truncate">{upl.name}</span><span className="num c-secondary">{Math.round(upl.pct)}%</span></div>
                <div className="h-2 rounded-full hatched overflow-hidden"><div className="h-full rounded-full bar-hatched-orange transition-all" style={{ width: upl.pct + '%' }} /></div>
                <div className="text-[11px] c-muted mt-2">جارٍ رفع الصورة…</div>
              </div>
            </div>
          ) : hasImage ? (
            <div className="relative rounded-2xl overflow-hidden group" style={{ aspectRatio: P.ratio === '4 / 5' ? '16 / 7' : P.ratio, boxShadow: '0 0 0 1px var(--border-subtle)' }}>
              {f.placement === 'popup'
                ? <div className="w-full h-full flex items-center justify-center hatched"><div className="h-[88%] rounded-lg overflow-hidden" style={{ aspectRatio: '4 / 5' }}><BannerArt b={f} big /></div></div>
                : <BannerArt b={f} big />}
              <div className="absolute bottom-3 left-3 flex items-center gap-2">
                <button className="btn btn-sm" style={{ background: 'rgba(255,255,255,.95)', color: '#18151c' }} onClick={() => fileRef.current.click()}><Icon n="refresh" s={13} /> استبدال الصورة</button>
              </div>
              {upl.state === 'done' && <span className="absolute top-3 right-3 badge b-green"><Icon n="check" s={11} w={2.5} /> تم الرفع</span>}
            </div>
          ) : (
            <button type="button" onClick={() => fileRef.current.click()}
              onDragOver={e => e.preventDefault()} onDrop={e => { e.preventDefault(); startUpload(e.dataTransfer.files[0]); }}
              className="w-full rounded-2xl flex flex-col items-center justify-center gap-2 transition hover:opacity-90"
              style={{ aspectRatio: P.ratio === '4 / 5' ? '16 / 6' : P.ratio, maxHeight: 220, border: `1.5px dashed ${err.image || upl.state === 'error' ? 'var(--badge-red-text)' : 'var(--border-strong)'}`, background: 'var(--bg-subcard)' }}>
              <span className="w-11 h-11 rounded-full flex items-center justify-center" style={{ background: 'var(--bg-card)', color: 'var(--accent-orange)' }}><Icon n="upload" s={18} /></span>
              <span className="text-[13px] font-semibold c-primary">اسحب الصورة هنا أو اضغط للاختيار</span>
              <span className="text-[11px] c-muted">المقاس المقترح لـ{P.label}: {f.placement === 'slider' ? '1920×720' : f.placement === 'middle' ? '1920×600' : '800×1000'}</span>
            </button>
          )}
          {upl.state === 'error' && (
            <div className="mt-2"><InlineAlert kind="red" title="فشل رفع الصورة">
              <span>{upl.msg}</span>
              <button className="font-semibold c-orange mr-2 hover:underline" onClick={() => fileRef.current.click()}>إعادة المحاولة</button>
            </InlineAlert></div>
          )}
          {err.image && upl.state !== 'error' && <span className="block text-[11px] mt-1" style={{ color: 'var(--badge-red-text)' }}>{err.image}</span>}
          {!hasImage && upl.state === 'idle' && <button className="text-[11px] c-muted mt-1.5 hover:c-primary underline decoration-dotted" onClick={() => { failNext.current = true; fileRef.current.click(); }}>معاينة حالة فشل الرفع</button>}
        </div>

        {/* Placement */}
        <div>
          <span className="field-label">مكان العرض <span className="c-orange">*</span></span>
          <div className="grid grid-cols-3 gap-2.5">
            {Object.entries(BN_PLACEMENTS).map(([k, p]) => (
              <button key={k} type="button" onClick={() => set('placement', k)}
                className={`rounded-2xl p-3 flex items-center gap-2.5 text-right border transition ${f.placement === k ? 'border-[var(--accent-orange)]' : 'border-transparent'}`}
                style={{ background: f.placement === k ? 'var(--accent-orange-light)' : 'var(--bg-subcard)' }}>
                <span className={`w-8 h-8 rounded-full flex items-center justify-center shrink-0 ${f.placement === k ? 'bg-[var(--accent-orange)] text-white' : 'v-icon-well'}`}><Icon n={p.icon} s={15} /></span>
                <span className="text-[12.5px] font-semibold c-primary">{p.label}</span>
              </button>
            ))}
          </div>
        </div>

        {/* Link */}
        <div className="v-subcard p-4 space-y-3.5">
          <div>
            <span className="field-label">نوع الرابط</span>
            <div className="seg flex-wrap !bg-[var(--bg-card)]">
              {Object.entries(BN_LINK_TYPES).map(([k, l]) => (
                <button key={k} type="button" className={f.linkType === k ? 'on !bg-[var(--pill-active-bg)] !text-[var(--pill-active-text)]' : ''} onClick={() => { set('linkType', k); set('linkId', null); setErr(e => ({ ...e, linkId: null, url: null })); }}>
                  <span className="inline-flex items-center gap-1.5"><Icon n={l.icon} s={12} /> {l.label}</span>
                </button>
              ))}
            </div>
          </div>
          {f.linkType === 'none'
            ? <div className="text-[11.5px] c-muted">الصورة ستظهر بدون إمكانية الضغط عليها.</div>
            : <div className="fade-in" key={f.linkType}>{targetField()}</div>}
        </div>

        {/* Schedule */}
        <div className="grid grid-cols-1 sm:grid-cols-[120px_1fr_1fr] gap-4">
          <Field label="ترتيب الظهور" req error={err.sort} hint="الأصغر يظهر أولًا">
            <NumInput integer value={f.sort} onChange={v => set('sort', v)} placeholder="1" err={err.sort} />
          </Field>
          <Field label="بداية العرض" hint={f.start ? null : 'فارغ = يبدأ فورًا'}>
            <div className="flex items-center gap-1.5">
              <input type="datetime-local" className="inp num" value={f.start} onChange={e => { set('start', e.target.value); setErr(x => ({ ...x, end: null })); }} />
              {f.start && <button className="icon-btn shrink-0" title="مسح" onClick={() => set('start', '')}><Icon n="x" s={12} /></button>}
            </div>
          </Field>
          <Field label="نهاية العرض" error={err.end} hint={f.end ? null : 'فارغ = بدون نهاية'}>
            <div className="flex items-center gap-1.5">
              <input type="datetime-local" className={`inp num ${err.end ? 'err' : ''}`} value={f.end} onChange={e => set('end', e.target.value)} />
              {f.end && <button className="icon-btn shrink-0" title="مسح" onClick={() => set('end', '')}><Icon n="x" s={12} /></button>}
            </div>
          </Field>
        </div>

        <div className="v-subcard p-3.5 flex items-center justify-between">
          <div>
            <div className="text-[13px] font-semibold c-primary flex items-center gap-2">التفعيل: {f.active ? 'مفعّل' : 'معطّل'}
              <Badge cls={BN_STATUS[bnStatus({ ...f })].cls} dot>{BN_STATUS[bnStatus({ ...f })].label}</Badge>
            </div>
            <div className="text-[11px] c-secondary mt-0.5">الحالة تُحسب تلقائيًا من التفعيل وتواريخ العرض.</div>
          </div>
          <Toggle on={f.active} onChange={v => set('active', v)} />
        </div>
      </div>
    </Modal>
  );
}

Object.assign(window, { BannersScreen });
