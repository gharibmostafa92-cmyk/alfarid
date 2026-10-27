import React, {
  useState,
  useEffect,
  useRef,
  useContext,
  createContext,
  Fragment,
  type ReactNode,
  type CSSProperties,
} from 'react';
import { createPortal } from 'react-dom';

/* ---------- formatting ---------- */
export const fmtNum = (n: number | string, d = 2) =>
  Number(n).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });

export const fmtMoney = (n: number | string, d = 2) => (
  <span className="num ltr">
    {fmtNum(n, d)}{' '}
    <span className="tx3" style={{ fontSize: '0.82em', fontWeight: 500 }}>
      EGP
    </span>
  </span>
);

export const fmtInt = (n: number | string) => Number(n).toLocaleString('en-US');

export const Num = ({ children, className = '' }: { children: ReactNode; className?: string }) => (
  <span className={'num ltr ' + className}>{children}</span>
);

/* ---------- icons ---------- */
export const ICON_PATHS: Record<string, ReactNode> = {
  search: (
    <>
      <circle cx="11" cy="11" r="7" />
      <path d="M20 20l-3.5-3.5" />
    </>
  ),
  bell: (
    <path d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 00-5-5.9V4a1 1 0 10-2 0v1.1A6 6 0 006 11v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
  ),
  help: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M9.5 9a2.5 2.5 0 015 0c0 1.5-2.5 2-2.5 3.5m0 3h.01" />
    </>
  ),
  sun: (
    <>
      <circle cx="12" cy="12" r="4" />
      <path d="M12 3v2m0 14v2M3 12h2m14 0h2m-2.6-6.4l-1.4 1.4M7 17l-1.4 1.4m0-12.8L7 7m10 10l1.4 1.4" />
    </>
  ),
  moon: <path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" />,
  orders: (
    <>
      <path d="M6 2l-2 4v14a2 2 0 002 2h12a2 2 0 002-2V6l-2-4z" />
      <path d="M4 6h16M16 10a4 4 0 01-8 0" />
    </>
  ),
  returns: (
    <>
      <path d="M9 14L4 9l5-5" />
      <path d="M4 9h10.5a5.5 5.5 0 010 11H11" />
    </>
  ),
  truck: (
    <>
      <path d="M1 4h14v12H1zM15 9h4l3 3v4h-7" />
      <circle cx="5.5" cy="18.5" r="2" />
      <circle cx="18.5" cy="18.5" r="2" />
    </>
  ),
  tag: (
    <>
      <path d="M20.6 13.4l-7.2 7.2a2 2 0 01-2.8 0L2 12V2h10l8.6 8.6a2 2 0 010 2.8z" />
      <circle cx="7" cy="7" r="1.5" />
    </>
  ),
  ticket: (
    <>
      <path d="M3 8V6a2 2 0 012-2h14a2 2 0 012 2v2a2 2 0 000 4v2a2 2 0 01-2 2H5a2 2 0 01-2-2v-2a2 2 0 000-4z" />
      <path d="M13 4v2m0 4v2m0 4v2" />
    </>
  ),
  factory: <path d="M2 20h20M4 20V9l5 3V9l5 3V5h4v15" />,
  cart: (
    <>
      <circle cx="9" cy="20" r="1.5" />
      <circle cx="18" cy="20" r="1.5" />
      <path d="M2 3h3l2.7 12.4a2 2 0 002 1.6h7.8a2 2 0 002-1.6L21 7H6" />
    </>
  ),
  vault: (
    <>
      <rect x="3" y="4" width="18" height="16" rx="2.5" />
      <circle cx="12" cy="12" r="3.5" />
      <path d="M12 8.5V7m0 10v-1.5M15.5 12H17M7 12h1.5" />
    </>
  ),
  clock: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7v5l3 2" />
    </>
  ),
  box: (
    <>
      <path d="M21 8l-9-5-9 5v8l9 5 9-5z" />
      <path d="M3 8l9 5 9-5M12 13v8" />
    </>
  ),
  chart: <path d="M4 20V10M10 20V4M16 20v-7M22 20H2" />,
  star: <path d="M12 2l3 6.5 7 .9-5.1 4.8 1.3 7L12 17.8 5.8 21.2l1.3-7L2 9.4l7-.9z" />,
  plus: <path d="M12 5v14M5 12h14" />,
  x: <path d="M18 6L6 18M6 6l12 12" />,
  chevL: <path d="M15 18l-6-6 6-6" />,
  chevR: <path d="M9 18l6-6-6-6" />,
  chevD: <path d="M19 9l-7 7-7-7" />,
  filter: <path d="M4 6h16M7 12h10M10 18h4" />,
  more: (
    <>
      <circle cx="12" cy="5" r="1" />
      <circle cx="12" cy="12" r="1" />
      <circle cx="12" cy="19" r="1" />
    </>
  ),
  edit: (
    <>
      <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7" />
      <path d="M18.5 2.5a2.1 2.1 0 013 3L12 15l-4 1 1-4z" />
    </>
  ),
  trash: (
    <>
      <path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2m3 0v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6" />
    </>
  ),
  check: <path d="M20 6L9 17l-5-5" />,
  upload: (
    <>
      <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12" />
    </>
  ),
  download: (
    <>
      <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3" />
    </>
  ),
  file: (
    <>
      <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" />
      <path d="M14 2v6h6" />
    </>
  ),
  link: (
    <>
      <path d="M10 13a5 5 0 007.5.5l3-3a5 5 0 00-7-7l-1.7 1.7" />
      <path d="M14 11a5 5 0 00-7.5-.5l-3 3a5 5 0 007 7l1.7-1.7" />
    </>
  ),
  flag: (
    <>
      <path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1zM4 22v-7" />
    </>
  ),
  alert: (
    <>
      <path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z" />
      <path d="M12 9v4m0 4h.01" />
    </>
  ),
  info: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 8h.01M12 11v5" />
    </>
  ),
  user: (
    <>
      <circle cx="12" cy="8" r="4" />
      <path d="M4 21a8 8 0 0116 0" />
    </>
  ),
  users: (
    <>
      <circle cx="9" cy="8" r="3.5" />
      <path d="M2 20a7 7 0 0114 0M16 4.5a3.5 3.5 0 010 7M18 14a6 6 0 014 6" />
    </>
  ),
  phone: (
    <path d="M3 5a2 2 0 012-2h2.6a1 1 0 01.95.68l1.1 3.3a1 1 0 01-.27 1.02l-1.5 1.5a12 12 0 005.6 5.6l1.5-1.5a1 1 0 011.02-.27l3.3 1.1a1 1 0 01.68.95V19a2 2 0 01-2 2h-1C9.82 21 3 14.18 3 6V5z" />
  ),
  pin: (
    <>
      <path d="M12 22s7-6.5 7-12a7 7 0 00-14 0c0 5.5 7 12 7 12z" />
      <circle cx="12" cy="10" r="2.5" />
    </>
  ),
  store: (
    <>
      <path d="M3 9l1.5-5h15L21 9M3 9v11h18V9M3 9h18" />
      <path d="M9 20v-6h6v6" />
    </>
  ),
  card: (
    <>
      <rect x="2" y="5" width="20" height="14" rx="2.5" />
      <path d="M2 10h20" />
    </>
  ),
  wallet: (
    <>
      <path d="M20 7V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2h13a2 2 0 002-2v-2" />
      <path d="M22 11h-5a2 2 0 000 4h5z" />
    </>
  ),
  bank: (
    <>
      <path d="M3 21h18M4 10h16M12 3l9 5H3zM6 10v8m4-8v8m4-8v8m4-8v8" />
    </>
  ),
  cash: (
    <>
      <rect x="2" y="6" width="20" height="12" rx="2" />
      <circle cx="12" cy="12" r="2.5" />
      <path d="M6 12h.01M18 12h.01" />
    </>
  ),
  arrowL: <path d="M19 12H5m7-7l-7 7 7 7" />,
  arrowR: <path d="M5 12h14m-7-7l7 7-7 7" />,
  swap: (
    <>
      <path d="M7 16V4m0 0L3 8m4-4l4 4M17 8v12m0 0l4-4m-4 4l-4-4" />
    </>
  ),
  send: <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z" />,
  eye: (
    <>
      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z" />
      <circle cx="12" cy="12" r="3" />
    </>
  ),
  lock: (
    <>
      <rect x="4" y="11" width="16" height="10" rx="2" />
      <path d="M8 11V7a4 4 0 018 0v4" />
    </>
  ),
  key: (
    <>
      <circle cx="8" cy="15" r="4" />
      <path d="M10.8 12.2L20 3m-3 3l3 3m-6 0l2 2" />
    </>
  ),
  calendar: (
    <>
      <rect x="3" y="4" width="18" height="17" rx="2.5" />
      <path d="M3 9h18M8 2v4m8-4v4" />
    </>
  ),
  layers: (
    <>
      <path d="M12 2l10 5-10 5L2 7z" />
      <path d="M2 17l10 5 10-5M2 12l10 5 10-5" />
    </>
  ),
  globe: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M3 12h18M12 3a14 14 0 010 18M12 3a14 14 0 000 18" />
    </>
  ),
  receipt: (
    <>
      <path d="M5 2v20l3-2 2 2 2-2 2 2 2-2 3 2V2l-3 2-2-2-2 2-2-2-2 2z" />
      <path d="M9 8h6M9 12h6M9 16h3" />
    </>
  ),
  quote: (
    <>
      <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z" />
      <path d="M8 9h8M8 13h5" />
    </>
  ),
  logout: <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4m7 14l5-5-5-5m5 5H9" />,
  grid: (
    <>
      <rect x="3" y="3" width="7" height="7" rx="1.5" />
      <rect x="14" y="3" width="7" height="7" rx="1.5" />
      <rect x="3" y="14" width="7" height="7" rx="1.5" />
      <rect x="14" y="14" width="7" height="7" rx="1.5" />
    </>
  ),
  ban: (
    <>
      <circle cx="12" cy="12" r="9" />
      <path d="M5.6 5.6l12.8 12.8" />
    </>
  ),
  refresh: (
    <>
      <path d="M23 4v6h-6M1 20v-6h6" />
      <path d="M3.5 9a9 9 0 0114.9-3.4L23 10M1 14l4.6 4.4A9 9 0 0020.5 15" />
    </>
  ),
  image: (
    <>
      <rect x="3" y="3" width="18" height="18" rx="2.5" />
      <circle cx="8.5" cy="8.5" r="1.5" />
      <path d="M21 15l-5-5L5 21" />
    </>
  ),
  percent: (
    <>
      <path d="M19 5L5 19" />
      <circle cx="6.5" cy="6.5" r="2.5" />
      <circle cx="17.5" cy="17.5" r="2.5" />
    </>
  ),
  gift: (
    <>
      <rect x="3" y="8" width="18" height="4" rx="1" />
      <path d="M12 8v13M5 12v9h14v-9M12 8S11 3 8 3a2.5 2.5 0 000 5M12 8s1-5 4-5a2.5 2.5 0 010 5" />
    </>
  ),
  hash: <path d="M4 9h16M4 15h16M10 3L8 21M16 3l-2 18" />,
};

export function Icon({
  name,
  size = 16,
  sw = 1.9,
  className = '',
  style,
}: {
  name: string;
  size?: number;
  sw?: number;
  className?: string;
  style?: CSSProperties;
}) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={sw}
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
      style={{ flexShrink: 0, ...style }}
    >
      {ICON_PATHS[name] || ICON_PATHS.info}
    </svg>
  );
}

/* ---------- toast ---------- */
type ToastFunction = (text: string, tone?: 'accent' | 'green' | 'red') => void;
const ToastCtx = createContext<ToastFunction>(() => {});

export function ToastHost({ children, portalHost }: { children: ReactNode; portalHost?: HTMLElement | ShadowRoot | null }) {
  const [t, setT] = useState<{ text: string; tone: string; k: number } | null>(null);
  const timer = useRef<any>(null);

  const show: ToastFunction = (text, tone = 'accent') => {
    setT({ text, tone, k: Date.now() });
    clearTimeout(timer.current);
    timer.current = setTimeout(() => setT(null), 2600);
  };

  const toastEl = (
    <div
      className="toast"
      style={{
        opacity: t ? 1 : 0,
        transform: t ? 'none' : 'translateY(8px)',
        pointerEvents: 'none',
      }}
    >
      <div
        className="px-4 py-2.5 rounded-full shadow-lg text-[12px] font-medium flex items-center gap-2"
        style={{ background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' }}
      >
        <span
          className="w-2 h-2 rounded-full"
          style={{
            background:
              t?.tone === 'red' ? 'var(--red-tx)' : t?.tone === 'green' ? '#22c55e' : 'var(--accent)',
          }}
        />
        <span>{t?.text}</span>
      </div>
    </div>
  );

  return (
    <ToastCtx.Provider value={show}>
      {children}
      {portalHost ? createPortal(toastEl, portalHost as Element) : toastEl}
    </ToastCtx.Provider>
  );
}

export const useToast = () => useContext(ToastCtx);

/* ---------- basic atoms ---------- */
export function Badge({
  tone = 'gray',
  dot = true,
  children,
  className = '',
}: {
  tone?: string;
  dot?: boolean;
  children: ReactNode;
  className?: string;
}) {
  return (
    <span className={`badge t-${tone} ${className}`}>
      {dot && <span className="dot" />}
      {children}
    </span>
  );
}

export function StatusBadge({ map, value }: { map: Record<string, { label: string; tone: string }>; value: string }) {
  const s = map[value] || { label: value, tone: 'gray' };
  return <Badge tone={s.tone}>{s.label}</Badge>;
}

export function Toggle({
  on,
  onChange,
  disabled,
}: {
  on: boolean;
  onChange?: (v: boolean) => void;
  disabled?: boolean;
}) {
  return (
    <button
      type="button"
      disabled={disabled}
      className={'tgl' + (on ? ' on' : '')}
      onClick={() => onChange && onChange(!on)}
      aria-pressed={on}
    />
  );
}

export function ActiveDot({ on }: { on: boolean }) {
  return (
    <span className={'inline-flex items-center gap-1.5 text-[12px] font-medium ' + (on ? 'c-green' : 'tx3')}>
      <span className="w-1.5 h-1.5 rounded-full" style={{ background: 'currentColor' }} />
      {on ? 'مفعّل' : 'معطّل'}
    </span>
  );
}

export function Seg({
  options,
  value,
  onChange,
}: {
  options: (string | { value: string; label: string })[];
  value: string;
  onChange: (v: string) => void;
}) {
  return (
    <div className="seg">
      {options.map((o) => {
        const v = typeof o === 'string' ? o : o.value;
        const l = typeof o === 'string' ? o : o.label;
        return (
          <button
            key={v}
            type="button"
            className={value === v ? 'on' : ''}
            onClick={() => onChange(v)}
          >
            {l}
          </button>
        );
      })}
    </div>
  );
}

export function Avatar({
  name = '',
  tone = 0,
  size = 30,
}: {
  name?: string;
  tone?: number;
  size?: number;
}) {
  const palettes = ['#e0491f', '#18151c', '#7c5cff', '#0ea5a4', '#d97706', '#db2777'];
  const ch = name.trim().charAt(0) || '؟';
  return (
    <span
      className="rounded-full inline-flex items-center justify-center text-white font-semibold shrink-0"
      style={{
        width: size,
        height: size,
        fontSize: size * 0.42,
        background: palettes[Math.abs(tone) % palettes.length],
      }}
    >
      {ch}
    </span>
  );
}

/* ---------- layout pieces ---------- */
export function PageHead({
  title,
  sub,
  actions,
  back,
}: {
  title: ReactNode;
  sub?: ReactNode;
  actions?: ReactNode;
  back?: { onClick: () => void; label: string };
}) {
  return (
    <div className="stg stg-1 flex flex-wrap items-end justify-between gap-4 pt-1">
      <div className="min-w-0">
        {back && (
          <button onClick={back.onClick} className="btn btn-ghost btn-xs mb-2 -ms-2">
            <Icon name="chevR" size={14} />
            {back.label}
          </button>
        )}
        <h1 className="font-display text-[30px] sm:text-[36px] font-medium tracking-[-0.01em] leading-tight tx1">
          {title}
        </h1>
        {sub && <p className="text-[13.5px] mt-1 tx2 pretty">{sub}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2.5">{actions}</div>}
    </div>
  );
}

export function Card({
  children,
  className = '',
  pad = 'p-5',
  stg = 2,
  ...rest
}: {
  children: ReactNode;
  className?: string;
  pad?: string;
  stg?: number;
  [k: string]: any;
}) {
  return (
    <div className={`v-card ${pad} stg stg-${stg} ${className}`} {...rest}>
      {children}
    </div>
  );
}

export function CardHead({
  title,
  sub,
  right,
  className = 'mb-4',
}: {
  title: ReactNode;
  sub?: ReactNode;
  right?: ReactNode;
  className?: string;
}) {
  return (
    <div className={'flex flex-wrap items-center justify-between gap-3 ' + className}>
      <div>
        <h3 className="font-display text-[16px] font-semibold tx1">{title}</h3>
        {sub && <p className="text-[11.5px] mt-0.5 tx3">{sub}</p>}
      </div>
      {right && <div className="flex items-center gap-2">{right}</div>}
    </div>
  );
}

export function KPI({
  label,
  value,
  icon,
  hero,
  foot,
  small,
}: {
  label: ReactNode;
  value: ReactNode;
  icon?: string;
  hero?: boolean;
  foot?: ReactNode;
  small?: boolean;
}) {
  if (hero)
    return (
      <div className="v-orange rounded-[18px] p-4 flex flex-col justify-between min-h-[118px]">
        <div className="flex items-center justify-between">
          <span className="text-[12.5px] font-medium text-white/90">{label}</span>
          {icon && (
            <span className="w-7 h-7 rounded-full bg-white/15 flex items-center justify-center">
              <Icon name={icon} size={14} />
            </span>
          )}
        </div>
        <div className={'font-display font-bold tracking-tight my-2.5 num ' + (small ? 'text-[22px]' : 'text-[28px]')}>
          {value}
        </div>
        {foot && <div className="text-[11px] text-white/80">{foot}</div>}
      </div>
    );
  return (
    <div className="v-sub rounded-[18px] p-4 flex flex-col justify-between min-h-[118px]">
      <div className="flex items-center justify-between">
        <span className="text-[12.5px] font-medium tx2">{label}</span>
        {icon && (
          <span className="v-well w-7 h-7 rounded-full flex items-center justify-center">
            <Icon name={icon} size={14} />
          </span>
        )}
      </div>
      <div className={'font-display font-bold tracking-tight my-2.5 tx1 num ' + (small ? 'text-[22px]' : 'text-[28px]')}>
        {value}
      </div>
      {foot && <div className="text-[11px] tx3">{foot}</div>}
    </div>
  );
}

/* ---------- filter bar ---------- */
export function SearchPill({
  value,
  onChange,
  placeholder = 'بحث',
  w = 'w-44',
}: {
  value: string;
  onChange: (v: string) => void;
  placeholder?: string;
  w?: string;
}) {
  return (
    <div className="v-sub px-3 py-1.5 rounded-full flex items-center gap-2">
      <Icon name="search" size={14} className="tx3" />
      <input
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className={'bg-transparent text-[12px] outline-none tx1 ' + w}
      />
    </div>
  );
}

export function FSelect({
  value,
  onChange,
  options,
  placeholder,
}: {
  value: string;
  onChange: (v: string) => void;
  options: (string | { value: string; label: string })[];
  placeholder: string;
}) {
  return (
    <select
      className={'pill-inp' + (value ? ' on' : '')}
      value={value}
      onChange={(e) => onChange(e.target.value)}
    >
      <option value="">{placeholder}</option>
      {options.map((o) =>
        typeof o === 'string' ? (
          <option key={o} value={o}>
            {o}
          </option>
        ) : (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        )
      )}
    </select>
  );
}

export function FChip({
  on,
  onClick,
  children,
  icon,
}: {
  on: boolean;
  onClick: () => void;
  children: ReactNode;
  icon?: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={'pill-inp inline-flex items-center gap-1.5 font-medium ' + (on ? 'on' : '')}
    >
      {icon && <Icon name={icon} size={13} />}
      {children}
    </button>
  );
}

export function FDate({
  value,
  onChange,
  label,
}: {
  value: string;
  onChange: (v: string) => void;
  label: string;
}) {
  return (
    <label className="pill-inp inline-flex items-center gap-2 !py-[5px]">
      <span className="tx3 text-[11.5px]">{label}</span>
      <input
        type="date"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="bg-transparent outline-none text-[11.5px] tx1 num"
      />
    </label>
  );
}

export function TabsBar({
  tabs,
  value,
  onChange,
}: {
  tabs: { value: string; label: string; icon?: string; count?: number }[];
  value: string;
  onChange: (v: string) => void;
}) {
  return (
    <div className="v-pill p-1.5 inline-flex items-center gap-1 stg stg-2 flex-wrap">
      {tabs.map((t) => (
        <button
          key={t.value}
          onClick={() => onChange(t.value)}
          className="px-4 py-2 rounded-full text-[13px] font-medium transition flex items-center gap-2"
          style={
            value === t.value
              ? { background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' }
              : { color: 'var(--text-secondary)' }
          }
        >
          {t.icon && <Icon name={t.icon} size={14} />}
          {t.label}
          {t.count != null && <span className="num text-[11px] opacity-70">{t.count}</span>}
        </button>
      ))}
    </div>
  );
}

/* ---------- table ---------- */
export interface ColumnDef<T> {
  h: ReactNode;
  w?: string | number;
  align?: 'left' | 'right' | 'center';
  k?: keyof T;
  r?: (row: T, index: number) => ReactNode;
}

export function DataTable<T extends Record<string, any>>({
  cols,
  rows,
  onRow,
  empty = 'لا توجد بيانات مطابقة',
  compact,
  foot,
}: {
  cols: ColumnDef<T>[];
  rows: T[];
  onRow?: (row: T) => void;
  empty?: ReactNode;
  compact?: boolean;
  foot?: ReactNode;
}) {
  return (
    <div className="overflow-x-auto">
      <table className={'tbl' + (compact ? ' compact' : '')}>
        <thead>
          <tr>
            {cols.map((c, i) => (
              <th key={i} style={{ width: c.w, textAlign: c.align }}>
                {c.h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 && (
            <tr>
              <td colSpan={cols.length} className="text-center !py-10 tx3">
                {empty}
              </td>
            </tr>
          )}
          {rows.map((r, ri) => (
            <tr
              key={r.id ?? ri}
              className={onRow ? 'clickable' : ''}
              onClick={onRow ? () => onRow(r) : undefined}
            >
              {cols.map((c, ci) => (
                <td key={ci} style={{ textAlign: c.align }}>
                  {c.r ? c.r(r, ri) : c.k ? r[c.k] : null}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
        {foot}
      </table>
    </div>
  );
}

export function Pager({
  total,
  per = 20,
  page = 1,
  onPage,
}: {
  total: number;
  per?: number;
  page?: number;
  onPage?: (p: number) => void;
}) {
  const pages = Math.max(1, Math.ceil(total / per));
  return (
    <div className="flex items-center justify-between pt-4 text-[12px] tx2">
      <span>
        عرض <Num>{Math.min(total, (page - 1) * per + 1)}–{Math.min(total, page * per)}</Num> من{' '}
        <Num>{total}</Num>
      </span>
      <div className="flex items-center gap-1.5">
        <button
          className="icon-btn !w-7 !h-7"
          disabled={page <= 1}
          onClick={() => onPage && onPage(page - 1)}
        >
          <Icon name="chevR" size={13} />
        </button>
        {Array.from({ length: Math.min(pages, 4) }).map((_, i) => (
          <button
            key={i}
            onClick={() => onPage && onPage(i + 1)}
            className={'w-7 h-7 rounded-full text-[11.5px] font-semibold num ' + (page === i + 1 ? '' : 'tx2')}
            style={
              page === i + 1
                ? { background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' }
                : {}
            }
          >
            {i + 1}
          </button>
        ))}
        <button
          className="icon-btn !w-7 !h-7"
          disabled={page >= pages}
          onClick={() => onPage && onPage(page + 1)}
        >
          <Icon name="chevL" size={13} />
        </button>
      </div>
    </div>
  );
}

export function RowActions({ children }: { children: ReactNode }) {
  return (
    <div className="flex items-center gap-1 justify-end" onClick={(e) => e.stopPropagation()}>
      {children}
    </div>
  );
}

/* ---------- modal / drawer ---------- */
export function Modal({
  open,
  onClose,
  title,
  sub,
  children,
  footer,
  width = 520,
  icon,
  tone,
}: {
  open: boolean;
  onClose: () => void;
  title: ReactNode;
  sub?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  width?: number | string;
  icon?: string;
  tone?: string;
}) {
  useEffect(() => {
    if (!open) return;
    const h = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', h);
    return () => window.removeEventListener('keydown', h);
  }, [open, onClose]);

  if (!open) return null;

  return (
    <div className="modal-bd" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className="modal" style={{ maxWidth: width }} dir="rtl">
        <div className="flex items-start justify-between gap-4 p-6 pb-4">
          <div className="flex items-start gap-3">
            {icon && (
              <span
                className={`w-10 h-10 rounded-full flex items-center justify-center shrink-0 t-${tone || 'orange'}`}
              >
                <Icon name={icon} size={17} />
              </span>
            )}
            <div>
              <h3 className="font-display text-[18px] font-semibold tx1">{title}</h3>
              {sub && <p className="text-[12px] tx2 mt-1 pretty">{sub}</p>}
            </div>
          </div>
          <button className="icon-btn shrink-0" onClick={onClose}>
            <Icon name="x" size={14} />
          </button>
        </div>
        <div className="modal-body">{children}</div>
        {footer && <div className="flex items-center justify-end gap-2 p-5 pt-4 mt-2 v-bt">{footer}</div>}
      </div>
    </div>
  );
}

export function ConfirmModal({
  open,
  onClose,
  onConfirm,
  title,
  text,
  cta = 'تأكيد',
  danger,
}: {
  open: boolean;
  onClose: () => void;
  onConfirm?: () => void | boolean | Promise<void | boolean>;
  title: string;
  text: string;
  cta?: string;
  danger?: boolean;
}) {
  const [confirming, setConfirming] = useState(false);
  const confirmingRef = useRef(false);
  const toast = useToast();
  return (
    <Modal
      open={open}
      onClose={() => { if (!confirmingRef.current) onClose(); }}
      title={title}
      icon={danger ? 'alert' : 'info'}
      tone={danger ? 'red' : 'orange'}
      width={440}
      footer={
        <>
          <button className="btn btn-soft" disabled={confirming} onClick={onClose}>
            تراجع
          </button>
          <button
            className={'btn ' + (danger ? 'btn-danger' : 'btn-dark')}
            disabled={confirming}
            onClick={async () => {
              if (confirmingRef.current) return;
              confirmingRef.current = true;
              setConfirming(true);
              try {
                if (await onConfirm?.() !== false) onClose();
              } catch {
                toast('تعذّر تنفيذ الإجراء، حاول مرة أخرى', 'red');
              } finally {
                confirmingRef.current = false;
                setConfirming(false);
              }
            }}
          >
            {cta}
          </button>
        </>
      }
    >
      <p className="text-[13px] tx2 leading-relaxed pretty pb-2">{text}</p>
    </Modal>
  );
}

/* ---------- form ---------- */
export function Field({
  label,
  req,
  hint,
  err,
  children,
  className = '',
  count,
}: {
  label?: ReactNode;
  req?: boolean;
  hint?: ReactNode;
  err?: ReactNode;
  children: ReactNode;
  className?: string;
  count?: ReactNode;
}) {
  return (
    <label className={'block ' + className}>
      {label && (
        <div className="flex items-center justify-between mb-1.5">
          <span className="lbl">
            {label}
            {req && <span className="accent ms-0.5">*</span>}
          </span>
          {count && <span className="text-[10.5px] tx3 num">{count}</span>}
        </div>
      )}
      {children}
      {err ? (
        <div className="text-[11px] c-red mt-1.5 flex items-center gap-1">
          <Icon name="alert" size={12} />
          {err}
        </div>
      ) : hint ? (
        <div className="text-[11px] tx3 mt-1.5 pretty">{hint}</div>
      ) : null}
    </label>
  );
}

export function TextArea({
  max,
  value,
  onChange,
  placeholder,
  ...rest
}: {
  max?: number;
  value?: string;
  onChange?: (v: string) => void;
  placeholder?: string;
  [k: string]: any;
}) {
  const [v, setV] = useState(value || '');
  const val = onChange ? value : v;
  return (
    <Field {...rest} count={max ? `${(val || '').length} / ${max}` : null}>
      <textarea
        className="inp"
        maxLength={max}
        value={val}
        onChange={(e) => (onChange ? onChange(e.target.value) : setV(e.target.value))}
        placeholder={placeholder}
      />
    </Field>
  );
}

export function ToggleRow({
  label,
  sub,
  on,
  onChange,
}: {
  label: ReactNode;
  sub?: ReactNode;
  on?: boolean;
  onChange?: (v: boolean) => void;
}) {
  const [v, setV] = useState(on ?? true);
  const val = onChange ? on : v;
  return (
    <div className="v-sub px-4 py-3 flex items-center justify-between gap-3">
      <div>
        <div className="text-[13px] font-medium tx1">{label}</div>
        {sub && <div className="text-[11.5px] tx3 mt-0.5">{sub}</div>}
      </div>
      <Toggle on={val ?? true} onChange={(x) => (onChange ? onChange(x) : setV(x))} />
    </div>
  );
}

export function Note({
  tone = 'gray',
  icon = 'info',
  children,
  className = '',
}: {
  tone?: string;
  icon?: string;
  children: ReactNode;
  className?: string;
}) {
  return (
    <div
      className={`rounded-[14px] px-3.5 py-2.5 flex items-start gap-2.5 text-[12px] leading-relaxed t-${tone} ${className}`}
      style={{ fontWeight: 500 }}
    >
      <Icon name={icon} size={15} style={{ marginTop: 2 }} />
      <div className="pretty">{children}</div>
    </div>
  );
}

export function Dropzone({
  accept,
  max,
  onFile,
  label = 'اسحب الملف هنا أو اضغط للاختيار',
}: {
  accept?: string;
  max?: string | number;
  onFile?: (file: File | null) => void;
  label?: string;
}) {
  const [file, setFile] = useState<File | null>(null);
  const ref = useRef<HTMLInputElement>(null);
  return (
    <div className="drop" onClick={() => ref.current?.click()}>
      <input
        ref={ref}
        type="file"
        className="hidden"
        accept={accept}
        onChange={(e) => { const selected = e.target.files?.[0] || null; setFile(selected); onFile?.(selected); }}
      />
      {file ? (
        <div className="flex items-center justify-center gap-2 text-[12.5px] tx1">
          <Icon name="file" size={16} className="accent" />
          <span className="font-medium">{file.name}</span>
          <span className="tx3 num">{(file.size / 1024).toFixed(0)} KB</span>
        </div>
      ) : (
        <>
          <div className="w-10 h-10 rounded-full v-well mx-auto flex items-center justify-center mb-2">
            <Icon name="upload" size={16} />
          </div>
          <div className="text-[12.5px] font-medium tx1">{label}</div>
          <div className="text-[11px] tx3 mt-1">
            <span className="ltr">{accept}</span> {max && <>· حتى <Num>{max}</Num></>}
          </div>
        </>
      )}
    </div>
  );
}

/* ---------- detail bits ---------- */
export function KV({ items }: { items: (([ReactNode, ReactNode] | false | null | undefined))[] }) {
  return (
    <dl className="kv">
      {items.filter(Boolean).map((it, i) => {
        const [k, v] = it as [ReactNode, ReactNode];
        return (
          <Fragment key={i}>
            <dt>{k}</dt>
            <dd>{v ?? <span className="tx3">—</span>}</dd>
          </Fragment>
        );
      })}
    </dl>
  );
}

export function Timeline({
  items,
}: {
  items: { icon?: string; body: ReactNode }[];
}) {
  return (
    <ol className="relative">
      {items.map((it, i) => (
        <li key={i} className="flex gap-3 pb-5 last:pb-0 relative">
          {i < items.length - 1 && (
            <span
              className="absolute top-6 bottom-0 w-px"
              style={{ insetInlineStart: 11, background: 'var(--divider-color)' }}
            />
          )}
          <span
            className={
              'w-[23px] h-[23px] rounded-full flex items-center justify-center shrink-0 ' +
              (i === 0 ? 'btn-accent' : 'v-well')
            }
          >
            <Icon name={it.icon || 'check'} size={11} sw={2.4} />
          </span>
          <div className="flex-1 min-w-0 -mt-0.5">{it.body}</div>
        </li>
      ))}
    </ol>
  );
}

export function Empty({
  icon = 'box',
  title,
  text,
  action,
}: {
  icon?: string;
  title: string;
  text?: string;
  action?: ReactNode;
}) {
  return (
    <div className="text-center py-12">
      <div className="w-12 h-12 rounded-full v-well mx-auto flex items-center justify-center mb-3">
        <Icon name={icon} size={20} />
      </div>
      <div className="font-display text-[15px] font-semibold tx1">{title}</div>
      {text && <div className="text-[12px] tx2 mt-1 max-w-sm mx-auto pretty">{text}</div>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

export function HatchBar({ pct, h = 14 }: { pct: number; h?: number }) {
  return (
    <div className="w-full rounded-full overflow-hidden flex items-center gap-1 p-0.5" style={{ height: h }}>
      <div
        className="h-full rounded-full transition-all duration-500"
        style={{ width: pct + '%', background: 'var(--accent)' }}
      />
      <div className="h-full flex-1 rounded-full hatch-track" />
    </div>
  );
}
