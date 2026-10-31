// أيقونات خطية خفيفة (inline SVG) — بلا مكتبات خارجية.
// كلها بنفس السُمك والحجم عشان التناسق البصري.
const paths: Record<string, string> = {
  plus: 'M12 5v14M5 12h14',
  search: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM20 20l-3.5-3.5',
  menu: 'M4 7h16M4 12h16M4 17h10',
  x: 'M6 6l12 12M18 6L6 18',
  pin: 'M9 4h6l-1 5 3 3v2H7v-2l3-3-1-5zM12 14v6',
  trash: 'M5 7h14M10 7V5h4v2M7 7l1 12h8l1-12M10 11v5M14 11v5',
  send: 'M12 19V6M6 11l6-6 6 6',
  copy: 'M9 9h10v10H9zM5 15V5h10',
  check: 'M5 12.5l4.5 4.5L19 7',
  alert: 'M12 4l9 16H3l9-16zM12 10v4M12 17.5v.01',
  shield: 'M12 3l8 3v6c0 4.5-3.4 8.2-8 9-4.6-.8-8-4.5-8-9V6l8-3zM9 12l2 2 4-4',
  clock: 'M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16zM12 8v4l3 2',
  undo: 'M9 14L4 9l5-5M4 9h10a6 6 0 0 1 0 12h-3',
  external: 'M14 4h6v6M20 4l-9 9M18 14v6H4V6h6',
  chevron: 'M6 9l6 6 6-6',
  chevronStart: 'M9 6l6 6-6 6',
  spark: 'M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3zM18.5 15.5l.7 1.8 1.8.7-1.8.7-.7 1.8-.7-1.8-1.8-.7 1.8-.7.7-1.8z',
  chart: 'M4 20h16M7 16v-5M12 16V7M17 16v-8',
  chat: 'M5 5h14v10H9l-4 4V5z',
  user: 'M12 4a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM4 20c1.5-3.5 4.5-5 8-5s6.5 1.5 8 5',
  branch: 'M4 20V9l8-5 8 5v11M9 20v-6h6v6M3 20h18',
  role: 'M8.5 14a4.5 4.5 0 1 1 3.9-6.8L20 7v3h-2v2h-2.5M8.5 9.5v.01',
  command: 'M15 4L9 20',
  arrowDown: 'M12 5v14M6 13l6 6 6-6',
  eye: 'M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12zM12 9.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z',
  eyeOff: 'M3 3l18 18M10.6 6a9.6 9.6 0 0 1 1.4-.1c6 0 9.5 6.1 9.5 6.1a16 16 0 0 1-2.6 3.3M6.6 7.2C4 9 2.5 12 2.5 12s3.5 6.5 9.5 6.5c1.6 0 3-.4 4.2-1',
  refresh: 'M20 11a8 8 0 1 0-2.3 5.7M20 5v6h-6',
  sidebar: 'M4 5h16v14H4zM15 5v14',
  lock: 'M7 11V8a5 5 0 0 1 10 0v3M5 11h14v9H5zM12 15v2',
  key: 'M8.5 14a4.5 4.5 0 1 1 3.9-6.8L20 7v3h-2v2h-2.5',
  layers: 'M12 4l9 5-9 5-9-5 9-5zM3 14l9 5 9-5',
  bolt: 'M13 3L5 13h6l-1 8 8-10h-6l1-8z',
  minus: 'M6 12h12',
  info: 'M12 4a8 8 0 1 0 0 16 8 8 0 0 0 0-16zM12 11v5M12 8v.01',
  settings: 'M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6zM19 12a7 7 0 0 0-.1-1.2l2-1.5-2-3.4-2.3 1a7 7 0 0 0-2-1.2L14.2 3h-4l-.4 2.6a7 7 0 0 0-2 1.2l-2.4-1-2 3.4 2 1.5a7 7 0 0 0 0 2.4l-2 1.5 2 3.4 2.4-1a7 7 0 0 0 2 1.2l.4 2.6h4l.4-2.6a7 7 0 0 0 2-1.2l2.3 1 2-3.4-2-1.5c.1-.4.1-.8.1-1.2z',
};

export function Icon({ name, size = 18, className }: { name: keyof typeof paths | string; size?: number; className?: string }) {
  return (
    <svg
      className={`as-icon${className ? ` ${className}` : ''}`}
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.8}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      <path d={paths[name] ?? paths.info} />
    </svg>
  );
}

export const entityIcon: Record<string, string> = { user: 'user', branch: 'branch', role: 'role', command: 'command' };
export const entityLabel: Record<string, string> = { user: 'موظف', branch: 'فرع', role: 'دور', command: 'أمر' };

// حلقة عدّاد تنازلي — fraction من 0 لـ 1.
export function Ring({ fraction, size = 22 }: { fraction: number; size?: number }) {
  const r = 9;
  const c = 2 * Math.PI * r;
  const f = Math.max(0, Math.min(1, fraction));
  return (
    <svg className="as-ring" width={size} height={size} viewBox="0 0 24 24" aria-hidden="true">
      <circle cx="12" cy="12" r={r} fill="none" stroke="currentColor" strokeOpacity={0.18} strokeWidth={3} />
      <circle
        cx="12" cy="12" r={r} fill="none" stroke="currentColor" strokeWidth={3} strokeLinecap="round"
        strokeDasharray={c} strokeDashoffset={c * (1 - f)} transform="rotate(-90 12 12)"
        style={{ transition: 'stroke-dashoffset 1s linear' }}
      />
    </svg>
  );
}
