import React, { useState, useEffect, useRef, ReactNode, CSSProperties } from 'react';

// ===== Shared UI primitives (Vortix visual language, RTL) =====

export const ICON_PATHS: Record<string, ReactNode> = {
  search: <><circle cx="11" cy="11" r="7"/><path strokeLinecap="round" d="M20 20l-3.5-3.5"/></>,
  plus: <path strokeLinecap="round" d="M12 5v14M5 12h14"/>,
  users: <><circle cx="9" cy="8" r="3.5"/><path strokeLinecap="round" d="M2.5 20c.6-3.6 3.3-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path strokeLinecap="round" d="M16 4.6a3.5 3.5 0 010 6.8M18 14.8c1.9.7 3.2 2.4 3.5 5.2"/></>,
  userCheck: <><circle cx="9" cy="8" r="3.5"/><path strokeLinecap="round" d="M2.5 20c.6-3.6 3.3-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path strokeLinecap="round" strokeLinejoin="round" d="M16 11l2 2 4-4"/></>,
  userOff: <><circle cx="9" cy="8" r="3.5"/><path strokeLinecap="round" d="M2.5 20c.6-3.6 3.3-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path strokeLinecap="round" d="M17 9l4 4M21 9l-4 4"/></>,
  shield: <path strokeLinecap="round" strokeLinejoin="round" d="M12 3l7.5 3v5.5c0 4.6-3.2 8.3-7.5 9.5-4.3-1.2-7.5-4.9-7.5-9.5V6L12 3z"/>,
  shieldCheck: <><path strokeLinecap="round" strokeLinejoin="round" d="M12 3l7.5 3v5.5c0 4.6-3.2 8.3-7.5 9.5-4.3-1.2-7.5-4.9-7.5-9.5V6L12 3z"/><path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4"/></>,
  settings: <><circle cx="12" cy="12" r="3"/><path strokeLinecap="round" strokeLinejoin="round" d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/></>,
  chevDown: <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7"/>,
  chevLeft: <path strokeLinecap="round" strokeLinejoin="round" d="M15 18l-6-6 6-6"/>,
  chevRight: <path strokeLinecap="round" strokeLinejoin="round" d="M9 18l6-6-6-6"/>,
  close: <path strokeLinecap="round" d="M6 6l12 12M18 6L6 18"/>,
  check: <path strokeLinecap="round" strokeLinejoin="round" d="M5 12.5l4.5 4.5L19 7.5"/>,
  edit: <><path strokeLinecap="round" strokeLinejoin="round" d="M4 20h4L19 9a2.8 2.8 0 00-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/></>,
  power: <><path strokeLinecap="round" d="M12 3v8"/><path strokeLinecap="round" d="M6.3 6.8a8 8 0 1011.4 0"/></>,
  more: <><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></>,
  eye: <><path strokeLinecap="round" strokeLinejoin="round" d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></>,
  eyeOff: <><path strokeLinecap="round" strokeLinejoin="round" d="M3 3l18 18M10.6 5.1A10 10 0 0112 5c6.4 0 10 7 10 7a17 17 0 01-3.2 4.1M6.6 6.6A17 17 0 002 12s3.6 7 10 7a9.6 9.6 0 005.4-1.6M9.9 9.9a3 3 0 004.2 4.2"/></>,
  building: <><rect x="4" y="3" width="16" height="18" rx="2"/><path strokeLinecap="round" d="M9 7h2M13 7h2M9 11h2M13 11h2M9 15h2M13 15h2"/></>,
  globe: <><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3z"/></>,
  lock: <><rect x="5" y="11" width="14" height="10" rx="2.5"/><path strokeLinecap="round" d="M8 11V8a4 4 0 018 0v3"/></>,
  alert: <><path strokeLinecap="round" strokeLinejoin="round" d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/><path strokeLinecap="round" d="M12 9v4M12 17h.01"/></>,
  info: <><circle cx="12" cy="12" r="9"/><path strokeLinecap="round" d="M12 8h.01M12 11v5"/></>,
  refresh: <><path strokeLinecap="round" strokeLinejoin="round" d="M4 4v6h6"/><path strokeLinecap="round" strokeLinejoin="round" d="M20 20v-6h-6"/><path strokeLinecap="round" d="M20 10a8 8 0 00-14.3-3.7L4 10M4 14a8 8 0 0014.3 3.7L20 14"/></>,
  undo: <><path strokeLinecap="round" strokeLinejoin="round" d="M9 14L4 9l5-5"/><path strokeLinecap="round" d="M4 9h10.5a5.5 5.5 0 010 11H11"/></>,
  trash: <path strokeLinecap="round" strokeLinejoin="round" d="M4 7h16M10 11v6M14 11v6M5 7l1 12a2 2 0 002 2h8a2 2 0 002-2l1-12M9 7V4h6v3"/>,
  box: <><path strokeLinejoin="round" d="M3 7.5L12 3l9 4.5v9L12 21l-9-4.5v-9z"/><path strokeLinejoin="round" d="M3 7.5L12 12l9-4.5M12 12v9"/></>,
  tag: <><path strokeLinejoin="round" d="M3 12V4a1 1 0 011-1h8l9 9-9 9-9-9z"/><circle cx="7.5" cy="7.5" r="1.5"/></>,
  cart: <><path strokeLinecap="round" strokeLinejoin="round" d="M3 4h2l2.4 11.2a2 2 0 002 1.6h7.7a2 2 0 002-1.5L21 8H6"/><circle cx="10" cy="20.5" r="1"/><circle cx="17" cy="20.5" r="1"/></>,
  pos: <><rect x="4" y="3" width="16" height="11" rx="2"/><path strokeLinecap="round" d="M8 7h8M4 18h16M7 21h10"/></>,
  truck: <><path strokeLinejoin="round" d="M2 6h12v10H2zM14 9h4l4 4v3h-8z"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="18" r="2"/></>,
  pin: <><path strokeLinejoin="round" d="M12 21s-7-6.2-7-11.5a7 7 0 0114 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></>,
  wallet: <><rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M3 10h18"/><path strokeLinecap="round" d="M16 14.5h2"/></>,
  sliders: <><path strokeLinecap="round" d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/></>,
  percent: <><path strokeLinecap="round" d="M19 5L5 19"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/></>,
  hash: <path strokeLinecap="round" d="M5 9h15M4 15h15M10 3L8 21M16 3l-2 18"/>,
  doc: <><path strokeLinejoin="round" d="M6 3h8l5 5v13H6z"/><path strokeLinejoin="round" d="M14 3v5h5M9 13h6M9 17h6"/></>,
  calendar: <><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path strokeLinecap="round" d="M3.5 10h17M8 3v4M16 3v4"/></>,
  ban: <><circle cx="12" cy="12" r="9"/><path strokeLinecap="round" d="M5.6 5.6l12.8 12.8"/></>,
  infinity: <path strokeLinecap="round" strokeLinejoin="round" d="M12 12c-2-2.7-3.6-4-5.5-4a4 4 0 000 8c1.9 0 3.5-1.3 5.5-4zm0 0c2 2.7 3.6 4 5.5 4a4 4 0 000-8c-1.9 0-3.5 1.3-5.5 4z"/>,
  image: <><rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="9" cy="10" r="2"/><path strokeLinecap="round" strokeLinejoin="round" d="M21 16l-5-5-9 9"/></>,
  link: <><path strokeLinecap="round" d="M10 14a4 4 0 005.7 0l3.5-3.5a4 4 0 00-5.7-5.7L12 6.3"/><path strokeLinecap="round" d="M14 10a4 4 0 00-5.7 0l-3.5 3.5a4 4 0 005.7 5.7L12 17.7"/></>,
  mail: <><rect x="3" y="5" width="18" height="14" rx="2.5"/><path strokeLinecap="round" strokeLinejoin="round" d="M3 7l9 6 9-6"/></>,
  phone: <path strokeLinecap="round" strokeLinejoin="round" d="M3 5a2 2 0 012-2h2.6a1 1 0 01.95.68l1.1 3.3a1 1 0 01-.27 1.02l-1.5 1.5a12 12 0 005.6 5.6l1.5-1.5a1 1 0 011.02-.27l3.3 1.1a1 1 0 01.68.95V19a2 2 0 01-2 2h-1C9.82 21 3 14.18 3 6V5z"/>,
  key: <><circle cx="8" cy="15" r="4"/><path strokeLinecap="round" strokeLinejoin="round" d="M11 12l9-9M17 6l3 3M14 9l2 2"/></>,
  grid: <><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></>,
  sun: <><circle cx="12" cy="12" r="4"/><path strokeLinecap="round" d="M12 3v2m0 14v2M3 12h2m14 0h2m-2.6-6.4l-1.4 1.4M7 17l-1.4 1.4m0-12.8L7 7m10 10l1.4 1.4"/></>,
  moon: <path strokeLinecap="round" strokeLinejoin="round" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/>,
  bell: <path strokeLinecap="round" strokeLinejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 00-5-5.9V4a1 1 0 10-2 0v1.1A6 6 0 006 11v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>,
  help: <><circle cx="12" cy="12" r="9"/><path strokeLinecap="round" strokeLinejoin="round" d="M9.5 9a2.5 2.5 0 015 0c0 1.5-2.5 2-2.5 3.5m0 3h.01"/></>,
  logout: <path strokeLinecap="round" strokeLinejoin="round" d="M15 21h4a2 2 0 002-2V5a2 2 0 00-2-2h-4M8 17l-5-5 5-5M3 12h12"/>,
  filter: <path strokeLinecap="round" d="M4 6h16M7 12h10M10 18h4"/>,
  dual: <><circle cx="8" cy="8" r="3"/><circle cx="16" cy="8" r="3"/><path strokeLinecap="round" d="M2.5 19c.5-3 2.7-4.5 5.5-4.5M21.5 19c-.5-3-2.7-4.5-5.5-4.5M10 18l2 2 3.5-3.5"/></>,
};

export function Icon({ name, className = 'w-4 h-4', strokeWidth = 1.9, style }: { name: string; className?: string; strokeWidth?: number; style?: CSSProperties }) {
  return (
    <svg className={className} style={style} fill="none" stroke="currentColor" strokeWidth={strokeWidth} viewBox="0 0 24 24">
      {ICON_PATHS[name]}
    </svg>
  );
}

export function Toggle({ on, onChange, disabled, size = 'md' }: { on: boolean; onChange: (val: boolean) => void; disabled?: boolean; size?: 'sm' | 'md' }) {
  const w = size === 'sm' ? 'w-9 h-5' : 'w-11 h-6';
  const k = size === 'sm' ? 'w-4 h-4' : 'w-5 h-5';
  return (
    <button type="button" role="switch" aria-checked={on} disabled={disabled}
      onClick={() => !disabled && onChange(!on)}
      className={`${w} rounded-full p-0.5 transition-colors duration-200 flex items-center shrink-0 ${disabled ? 'opacity-45 cursor-not-allowed' : ''}`}
      style={{ background: on ? 'var(--accent-orange)' : 'var(--toggle-off-bg)', justifyContent: on ? 'flex-start' : 'flex-end' }}>
      <span className={`${k} rounded-full bg-white shadow-sm block`} />
    </button>
  );
}

export function Badge({ tone = 'neutral', children, className = '', dot }: { tone?: 'green' | 'red' | 'orange' | 'neutral' | 'dark' | 'outline'; children: ReactNode; className?: string; dot?: boolean }) {
  const tones = {
    green: 'v-badge-green', red: 'v-badge-red',
    orange: 'v-badge-orange', neutral: 'v-badge-neutral', dark: 'v-badge-dark', outline: 'v-badge-outline',
  };
  return (
    <span className={`${tones[tone]} inline-flex items-center gap-1.5 px-2.5 py-[3px] rounded-full text-[11px] font-semibold whitespace-nowrap ${className}`}>
      {dot && <span className="w-1.5 h-1.5 rounded-full" style={{ background: 'currentColor' }} />}
      {children}
    </span>
  );
}

export const AVATAR_TONES = [
  ['#fde8e1', '#c2410c'], ['#e8e4f7', '#5b4bb7'], ['#dcf3ea', '#11795a'], ['#fbeccc', '#a1620a'],
  ['#e2eefb', '#2d6aa8'], ['#f7e1ec', '#b03a72'],
];

export function Avatar({ name, id = 0, size = 36, ring }: { name: string; id?: number; size?: number; ring?: boolean }) {
  const parts = name.trim().split(/\s+/);
  const initials = (parts[0]?.[0] || '') + (parts[1]?.[0] || '');
  const [bg, fg] = AVATAR_TONES[Math.abs(id) % AVATAR_TONES.length];
  return (
    <div className="rounded-full flex items-center justify-center font-semibold shrink-0 avatar-chip"
      style={{ width: size, height: size, background: bg, color: fg, fontSize: size * 0.36, boxShadow: ring ? '0 0 0 2px var(--bg-card), 0 0 0 3.5px var(--accent-orange)' : undefined }}>
      {initials}
    </div>
  );
}

export function Field({ label, hint, error, required, children, className = '', aside }: { label: string; hint?: string; error?: string | null; required?: boolean; children: ReactNode; className?: string; aside?: ReactNode }) {
  return (
    <label className={`block ${className}`}>
      <div className="flex items-center justify-between mb-1.5">
        <span className="text-[12.5px] font-semibold" style={{ color: 'var(--text-primary)' }}>
          {label}{required && <span style={{ color: 'var(--accent-orange)' }}> *</span>}
        </span>
        {aside}
      </div>
      {children}
      {error ? <div className="text-[11.5px] mt-1.5 font-medium" style={{ color: 'var(--badge-red-text)' }}>{error}</div>
        : hint ? <div className="text-[11.5px] mt-1.5" style={{ color: 'var(--text-muted)' }}>{hint}</div> : null}
    </label>
  );
}

export function TextInput({ value, onChange, placeholder, icon, dir, type = 'text', disabled, error, suffix, className = '', ...rest }: {
  value: string | number | undefined;
  onChange: (v: string) => void;
  placeholder?: string;
  icon?: string;
  dir?: string;
  type?: string;
  disabled?: boolean;
  error?: string | boolean | null;
  suffix?: ReactNode;
  className?: string;
  [key: string]: unknown;
}) {
  return (
    <div className={`v-input flex items-center gap-2 px-3.5 h-11 ${error ? 'is-error' : ''} ${disabled ? 'is-disabled' : ''} ${className}`}>
      {icon && <Icon name={icon} className="w-4 h-4 shrink-0" style={{ color: 'var(--text-muted)' }} />}
      <input type={type} value={value ?? ''} disabled={disabled} dir={dir} placeholder={placeholder}
        onChange={e => onChange(e.target.value)}
        className="bg-transparent outline-none w-full text-[13px] h-full" style={{ color: 'var(--text-primary)' }} {...rest} />
      {suffix}
    </div>
  );
}

export function useOutside(ref: React.RefObject<HTMLElement | null>, cb: () => void, active: boolean) {
  useEffect(() => {
    if (!active) return;
    const h = (e: MouseEvent) => {
      // Document listeners see the shadow host as target; inspect the original path.
      if (ref.current && !e.composedPath().includes(ref.current)) cb();
    };
    document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, [active, cb, ref]);
}

export interface SelectOption {
  value: string | number | null;
  label: string;
  icon?: string;
  meta?: string;
  disabled?: boolean;
}

export function Select({ value, onChange, options, placeholder = 'اختر', searchable, icon, compact, renderValue, width, disabled }: {
  value: string | number | null;
  onChange: (v: any) => void;
  options: SelectOption[];
  placeholder?: string;
  searchable?: boolean;
  icon?: string;
  compact?: boolean;
  renderValue?: (cur?: SelectOption) => ReactNode;
  width?: number | string;
  disabled?: boolean;
}) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const ref = useRef<HTMLDivElement>(null);
  useOutside(ref, () => setOpen(false), open);
  const cur = options.find(o => o.value === value);
  const list = options.filter(o => !q || o.label.includes(q));
  return (
    <div ref={ref} className="relative" style={{ width }}>
      <button type="button" disabled={disabled} onClick={() => setOpen(!open)}
        className={compact ? 'v-subcard rounded-full px-3.5 h-9 flex items-center gap-2 text-[12.5px] font-medium w-full hover:opacity-90 transition'
          : `v-input flex items-center gap-2 px-3.5 h-11 w-full text-[13px] ${open ? 'is-focus' : ''}`}
        style={{ color: 'var(--text-primary)' }}>
        {icon && <Icon name={icon} className="w-3.5 h-3.5 shrink-0" style={{ color: 'var(--text-muted)' }} />}
        <span className="truncate flex-1 text-start" style={{ color: cur ? 'var(--text-primary)' : 'var(--text-muted)' }}>
          {renderValue ? renderValue(cur) : cur ? cur.label : placeholder}
        </span>
        <Icon name="chevDown" className={`w-3.5 h-3.5 shrink-0 transition-transform ${open ? 'rotate-180' : ''}`} style={{ color: 'var(--text-muted)' }} />
      </button>
      {open && (
        <div className="v-pop absolute z-40 mt-1.5 w-full min-w-[220px] p-1.5 start-0">
          {searchable && (
            <div className="v-subcard rounded-full px-3 h-9 flex items-center gap-2 mb-1.5">
              <Icon name="search" className="w-3.5 h-3.5" style={{ color: 'var(--text-muted)' }} />
              <input autoFocus value={q} onChange={e => setQ(e.target.value)} placeholder="بحث..." className="bg-transparent outline-none text-[12.5px] w-full" style={{ color: 'var(--text-primary)' }} />
            </div>
          )}
          <div className="max-h-[240px] overflow-y-auto">
            {list.length === 0 && <div className="text-[12px] px-3 py-3 text-center" style={{ color: 'var(--text-muted)' }}>لا توجد نتائج</div>}
            {list.map(o => (
              <button type="button" key={String(o.value)} disabled={o.disabled}
                onClick={() => { onChange(o.value); setOpen(false); setQ(''); }}
                className={`w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-[12.5px] text-start transition v-option ${o.value === value ? 'is-active' : ''} ${o.disabled ? 'opacity-40 cursor-not-allowed' : ''}`}>
                {o.icon && <Icon name={o.icon} className="w-3.5 h-3.5 shrink-0" style={{ color: 'var(--text-muted)' }} />}
                <span className="flex-1 truncate">{o.label}</span>
                {o.meta && <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{o.meta}</span>}
                {o.value === value && <Icon name="check" className="w-3.5 h-3.5" style={{ color: 'var(--accent-orange)' }} strokeWidth={2.4} />}
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

export interface MultiSelectOption {
  value: number | string;
  label: string;
  system?: boolean;
  disabled?: boolean;
  note?: string;
}

export function MultiSelect({ values, onChange, options, placeholder = 'اختر', disabled }: {
  values: (number | string)[];
  onChange: (v: (number | string)[]) => void;
  options: MultiSelectOption[];
  placeholder?: string;
  disabled?: boolean;
}) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const ref = useRef<HTMLDivElement>(null);
  useOutside(ref, () => setOpen(false), open);
  const toggle = (v: number | string) => {
    if (disabled || options.find(option => option.value === v)?.disabled) return;
    onChange(values.includes(v) ? values.filter(x => x !== v) : [...values, v]);
  };
  const list = options.filter(o => !q || o.label.includes(q));
  return (
    <div ref={ref} className="relative">
      <div onClick={() => !disabled && setOpen(true)}
        className={`v-input flex flex-wrap items-center gap-1.5 px-2 py-1.5 min-h-[44px] cursor-text ${open ? 'is-focus' : ''} ${disabled ? 'is-disabled' : ''}`}>
        {values.map(v => {
          const o = options.find(x => x.value === v); if (!o) return null;
          return (
            <span key={v} className="v-chip inline-flex items-center gap-1 ps-2.5 pe-1 h-7 rounded-full text-[12px] font-semibold">
              {o.system && <Icon name="shieldCheck" className="w-3 h-3" style={{ color: 'var(--accent-orange)' }} />}
              {o.label}
              {!disabled && !o.disabled && (
                <button type="button" onClick={e => { e.stopPropagation(); toggle(v); }} className="w-5 h-5 rounded-full flex items-center justify-center hover:opacity-70">
                  <Icon name="close" className="w-3 h-3" strokeWidth={2.4} />
                </button>
              )}
            </span>
          );
        })}
        <input value={q} disabled={disabled} onChange={e => { setQ(e.target.value); setOpen(true); }} onFocus={() => setOpen(true)}
          placeholder={values.length ? '' : placeholder}
          className="bg-transparent outline-none text-[13px] flex-1 min-w-[80px] px-1.5 h-7" style={{ color: 'var(--text-primary)' }} />
      </div>
      {open && !disabled && (
        <div className="v-pop absolute z-40 mt-1.5 w-full p-1.5 max-h-[260px] overflow-y-auto">
          {list.map(o => {
            const on = values.includes(o.value);
            return (
              <button type="button" key={o.value} disabled={o.disabled} onClick={() => toggle(o.value)}
                className={`w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-[12.5px] text-start v-option ${o.disabled ? 'opacity-40 cursor-not-allowed' : ''}`}>
                <Checkbox checked={on} />
                <span className="flex-1">{o.label}</span>
                {o.system && <Badge tone="orange">نظام</Badge>}
                {o.note && <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{o.note}</span>}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

export function Checkbox({ checked, indeterminate, onChange, disabled, size = 18 }: {
  checked?: boolean;
  indeterminate?: boolean;
  onChange?: (val: boolean) => void;
  disabled?: boolean;
  size?: number;
}) {
  const on = checked || indeterminate;
  return (
    <span role="checkbox" aria-checked={indeterminate ? 'mixed' : !!checked}
      onClick={e => { if (onChange && !disabled) { e.stopPropagation(); onChange(!checked); } }}
      className={`inline-flex items-center justify-center shrink-0 transition-all duration-150 ${disabled ? 'opacity-50' : 'cursor-pointer'}`}
      style={{ width: size, height: size, borderRadius: 6, background: on ? 'var(--accent-orange)' : 'transparent', border: on ? '1.5px solid var(--accent-orange)' : '1.5px solid var(--checkbox-border)' }}>
      {checked && !indeterminate && <Icon name="check" className="w-3 h-3 text-white" strokeWidth={3} />}
      {indeterminate && <span className="w-2 h-[2px] rounded bg-white" />}
    </span>
  );
}

export interface SegmentedOption {
  value: string | number;
  label: string;
  count?: number;
}

export function Segmented({ value, onChange, options, size = 'md' }: {
  value: string | number;
  onChange: (val: any) => void;
  options: SegmentedOption[];
  size?: 'sm' | 'md';
}) {
  return (
    <div className="v-subcard inline-flex p-1 rounded-full">
      {options.map(o => {
        const active = o.value === value;
        return (
          <button type="button" key={String(o.value)} onClick={() => onChange(o.value)}
            className={`${size === 'sm' ? 'px-3 py-1 text-[11.5px]' : 'px-3.5 py-1.5 text-[12px]'} rounded-full font-semibold transition-all ${active ? 'shadow-xs' : ''}`}
            style={{ background: active ? 'var(--seg-active-bg)' : 'transparent', color: active ? 'var(--text-primary)' : 'var(--text-secondary)' }}>
            {o.label}{o.count != null && <span className="ms-1.5 opacity-60 font-medium">{o.count}</span>}
          </button>
        );
      })}
    </div>
  );
}

export function PrimaryButton({ children, onClick, icon, className = '', disabled, type = 'button', loading }: {
  children: ReactNode;
  onClick?: () => void;
  icon?: string;
  className?: string;
  disabled?: boolean;
  type?: 'button' | 'submit' | 'reset';
  loading?: boolean;
}) {
  return (
    <button type={type} onClick={onClick} disabled={disabled || loading}
      className={`h-10 px-5 rounded-full text-[13px] font-semibold flex items-center justify-center gap-2 transition hover:opacity-90 disabled:opacity-50 ${className}`}
      style={{ background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' }}>
      {loading ? <span className="v-spinner" /> : icon && <Icon name={icon} className="w-4 h-4" strokeWidth={2.2} />}
      <span>{children}</span>
    </button>
  );
}

export function AccentButton({ children, onClick, icon, className = '', disabled, loading }: {
  children: ReactNode;
  onClick?: () => void;
  icon?: string;
  className?: string;
  disabled?: boolean;
  loading?: boolean;
}) {
  return (
    <button type="button" onClick={onClick} disabled={disabled || loading}
      className={`h-10 px-5 rounded-full text-[13px] font-semibold flex items-center justify-center gap-2 transition hover:brightness-105 disabled:opacity-50 text-white v-accent-btn ${className}`}>
      {loading ? <span className="v-spinner is-light" /> : icon && <Icon name={icon} className="w-4 h-4" strokeWidth={2.2} />}
      <span>{children}</span>
    </button>
  );
}

export function GhostButton({ children, onClick, icon, className = '', disabled }: {
  children: ReactNode;
  onClick?: () => void;
  icon?: string;
  className?: string;
  disabled?: boolean;
}) {
  return (
    <button type="button" onClick={onClick} disabled={disabled}
      className={`v-subcard h-10 px-4 rounded-full text-[13px] font-semibold flex items-center justify-center gap-2 transition hover:opacity-85 disabled:opacity-50 ${className}`}
      style={{ color: 'var(--text-primary)' }}>
      {icon && <Icon name={icon} className="w-4 h-4" />}
      <span>{children}</span>
    </button>
  );
}

export function IconButton({ icon, onClick, title, disabled, tone, className = '' }: {
  icon: string;
  onClick?: () => void;
  title?: string;
  disabled?: boolean;
  tone?: 'danger' | 'neutral';
  className?: string;
}) {
  return (
    <button type="button" disabled={disabled} title={title} onClick={onClick}
      className={`w-8 h-8 rounded-full v-subcard flex items-center justify-center hover:opacity-80 transition ${className}`}
      style={{ color: tone === 'danger' ? 'var(--badge-red-text)' : 'var(--text-secondary)' }}>
      <Icon name={icon} className="w-3.5 h-3.5" strokeWidth={2} />
    </button>
  );
}

export function PageHeader({ title, subtitle, action }: { title: string; subtitle?: string; action?: ReactNode }) {
  return (
    <div className="stagger-item stagger-1 pt-1 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 className="font-display text-[34px] sm:text-[38px] font-semibold tracking-[-0.01em] leading-tight" style={{ color: 'var(--text-primary)' }}>{title}</h1>
        {subtitle && <p className="text-[13.5px] mt-1" style={{ color: 'var(--text-secondary)' }}>{subtitle}</p>}
      </div>
      {action}
    </div>
  );
}

export function StickyBar({ show, onSave, onReset, saving, message = 'لديك تغييرات غير محفوظة', count }: {
  show: boolean;
  onSave: () => void;
  onReset: () => void;
  saving?: boolean;
  message?: string;
  count?: number | null;
}) {
  return (
    <div className={`fixed bottom-6 inset-x-0 z-40 flex justify-center pointer-events-none transition-all duration-300 ${show ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6'}`}>
      <div className={`v-sticky-bar rounded-full ps-5 pe-1.5 py-1.5 flex items-center gap-4 ${show ? 'pointer-events-auto' : ''}`}>
        <span className="w-2 h-2 rounded-full bg-[var(--accent-orange)] v-pulse" />
        <span className="text-[13px] font-medium whitespace-nowrap">{message}{count ? <span className="opacity-60"> · {count} تعديل</span> : null}</span>
        <div className="flex items-center gap-1.5">
          <button type="button" onClick={onReset} className="h-9 px-4 rounded-full text-[12.5px] font-semibold flex items-center gap-1.5 hover:bg-white/10 transition v-sticky-ghost">
            <Icon name="undo" className="w-3.5 h-3.5" /> تراجع
          </button>
          <button type="button" onClick={onSave} disabled={saving} className="h-9 px-5 rounded-full text-[12.5px] font-semibold flex items-center gap-2 text-white v-accent-btn">
            {saving ? <span className="v-spinner is-light" /> : <Icon name="check" className="w-3.5 h-3.5" strokeWidth={2.6} />}
            حفظ التغييرات
          </button>
        </div>
      </div>
    </div>
  );
}

export function Skeleton({ className = '', style }: { className?: string; style?: CSSProperties | null }) {
  return <div className={`v-skeleton ${className}`} style={style || undefined} />;
}

export function EmptyState({ icon = 'search', title, text, action }: { icon?: string; title: string; text?: string; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center text-center py-14 px-6">
      <div className="relative mb-4">
        <div className="w-16 h-16 rounded-[20px] v-subcard flex items-center justify-center" style={{ color: 'var(--text-muted)' }}>
          <Icon name={icon} className="w-7 h-7" strokeWidth={1.6} />
        </div>
        <span className="absolute -bottom-1 -start-1 w-6 h-6 rounded-full flex items-center justify-center text-white v-accent-btn" style={{ boxShadow: '0 0 0 3px var(--bg-card)' }}>
          <Icon name="close" className="w-3 h-3" strokeWidth={3} />
        </span>
      </div>
      <div className="font-display text-[15px] font-semibold" style={{ color: 'var(--text-primary)' }}>{title}</div>
      {text && <div className="text-[12.5px] mt-1 max-w-[320px]" style={{ color: 'var(--text-secondary)' }}>{text}</div>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

export function Notice({ tone = 'info', icon, title, children, action, className = '' }: {
  tone?: 'info' | 'warn' | 'danger';
  icon?: string;
  title?: string;
  children: ReactNode;
  action?: ReactNode;
  className?: string;
}) {
  const map = { warn: 'v-notice-warn', info: 'v-notice-info', danger: 'v-notice-danger' };
  return (
    <div className={`${map[tone]} rounded-2xl px-4 py-3 flex items-start gap-3 ${className}`}>
      <Icon name={icon || (tone === 'info' ? 'info' : 'alert')} className="w-4 h-4 mt-0.5 shrink-0" strokeWidth={2} />
      <div className="flex-1 text-[12.5px] leading-relaxed">
        {title && <div className="font-semibold mb-0.5">{title}</div>}
        <div className="opacity-90">{children}</div>
      </div>
      {action}
    </div>
  );
}

// Toast System
interface ToastItem {
  id: number;
  text: string;
  tone: 'ok' | 'warn' | 'err';
}

export const toastBus = { fn: null as ((t: ToastItem) => void) | null };

export function toast(text: string, tone: 'ok' | 'warn' | 'err' = 'ok') {
  toastBus.fn && toastBus.fn({ text, tone, id: Date.now() });
}

export function ToastHost() {
  const [t, setT] = useState<ToastItem | null>(null);
  useEffect(() => {
    toastBus.fn = v => {
      setT(v);
      setTimeout(() => setT(c => (c && c.id === v.id ? null : c)), 2600);
    };
  }, []);
  return (
    <div className={`fixed top-6 inset-x-0 z-[70] flex justify-center pointer-events-none transition-all duration-300 ${t ? 'opacity-100 translate-y-0' : 'opacity-0 -translate-y-2'}`}>
      <div className="px-4 py-2.5 rounded-full shadow-lg text-[12.5px] font-medium flex items-center gap-2" style={{ background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' }}>
        <span className="w-2 h-2 rounded-full" style={{ background: t?.tone === 'err' ? '#fb7185' : t?.tone === 'warn' ? '#f59e0b' : '#34d399' }} />
        <span>{t?.text}</span>
      </div>
    </div>
  );
}

export function Drawer({ open, onClose, children, width = 520 }: { open: boolean; onClose: () => void; children: ReactNode; width?: number }) {
  useEffect(() => {
    if (!open) return;
    const h = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', h);
    return () => window.removeEventListener('keydown', h);
  }, [open, onClose]);

  return (
    <div className={`fixed inset-0 z-50 ${open ? '' : 'pointer-events-none'}`}>
      <div onClick={onClose} className={`absolute inset-0 v-scrim transition-opacity duration-300 ${open ? 'opacity-100' : 'opacity-0'}`} />
      <div className={`absolute top-3 bottom-3 start-3 v-card v-drawer flex flex-col transition-transform duration-[420ms] ${open ? 'translate-x-0' : 'translate-x-[calc(100%+24px)]'}`}
        style={{ width: `min(${width}px, calc(100vw - 24px))`, transitionTimingFunction: 'cubic-bezier(0.22,1,0.36,1)' }}>
        {children}
      </div>
    </div>
  );
}

export function Modal({ open, onClose, children, width = 420 }: { open: boolean; onClose: () => void; children: ReactNode; width?: number }) {
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
      <div onClick={onClose} className="absolute inset-0 v-scrim v-fade" />
      <div className="relative v-card p-6 v-pop-in" style={{ width: `min(${width}px, 100%)` }}>{children}</div>
    </div>
  );
}

export interface ActionMenuItem {
  icon?: string;
  label?: string;
  onClick?: () => void;
  divider?: boolean;
  disabled?: boolean;
  title?: string;
  danger?: boolean;
}

export function ActionMenu({ items }: { items: ActionMenuItem[] }) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  useOutside(ref, () => setOpen(false), open);
  return (
    <div ref={ref} className="relative inline-block">
      <IconButton icon="more" onClick={() => setOpen(!open)} title="الإجراءات" />
      {open && (
        <div className="v-pop absolute z-30 mt-1.5 end-0 w-[200px] p-1.5">
          {items.map((it, i) => it.divider ? <div key={i} className="my-1 h-px" style={{ background: 'var(--divider-color)' }} /> : (
            <button type="button" key={i} disabled={it.disabled} title={it.title}
              onClick={() => { setOpen(false); it.onClick?.(); }}
              className={`w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-[12.5px] text-start v-option ${it.disabled ? 'opacity-40 cursor-not-allowed' : ''}`}
              style={{ color: it.danger ? 'var(--badge-red-text)' : 'var(--text-primary)' }}>
              {it.icon && <Icon name={it.icon} className="w-3.5 h-3.5" />}
              <span className="flex-1">{it.label}</span>
              {it.disabled && <Icon name="lock" className="w-3 h-3 opacity-70" />}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

export const fmtDate = (d: string | number | Date) =>
  new Date(d).toLocaleDateString('ar-EG-u-nu-latn', { day: 'numeric', month: 'short', year: 'numeric' });

