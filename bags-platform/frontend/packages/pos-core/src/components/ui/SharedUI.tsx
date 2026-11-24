import React, {
  useState,
  useEffect,
  useRef,
  useCallback,
  createContext,
  useContext,
  ReactNode,
} from 'react';
import ReactDOM from 'react-dom';
import { Icon } from './Icons';
import type { PosProduct } from '../../types/pos.types';

export const cx = (...a: (string | boolean | undefined | null)[]): string =>
  a.filter(Boolean).join(' ');

export const nf = new Intl.NumberFormat('ar-EG', { maximumFractionDigits: 0 });
export const fmt = (v: number | string): string => nf.format(Number(v) || 0);

export const nf2 = new Intl.NumberFormat('ar-EG', {
  minimumFractionDigits: 0,
  maximumFractionDigits: 2,
});
export const egp = (v: number): string => nf2.format(Math.round(v * 100) / 100);

export const normalizeMoneyDigits = (s: string | number): string => String(s)
    .replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
    .replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
    .replace(/[،٫]/g, '.');

export const toAsciiNum = (s: string | number, maxDecimals = 2): string => {
  const cleaned = normalizeMoneyDigits(s).replace(/[^\d.]/g, '');
  const [intPart, ...rest] = cleaned.split('.');
  if (rest.length === 0) return intPart;
  const dec = rest.join('').slice(0, maxDecimals);
  return maxDecimals > 0 ? `${intPart}.${dec}` : intPart;
};

// Status dictionary
export const STATUS: Record<string, Record<string, [string, string]>> = {
  product: {
    published: ['منشور', 'b-ok'],
    draft: ['مسودة', 'b-neutral'],
    archived: ['مؤرشف', 'b-warn'],
  },
  order: {
    new: ['جديد', 'b-acc'],
    processing: ['قيد التجهيز', 'b-info'],
    shipped: ['تم الشحن', 'b-warn'],
    completed: ['مكتمل', 'b-ok'],
    cancelled: ['ملغي', 'b-bad'],
  },
  pay: {
    paid: ['مدفوع', 't-ok'],
    cod: ['عند الاستلام', 't-2'],
    credit: ['آجل', 't-warn'],
    partial: ['مدفوع جزئيًا', 't-warn'],
    refunded: ['مسترد', 't-bad'],
  },
  transfer: {
    draft: ['مسودة', 'b-neutral'],
    approved: ['معتمد', 'b-info'],
    shipped: ['مشحون', 'b-warn'],
    received: ['مستلم', 'b-ok'],
    cancelled: ['ملغي', 'b-bad'],
  },
  dealer: {
    active: ['نشط', 'b-ok'],
    pending: ['بانتظار الاعتماد', 'b-warn'],
    suspended: ['موقوف', 'b-bad'],
  },
  category: {
    active: ['نشط', 'b-ok'],
    inactive: ['غير نشط', 'b-neutral'],
  },
};

export function Status({ kind, s }: { kind: string; s: string }) {
  const [label, cls] = (STATUS[kind] && STATUS[kind][s]) || [s, 'b-neutral'];
  return (
    <span className={cx('badge', cls)}>
      <span className="dot" />
      {label}
    </span>
  );
}

export function PImg({
  p,
  size,
  children,
}: {
  p?: Partial<Pick<PosProduct, 'cat' | 'h' | 'imageUrl'>> | null;
  size?: 'xs' | 'sm' | 'md';
  children?: ReactNode;
}) {
  if (!p) return null;
  const icon =
    p.cat === 'hand' || p.cat === 'cross' || p.cat === 'back'
      ? 'bag'
      : p.cat === 'wallet'
      ? 'wallet'
      : p.cat === 'travel'
      ? 'truck'
      : p.cat === 'acc'
      ? 'tag'
      : 'bag';

  return (
    <div
      className={cx('pimg', size)}
      style={{ '--h': p.h ?? 240 } as React.CSSProperties}
    >
      <Icon
        n={icon}
        s={size === 'xs' ? 16 : size === 'sm' ? 20 : 34}
        w={1.4}
        style={{ position: 'relative', zIndex: 1 }}
      />
      {p.imageUrl && <img src={p.imageUrl} alt="" loading="lazy" onError={(event) => { event.currentTarget.hidden = true; }} />}
      {children}
    </div>
  );
}

export function Kbd({ children }: { children: ReactNode }) {
  return <span className="kbd">{children}</span>;
}

export function MoneyInput({
  id,
  name,
  value,
  onChange,
  autoFocus,
  size,
  placeholder = '0',
  onEnter,
  disabled,
  readOnly,
  required,
  normalize = toAsciiNum,
}: {
  id?: string;
  name?: string;
  value: string;
  onChange: (v: string) => void;
  autoFocus?: boolean;
  size?: string;
  placeholder?: string;
  onEnter?: () => void;
  disabled?: boolean;
  readOnly?: boolean;
  required?: boolean;
  normalize?: (value: string) => string;
}) {
  const ref = useRef<HTMLInputElement>(null);
  useEffect(() => {
    if (autoFocus && !disabled && !readOnly) {
      const timer = setTimeout(() => ref.current?.focus(), 60);
      return () => clearTimeout(timer);
    }
  }, [autoFocus, disabled, readOnly]);

  return (
    <label className={cx('money-in', size, (disabled || readOnly) && 'is-disabled')} data-money-input>
      <span className="cur">ج.م</span>
      <input
        ref={ref}
        id={id}
        name={name}
        inputMode="decimal"
        value={value}
        placeholder={placeholder}
        disabled={disabled}
        readOnly={readOnly}
        required={required}
        onChange={(e) => !disabled && !readOnly && onChange(normalize(e.target.value))}
        onKeyDown={(e) => {
          if (e.key === 'Enter' && onEnter) onEnter();
        }}
      />
    </label>
  );
}

export function PageHead({
  title,
  sub,
  actions,
  back,
}: {
  title: string;
  sub?: string;
  actions?: ReactNode;
  back?: { label: string; onClick: () => void };
}) {
  return (
    <div className="page-head">
      <div className="grow">
        {back && (
          <button className="back" onClick={back.onClick}>
            <Icon n="chevRight" s={14} />
            {back.label}
          </button>
        )}
        <h1>{title}</h1>
        {sub && <p>{sub}</p>}
      </div>
      {actions && <div className="page-actions">{actions}</div>}
    </div>
  );
}

export function Card({
  title,
  meta,
  actions,
  children,
  pad,
  className,
  style,
}: {
  title?: string;
  meta?: ReactNode;
  actions?: ReactNode;
  children?: ReactNode;
  pad?: boolean;
  className?: string;
  style?: React.CSSProperties;
}) {
  return (
    <section className={cx('card', className)} style={style}>
      {title && (
        <header className="card-head">
          <div className="row gap-2">
            <h3>{title}</h3>
            {meta && <span className="meta">{meta}</span>}
          </div>
          {actions && <div className="row gap-2">{actions}</div>}
        </header>
      )}
      {pad ? <div className="card-pad">{children}</div> : children}
    </section>
  );
}

export function KPI({
  label,
  value,
  unit,
  icon,
  foot,
  hero,
  delta,
  onClick,
  active,
  warn,
}: {
  label: string;
  value: string;
  unit?: string;
  icon?: string;
  foot?: ReactNode;
  hero?: boolean;
  delta?: number;
  onClick?: () => void;
  active?: boolean;
  warn?: boolean;
}) {
  const Tag = onClick ? 'button' : 'div';
  return (
    <Tag
      className={cx('card kpi', hero && 'hero', warn && 'warn-state')}
      onClick={onClick}
      aria-pressed={onClick ? !!active : undefined}
      style={
        active
          ? { borderColor: 'var(--acc)', boxShadow: '0 0 0 3px var(--acc-soft)' }
          : undefined
      }
    >
      <div className="kpi-label">
        <span>{label}</span>
        {icon && (
          <span className="kpi-icon">
            <Icon n={icon} s={16} />
          </span>
        )}
      </div>
      <div className="kpi-value">
        <bdi>{value}</bdi>
        {unit && <small>{unit}</small>}
      </div>
      {(foot || delta != null) && (
        <div className="kpi-foot">
          {delta != null && (
            <span className={cx('delta', delta > 0 ? 'up' : 'down')}>
              {delta > 0 ? '↑' : '↓'} {fmt(Math.abs(delta))}٪
            </span>
          )}
          {foot}
        </div>
      )}
    </Tag>
  );
}

export function Empty({
  icon = 'box',
  title,
  body,
  action,
  style,
}: {
  icon?: string;
  title: string;
  body?: string;
  action?: ReactNode;
  style?: React.CSSProperties;
}) {
  return (
    <div className="empty" style={style}>
      <div className="empty-ic">
        <Icon n={icon} s={24} />
      </div>
      <h4>{title}</h4>
      {body && <p>{body}</p>}
      {action && <div style={{ marginTop: 8 }}>{action}</div>}
    </div>
  );
}

export function Seg<T extends string>({
  value,
  onChange,
  options,
}: {
  value: T;
  onChange: (v: T) => void;
  options: { value: T; label: string }[];
}) {
  return (
    <div className="seg" role="group">
      {options.map((o) => (
        <button
          key={o.value}
          type="button"
          aria-pressed={value === o.value}
          onClick={() => onChange(o.value)}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}

export function Toggle({
  on,
  onChange,
  label,
}: {
  on: boolean;
  onChange: (v: boolean) => void;
  label?: string;
}) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={on}
      aria-label={label}
      className="tgl"
      onClick={() => onChange(!on)}
    >
      <span />
    </button>
  );
}

export function Check({
  on,
  onChange,
  label,
}: {
  on: boolean | 'mixed';
  onChange: (v: boolean) => void;
  label?: string;
}) {
  return (
    <button
      type="button"
      role="checkbox"
      aria-checked={on === 'mixed' ? 'mixed' : on}
      aria-label={label}
      className="chk"
      onClick={(e) => {
        e.stopPropagation();
        onChange(on !== true);
      }}
    >
      {on && <Icon n={on === 'mixed' ? 'minus' : 'check'} s={12} w={3} />}
    </button>
  );
}

export function Field({
  label,
  req,
  hint,
  err,
  children,
  style,
}: {
  label: ReactNode;
  req?: boolean;
  hint?: ReactNode;
  err?: ReactNode;
  children: ReactNode;
  style?: React.CSSProperties;
}) {
  return (
    <label className="field" style={style}>
      <span className="label">
        {label}
        {req && <span className="req">*</span>}
      </span>
      {children}
      {err ? <span className="err">{err}</span> : hint && <span className="hint">{hint}</span>}
    </label>
  );
}

export function Search({
  value,
  onChange,
  placeholder,
  width,
}: {
  value: string;
  onChange: (v: string) => void;
  placeholder?: string;
  width?: number | string;
}) {
  return (
    <div className="input-group" style={width ? { width } : undefined}>
      <Icon n="search" s={16} />
      <input
        className="input"
        type="search"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
      />
    </div>
  );
}

export function Pager({
  page,
  pages,
  total,
  from,
  to,
  onPage,
}: {
  page: number;
  pages: number;
  total: number;
  from: number;
  to: number;
  onPage: (p: number) => void;
}) {
  const nums: (number | '…')[] = [];
  for (let i = 1; i <= pages; i++) {
    if (i === 1 || i === pages || Math.abs(i - page) <= 1) {
      nums.push(i);
    } else if (nums[nums.length - 1] !== '…') {
      nums.push('…');
    }
  }

  return (
    <div className="tbl-foot">
      <span>
        عرض <b className="num t-1">{fmt(from)}–{fmt(to)}</b> من{' '}
        <b className="num t-1">{fmt(total)}</b>
      </span>
      <div className="pager">
        <button disabled={page <= 1} onClick={() => onPage(page - 1)} aria-label="السابق">
          <Icon n="chevRight" s={14} />
        </button>
        {nums.map((n, i) =>
          n === '…' ? (
            <span key={i} className="t-3 fs-12">
              …
            </span>
          ) : (
            <button
              key={i}
              aria-current={n === page ? 'page' : undefined}
              onClick={() => onPage(n)}
            >
              {fmt(n)}
            </button>
          )
        )}
        <button disabled={page >= pages} onClick={() => onPage(page + 1)} aria-label="التالي">
          <Icon n="chevLeft" s={14} />
        </button>
      </div>
    </div>
  );
}

export function useOutside(ref: React.RefObject<HTMLElement | null>, cb: () => void) {
  useEffect(() => {
    const h = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) cb();
    };
    document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, [ref, cb]);
}

export function useEsc(cb: () => void, on = true) {
  useEffect(() => {
    if (!on) return;
    const h = (e: KeyboardEvent) => {
      if (e.key === 'Escape') cb();
    };
    window.addEventListener('keydown', h);
    return () => window.removeEventListener('keydown', h);
  }, [cb, on]);
}

export function Menu({
  trigger,
  items,
  align = 'end',
  up = false,
}: {
  trigger: (opts: { open: boolean; toggle: () => void }) => ReactNode;
  items: ({ label: string; icon?: string; danger?: boolean; onClick?: () => void } | '-')[];
  align?: 'start' | 'end';
  up?: boolean;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  useOutside(ref, () => setOpen(false));

  return (
    <div ref={ref} style={{ position: 'relative', display: 'inline-flex' }}>
      {trigger({ open, toggle: () => setOpen((o) => !o) })}
      {open && (
        <div
          className="menu"
          style={{
            [up ? 'bottom' : 'top']: 'calc(100% + 6px)',
            [align === 'end' ? 'left' : 'right']: 0,
          }}
        >
          {items.map((it, i) =>
            it === '-' ? (
              <hr key={i} />
            ) : (
              <button
                key={i}
                className={it.danger ? 'danger' : ''}
                onClick={() => {
                  setOpen(false);
                  it.onClick?.();
                }}
              >
                {it.icon && <Icon n={it.icon} s={16} />}
                {it.label}
              </button>
            )
          )}
        </div>
      )}
    </div>
  );
}

export function FilterSelect({
  label,
  value,
  options,
  onChange,
}: {
  label: string;
  value: string;
  options: { value: string; label: string }[];
  onChange: (v: string) => void;
}) {
  const cur = options.find((o) => o.value === value);
  return (
    <Menu
      trigger={({ toggle }) => (
        <button className={cx('chip', value && 'on')} onClick={toggle}>
          {value ? (
            <>
              {label}: {cur?.label}
            </>
          ) : (
            <>
              <Icon n="plus" s={13} />
              {label}
            </>
          )}
        </button>
      )}
      items={[
        ...options.map((o) => ({
          label: o.label,
          icon: o.value === value ? 'check' : undefined,
          onClick: () => onChange(o.value),
        })),
        ...(value
          ? (['-', { label: 'إزالة الفلتر', icon: 'x', onClick: () => onChange('') }] as const)
          : []),
      ]}
    />
  );
}

export function Modal({
  open,
  onClose,
  title,
  children,
  footer,
  width,
  className,
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  children: ReactNode;
  footer?: ReactNode;
  width?: number;
  className?: string;
}) {
  useEsc(onClose, open);
  if (!open) return null;

  return ReactDOM.createPortal(
    <div
      className={document.querySelector('.app')?.classList.contains('theme-dark') ? 'theme-dark' : ''}
      dir="rtl"
    >
      <div className="pos-overlay" onClick={onClose} />
      <div
        className={cx('pos-modal', className)}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        style={width ? { width: `min(${width}px, calc(100vw - 32px))` } : undefined}
      >
        <div className="pop">
          <div className="row between" style={{ padding: '18px 20px 0' }}>
            <h3 className="fs-16">{title}</h3>
            <button className="icon-btn sm" onClick={onClose} aria-label="إغلاق">
              <Icon n="x" s={16} />
            </button>
          </div>
          <div style={{ padding: '14px 20px 20px' }}>{children}</div>
          {footer && (
            <div
              className="row gap-2"
              style={{
                padding: '14px 20px',
                borderTop: '1px solid var(--line)',
                justifyContent: 'flex-end',
              }}
            >
              {footer}
            </div>
          )}
        </div>
      </div>
    </div>,
    document.body
  );
}

export function Drawer({
  open,
  onClose,
  title,
  sub,
  children,
  footer,
  className,
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  sub?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  className?: string;
}) {
  useEsc(onClose, open);
  const asideRef = useRef<HTMLElement>(null);
  const dragRef = useRef<{
    active: boolean;
    startY: number;
    lastY: number;
    lastTime: number;
    velocityY: number;
    offsetY: number;
  }>({ active: false, startY: 0, lastY: 0, lastTime: 0, velocityY: 0, offsetY: 0 });

  useEffect(() => {
    if (!open && asideRef.current) {
      asideRef.current.style.transform = '';
      asideRef.current.style.transition = '';
    }
  }, [open]);

  if (!open) return null;

  const isMobileSheet = () =>
    typeof window !== 'undefined' && window.matchMedia('(max-width: 760px)').matches;

  const onHeaderPointerDown = (e: React.PointerEvent<HTMLDivElement>) => {
    if (!isMobileSheet()) return;
    if ((e.target as HTMLElement).closest('button, a, input')) return;
    const el = asideRef.current;
    if (!el) return;
    el.setPointerCapture?.(e.pointerId);
    el.style.transition = 'none';
    const now = performance.now();
    dragRef.current = {
      active: true,
      startY: e.clientY,
      lastY: e.clientY,
      lastTime: now,
      velocityY: 0,
      offsetY: 0,
    };
  };

  const onPointerMove = (e: React.PointerEvent<HTMLElement>) => {
    const d = dragRef.current;
    if (!d.active || !asideRef.current) return;
    const now = performance.now();
    const dt = Math.max(1, now - d.lastTime);
    const dy = e.clientY - d.startY;
    d.velocityY = (e.clientY - d.lastY) / dt;
    d.lastY = e.clientY;
    d.lastTime = now;
    // Rubber-band upward drag; 1:1 downward drag
    const offset = dy < 0 ? (dy * 120 * 0.35) / (120 + 0.35 * Math.abs(dy)) : dy;
    d.offsetY = offset;
    asideRef.current.style.transform = `translate3d(0, ${offset}px, 0)`;
  };

  const onPointerUp = (e: React.PointerEvent<HTMLElement>) => {
    const d = dragRef.current;
    if (!d.active || !asideRef.current) return;
    d.active = false;
    const el = asideRef.current;
    try {
      el.releasePointerCapture?.(e.pointerId);
    } catch {
      // ignore if not captured
    }
    const shouldClose = d.offsetY > 90 || (d.velocityY > 0.45 && d.offsetY > 24);
    el.style.transition = 'transform 260ms cubic-bezier(.2, 1, .3, 1)';
    if (shouldClose) {
      el.style.transform = 'translate3d(0, 100%, 0)';
      window.setTimeout(onClose, 200);
    } else {
      el.style.transform = 'translate3d(0, 0, 0)';
      window.setTimeout(() => {
        if (asideRef.current) {
          asideRef.current.style.transform = '';
          asideRef.current.style.transition = '';
        }
      }, 260);
    }
  };

  return ReactDOM.createPortal(
    <div
      className={document.querySelector('.app')?.classList.contains('theme-dark') ? 'theme-dark' : ''}
      dir="rtl"
    >
      <div className="pos-overlay" onClick={onClose} />
      <aside
        ref={asideRef}
        className={cx('drawer', className)}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        onPointerMove={onPointerMove}
        onPointerUp={onPointerUp}
        onPointerCancel={onPointerUp}
      >
        <div className="drawer-grab-area" onPointerDown={onHeaderPointerDown}>
          <div className="drawer-grabber" aria-hidden="true" />
          <div className="drawer-head">
            <div className="grow" style={{ minWidth: 0 }}>
              <h3 className="fs-16">{title}</h3>
              {sub && <div className="fs-12 t-3">{sub}</div>}
            </div>
            <button className="icon-btn" onClick={onClose} aria-label="إغلاق">
              <Icon n="x" s={18} />
            </button>
          </div>
        </div>
        <div className="drawer-body">{children}</div>
        {footer && <div className="drawer-foot">{footer}</div>}
      </aside>
    </div>,
    document.body
  );
}

const ToastCtx = createContext<(text: string, tone?: 'ok' | 'bad') => void>(() => {});
export const useToast = () => useContext(ToastCtx);

export function ToastHost({ children }: { children: ReactNode }) {
  const [t, setT] = useState<{ text: string; tone: 'ok' | 'bad'; k: number } | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  const show = useCallback((text: string, tone: 'ok' | 'bad' = 'ok') => {
    setT({ text, tone, k: Date.now() });
    clearTimeout(timer.current);
    timer.current = setTimeout(() => setT(null), tone === 'bad' ? 5500 : 3000);
  }, []);

  useEffect(() => () => clearTimeout(timer.current), []);

  return (
    <ToastCtx.Provider value={show}>
      {children}
      {t && (
        <div key={t.k} dir="rtl" className="pos-toast" data-tone={t.tone}>
          <span
            style={{
              width: 20,
              height: 20,
              borderRadius: 99,
              display: 'grid',
              placeItems: 'center',
              background: t.tone === 'bad' ? 'var(--bad)' : 'var(--acc)',
              color: '#fff',
            }}
          >
            <Icon n={t.tone === 'bad' ? 'x' : 'check'} s={12} w={3} />
          </span>
          <span role={t.tone === 'bad' ? 'alert' : 'status'}>{t.text}</span>
          <button type="button" className="icon-btn" aria-label="إغلاق التنبيه" onClick={() => setT(null)}><Icon n="x" s={16} /></button>
        </div>
      )}
    </ToastCtx.Provider>
  );
}

export function Stepper({ steps, current }: { steps: string[]; current: number }) {
  return (
    <div className="stepper">
      {steps.map((s, i) => (
        <React.Fragment key={s}>
          {i > 0 && <div className={cx('step-line', i <= current && 'done')} />}
          <div className={cx('step', i < current && 'done', i === current && 'current')}>
            <span className="s-dot">
              {i < current ? <Icon n="check" s={13} w={2.6} /> : fmt(i + 1)}
            </span>
            <span className="hide-mobile">{s}</span>
          </div>
        </React.Fragment>
      ))}
    </div>
  );
}
