// Shared UI primitives — one implementation for every screen.
const { useState, useEffect, useRef, useMemo, useCallback, createContext, useContext } = React;

const IC = {
  home: <><path d="M3 10.5L12 3l9 7.5V20a1 1 0 01-1 1h-5v-6h-6v6H4a1 1 0 01-1-1z"/></>,
  orders: <><rect x="5" y="3.5" width="14" height="17" rx="2"/><path d="M9 8.5h6M9 12.5h6M9 16.5h3.5"/></>,
  box: <><path d="M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3z"/><path d="M12 12l8-4.5M12 12v9M12 12L4 7.5"/></>,
  tree: <><rect x="9" y="3" width="6" height="5" rx="1.5"/><path d="M12 8v4M5.5 12h13M5.5 12v4M18.5 12v4"/><rect x="2.5" y="16" width="6" height="5" rx="1.5"/><rect x="15.5" y="16" width="6" height="5" rx="1.5"/></>,
  stock: <><path d="M3 9l9-5 9 5v11H3z"/><path d="M7 20v-7h10v7M7 16.5h10"/></>,
  factory: <><path d="M3 21V10l6 3.5V10l6 3.5V5h6v16z"/><path d="M7 17h2M12 17h2M17 17h1"/></>,
  users: <><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.3-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 010 6.8M18 14.8c1.9.7 3.2 2.4 3.5 5.2"/></>,
  dealer: <><path d="M4 9l1.5-5h13L20 9"/><path d="M4 9v11h16V9"/><path d="M4 9c0 1.7 1.3 3 3 3s3-1.3 3-3c0 1.7 1.3 3 3 3s3-1.3 3-3c0 1.7 1.3 3 3 3"/><path d="M10 20v-5h4v5"/></>,
  wallet: <><rect x="3" y="6" width="18" height="14" rx="2.5"/><path d="M3 10h18M16 15h2"/><path d="M6 6l9-3 1.5 3"/></>,
  chart: <><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></>,
  shield: <><path d="M12 3l7.5 3v5.5c0 4.6-3.2 8.3-7.5 9.5-4.3-1.2-7.5-4.9-7.5-9.5V6L12 3z"/><path d="M9 12l2 2 4-4"/></>,
  gear: <><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/></>,
  spark: <><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></>,
  pos: <><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></>,
  swap: <><path d="M7 4L3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7"/></>,
  sliders: <><path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/></>,
  history: <><path d="M3 12a9 9 0 109-9 9.7 9.7 0 00-6.7 2.8L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/></>,
  clipboard: <><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M9 11l2 2 4-4M9 17h6"/></>,
  truck: <><rect x="1.5" y="5" width="13" height="11" rx="1"/><path d="M14.5 9h4l3 3.5V16h-7"/><circle cx="6" cy="18" r="2"/><circle cx="17.5" cy="18" r="2"/></>,
  lock: <><rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7a4 4 0 018 0v3.5"/></>,
  alert: <><path d="M12 3.5L2.5 20h19z"/><path d="M12 10v4.5M12 17.5h.01"/></>,
  search: <><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></>,
  bell: <path d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 00-5-5.9V4a1 1 0 10-2 0v1.1A6 6 0 006 11v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>,
  sun: <><circle cx="12" cy="12" r="4"/><path d="M12 3v2m0 14v2M3 12h2m14 0h2m-2.6-6.4l-1.4 1.4M7 17l-1.4 1.4m0-12.8L7 7m10 10l1.4 1.4"/></>,
  moon: <path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/>,
  logout: <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4m7 14l5-5-5-5m5 5H9"/>,
  plus: <path d="M12 5v14M5 12h14"/>,
  minus: <path d="M5 12h14"/>,
  x: <path d="M6 6l12 12M18 6L6 18"/>,
  check: <path d="M5 12.5l4.5 4.5L19 7.5"/>,
  chevDown: <path d="M6 9l6 6 6-6"/>,
  chevLeft: <path d="M15 18l-6-6 6-6"/>,
  chevRight: <path d="M9 18l6-6-6-6"/>,
  arrowRight: <path d="M5 12h14M13 6l6 6-6 6"/>,
  arrowLeft: <path d="M19 12H5M11 6l-6 6 6 6"/>,
  arrowUpLeft: <path d="M17 17L7 7M7 15V7h8"/>,
  edit: <><path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/></>,
  trash: <><path d="M4 7h16M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13M9 7V4h6v3"/></>,
  dots: <><circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/></>,
  drag: <><circle cx="9" cy="6" r="1.2"/><circle cx="15" cy="6" r="1.2"/><circle cx="9" cy="12" r="1.2"/><circle cx="15" cy="12" r="1.2"/><circle cx="9" cy="18" r="1.2"/><circle cx="15" cy="18" r="1.2"/></>,
  eye: <><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></>,
  star: <path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>,
  filter: <path d="M4 5h16l-6 7.5V19l-4 2v-8.5z"/>,
  download: <path d="M12 4v11M7 10l5 5 5-5M4 20h16"/>,
  refresh: <><path d="M20 11a8 8 0 00-14.3-4.9L4 8"/><path d="M4 3v5h5M4 13a8 8 0 0014.3 4.9L20 16"/><path d="M20 21v-5h-5"/></>,
  image: <><rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/></>,
  folder: <path d="M3 7a2 2 0 012-2h4l2 2.5h8a2 2 0 012 2V18a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>,
  tag: <><path d="M3 12V4a1 1 0 011-1h8l9 9-9 9-9-9z"/><circle cx="8" cy="8" r="1.5"/></>,
  globe: <><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3z"/></>,
  mic: <><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0014 0M12 18v3"/></>,
  barcode: <path d="M4 5v14M7 5v14M10.5 5v14M14 5v14M16.5 5v14M20 5v14"/>,
  info: <><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M12 11v5"/></>,
  panel: <><rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M15 4v16"/></>,
  phone: <path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z"/>,
  link: <><path d="M10 14a4.5 4.5 0 006.4 0l3-3a4.5 4.5 0 00-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 00-6.4 0l-3 3a4.5 4.5 0 006.4 6.4l1-1"/></>,
  copy: <><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 00-2-2H6a2 2 0 00-2 2v8a2 2 0 002 2h2"/></>,
  printer: <><path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 14h12v7H6z"/></>,
  calendar: <><rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M3 10h18M8 3v4M16 3v4"/></>,
  store: <><path d="M3 9l2-5h14l2 5"/><path d="M4 9v11h16V9"/><path d="M9 20v-6h6v6"/></>,
  warehouse: <><path d="M2 20V8l10-5 10 5v12"/><path d="M6 20v-8h12v8M6 16h12"/></>,
};
function Icon({ n, s = 18, w = 1.8, className, style }) {
  return <svg width={s} height={s} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={w} strokeLinecap="round" strokeLinejoin="round" className={className} style={style} aria-hidden="true">{IC[n]}</svg>;
}

const cx = (...a) => a.filter(Boolean).join(' ');
const nf = new Intl.NumberFormat('ar-EG', { maximumFractionDigits: 0 });
const fmt = v => nf.format(v);
const money = v => nf.format(v);

// ---------- Status dictionaries (single source) ----------
const STATUS = {
  product: { published: ['منشور', 'b-ok'], draft: ['مسودة', 'b-neutral'], archived: ['مؤرشف', 'b-warn'] },
  order: { new: ['جديد', 'b-acc'], processing: ['قيد التجهيز', 'b-info'], shipped: ['تم الشحن', 'b-warn'], completed: ['مكتمل', 'b-ok'], cancelled: ['ملغي', 'b-bad'] },
  pay: { paid: ['مدفوع', 't-ok'], cod: ['عند الاستلام', 't-2'], credit: ['آجل', 't-warn'], partial: ['مدفوع جزئيًا', 't-warn'], refunded: ['مسترد', 't-bad'] },
  transfer: { draft: ['مسودة', 'b-neutral'], approved: ['معتمد', 'b-info'], shipped: ['مشحون', 'b-warn'], received: ['مستلم', 'b-ok'], cancelled: ['ملغي', 'b-bad'] },
  dealer: { active: ['نشط', 'b-ok'], pending: ['بانتظار الاعتماد', 'b-warn'], suspended: ['موقوف', 'b-bad'] },
  category: { active: ['نشط', 'b-ok'], inactive: ['غير نشط', 'b-neutral'] },
};
const CHANNEL = { store: 'المتجر الإلكتروني', wholesale: 'الجملة', pos: 'نقطة البيع', retail: 'تجزئة', both: 'تجزئة وجملة' };

function Status({ kind, s }) {
  const [label, cls] = STATUS[kind][s] || [s, 'b-neutral'];
  return <span className={cx('badge', cls)}><span className="dot" />{label}</span>;
}

// ---------- Layout ----------
function PageHead({ title, sub, actions, back }) {
  return <div className="page-head">
    <div className="grow">
      {back && <button className="back" onClick={back.onClick}><Icon n="chevRight" s={14} />{back.label}</button>}
      <h1>{title}</h1>
      {sub && <p>{sub}</p>}
    </div>
    {actions && <div className="page-actions">{actions}</div>}
  </div>;
}
function Card({ title, meta, actions, children, pad, className, style }) {
  return <section className={cx('card', className)} style={style}>
    {title && <header className="card-head"><div className="row gap-2"><h3>{title}</h3>{meta && <span className="meta">{meta}</span>}</div>{actions && <div className="row gap-2">{actions}</div>}</header>}
    {pad ? <div className="card-pad">{children}</div> : children}
  </section>;
}
function KPI({ label, value, unit, icon, foot, hero, delta, onClick, active, warn }) {
  const Tag = onClick ? 'button' : 'div';
  return <Tag className={cx('card kpi', hero && 'hero', warn && 'warn-state')} onClick={onClick} aria-pressed={onClick ? !!active : undefined}
    style={active ? { borderColor: 'var(--acc)', boxShadow: '0 0 0 3px var(--acc-soft)' } : null}>
    <div className="kpi-label"><span>{label}</span>{icon && <span className="kpi-icon"><Icon n={icon} s={16} /></span>}</div>
    <div className="kpi-value"><bdi>{value}</bdi>{unit && <small>{unit}</small>}</div>
    {(foot || delta) && <div className="kpi-foot">{delta && <span className={cx('delta', delta > 0 ? 'up' : 'down')}>{delta > 0 ? '↑' : '↓'} {fmt(Math.abs(delta))}٪</span>}{foot}</div>}
  </Tag>;
}
function Empty({ icon = 'box', title, body, action }) {
  return <div className="empty"><div className="empty-ic"><Icon n={icon} s={24} /></div><h4>{title}</h4>{body && <p>{body}</p>}{action && <div style={{ marginTop: 8 }}>{action}</div>}</div>;
}
function Seg({ value, onChange, options }) {
  return <div className="seg" role="group">{options.map(o => <button key={o.value} type="button" aria-pressed={value === o.value} onClick={() => onChange(o.value)}>{o.label}</button>)}</div>;
}
function Toggle({ on, onChange, label }) {
  return <button type="button" role="switch" aria-checked={on} aria-label={label} className="tgl" onClick={() => onChange(!on)}><span /></button>;
}
function Check({ on, onChange, label }) {
  return <button type="button" role="checkbox" aria-checked={on} aria-label={label} className="chk" onClick={e => { e.stopPropagation(); onChange(on === true ? false : true); }}>{on && <Icon n={on === 'mixed' ? 'minus' : 'check'} s={12} w={3} />}</button>;
}
function Field({ label, req, hint, err, children, style }) {
  return <label className="field" style={style}><span className="label">{label}{req && <span className="req">*</span>}</span>{children}{err ? <span className="err">{err}</span> : hint && <span className="hint">{hint}</span>}</label>;
}
function Search({ value, onChange, placeholder, width }) {
  return <div className="input-group" style={width ? { width } : null}><Icon n="search" s={16} /><input className="input" type="search" value={value} onChange={e => onChange(e.target.value)} placeholder={placeholder} /></div>;
}
function Pager({ page, pages, total, from, to, onPage }) {
  const nums = [];
  for (let i = 1; i <= pages; i++) if (i === 1 || i === pages || Math.abs(i - page) <= 1) nums.push(i); else if (nums.at(-1) !== '…') nums.push('…');
  return <div className="tbl-foot">
    <span>عرض <b className="num t-1">{fmt(from)}–{fmt(to)}</b> من <b className="num t-1">{fmt(total)}</b></span>
    <div className="pager">
      <button disabled={page <= 1} onClick={() => onPage(page - 1)} aria-label="السابق"><Icon n="chevRight" s={14} /></button>
      {nums.map((n, i) => n === '…' ? <span key={i} className="t-3 fs-12">…</span> : <button key={i} aria-current={n === page ? 'page' : undefined} onClick={() => onPage(n)}>{fmt(n)}</button>)}
      <button disabled={page >= pages} onClick={() => onPage(page + 1)} aria-label="التالي"><Icon n="chevLeft" s={14} /></button>
    </div>
  </div>;
}

function useOutside(ref, cb) {
  useEffect(() => { const h = e => { if (ref.current && !ref.current.contains(e.target)) cb(); }; document.addEventListener('mousedown', h); return () => document.removeEventListener('mousedown', h); }, [cb]);
}
function Menu({ trigger, items, align = 'end', up = false }) {
  const [open, setOpen] = useState(false);
  const ref = useRef();
  useOutside(ref, () => setOpen(false));
  return <div ref={ref} style={{ position: 'relative', display: 'inline-flex' }}>
    {trigger({ open, toggle: () => setOpen(o => !o) })}
    {open && <div className="menu" style={{ [up ? 'bottom' : 'top']: 'calc(100% + 6px)', [align === 'end' ? 'left' : 'right']: 0 }}>
      {items.map((it, i) => it === '-' ? <hr key={i} /> : <button key={i} className={it.danger ? 'danger' : ''} onClick={() => { setOpen(false); it.onClick?.(); }}>{it.icon && <Icon n={it.icon} s={16} />}{it.label}</button>)}
    </div>}
  </div>;
}
function FilterSelect({ label, value, options, onChange }) {
  const cur = options.find(o => o.value === value);
  return <Menu trigger={({ toggle }) => <button className={cx('chip', value && 'on')} onClick={toggle}>{value ? <>{label}: {cur?.label}</> : <><Icon n="plus" s={13} />{label}</>}</button>}
    items={[...options.map(o => ({ label: o.label, icon: o.value === value ? 'check' : undefined, onClick: () => onChange(o.value) })), ...(value ? ['-', { label: 'إزالة الفلتر', icon: 'x', onClick: () => onChange('') }] : [])]} />;
}

function useEsc(cb, on) { useEffect(() => { if (!on) return; const h = e => e.key === 'Escape' && cb(); window.addEventListener('keydown', h); return () => window.removeEventListener('keydown', h); }, [on, cb]); }
function Modal({ open, onClose, title, children, footer, width }) {
  useEsc(onClose, open);
  if (!open) return null;
  return ReactDOM.createPortal(<div className={document.querySelector('.app')?.classList.contains('theme-dark') ? 'theme-dark' : ''} dir="rtl">
    <div className="overlay" onClick={onClose} />
    <div className="modal" role="dialog" aria-modal="true" aria-label={title} style={width ? { width: `min(${width}px, calc(100vw - 32px))` } : null}>
      <div className="pop">
        <div className="row between" style={{ padding: '18px 20px 0' }}><h3 className="fs-16">{title}</h3><button className="icon-btn sm" onClick={onClose} aria-label="إغلاق"><Icon n="x" s={16} /></button></div>
        <div style={{ padding: '14px 20px 20px' }}>{children}</div>
        {footer && <div className="row gap-2" style={{ padding: '14px 20px', borderTop: '1px solid var(--line)', justifyContent: 'flex-end' }}>{footer}</div>}
      </div>
    </div>
  </div>, document.body);
}
function Drawer({ open, onClose, title, sub, children, footer }) {
  useEsc(onClose, open);
  if (!open) return null;
  return ReactDOM.createPortal(<div className={document.querySelector('.app')?.classList.contains('theme-dark') ? 'theme-dark' : ''} dir="rtl">
    <div className="overlay" onClick={onClose} />
    <aside className="drawer" role="dialog" aria-modal="true" aria-label={title}>
      <div className="drawer-head"><div className="grow"><h3 className="fs-16">{title}</h3>{sub && <div className="fs-12 t-3">{sub}</div>}</div><button className="icon-btn" onClick={onClose} aria-label="إغلاق"><Icon n="x" s={18} /></button></div>
      <div className="drawer-body">{children}</div>
      {footer && <div className="drawer-foot">{footer}</div>}
    </aside>
  </div>, document.body);
}

const ToastCtx = createContext(() => {});
const useToast = () => useContext(ToastCtx);
function ToastHost({ children }) {
  const [t, setT] = useState(null);
  const timer = useRef();
  const show = useCallback((text, tone = 'ok') => { setT({ text, tone, k: Date.now() }); clearTimeout(timer.current); timer.current = setTimeout(() => setT(null), 2600); }, []);
  return <ToastCtx.Provider value={show}>{children}{t && <div key={t.k} className="toast" role="status"><span style={{ width: 20, height: 20, borderRadius: 99, display: 'grid', placeItems: 'center', background: t.tone === 'bad' ? 'var(--bad)' : 'var(--acc)', color: '#fff' }}><Icon n={t.tone === 'bad' ? 'x' : 'check'} s={12} w={3} /></span>{t.text}</div>}</ToastCtx.Provider>;
}

function Stepper({ steps, current }) {
  return <div className="stepper">{steps.map((s, i) => <React.Fragment key={s}>
    {i > 0 && <div className={cx('step-line', i <= current && 'done')} />}
    <div className={cx('step', i < current && 'done', i === current && 'current')}><span className="s-dot">{i < current ? <Icon n="check" s={13} w={2.6} /> : fmt(i + 1)}</span><span className="hide-mobile">{s}</span></div>
  </React.Fragment>)}</div>;
}

Object.assign(window, { IC, Icon, cx, fmt, money, STATUS, CHANNEL, Status, PageHead, Card, KPI, Empty, Seg, Toggle, Check, Field, Search, Pager, Menu, FilterSelect, Modal, Drawer, ToastHost, useToast, Stepper, useOutside, useEsc });