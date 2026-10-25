// ============ Shared Vortix UI primitives (RTL) ============
const { useState, useEffect, useRef, useMemo, useContext, createContext, Fragment } = React;

const InvCtx = createContext(null);
const useInv = () => useContext(InvCtx);
const useCan = () => { const { role } = useInv(); return p => INV_ROLES[role].perms.includes(p); };

// Simulated fetch lifecycle: loading → (live | empty | error) per demo tweak
function useLoad(dep) {
  const { demoState, reloadKey } = useInv();
  const [phase, setPhase] = useState('loading');
  useEffect(() => {
    setPhase('loading');
    if (demoState === 'loading') return;
    const t = setTimeout(() => setPhase(demoState === 'live' ? 'ready' : demoState), 550);
    return () => clearTimeout(t);
  }, [demoState, reloadKey, dep]);
  return phase;
}

// ---------- Icons (stroke, 1.9 like Vortix) ----------
const IP = {
  plus: 'M12 5v14M5 12h14',
  edit: 'M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4',
  trash: 'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3',
  search: 'M11 4a7 7 0 100 14 7 7 0 000-14zM20 20l-3.5-3.5',
  filter: 'M4 6h16M7 12h10M10 18h4',
  x: 'M6 6l12 12M18 6L6 18',
  check: 'M5 12l5 5L20 7',
  chevL: 'M15 18l-6-6 6-6',
  chevR: 'M9 6l6 6-6 6',
  chevD: 'M19 9l-7 7-7-7',
  pin: 'M12 21s-7-6.2-7-11.5A7 7 0 0119 9.5C19 14.8 12 21 12 21zM12 7a2.5 2.5 0 100 5 2.5 2.5 0 000-5z',
  store: 'M4 10h16v10H4zM3 10l2-6h14l2 6M9 20v-5h6v5',
  warehouse: 'M3 21V9l9-5 9 5v12M7 21v-8h10v8M7 17h10',
  box: 'M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3zM12 12l8-4.5M12 12v9M12 12L4 7.5',
  layers: 'M12 3l9 5-9 5-9-5 9-5zM3 13l9 5 9-5M3 17.5l9 5 9-5',
  sliders: 'M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0M14 4v4M8 10v4M16 16v4',
  history: 'M3 12a9 9 0 109-9 9 9 0 00-6.4 2.6L3 8M3 3v5h5M12 7v5l3 2',
  swap: 'M7 4L3 8l4 4M3 8h14M17 20l4-4-4-4M21 16H7',
  clipboard: 'M9 4h6v3H9zM7 5H5v16h14V5h-2M9 12l2 2 4-4',
  factory: 'M3 21V10l6 4V10l6 4V6h6v15H3zM7 17h2M12 17h2M17 17h2',
  alert: 'M12 9v4M12 17h.01M10.3 3.9L2 18a2 2 0 001.7 3h16.6a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z',
  refresh: 'M4 4v6h6M20 20v-6h-6M5.6 15a7 7 0 0011.8 2.4L20 14M4 10l2.6-3.4A7 7 0 0118.4 9',
  lock: 'M6 11h12v10H6zM8 11V7a4 4 0 018 0v4',
  eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12zM12 9a3 3 0 100 6 3 3 0 000-6z',
  more: 'M12 6h.01M12 12h.01M12 18h.01',
  truck: 'M3 6h11v10H3zM14 10h4l3 3v3h-7M7 19a2 2 0 100-4 2 2 0 000 4zM17 19a2 2 0 100-4 2 2 0 000 4z',
  inbox: 'M3 13l3-8h12l3 8v6H3v-6zM3 13h5l1 3h6l1-3h5',
  barcode: 'M4 6v12M7 6v12M10 6v12M14 6v12M17 6v12M20 6v12',
  power: 'M12 3v8M6.3 7a8 8 0 1011.4 0',
  arrowL: 'M19 12H5M11 6l-6 6 6 6',
  sun: 'M12 8a4 4 0 100 8 4 4 0 000-8zM12 3v2m0 14v2M3 12h2m14 0h2m-2.6-6.4l-1.4 1.4M7 17l-1.4 1.4m0-12.8L7 7m10 10l1.4 1.4',
  moon: 'M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z',
  bell: 'M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 00-5-5.9V4a1 1 0 10-2 0v1.1A6 6 0 006 11v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9',
  help: 'M12 3a9 9 0 100 18 9 9 0 000-18zM9.5 9a2.5 2.5 0 015 0c0 1.5-2.5 2-2.5 3.5m0 3h.01',
  logout: 'M15 21h4a2 2 0 002-2V5a2 2 0 00-2-2h-4M8 17l-5-5 5-5M3 12h12',
  info: 'M12 3a9 9 0 100 18 9 9 0 000-18zM12 8h.01M12 11v5',
  image: 'M4 5h16v14H4zM4 16l5-5 4 4 3-3 4 4M15.5 9.5h.01',
  upload: 'M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 001 1h14a1 1 0 001-1v-3',
  link: 'M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1M14 10a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1-1',
  calendar: 'M4 5h16v16H4zM16 3v4M8 3v4M4 10h16',
  tag: 'M3 12V4h8l10 10-8 8L3 12zM7.5 7.5h.01',
  grid: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
  external: 'M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h5',
  slash: 'M5 19L19 5M12 3a9 9 0 100 18 9 9 0 000-18z',
  slides: 'M7 6h10v12H7zM3 8v8M21 8v8',
  popup: 'M4 4h16v16H4zM8 8h8v8H8z',
  mid: 'M4 4h16v4H4zM4 10h16v5H4zM4 17h16v3H4z',
  storefront: 'M3 9l1.5-5h15L21 9M3 9v11h18V9M3 9a3 3 0 006 0 3 3 0 006 0 3 3 0 006 0M10 20v-5h4v5',
};
function Icon({ n, s = 16, w = 1.9, className = '', style }) {
  return (
    <svg width={s} height={s} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={w} strokeLinecap="round" strokeLinejoin="round" className={className} style={style}>
      <path d={IP[n]} />
    </svg>
  );
}

// ---------- Layout ----------
function PageHead({ title, sub, actions, back }) {
  return (
    <div className="flex flex-wrap items-end justify-between gap-4 pt-1 stagger-item stagger-1">
      <div>
        {back && (
          <button onClick={back.onClick} className="flex items-center gap-1.5 text-[12px] font-medium c-secondary hover:c-primary mb-2">
            <Icon n="chevR" s={14} /> {back.label}
          </button>
        )}
        <h1 className="font-display text-[34px] sm:text-[38px] font-medium tracking-[-0.02em] leading-tight c-primary">{title}</h1>
        {sub && <p className="text-[13.5px] mt-1 c-secondary">{sub}</p>}
      </div>
      {actions && <div className="flex items-center gap-2.5 flex-wrap">{actions}</div>}
    </div>
  );
}

function Card({ children, className = '', pad = 'p-5', stagger = 2 }) {
  return <div className={`v-card ${pad} stagger-item stagger-${stagger} ${className}`}>{children}</div>;
}

function CardHead({ title, meta, right }) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div className="flex items-center gap-2.5">
        <h3 className="font-display text-[17px] font-semibold c-primary">{title}</h3>
        {meta && <span className="text-[11.5px] c-muted">| &nbsp;{meta}</span>}
      </div>
      {right && <div className="flex items-center gap-2.5 flex-wrap">{right}</div>}
    </div>
  );
}

function Badge({ cls = 'b-gray', dot, children }) {
  return <span className={`badge ${cls}`}>{dot && <span className="dot" />}{children}</span>;
}
const ActiveBadge = ({ on }) => <Badge cls={on ? 'b-green' : 'b-gray'} dot>{on ? 'نشط' : 'غير نشط'}</Badge>;

function SearchPill({ value, onChange, placeholder = 'بحث', w = 'w-40' }) {
  return (
    <div className="v-subcard px-3 py-1.5 rounded-full flex items-center gap-2">
      <Icon n="search" s={14} className="c-muted" />
      <input value={value} onChange={e => onChange(e.target.value)} placeholder={placeholder} className={`bg-transparent text-[12px] outline-none ${w} c-primary`} />
    </div>
  );
}

function Field({ label, req, hint, error, children, className = '' }) {
  return (
    <label className={`block ${className}`}>
      <span className="field-label">{label}{req && <span className="c-orange">*</span>}</span>
      {children}
      {error ? <span className="block text-[11px] mt-1" style={{ color: 'var(--badge-red-text)' }}>{error}</span>
        : hint ? <span className="block text-[11px] mt-1 c-muted">{hint}</span> : null}
    </label>
  );
}

function Sel({ value, onChange, options, placeholder, className = '', small, disabled }) {
  return (
    <select disabled={disabled} value={value ?? ''} onChange={e => onChange(e.target.value || null)} className={`inp ${small ? 'inp-sm' : ''} ${className}`}>
      {placeholder !== undefined && <option value="">{placeholder}</option>}
      {options.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
    </select>
  );
}
const branchOpts = (filter) => INV_BRANCHES.filter(b => !filter || filter(b)).map(b => ({ value: b.id, label: `${b.name} · ${b.type === 'warehouse' ? 'مخزن' : 'فرع'}` }));
const locOpts = (branchId) => INV_LOCATIONS.filter(l => l.branchId === branchId && l.active).map(l => ({ value: l.id, label: `${l.name} (${l.code})` }));

function Toggle({ on, onChange, disabled }) {
  return <button type="button" disabled={disabled} onClick={() => onChange(!on)} className={`vtoggle ${on ? 'on' : ''} ${disabled ? 'opacity-40' : ''}`} />;
}

// Permission-gated button: hidden or locked with tooltip
function Can({ perm, children, mode = 'hide' }) {
  const can = useCan();
  if (can(perm)) return children;
  if (mode === 'hide') return null;
  return (
    <span title="لا تملك الصلاحية" className="inline-flex items-center gap-1.5 text-[11.5px] c-muted px-3 py-1.5 rounded-full v-subcard">
      <Icon n="lock" s={12} /> {mode}
    </span>
  );
}

// ---------- States ----------
function SkeletonRows({ cols = 6, rows = 6 }) {
  return (
    <div className="space-y-0">
      <div className="v-subcard h-9 rounded-xl mb-1" />
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="flex items-center gap-4 px-3 py-3.5 v-border-b">
          {Array.from({ length: cols }).map((_, j) => (
            <div key={j} className="sk h-3" style={{ flex: j === 0 ? 2 : 1, opacity: 1 - i * 0.1 }} />
          ))}
        </div>
      ))}
    </div>
  );
}

function EmptyState({ icon = 'inbox', title, sub, action }) {
  return (
    <div className="v-subcard py-14 px-6 flex flex-col items-center text-center fade-in">
      <div className="w-14 h-14 rounded-full flex items-center justify-center mb-4 relative" style={{ background: 'var(--bg-card)' }}>
        <div className="absolute inset-0 rounded-full hatched opacity-60" />
        <Icon n={icon} s={22} className="c-secondary relative" />
      </div>
      <div className="font-display text-[15px] font-semibold c-primary">{title}</div>
      {sub && <div className="text-[12.5px] c-secondary mt-1 max-w-sm" style={{ textWrap: 'pretty' }}>{sub}</div>}
      {action && <div className="mt-5">{action}</div>}
    </div>
  );
}

function ErrorState({ msg = 'تعذّر الاتصال بالخادم. تحقّق من الاتصال وحاول مرة أخرى.', code = 'HTTP 503' }) {
  const { reload } = useInv();
  return (
    <div className="py-12 px-6 flex flex-col items-center text-center rounded-2xl fade-in" style={{ background: 'var(--badge-red-bg)' }}>
      <div className="w-12 h-12 rounded-full flex items-center justify-center mb-3" style={{ background: 'var(--bg-card)', color: 'var(--badge-red-text)' }}>
        <Icon n="alert" s={20} />
      </div>
      <div className="font-display text-[15px] font-semibold" style={{ color: 'var(--badge-red-text)' }}>حدث خطأ أثناء تحميل البيانات</div>
      <div className="text-[12.5px] c-secondary mt-1 max-w-md">{msg}</div>
      <div className="mono text-[10.5px] c-muted mt-2">{code}</div>
      <button onClick={reload} className="btn btn-dark btn-sm mt-4"><Icon n="refresh" s={13} /> إعادة المحاولة</button>
    </div>
  );
}

// Wrap list content with phase handling
function Phase({ phase, cols, rows, empty, children }) {
  if (phase === 'loading') return <SkeletonRows cols={cols} rows={rows} />;
  if (phase === 'error') return <ErrorState />;
  if (phase === 'empty') return empty;
  return children;
}

function InlineAlert({ kind = 'red', icon = 'alert', title, children, onClose }) {
  const map = { red: ['--badge-red-bg', '--badge-red-text'], amber: ['--badge-amber-bg', '--badge-amber-text'], blue: ['--badge-blue-bg', '--badge-blue-text'], green: ['--badge-green-bg', '--badge-green-text'] }[kind];
  return (
    <div className="rounded-2xl px-4 py-3 flex items-start gap-3 fade-in" style={{ background: `var(${map[0]})` }}>
      <span style={{ color: `var(${map[1]})` }} className="mt-0.5"><Icon n={icon} s={16} /></span>
      <div className="flex-1 text-[12.5px]">
        {title && <div className="font-semibold" style={{ color: `var(${map[1]})` }}>{title}</div>}
        <div className="c-primary opacity-80 mt-0.5">{children}</div>
      </div>
      {onClose && <button onClick={onClose} className="c-secondary"><Icon n="x" s={14} /></button>}
    </div>
  );
}

// ---------- Modal ----------
function Modal({ open, onClose, title, sub, children, footer, width = 'max-w-lg' }) {
  useEffect(() => {
    if (!open) return;
    const h = e => e.key === 'Escape' && onClose && onClose();
    window.addEventListener('keydown', h); return () => window.removeEventListener('keydown', h);
  }, [open]);
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 fade-in" style={{ background: 'rgba(0,0,0,.4)', backdropFilter: 'blur(4px)' }} onMouseDown={e => e.target === e.currentTarget && onClose && onClose()}>
      <div className={`v-card ${width} w-full shadow-2xl modal-in flex flex-col max-h-[92vh]`}>
        <div className="flex items-start justify-between gap-4 p-6 pb-4">
          <div>
            <h3 className="font-display text-[18px] font-semibold c-primary">{title}</h3>
            {sub && <p className="text-[12px] c-secondary mt-0.5">{sub}</p>}
          </div>
          <button onClick={onClose} className="w-8 h-8 rounded-full v-subcard flex items-center justify-center c-secondary shrink-0"><Icon n="x" s={14} /></button>
        </div>
        <div className="px-6 pb-2 overflow-y-auto flex-1">{children}</div>
        {footer && <div className="p-6 pt-4 flex items-center justify-start gap-2.5 flex-row-reverse">{footer}</div>}
      </div>
    </div>
  );
}

function ConfirmDialog({ open, onClose, onConfirm, title, body, confirmLabel = 'تأكيد', danger, busy, error }) {
  return (
    <Modal open={open} onClose={onClose} title={title} width="max-w-md"
      footer={<>
        <button className={`btn ${danger ? 'btn-orange' : 'btn-dark'}`} onClick={onConfirm} disabled={busy}>
          {busy ? <span className="w-3.5 h-3.5 rounded-full border-2 border-current border-t-transparent animate-spin" /> : null}
          {confirmLabel}
        </button>
        <button className="btn btn-soft" onClick={onClose}>تراجع</button>
      </>}>
      <div className="text-[13px] c-secondary leading-relaxed" style={{ textWrap: 'pretty' }}>{body}</div>
      {error && <div className="mt-4"><InlineAlert kind="red" title="رفض الخادم العملية">{error}</InlineAlert></div>}
    </Modal>
  );
}

// ---------- Toast ----------
function Toast({ toast }) {
  return (
    <div className={`fixed bottom-6 left-6 z-[60] pointer-events-none transition-all duration-300 ${toast ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2'}`}>
      <div className="px-4 py-2.5 rounded-full shadow-lg text-xs font-medium flex items-center gap-2" style={{ background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' }}>
        <span className="w-2 h-2 rounded-full bg-[#e0491f]" />
        <span>{toast?.text}</span>
      </div>
    </div>
  );
}

// ---------- Variant picker: product → variant/SKU (never stops at product) ----------
function VariantPicker({ value, onChange, placeholder = 'ابحث بالاسم أو SKU أو الباركود', small, exclude = [], allowClear }) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const ref = useRef();
  useEffect(() => {
    const h = e => ref.current && !ref.current.contains(e.target) && setOpen(false);
    document.addEventListener('mousedown', h); return () => document.removeEventListener('mousedown', h);
  }, []);
  const v = value ? invVariant(value) : null;
  const ql = q.trim().toLowerCase();
  const groups = INV_PRODUCTS.map(p => ({
    ...p,
    variants: p.variants.filter(x => !exclude.includes(x.id) && (!ql || p.name.includes(q) || x.sku.toLowerCase().includes(ql) || x.barcode.includes(ql) || x.attrs.includes(q))),
  })).filter(p => p.variants.length);

  return (
    <div ref={ref} className="relative">
      <button type="button" onClick={() => setOpen(o => !o)} className={`inp ${small ? 'inp-sm' : ''} flex items-center justify-between gap-2 text-right`}>
        {v ? (
          <span className="flex items-center gap-2 min-w-0">
            <span className="truncate c-primary">{v.productName}</span>
            <span className="text-[11px] c-muted shrink-0">{v.attrs}</span>
            <span className="mono text-[10.5px] px-1.5 py-0.5 rounded-md shrink-0" style={{ background: 'var(--icon-well-bg)' }}>{v.sku}</span>
          </span>
        ) : <span className="c-muted truncate">{placeholder}</span>}
        <span className="flex items-center gap-1 shrink-0">
          {allowClear && v && <span onClick={e => { e.stopPropagation(); onChange(null); }} className="c-muted hover:c-primary"><Icon n="x" s={12} /></span>}
          <Icon n="chevD" s={13} className="c-muted" />
        </span>
      </button>
      {open && (
        <div className="absolute z-40 mt-1.5 w-full min-w-[320px] v-card shadow-2xl p-2 modal-in" style={{ borderRadius: 16 }}>
          <div className="v-subcard px-3 py-2 rounded-xl flex items-center gap-2 mb-1.5">
            <Icon n="search" s={14} className="c-muted" />
            <input autoFocus value={q} onChange={e => setQ(e.target.value)} placeholder="اسم المنتج، SKU، باركود…" className="bg-transparent text-[12.5px] outline-none flex-1 c-primary" />
            <Icon n="barcode" s={14} className="c-muted" />
          </div>
          <div className="max-h-[260px] overflow-y-auto">
            {groups.length === 0 && <div className="text-center text-[12px] c-muted py-6">لا توجد نتائج مطابقة</div>}
            {groups.map(p => (
              <div key={p.id} className="mb-1">
                <div className="text-[11px] font-semibold c-secondary px-2.5 pt-2 pb-1 flex items-center gap-1.5"><Icon n="box" s={12} /> {p.name} <span className="c-muted font-normal">· {p.variants.length} متغير</span></div>
                {p.variants.map(x => (
                  <button type="button" key={x.id} onClick={() => { onChange(x.id); setOpen(false); setQ(''); }}
                    className={`w-full flex items-center justify-between gap-3 px-2.5 py-2 rounded-xl text-right hover:bg-[var(--bg-subcard)] ${value === x.id ? 'bg-[var(--bg-subcard)]' : ''}`}>
                    <span className="text-[12.5px] c-primary pr-4">{x.attrs}</span>
                    <span className="flex items-center gap-2">
                      <span className="mono text-[10.5px] c-muted">{x.barcode}</span>
                      <span className="mono text-[11px] font-semibold c-primary">{x.sku}</span>
                      {value === x.id && <Icon n="check" s={13} className="c-orange" />}
                    </span>
                  </button>
                ))}
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

function VariantCell({ id, showBarcode }) {
  const v = invVariant(id);
  return (
    <div className="min-w-0">
      <div className="font-medium c-primary truncate">{v.productName}</div>
      <div className="text-[11px] c-muted mt-0.5 flex items-center gap-1.5">{v.attrs}{showBarcode && <><span>·</span><span className="mono">{v.barcode}</span></>}</div>
    </div>
  );
}
const Sku = ({ id, children }) => <span className="mono text-[11.5px] font-semibold px-2 py-0.5 rounded-md c-primary" style={{ background: 'var(--icon-well-bg)' }}>{children || invVariant(id).sku}</span>;

function NumInput({ value, onChange, decimals = 4, integer, small, placeholder = '0', className = '', err }) {
  return (
    <input inputMode="decimal" value={value ?? ''} placeholder={placeholder}
      onChange={e => {
        let s = e.target.value.replace(/[^\d.]/g, '');
        if (integer) s = s.replace(/\./g, '');
        const parts = s.split('.');
        if (parts.length > 2) s = parts[0] + '.' + parts.slice(1).join('');
        if (parts[1] && parts[1].length > decimals) s = parts[0] + '.' + parts[1].slice(0, decimals);
        onChange(s);
      }}
      className={`inp ${small ? 'inp-sm' : ''} num text-left ${err ? 'err' : ''} ${className}`} />
  );
}

function KPI({ label, value, icon, hot, sub }) {
  return (
    <div className={`${hot ? 'v-orange-card' : 'v-subcard'} rounded-[18px] p-4 flex flex-col justify-between min-h-[112px]`}>
      <div className="flex items-center justify-between">
        <span className={`text-[12.5px] font-medium ${hot ? 'text-white/90' : 'c-secondary'}`}>{label}</span>
        <span className={`w-7 h-7 rounded-full flex items-center justify-center ${hot ? 'bg-white/15 text-white' : 'v-icon-well'}`}><Icon n={icon} s={14} w={2} /></span>
      </div>
      <div className={`font-display text-[28px] font-bold tracking-tight mt-2 num text-right ${hot ? '' : 'c-primary'}`}>{value}</div>
      {sub && <div className={`text-[11px] ${hot ? 'text-white/80' : 'c-muted'}`}>{sub}</div>}
    </div>
  );
}

Object.assign(window, {
  InvCtx, useInv, useCan, useLoad, Icon, PageHead, Card, CardHead, Badge, ActiveBadge, SearchPill, Field, Sel, branchOpts, locOpts,
  Toggle, Can, SkeletonRows, EmptyState, ErrorState, Phase, InlineAlert, Modal, ConfirmDialog, Toast, VariantPicker, VariantCell, Sku, NumInput, KPI,
});
