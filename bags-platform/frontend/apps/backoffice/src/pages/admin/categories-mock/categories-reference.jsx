// Categories design retained from task.txt; catalog data comes from the authenticated API.
import React, { useState, useEffect, useRef, useMemo, useCallback, useId } from 'react';
import { categoriesApi } from '@alfarid/api-client';
import { IMG } from './categories.mock';
import { useCategoriesContext as useApp } from './categories-context';
import { toUiTree, toPayload, buildReorderItems } from './categories-adapter';
import { parseApiError } from './api-errors';
import { normalizeSearch, displayName, autoSlug, toLatinDigits } from './i18n-utils';

const cx = (...a) => a.filter(Boolean).join(' ');

function vtoast(text, kind = 'success') {
  window.dispatchEvent(new CustomEvent('vtoast', { detail: { text, kind, id: Math.random() } }));
}

export function ToastHost() {
  const [items, setItems] = useState([]);
  useEffect(() => {
    const h = (e) => {
      const t = e.detail;
      setItems((x) => [...x, t]);
      setTimeout(() => setItems((x) => x.filter((i) => i.id !== t.id)), 2800);
    };
    window.addEventListener('vtoast', h);
    return () => window.removeEventListener('vtoast', h);
  }, []);
  return (
    <div className="fixed bottom-6 left-6 z-[80] flex flex-col gap-2 items-start pointer-events-none">
      {items.map((t) => (
        <div key={t.id} className="pop px-4 py-2.5 rounded-full shadow-lg text-[12.5px] font-medium flex items-center gap-2 bg-ink">
          <span className={cx('w-2 h-2 rounded-full', t.kind === 'error' ? 'bg-rose-500' : t.kind === 'info' ? 'bg-sky-400' : 'bg-[var(--accent)]')} />
          {t.text}
        </div>
      ))}
    </div>
  );
}

function Badge({ tone = 'gray', children, dot, className = '' }) {
  return (
    <span className={cx('inline-flex items-center gap-1.5 px-2.5 py-[3px] rounded-full text-[11px] font-semibold whitespace-nowrap', 'b-' + tone, className)}>
      {dot && <span className="w-1.5 h-1.5 rounded-full bg-current" />}
      {children}
    </span>
  );
}

const STATUS_MAP = {
  active: ['green', 'نشط'], inactive: ['gray', 'غير نشط'],
  draft: ['amber', 'مسودة'], published: ['green', 'منشور'], archived: ['gray', 'مؤرشف'],
};

function StatusBadge({ s }) { const [t, l] = STATUS_MAP[s] || ['gray', s]; return <Badge tone={t} dot>{l}</Badge>; }

function Spinner({ size = 16, className = '' }) {
  return <svg width={size} height={size} viewBox="0 0 24 24" className={cx('spin', className)}><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" strokeOpacity=".2" strokeWidth="3"/><path d="M21 12a9 9 0 00-9-9" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round"/></svg>;
}

function Toggle({ on, onChange, disabled }) {
  return <button type="button" role="switch" aria-checked={on} disabled={disabled} onClick={() => !disabled && onChange && onChange(!on)} className={cx('tgl', on && 'on', disabled && 'dis')}><span /></button>;
}

function Check({ on, mixed, onChange, disabled }) {
  return (
    <button type="button" role="checkbox" aria-checked={mixed ? 'mixed' : on} disabled={disabled} onClick={(e) => { e.stopPropagation(); onChange && onChange(!on); }} className={cx('chk', (on || mixed) && 'on', disabled && 'opacity-40')}>
      {mixed ? <Icon name="minus" size={12} sw={3} /> : on ? <Icon name="check" size={12} sw={3} /> : null}
    </button>
  );
}

function Seg({ value, options, onChange, size }) {
  return (
    <div className="seg">
      {options.map((o) => {
        const v = typeof o === 'string' ? o : o.value; const l = typeof o === 'string' ? o : o.label;
        return <button key={v} type="button" className={cx(value === v && 'on', size === 'lg' && '!px-5 !py-2 !text-[13px]')} onClick={() => onChange(v)}>{l}</button>;
      })}
    </div>
  );
}

function Skel({ w = '100%', h = 12, r, className = '' }) { return <div className={cx('skel', className)} style={{ width: w, height: h, borderRadius: r }} />; }

function Field({ label, required, hint, error, children, className = '', aside, id }) {
  const labelId = useId();
  return (
    <div className={cx('flex flex-col gap-1.5', className)} data-field={id} role="group" aria-labelledby={label ? labelId : undefined}>
      {label && (
        <div className="flex items-center justify-between gap-2">
          <label id={labelId} className="text-[12.5px] font-semibold t-1 flex items-center gap-1">
            {label}
            {required && <span className="t-acc text-[13px] leading-none" title="مطلوب">*</span>}
          </label>
          {aside}
        </div>
      )}
      {children}
      {error ? (
        <div className="flex items-start gap-1.5 text-[11.5px] font-medium" style={{ color: 'var(--badge-red-text)' }}>
          <Icon name="alertCircle" size={13} className="mt-[1px]" />
          <span>{error}</span>
        </div>
      ) : hint ? <div className="text-[11.5px] t-3 leading-relaxed text-pretty">{hint}</div> : null}
    </div>
  );
}

function Input({ error, ltr, icon, suffix, className = '', readOnly, ...p }) {
  if (icon || suffix) {
    return (
      <div className="relative">
        {icon && <Icon name={icon} size={15} className="absolute top-1/2 -translate-y-1/2 start-3.5 t-3 pointer-events-none" />}
        <input {...p} readOnly={readOnly} className={cx('v-input', error && 'is-error', ltr && 'ltr', icon && 'ps-10', suffix && 'pe-14', className)} />
        {suffix && <span className="absolute top-1/2 -translate-y-1/2 end-3.5 text-[11.5px] font-semibold t-3">{suffix}</span>}
      </div>
    );
  }
  return <input {...p} readOnly={readOnly} className={cx('v-input', error && 'is-error', ltr && 'ltr', className)} />;
}

function Textarea({ error, className = '', ...p }) { return <textarea rows={3} {...p} className={cx('v-input', error && 'is-error', className)} />; }

function useOutside(ref, cb, active) {
  useEffect(() => {
    if (!active) return;
    const h = (e) => { if (ref.current && !e.composedPath().includes(ref.current)) cb(); };
    document.addEventListener('mousedown', h);
    return () => document.removeEventListener('mousedown', h);
  }, [active]);
}

function SearchSelect({ value, onChange, options = [], placeholder = 'اختر…', error, disabled, loading: forceLoading, emptyText, multi, clearable = true, renderOption, asyncSim = true, searchPlaceholder = 'ابحث…' }) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const [loading, setLoading] = useState(false);
  const [hi, setHi] = useState(0);
  const ref = useRef(null);
  useOutside(ref, () => setOpen(false), open);
  useEffect(() => {
    if (!open || !asyncSim) return;
    setLoading(true);
    const t = setTimeout(() => setLoading(false), q ? 420 : 300);
    return () => clearTimeout(t);
  }, [q, open]);
  const isLoading = forceLoading || loading;
  const list = options.filter((o) => !q || (o.label + ' ' + (o.sub || '')).toLowerCase().includes(q.toLowerCase()));
  const selected = multi ? options.filter((o) => (value || []).includes(o.value)) : options.find((o) => o.value === value);
  const pick = (o) => {
    if (multi) {
      const cur = value || [];
      onChange(cur.includes(o.value) ? cur.filter((v) => v !== o.value) : [...cur, o.value]);
    } else { onChange(o.value); setOpen(false); setQ(''); }
  };
  const onKey = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setHi((h) => Math.min(h + 1, list.length - 1)); }
    if (e.key === 'ArrowUp') { e.preventDefault(); setHi((h) => Math.max(h - 1, 0)); }
    if (e.key === 'Enter' && list[hi] && !list[hi].disabled) { e.preventDefault(); pick(list[hi]); }
    if (e.key === 'Escape') setOpen(false);
  };
  return (
    <div className="relative" ref={ref}>
      <button type="button" disabled={disabled} onClick={() => setOpen(!open)}
        className={cx('v-input flex items-center justify-between gap-2 text-start', open && 'is-focus', error && 'is-error', disabled && 'is-disabled', multi && selected.length && '!h-auto min-h-[42px] py-1.5')}>
        <span className="flex-1 min-w-0 flex items-center gap-1.5 flex-wrap">
          {multi ? (selected.length ? selected.map((s) => (
            <span key={s.value} className="inline-flex items-center gap-1 ps-2.5 pe-1 h-7 rounded-full text-[12px] font-medium" style={{ background: 'var(--bg-card)', border: '1px solid var(--border-field)' }}>
              {s.label}
              <span role="button" onClick={(e) => { e.stopPropagation(); pick(s); }} className="w-5 h-5 rounded-full inline-flex items-center justify-center hover:bg-[var(--bg-subcard-hover)] t-3"><Icon name="x" size={11} /></span>
            </span>
          )) : <span className="t-3">{placeholder}</span>)
            : selected ? <span className="truncate flex items-center gap-2">{selected.icon}{selected.label}{selected.sub && <span className="t-3 text-[11.5px]">· {selected.sub}</span>}</span>
            : <span className="t-3">{placeholder}</span>}
        </span>
        {!multi && clearable && selected && !disabled && (
          <span role="button" onClick={(e) => { e.stopPropagation(); onChange(null); }} className="w-6 h-6 rounded-full inline-flex items-center justify-center t-3 hover:bg-[var(--bg-subcard-hover)]"><Icon name="x" size={12} /></span>
        )}
        <Icon name="chevDown" size={14} className={cx('t-3 transition-transform', open && 'rotate-180')} />
      </button>
      {open && (
        <div className="pop absolute z-40 mt-2 w-full min-w-[260px] v-card p-2" style={{ boxShadow: 'var(--shadow-pop)', borderRadius: 18 }}>
          <div className="relative mb-1.5">
            <Icon name="search" size={14} className="absolute top-1/2 -translate-y-1/2 start-3 t-3" />
            <input autoFocus value={q} onChange={(e) => { setQ(e.target.value); setHi(0); }} onKeyDown={onKey} placeholder={searchPlaceholder}
              className="w-full h-9 rounded-xl ps-9 pe-8 text-[12.5px] outline-none" style={{ background: 'var(--bg-subcard)', color: 'var(--text-primary)' }} />
            {isLoading && <Spinner size={14} className="absolute top-1/2 -translate-y-1/2 end-3 t-acc" />}
          </div>
          <div className="max-h-[248px] overflow-y-auto">
            {isLoading ? (
              <div className="p-2 space-y-3">{[0, 1, 2].map((i) => <div key={i} className="flex items-center gap-2.5"><Skel w={22} h={22} r={8} /><Skel w={`${70 - i * 15}%`} h={10} /></div>)}</div>
            ) : list.length === 0 ? (
              <div className="py-6 px-3 text-center">
                <div className="w-10 h-10 rounded-full v-well mx-auto flex items-center justify-center mb-2"><Icon name="search" size={16} /></div>
                <div className="text-[12.5px] font-semibold t-1">{emptyText || 'لا توجد نتائج'}</div>
                {q && <div className="text-[11.5px] t-3 mt-0.5">لا شيء يطابق «{q}»</div>}
              </div>
            ) : list.map((o, i) => {
              const sel = multi ? (value || []).includes(o.value) : o.value === value;
              return (
                <button type="button" key={o.value} onMouseEnter={() => setHi(i)} onClick={() => !o.disabled && pick(o)} disabled={o.disabled}
                  className={cx('w-full flex items-center gap-2.5 px-2.5 py-2 rounded-xl text-start text-[12.5px] transition', hi === i && 'bg-[var(--bg-subcard)]', o.disabled && 'opacity-40 cursor-not-allowed')}
                  style={{ paddingInlineStart: o.depth ? 10 + o.depth * 16 : undefined }}>
                  {multi && <Check on={sel} />}
                  {o.icon}
                  <span className="flex-1 min-w-0">
                    <span className={cx('block truncate', sel ? 'font-semibold t-1' : 't-1')}>{o.label}</span>
                    {o.sub && <span className="block text-[11px] t-3 truncate">{o.sub}</span>}
                  </span>
                  {o.note && <span className="text-[10.5px] t-3">{o.note}</span>}
                  {!multi && sel && <Icon name="check" size={14} className="t-acc" sw={2.4} />}
                </button>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}

function Modal({ open, onClose, children, width = 460, title, subtitle, icon, iconTone = 'acc', footer }) {
  const titleId = useId();
  useEffect(() => {
    if (!open) return;
    const h = (e) => e.key === 'Escape' && onClose && onClose();
    window.addEventListener('keydown', h); return () => window.removeEventListener('keydown', h);
  }, [open, onClose]);
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-[70] flex items-center justify-center p-4 fade" style={{ background: 'var(--overlay)', backdropFilter: 'blur(3px)' }} onMouseDown={(e) => e.target === e.currentTarget && onClose && onClose()}>
      <div role="dialog" aria-modal="true" aria-labelledby={titleId} className="pop v-card w-full overflow-hidden" style={{ maxWidth: width, boxShadow: 'var(--shadow-pop)', borderRadius: 26 }}>
        {(title || icon) && (
          <div className="p-6 pb-0 flex items-start gap-3.5">
            {icon && <span className={cx('w-11 h-11 rounded-full flex items-center justify-center shrink-0', 'b-' + iconTone)}><Icon name={icon} size={19} /></span>}
            <div className="flex-1 min-w-0 pt-0.5">
              <h3 id={titleId} className="font-display text-[17px] font-semibold t-1">{title}</h3>
              {subtitle && <p className="text-[13px] t-2 mt-1 leading-relaxed text-pretty">{subtitle}</p>}
            </div>
            {onClose && <button className="icon-btn -mt-1 -me-1" onClick={onClose}><Icon name="x" size={16} /></button>}
          </div>
        )}
        <div className="p-6">{children}</div>
        {footer && <div className="px-6 pb-6 -mt-2 flex items-center justify-end gap-2">{footer}</div>}
      </div>
    </div>
  );
}

function ConfirmDialog({ open, onClose, title, message, confirmLabel = 'تأكيد', danger, onConfirm, serverError, icon, extra }) {
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);
  useEffect(() => { if (open) { setErr(serverError || null); setBusy(false); } }, [open]);
  const go = async () => {
    setBusy(true); setErr(null);
    try {
      if (serverError) { setErr(serverError); return; }
      if (onConfirm) await onConfirm();
      onClose();
    } catch (e) {
      setErr(e?.title ? e : { title: 'تعذّر تنفيذ العملية', body: e?.message || String(e), items: e?.items || [] });
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal open={open} onClose={busy ? null : onClose} title={title} subtitle={message} icon={icon || (danger ? 'trash' : 'alert')} iconTone={danger ? 'red' : 'amber'}>
      {err && (
        <div className="pop rounded-2xl p-3.5 mb-1 flex gap-3 b-red">
          <Icon name="ban" size={17} className="mt-0.5" />
          <div className="text-[12.5px] leading-relaxed">
            <div className="font-semibold">{err.title}</div>
            <div className="opacity-90 mt-0.5">{err.body}</div>
            {err.items && <ul className="mt-2 space-y-1">{err.items.map((i) => <li key={i} className="flex items-center gap-1.5"><span className="w-1 h-1 rounded-full bg-current" />{i}</li>)}</ul>}
          </div>
        </div>
      )}
      {extra}
      <div className="flex items-center justify-end gap-2 mt-3">
        <button className="btn btn-soft" onClick={onClose} disabled={busy}>{err ? 'إغلاق' : 'إلغاء'}</button>
        {!err && <button className={cx('btn', danger ? 'btn-acc' : 'btn-ink')} style={danger ? { background: 'var(--badge-red-text)', boxShadow: 'none' } : null} onClick={go} disabled={busy}>
          {busy && <Spinner size={14} />}{confirmLabel}
        </button>}
      </div>
    </Modal>
  );
}

function StateBlock({ icon = 'folder', title, body, action, tone = 'gray', compact }) {
  return (
    <div className={cx('flex flex-col items-center text-center', compact ? 'py-10' : 'py-16')}>
      <div className="relative mb-4">
        <div className="w-16 h-16 rounded-full hatched-track opacity-70 absolute -inset-2 m-auto" style={{ width: 80, height: 80, top: -8, left: -8 }} />
        <div className={cx('relative w-16 h-16 rounded-full flex items-center justify-center', 'b-' + tone)} style={tone === 'gray' ? { background: 'var(--bg-card)', border: '1px solid var(--border-subtle)', color: 'var(--text-secondary)' } : null}>
          <Icon name={icon} size={24} />
        </div>
      </div>
      <div className="font-display text-[16px] font-semibold t-1">{title}</div>
      {body && <div className="text-[13px] t-2 mt-1 max-w-[380px] leading-relaxed text-pretty">{body}</div>}
      {action && <div className="mt-5 flex items-center gap-2">{action}</div>}
    </div>
  );
}

function EmptyState(p) { return <StateBlock icon="folder" {...p} />; }

function ErrorState({ onRetry, title = 'تعذّر تحميل البيانات', body = 'حدث خطأ في الاتصال بالخادم. بياناتك لم تتأثر — جرّب مرة أخرى.', code }) {
  return <StateBlock icon="wifiOff" tone="red" title={title} body={<>{body}{code && <span className="block mt-1.5 text-[11px] t-3 ltr inline-block">{code}</span>}</>} action={<button className="btn btn-ink" onClick={onRetry}><Icon name="refresh" size={14} />إعادة المحاولة</button>} />;
}

function Forbidden({ perm }) {
  return (
    <div className="v-card stagger s2">
      <StateBlock icon="lock" tone="amber" title="ليس لديك صلاحية لعرض هذه الصفحة"
        body={<>هذه الصفحة تتطلب صلاحية <span className="ltr inline-block font-semibold t-1 px-1.5 rounded-md v-well">{perm}</span>. تواصل مع مدير النظام لإضافتها لدورك.</>}
        action={<button className="btn btn-soft" onClick={() => (location.hash = '#/dashboard')}><Icon name="arrowRight" size={14} />العودة</button>} />
    </div>
  );
}

function SearchBox({ value, onChange, placeholder = 'بحث…', className = '', width = 280 }) {
  return (
    <div className={cx('relative', className)} style={{ width, maxWidth: '100%' }}>
      <Icon name="search" size={15} className="absolute top-1/2 -translate-y-1/2 start-3.5 t-3 pointer-events-none" />
      <input value={value} onChange={(e) => onChange(e.target.value)} placeholder={placeholder} className="v-input !h-10 !rounded-full ps-10 pe-9" />
      {value && <button onClick={() => onChange('')} className="absolute top-1/2 -translate-y-1/2 end-2 w-6 h-6 rounded-full inline-flex items-center justify-center t-3 hover:bg-[var(--bg-subcard-hover)]"><Icon name="x" size={12} /></button>}
    </div>
  );
}

function PageHeader({ crumbs = [], title, subtitle, actions, badge }) {
  return (
    <div className="stagger s1 flex flex-wrap items-end justify-between gap-4 pt-1">
      <div className="min-w-0">
        {crumbs.length > 0 && (
          <nav className="flex items-center gap-1.5 text-[12px] t-3 mb-2">
            {crumbs.map((c, i) => (
              <React.Fragment key={i}>
                {i > 0 && <Icon name="chevLeft" size={12} />}
                {c.href ? <a href={c.href} className="hover:t-1 hover:underline underline-offset-4">{c.label}</a> : <span className="t-2 font-medium">{c.label}</span>}
              </React.Fragment>
            ))}
          </nav>
        )}
        <div className="flex items-center gap-3 flex-wrap">
          <h1 className="font-display text-[30px] sm:text-[34px] font-semibold tracking-[-0.02em] leading-tight t-1">{title}</h1>
          {badge}
        </div>
        {subtitle && <p className="text-[13.5px] mt-1 t-2 text-pretty">{subtitle}</p>}
      </div>
      {actions && <div className="flex items-center gap-2 flex-wrap">{actions}</div>}
    </div>
  );
}

function ImageField({ value, onFile, onRemove, onChecking, progress, error, disabled, rules = ['JPG · PNG · WebP', 'حتى 4MB', 'حتى 16 ميجابكسل', 'SVG غير مقبول'], shape = 'square', label = 'رفع صورة' }) {
  const inputRef = useRef(null);
  const pendingUrl = useRef(null);
  const selection = useRef(0);
  const [localErr, setLocalErr] = useState(null);
  useEffect(() => () => {
    selection.current++;
    if (pendingUrl.current) URL.revokeObjectURL(pendingUrl.current);
    onChecking?.(false);
  }, [onChecking]);
  const uploading = typeof progress === 'number';
  const state = uploading ? 'uploading' : (value ? 'done' : 'empty');
  const pick = () => { if (!disabled) inputRef.current?.click(); };
  const onPick = (e) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    const current = ++selection.current;
    if (pendingUrl.current) { URL.revokeObjectURL(pendingUrl.current); pendingUrl.current = null; }
    onChecking?.(false);
    const okTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!okTypes.includes(file.type)) { setLocalErr('صيغة غير مدعومة'); return; }
    if (file.size > 4 * 1024 * 1024) { setLocalErr('الحجم أكبر من 4MB'); return; }
    const url = URL.createObjectURL(file);
    onChecking?.(true);
    pendingUrl.current = url;
    const img = new window.Image();
    img.onload = () => {
      if (current !== selection.current) return;
      pendingUrl.current = null;
      onChecking?.(false);
      if (img.naturalWidth * img.naturalHeight > 16000000) {
        URL.revokeObjectURL(url);
        setLocalErr('الصورة تتجاوز 16 ميجابكسل');
        return;
      }
      setLocalErr(null);
      onFile && onFile(file, url);
    };
    img.onerror = () => {
      if (current !== selection.current) return;
      pendingUrl.current = null;
      onChecking?.(false);
      URL.revokeObjectURL(url);
      setLocalErr('تعذّر قراءة الصورة');
    };
    img.src = url;
  };
  const r = shape === 'circle' ? 999 : 18;
  return (
    <div className="flex items-center gap-4">
      <input ref={inputRef} type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={onPick} />
      <div className="relative w-[88px] h-[88px] shrink-0 overflow-hidden checker" style={{ borderRadius: r }}>
        {state === 'done' && <div className="absolute inset-0" style={{ background: value }} />}
        {state === 'empty' && <div className="absolute inset-0 flex items-center justify-center t-3"><Icon name="image" size={22} /></div>}
        {state === 'uploading' && <div className="absolute inset-0 flex flex-col items-center justify-center gap-1.5"><Spinner size={18} className="t-acc" /><span className="text-[10.5px] font-bold num t-2">{progress}%</span></div>}
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2">
          <button type="button" className="btn btn-soft btn-sm" onClick={pick} disabled={disabled}><Icon name="upload" size={13} />{state === 'done' ? 'تغيير' : label}</button>
          {state === 'done' && <button type="button" className="btn btn-ghost btn-sm" disabled={disabled} style={{ color: 'var(--badge-red-text)' }} onClick={() => { onRemove && onRemove(); }}><Icon name="trash" size={13} />إزالة</button>}
        </div>
        <div className="flex flex-wrap gap-1.5 mt-2.5">{rules.map((x) => <span key={x} className="text-[10.5px] font-medium px-2 py-0.5 rounded-full v-well">{x}</span>)}</div>
        {(localErr || error) && <div className="flex items-start gap-1.5 text-[11.5px] font-medium mt-1.5" style={{ color: 'var(--badge-red-text)' }}><Icon name="alertCircle" size={13} className="mt-[1px]" /><span>{localErr || error}</span></div>}
      </div>
    </div>
  );
}

function StickyBar({ children, dirty, note }) {
  return (
    <div className="sticky bottom-4 z-30 mt-6">
      <div className="v-card px-4 py-3 flex flex-wrap items-center justify-between gap-3" style={{ boxShadow: 'var(--shadow-pop)', borderRadius: 999 }}>
        <div className="flex items-center gap-2.5 ps-2 text-[12.5px]">
          {dirty ? <><span className="w-2 h-2 rounded-full bg-[var(--accent)] animate-pulse" /><span className="font-semibold t-1">تغييرات غير محفوظة</span></> : <><Icon name="checkCircle" size={15} style={{ color: 'var(--badge-green-text)' }} /><span className="t-2">كل التغييرات محفوظة</span></>}
          {note && <span className="t-3 hidden md:inline">· {note}</span>}
        </div>
        <div className="flex items-center gap-2">{children}</div>
      </div>
    </div>
  );
}

const MAX_DEPTH = 3;

function treeDepth(n) { return 1 + Math.max(0, ...(n.children || []).map(treeDepth)); }

function findNode(nodes, id, parent = null, depth = 1) {
  for (const n of nodes) {
    if (n.id === id) return { node: n, parent, depth };
    const r = findNode(n.children || [], id, n, depth + 1); if (r) return r;
  }
  return null;
}

function isDescendant(node, id) { return (node.children || []).some((c) => c.id === id || isDescendant(c, id)); }

function removeNode(nodes, id) { return nodes.filter((n) => n.id !== id).map((n) => ({ ...n, children: removeNode(n.children || [], id) })); }

function insertInto(nodes, parentId, node) {
  if (parentId == null) return [...nodes, node];
  return nodes.map((n) => n.id === parentId ? { ...n, children: [...(n.children || []), node] } : { ...n, children: insertInto(n.children || [], parentId, node) });
}

function sumProducts(n) { return (n.products || 0) + (n.children || []).reduce((a, c) => a + sumProducts(c), 0); }

export function CategoriesReferencePage() {
  const { perms } = useApp();
  const { phase, tree, setTree, saved, setSaved, error, reload } = useCategoriesTree(perms.view);
  const initialized = useRef(false);
  const [open, setOpen] = useState({});
  const [sel, setSel] = useState(null);
  const [drag, setDrag] = useState(null);
  const [over, setOver] = useState(null);
  const [invalid, setInvalid] = useState(null);
  const [form, setForm] = useState(null);
  const [del, setDel] = useState(null);
  const [disable, setDisable] = useState(null);
  const [search, setSearch] = useState('');
  const [savingOrder, setSavingOrder] = useState(false);
  useEffect(() => {
    if (phase !== 'ready' || !tree.length) return;
    if (!initialized.current) {
      initialized.current = true;
      const ids = {};
      const walk = (nodes) => nodes.forEach((n) => { ids[n.id] = true; walk(n.children || []); });
      walk(tree);
      setOpen(ids);
    }
  }, [phase, tree]);
  useEffect(() => {
    if (sel && !findNode(tree, sel)) setSel(null);
  }, [tree, sel]);
  if (!perms.view) return <Forbidden perm="categories.view" />;
  const dirty = JSON.stringify(tree) !== JSON.stringify(saved);
  const lockMsg = 'احفظ الترتيب أو ألغيه الأول';
  const canCreate = perms.create && !dirty && !savingOrder;
  const canUpdate = perms.update && !dirty && !savingOrder;
  const canDelete = perms.delete && !dirty && !savingOrder;

  const canDropInto = (dragId, targetId) => {
    if (dragId === targetId) return { ok: false, why: 'لا يمكن نقل القسم داخل نفسه' };
    const d = findNode(tree, dragId); if (!d) return { ok: false };
    if (targetId != null) {
      if (isDescendant(d.node, targetId)) return { ok: false, why: 'لا يمكن نقل القسم داخل أحد أقسامه الفرعية' };
      const t = findNode(tree, targetId);
      if (t.depth + treeDepth(d.node) > MAX_DEPTH) return { ok: false, why: `النقل هنا يتجاوز الحد الأقصى (${MAX_DEPTH} مستويات)` };
    }
    return { ok: true };
  };
  const doMove = (dragId, targetId) => {
    if (!perms.update || savingOrder) return;
    const current = findNode(tree, dragId);
    if (!current || (current.parent?.id ?? null) === targetId) return;
    const chk = canDropInto(dragId, targetId);
    if (!chk.ok) { vtoast(chk.why, 'error'); return; }
    const d = findNode(tree, dragId);
    setTree(insertInto(removeNode(tree, dragId), targetId, d.node));
    if (targetId) setOpen({ ...open, [targetId]: true });
  };

  const selected = sel && findNode(tree, sel);
  const flat = flattenCats(tree);
  const matches = (n) => {
    if (!search) return true;
    const q = normalizeSearch(search);
    return normalizeSearch(n.ar).includes(q) || normalizeSearch(n.en).includes(q) || (n.children || []).some(matches);
  };
  const saveOrder = async () => {
    const items = buildReorderItems(tree, saved);
    if (!items.length) { setSaved(tree); return; }
    if (items.length > 500) { vtoast('تغييرات كتير مرة واحدة، احفظ على دفعات'); return; }
    setSavingOrder(true);
    try {
      await categoriesApi.reorder(items);
      vtoast('تم حفظ الترتيب');
      await reload({ silent: true });
    } catch (e) {
      const p = parseApiError(e);
      vtoast(p.message);
    } finally {
      setSavingOrder(false);
    }
  };

  const renderNode = (n, depth) => {
    const has = (n.children || []).length > 0;
    const isOpen = open[n.id] || !!search;
    const chk = drag != null && over === n.id ? canDropInto(drag, n.id) : null;
    if (!matches(n)) return null;
    return (
      <div key={n.id}>
        <div data-category-id={n.id} draggable={perms.update && !savingOrder} onDragStart={(e) => { e.stopPropagation(); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(n.id)); setDrag(n.id); }} onDragEnd={() => { setDrag(null); setOver(null); setInvalid(null); }}
          onDragOver={(e) => { e.preventDefault(); e.stopPropagation(); if (over !== n.id) { setOver(n.id); const c = canDropInto(drag, n.id); setInvalid(c.ok ? null : c.why); } }}
          onDrop={(e) => { e.preventDefault(); e.stopPropagation(); doMove(drag, n.id); setDrag(null); setOver(null); setInvalid(null); }}
          onClick={() => setSel(n.id)}
          className={cx('group flex items-center gap-2 h-12 pe-2 rounded-[14px] cursor-pointer transition relative',
            sel === n.id ? 'bg-[var(--bg-subcard)]' : 'hover:bg-[var(--bg-subcard)]',
            drag === n.id && 'opacity-40',
            chk && (chk.ok ? 'ring-2 ring-[var(--accent)] bg-[var(--accent-soft)]' : 'ring-2 ring-[var(--badge-red-text)] bg-[var(--badge-red-bg)]'))}
          style={{ paddingInlineStart: 8 + (depth - 1) * 26 }}>
          {depth > 1 && <span className="absolute top-0 bottom-1/2 w-3 border-b border-s rounded-es-lg" style={{ insetInlineStart: (depth - 1) * 26 - 10, borderColor: 'var(--border-field)' }} />}
          {perms.update && <span className="t-3 opacity-0 group-hover:opacity-100 cursor-grab"><Icon name="drag" size={14} /></span>}
          <button onClick={(e) => { e.stopPropagation(); setOpen({ ...open, [n.id]: !isOpen }); }} className={cx('w-6 h-6 rounded-full flex items-center justify-center t-3 hover:bg-[var(--bg-card)]', !has && 'invisible')}>
            <Icon name="chevLeft" size={13} className={cx('transition', isOpen && '-rotate-90')} />
          </button>
          <span className={cx('w-8 h-8 rounded-[10px] flex items-center justify-center shrink-0', depth === 1 ? '' : 'v-well')} style={depth === 1 ? { background: n.img || IMG[0] } : null}>
            {depth > 1 && <Icon name={has ? (isOpen ? 'folderOpen' : 'folder') : 'tag'} size={14} />}
          </span>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2"><span className={cx('text-[13px] truncate', depth === 1 ? 'font-semibold t-1' : 'font-medium t-1', n.status === 'inactive' && 'opacity-50')}><bdi>{displayName(n, 'ar')}</bdi></span>{n.status === 'inactive' && <Badge className="!py-0 !text-[10px]">غير نشط</Badge>}</div>
            <div className="text-[11px] t-3 ltr text-end inline-block" dir="ltr">{n.en}</div>
          </div>
          <span className="text-[11px] t-3 num hidden sm:inline">{sumProducts(n)} منتج</span>
          <span className="text-[10px] font-bold w-5 h-5 rounded-full v-well flex items-center justify-center num">{depth}</span>
          {(perms.create || perms.update || perms.delete) && (
            <div className="flex items-center opacity-0 group-hover:opacity-100 transition" onClick={(e) => e.stopPropagation()}>
              {perms.create && (depth < MAX_DEPTH
                ? <button title={dirty ? lockMsg : 'إضافة قسم فرعي'} disabled={dirty} className={cx('icon-btn !w-8 !h-8', dirty && 'opacity-30 cursor-not-allowed')} onClick={() => canCreate && setForm({ parentId: n.id })}><Icon name="plus" size={14} /></button>
                : <span title="الحد الأقصى ٣ مستويات" className="icon-btn !w-8 !h-8 opacity-30 cursor-not-allowed"><Icon name="plus" size={14} /></span>)}
              {perms.update && <button title={dirty ? lockMsg : 'تعديل'} disabled={dirty} className={cx('icon-btn !w-8 !h-8', dirty && 'opacity-30 cursor-not-allowed')} onClick={() => canUpdate && setForm(n)}><Icon name="edit" size={14} /></button>}
              {perms.delete && <button title={dirty ? lockMsg : 'حذف'} disabled={dirty} className={cx('icon-btn !w-8 !h-8', dirty && 'opacity-30 cursor-not-allowed')} onClick={() => canDelete && setDel(n)}><Icon name="trash" size={14} /></button>}
            </div>
          )}
        </div>
        {chk && !chk.ok && <div className="pop text-[11px] font-semibold flex items-center gap-1 my-1" style={{ color: 'var(--badge-red-text)', paddingInlineStart: 40 + (depth - 1) * 26 }}><Icon name="ban" size={12} />{chk.why}</div>}
        {has && isOpen && <div className="mt-0.5 space-y-0.5">{n.children.map((c) => renderNode(c, depth + 1))}</div>}
      </div>
    );
  };

  return (
    <div className="space-y-5">
      <PageHeader crumbs={[{ label: 'الكتالوج' }, { label: 'الأقسام' }]} title="الأقسام" subtitle={`شجرة أقسام حتى ${MAX_DEPTH} مستويات. اسحب لإعادة الترتيب أو استخدم «نقل إلى».`}
        actions={perms.create && <button className="btn btn-ink" title={dirty ? lockMsg : undefined} disabled={dirty} onClick={() => canCreate && setForm({ parentId: null })}><Icon name="plus" size={15} />قسم رئيسي</button>} />
      <div className="grid lg:grid-cols-[1fr_340px] gap-5 items-start">
        <div className="v-card p-4 stagger s2">
          <div className="flex flex-wrap items-center justify-between gap-2 mb-3 px-1">
            <SearchBox value={search} onChange={setSearch} placeholder="ابحث في الأقسام…" width={260} />
            <div className="flex items-center gap-1.5">
              <button className="btn btn-ghost btn-sm" onClick={() => setOpen(Object.fromEntries(flat.map((f) => [f.id, true])))}>فتح الكل</button>
              <button className="btn btn-ghost btn-sm" onClick={() => setOpen({})}>طي الكل</button>
            </div>
          </div>
          {phase === 'loading' ? <div className="space-y-2 p-2">{[0, 1, 2, 3, 4, 5].map((i) => <div key={i} className="flex items-center gap-3 h-11" style={{ paddingInlineStart: (i % 3) * 26 }}><Skel w={32} h={32} r={10} /><Skel w={`${40 - (i % 3) * 8}%`} h={10} /></div>)}</div>
            : phase === 'error' ? <ErrorState onRetry={() => reload()} body={error?.message} />
            : phase === 'empty' ? <EmptyState icon="tree" title="لا توجد أقسام بعد" body="ابدأ بقسم رئيسي، وبعدها أضف أقسام فرعية لحد ٣ مستويات." action={perms.create && <button className="btn btn-ink" title={dirty ? lockMsg : undefined} disabled={dirty} onClick={() => canCreate && setForm({ parentId: null })}><Icon name="plus" size={15} />قسم رئيسي</button>} />
            : (
              <div className="space-y-0.5" onDragOver={(e) => e.preventDefault()}>
                {tree.map((n) => renderNode(n, 1))}
                {drag != null && (
                  <div onDragOver={(e) => { e.preventDefault(); setOver('root'); }} onDrop={(e) => { e.preventDefault(); doMove(drag, null); setDrag(null); setOver(null); }}
                    className={cx('pop mt-2 h-12 rounded-[14px] border-2 border-dashed flex items-center justify-center text-[12px] font-semibold', over === 'root' ? 'border-[var(--accent)] t-acc bg-[var(--accent-soft)]' : 'border-[var(--border-field)] t-3')}>
                    أفلت هنا لجعله قسمًا رئيسيًا
                  </div>
                )}
              </div>
            )}
          <div className="flex items-center gap-4 mt-4 pt-3 px-1 v-border-t text-[11px] t-3">
            <span className="flex items-center gap-1.5"><span className="w-3 h-3 rounded ring-2 ring-[var(--accent)]" />إفلات مسموح</span>
            <span className="flex items-center gap-1.5"><span className="w-3 h-3 rounded ring-2 ring-[var(--badge-red-text)]" />إفلات غير مسموح</span>
            <span className="flex items-center gap-1.5"><span className="w-4 h-4 rounded-full v-well text-[9px] font-bold flex items-center justify-center">2</span>رقم المستوى</span>
          </div>
        </div>

        {/* Detail / move-to panel */}
        <div className="space-y-4 lg:sticky lg:top-5 stagger s3">
          {selected ? (
            <div className="v-card overflow-hidden">
              <div className="h-24 relative" style={{ background: (tree.find((t) => t.id === sel || isDescendant(t, sel)) || {}).img || IMG[0] }}>
                <span className="absolute bottom-3 start-4 text-[10.5px] font-semibold px-2 py-0.5 rounded-full bg-white/90 text-[#18151c]">المستوى {selected.depth}</span>
              </div>
              <div className="p-5">
                <div className="flex items-start justify-between gap-2">
                  <div><div className="font-display text-[17px] font-semibold t-1"><bdi>{displayName(selected.node, 'ar')}</bdi></div><div className="text-[12px] t-3 ltr inline-block" dir="ltr">{selected.node.en} · /{selected.node.slug}</div></div>
                  <StatusBadge s={selected.node.status} />
                </div>
                <div className="text-[11.5px] t-3 mt-3 flex items-center gap-1 flex-wrap">{flat.find((f) => f.id === sel).path.map((p, i, a) => <React.Fragment key={i}><span className={i === a.length - 1 ? 't-1 font-semibold' : ''}>{p}</span>{i < a.length - 1 && <Icon name="chevLeft" size={11} />}</React.Fragment>)}</div>
                <div className="grid grid-cols-2 gap-2.5 mt-4">
                  <div className="v-subcard p-3"><div className="text-[11px] t-3">أقسام فرعية</div><div className="font-display text-[20px] font-bold t-1 num">{(selected.node.children || []).length}</div></div>
                  <div className="v-subcard p-3"><div className="text-[11px] t-3">منتجات</div><div className="font-display text-[20px] font-bold t-1 num">{sumProducts(selected.node)}</div></div>
                </div>
                {selected.node.createdAt && <div className="text-[11.5px] t-3 mt-2">{new Intl.DateTimeFormat('ar-EG', { dateStyle: 'medium' }).format(new Date(selected.node.createdAt))}</div>}
                {perms.update && <>
                  <div className="mt-5"><Field label="نقل إلى" hint="بديل للسحب — الأقسام اللي تتجاوز الحد أو تقع داخل نفس الفرع غير متاحة.">
                    <SearchSelect value={selected.parent ? selected.parent.id : '__root'} clearable={false} asyncSim={false} disabled={savingOrder}
                      onChange={(pid) => doMove(sel, pid === '__root' ? null : pid)}
                      options={[{ value: '__root', label: 'بدون أب (قسم رئيسي)', depth: 0 }, ...flat.filter((f) => f.depth < MAX_DEPTH - 1 + 1).map((f) => {
                        const c = canDropInto(sel, f.id);
                        return { value: f.id, label: f.ar, depth: f.depth, disabled: !c.ok, note: !c.ok ? (f.id === sel ? 'نفسه' : c.why.includes('الحد') ? 'يتجاوز الحد' : 'فرعي منه') : null };
                      })]} />
                  </Field></div>
                  <div className="flex items-center gap-2 mt-4">
                    <button className="btn btn-soft flex-1" title={dirty ? lockMsg : undefined} disabled={dirty} onClick={() => canUpdate && setForm(selected.node)}><Icon name="edit" size={14} />تعديل</button>
                    <button className="btn btn-outline flex-1" title={dirty ? lockMsg : undefined} disabled={dirty} onClick={() => canUpdate && setDisable(selected.node)}>{selected.node.status === 'active' ? 'تعطيل' : 'تفعيل'}</button>
                  </div>
                </>}
              </div>
            </div>
          ) : <div className="v-card"><EmptyState compact icon="tree" title="اختر قسمًا" body="لعرض تفاصيله ونقله." /></div>}
        </div>
      </div>

      {perms.update && dirty && <StickyBar dirty note="ترتيب الأقسام اتغيّر — التغييرات مش هتتبعت قبل الحفظ">
        <button className="btn btn-ghost" onClick={() => setTree(saved)} disabled={savingOrder}>إلغاء التغييرات</button>
        <button className="btn btn-ink" onClick={saveOrder} disabled={savingOrder}>{savingOrder && <Spinner size={14} />}حفظ الترتيب</button>
      </StickyBar>}

      <CategoryForm open={!!form} value={form} tree={tree} onClose={() => setForm(null)} onSaved={() => reload({ silent: true })} />
      <ConfirmDialog open={!!del} onClose={() => setDel(null)} danger title={<>حذف قسم «<bdi>{del && del.ar}</bdi>»؟</>} message="سيتم حذف القسم نهائيًا." confirmLabel="حذف القسم"
        serverError={del && ((del.children || []).length || sumProducts(del)) ? { title: 'لا يمكن حذف هذا القسم', body: 'القسم مرتبط بيانات لازم تتنقل الأول:', items: [(del.children || []).length && `${del.children.length} أقسام فرعية`, sumProducts(del) && `${sumProducts(del)} منتج مرتبط`].filter(Boolean) } : null}
        onConfirm={async () => {
          try {
            await categoriesApi.remove(del.id);
            vtoast('تم حذف القسم');
            if (sel === del.id) setSel(null);
            await reload({ silent: true });
          } catch (e) {
            const p = parseApiError(e);
            throw { title: 'تعذّر حذف القسم', body: p.message, items: [] };
          }
        }} />
      <ConfirmDialog open={!!disable} onClose={() => setDisable(null)} title={disable && disable.status === 'active' ? <>تعطيل «<bdi>{disable.ar}</bdi>»؟</> : <>تفعيل «<bdi>{disable && disable.ar}</bdi>»؟</>}
        message={disable && disable.status === 'active' ? 'القسم المعطل وأقسامه الفرعية مش هيظهروا في المتجر.' : 'سيظهر القسم في المتجر.'} icon="ban" confirmLabel={disable && disable.status === 'active' ? 'تعطيل' : 'تفعيل'}
        extra={disable && disable.status !== 'active' && findNode(tree, disable.id)?.parent?.status === 'inactive' ? <div className="text-[12px] t-2 mb-2">القسم مش هيظهر في المتجر طول ما القسم الأب معطّل</div> : null}
        onConfirm={async () => {
          try {
            await categoriesApi.update(disable.id, { is_active: disable.status !== 'active' });
            vtoast(disable.status === 'active' ? 'تم تعطيل القسم' : 'تم تفعيل القسم');
            await reload({ silent: true });
          } catch (e) {
            const p = parseApiError(e);
            throw { title: disable.status === 'active' ? 'تعذّر تعطيل القسم' : 'تعذّر تفعيل القسم', body: p.message, items: [] };
          }
        }} />
    </div>
  );
}

const FORM_KEYS = ['ar', 'en', 'slug', 'desc_ar', 'desc_en', 'order', 'parentId', 'status', 'img'];

function CategoryForm({ open, value, tree, onClose, onSaved }) {
  const [v, setV] = useState({});
  const isEdit = Boolean(v.id);
  const [slugTouched, setSlugTouched] = useState(false);
  const [errs, setErrs] = useState({});
  const [lang, setLang] = useState('ar');
  const [busy, setBusy] = useState(false);
  const [uploadProgress, setUploadProgress] = useState(null);
  const [fileChecking, setFileChecking] = useState(false);
  const previewUrlRef = useRef(null);
  useEffect(() => {
    if (!open) {
      if (previewUrlRef.current) { URL.revokeObjectURL(previewUrlRef.current); previewUrlRef.current = null; }
      return;
    }
    setBusy(false);
    setFileChecking(false);
    setUploadProgress(null);
    setErrs({});
    setLang('ar');
    if (previewUrlRef.current) { URL.revokeObjectURL(previewUrlRef.current); previewUrlRef.current = null; }
    if (value && value.id) {
      const found = findNode(tree, value.id);
      setV({
        id: value.id,
        ar: value.ar || '',
        en: value.en || '',
        slug: value.slug || '',
        desc_ar: value.desc_ar || '',
        desc_en: value.desc_en || '',
        order: value.order ?? 0,
        status: value.status || 'active',
        parentId: value.parentId ?? found?.parent?.id ?? null,
        imageUrl: value.imageUrl,
        img: value.img,
        imageFile: null,
        imageRemoved: false,
        previewUrl: null,
      });
      setSlugTouched(true);
    } else {
      const parentId = value.parentId ?? value.parent ?? null;
      const siblings = parentId == null ? tree : (findNode(tree, parentId)?.node.children || []);
      setV({
        status: 'active',
        parentId,
        order: Math.max(-1, ...siblings.map((n) => Number(n.order) || 0)) + 1,
        ar: '', en: '', slug: '', desc_ar: '', desc_en: '',
        imageFile: null, imageRemoved: false, previewUrl: null, img: undefined, imageUrl: null,
      });
      setSlugTouched(false);
    }
  }, [open]);
  useEffect(() => () => { if (previewUrlRef.current) URL.revokeObjectURL(previewUrlRef.current); }, []);
  const generatedSlug = autoSlug(v.en);
  const flat = flattenCats(tree);
  const height = isEdit ? treeDepth(value) : 1;
  const parentName = v.parentId != null ? flat.find((f) => f.id === v.parentId)?.ar : null;
  const preview = v.previewUrl
    ? `url("${v.previewUrl}") center / cover no-repeat`
    : (v.imageRemoved ? null : (v.imageUrl ? `url("${v.imageUrl}") center / cover no-repeat` : v.img));
  const save = async () => {
    const e = {};
    if (!(v.ar || '').trim()) e.ar = 'الاسم العربي مطلوب';
    if (!(v.en || '').trim()) e.en = 'الاسم الإنجليزي مطلوب';
    if ((v.ar || '').length > 150) e.ar = 'الحد الأقصى 150 حرف';
    if ((v.en || '').length > 150) e.en = 'الحد الأقصى 150 حرف';
    const order = toLatinDigits(v.order ?? 0).trim();
    if (!/^\d+$/.test(order) || Number(order) > 2147483647) e.order = 'الترتيب يجب أن يكون عددًا صحيحًا من 0 إلى 2147483647';
    const slugVal = slugTouched ? v.slug : generatedSlug;
    if (v.slug && !/^[a-z0-9_-]+$/i.test(v.slug)) e.slug = 'حروف إنجليزي وأرقام و - فقط';
    setErrs(e);
    if (Object.keys(e).length) return;

    setBusy(true);
    setUploadProgress(v.imageFile ? 0 : null);
    try {
      const payload = toPayload({ ...v, slug: slugVal }, { isEdit });
      const onProgress = v.imageFile ? (pct) => setUploadProgress(pct) : undefined;
      if (isEdit) await categoriesApi.update(v.id, payload, onProgress);
      else await categoriesApi.create(payload, onProgress);
      vtoast(isEdit ? 'تم تحديث القسم' : 'تم إنشاء القسم');
      onSaved && onSaved();
      onClose();
    } catch (err) {
      const savedId = Number(err?.response?.data?.errors?.category_id?.[0]);
      if (err?.response?.data?.code === 'category_image_failed' && Number.isSafeInteger(savedId) && savedId > 0) {
        setV(current => ({ ...current, id: savedId, slug: slugVal }));
        setSlugTouched(true);
        onSaved && onSaved();
      }
      const p = parseApiError(err);
      if (p.isValidation) {
        const formErrs = Object.fromEntries(Object.entries(p.fieldErrors).filter(([k]) => FORM_KEYS.includes(k)));
        if (Object.keys(formErrs).length) setErrs(formErrs);
        else vtoast(p.message);
      } else vtoast(p.message);
    } finally {
      setBusy(false);
      setUploadProgress(null);
    }
  };
  const close = () => { if (!busy) onClose(); };
  return (
    <Drawer open={open} onClose={close} title={isEdit ? 'تعديل القسم' : (v.parentId ? 'قسم فرعي جديد' : 'قسم رئيسي جديد')} subtitle={v.parentId && !isEdit && parentName ? <>تحت <b className="t-1"><bdi>{parentName}</bdi></b></> : null} width={540}
      footer={<><button className="btn btn-soft" onClick={close} disabled={busy}>إلغاء</button><button className="btn btn-ink" onClick={save} disabled={busy || fileChecking}>{(busy || fileChecking) && <Spinner size={14} />}{isEdit ? 'حفظ' : 'إنشاء القسم'}</button></>}>
      <fieldset disabled={busy} className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <Field label="الاسم بالعربي" required error={errs.ar}><Input dir="rtl" value={v.ar || ''} onChange={(e) => setV({ ...v, ar: e.target.value })} error={errs.ar} /></Field>
          <Field label="الاسم بالإنجليزي" required error={errs.en}><Input ltr dir="ltr" value={v.en || ''} onChange={(e) => setV({ ...v, en: e.target.value })} error={errs.en} /></Field>
        </div>
        <Field label="القسم الأب" hint="اختياري — اتركه فارغًا لقسم رئيسي" error={errs.parentId}>
          <SearchSelect value={v.parentId || null} onChange={(p) => setV({ ...v, parentId: p })} placeholder="بدون أب (قسم رئيسي)"
            options={flat.map((f) => {
              const exceeds = (f.depth + 1) + height > MAX_DEPTH;
              const selfOrChild = isEdit && (f.id === value.id || isDescendant(value, f.id));
              return { value: f.id, label: f.ar, depth: f.depth, disabled: exceeds || selfOrChild, note: exceeds ? 'النقل هنا يتجاوز الحد الأقصى' : (selfOrChild ? (f.id === value.id ? 'نفسه' : 'فرعي منه') : null) };
            })} />
        </Field>
        <Field label="الرابط (slug)" error={errs.slug} hint={!slugTouched ? <>هيتولد تلقائيًا من الاسم الإنجليزي</> : null} aside={slugTouched && <button type="button" className="text-[11px] font-semibold t-acc" onClick={() => { setSlugTouched(false); setV({ ...v, slug: '' }); }}>استخدام التلقائي</button>}>
          <div className="relative"><span className="absolute top-1/2 -translate-y-1/2 left-3.5 text-[12.5px] t-3 ltr" dir="ltr">/categories/</span>
            <input className="v-input ltr" dir="ltr" style={{ paddingLeft: 98 }} value={slugTouched ? v.slug || '' : generatedSlug} placeholder="auto-generated" onChange={(e) => { setSlugTouched(true); setV({ ...v, slug: e.target.value }); }} />
          </div>
        </Field>
        <Field label="الوصف" error={lang === 'ar' ? errs.desc_ar : errs.desc_en} aside={<Seg value={lang} onChange={setLang} options={[{ value: 'ar', label: 'عربي' }, { value: 'en', label: 'EN' }]} />}>
          {lang === 'ar' ? <Textarea dir="rtl" value={v.desc_ar || ''} onChange={(e) => setV({ ...v, desc_ar: e.target.value })} /> : <Textarea className="ltr" dir="ltr" value={v.desc_en || ''} onChange={(e) => setV({ ...v, desc_en: e.target.value })} />}
        </Field>
        <Field label="صورة القسم" error={errs.img}>
          <ImageField value={preview} progress={uploadProgress} disabled={busy} onChecking={setFileChecking}
            onFile={(file, url) => {
              if (previewUrlRef.current) URL.revokeObjectURL(previewUrlRef.current);
              previewUrlRef.current = url;
              setV({ ...v, imageFile: file, imageRemoved: false, previewUrl: url });
            }}
            onRemove={() => {
              if (previewUrlRef.current) { URL.revokeObjectURL(previewUrlRef.current); previewUrlRef.current = null; }
              setV({ ...v, imageFile: null, imageRemoved: true, previewUrl: null, img: undefined });
            }} />
        </Field>
        <div className="grid grid-cols-[120px_1fr] gap-3">
          <Field label="الترتيب" error={errs.order}><Input ltr dir="ltr" type="text" inputMode="numeric" value={v.order ?? ''} onChange={(e) => setV({ ...v, order: e.target.value })} /></Field>
          <div className="v-subcard p-3.5 flex items-center justify-between self-end h-[42px] !rounded-[13px]"><span className="text-[12.5px] font-semibold t-1">نشط</span><Toggle on={v.status === 'active'} onChange={(on) => setV({ ...v, status: on ? 'active' : 'inactive' })} /></div>
        </div>
      </fieldset>
    </Drawer>
  );
}

function Drawer({ open, onClose, title, subtitle, children, footer, width = 520 }) {
  const titleId = useId();
  useEffect(() => {
    if (!open) return;
    const h = (e) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', h); return () => window.removeEventListener('keydown', h);
  }, [open, onClose]);
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-[60] fade" style={{ background: 'var(--overlay)', backdropFilter: 'blur(2px)' }} onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div role="dialog" aria-modal="true" aria-labelledby={titleId} className="drawer absolute top-3 bottom-3 start-3 v-card flex flex-col overflow-hidden" style={{ width, maxWidth: 'calc(100vw - 24px)', boxShadow: 'var(--shadow-pop)', borderRadius: 26 }}>
        <div className="px-6 pt-6 pb-4 flex items-start justify-between gap-3 v-border-b">
          <div>
            <h3 id={titleId} className="font-display text-[19px] font-semibold t-1">{title}</h3>
            {subtitle && <p className="text-[12.5px] t-2 mt-0.5">{subtitle}</p>}
          </div>
          <button className="icon-btn" onClick={onClose}><Icon name="x" size={16} /></button>
        </div>
        <div className="flex-1 overflow-y-auto px-6 py-5">{children}</div>
        {footer && <div className="px-6 py-4 v-border-t flex items-center justify-end gap-2">{footer}</div>}
      </div>
    </div>
  );
}

const ICON_PATHS = {
  grid: <><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></>,
  box: <><path d="M12 3l8 4.5v9L12 21l-8-4.5v-9L12 3z"/><path d="M12 12l8-4.5M12 12v9M12 12L4 7.5"/></>,
  store: <><path d="M4 9l1.5-5h13L20 9"/><path d="M4 9v11h16V9"/><path d="M4 9c0 1.7 1.3 3 3 3s3-1.3 3-3c0 1.7 1.3 3 3 3s3-1.3 3-3c0 1.7 1.3 3 3 3"/><path d="M10 20v-5h4v5"/></>,
  users: <><circle cx="9" cy="8" r="3.5"/><path d="M3 19c0-3.3 2.7-6 6-6s6 2.7 6 6"/><circle cx="17" cy="9" r="2.5"/><path d="M16 13.5c2.5.4 4.5 2.5 4.5 5.5"/></>,
  shield: <><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/></>,
  gear: <><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 01-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></>,
  layers: <><path d="M12 3l9 5-9 5-9-5 9-5z"/><path d="M3 13l9 5 9-5"/></>,
  tag: <><path d="M3 12V4a1 1 0 011-1h8l9 9-9 9-9-9z"/><circle cx="8" cy="8" r="1.5"/></>,
  tree: <><rect x="3" y="3" width="6" height="5" rx="1.2"/><rect x="15" y="10" width="6" height="5" rx="1.2"/><rect x="15" y="17" width="6" height="4" rx="1.2"/><path d="M6 8v11h9M6 12.5h9"/></>,
  component: <><rect x="3" y="3" width="8" height="8" rx="2"/><circle cx="17" cy="7" r="4"/><path d="M7 14l4 7H3l4-7z"/><rect x="13" y="13" width="8" height="8" rx="2"/></>,
  search: <><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></>,
  bell: <path d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 00-5-5.9V4a1 1 0 10-2 0v1.1A6 6 0 006 11v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>,
  help: <><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 015 0c0 1.5-2.5 2-2.5 3.5m0 3h.01"/></>,
  sun: <><circle cx="12" cy="12" r="4"/><path d="M12 3v2m0 14v2M3 12h2m14 0h2m-2.6-6.4l-1.4 1.4M7 17l-1.4 1.4m0-12.8L7 7m10 10l1.4 1.4"/></>,
  moon: <path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/>,
  logout: <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4m7 14l5-5-5-5m5 5H9"/>,
  info: <><circle cx="12" cy="12" r="9"/><path d="M12 8h.01M12 11v5"/></>,
  plus: <path d="M12 5v14M5 12h14"/>,
  minus: <path d="M5 12h14"/>,
  x: <path d="M6 6l12 12M18 6L6 18"/>,
  check: <path d="M5 12.5l4.5 4.5L19 7.5"/>,
  chevDown: <path d="M19 9l-7 7-7-7"/>,
  chevLeft: <path d="M15 18l-6-6 6-6"/>,
  chevRight: <path d="M9 18l6-6-6-6"/>,
  chevUp: <path d="M5 15l7-7 7 7"/>,
  edit: <><path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/></>,
  trash: <><path d="M4 7h16M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13M9 7V4h6v3"/></>,
  dots: <><circle cx="12" cy="5" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="12" cy="19" r="1.3"/></>,
  drag: <><circle cx="9" cy="6" r="1.2"/><circle cx="15" cy="6" r="1.2"/><circle cx="9" cy="12" r="1.2"/><circle cx="15" cy="12" r="1.2"/><circle cx="9" cy="18" r="1.2"/><circle cx="15" cy="18" r="1.2"/></>,
  eye: <><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></>,
  eyeOff: <><path d="M3 3l18 18"/><path d="M10.6 5.1A10 10 0 0112 5c6.5 0 10 7 10 7a17 17 0 01-3.2 4.1M6.6 6.6C3.8 8.4 2 12 2 12s3.5 7 10 7c1.6 0 3-.4 4.3-1"/><path d="M9.9 9.9a3 3 0 004.2 4.2"/></>,
  mail: <><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3 7l9 6 9-6"/></>,
  lock: <><rect x="5" y="11" width="14" height="10" rx="2.5"/><path d="M8 11V8a4 4 0 018 0v3"/></>,
  upload: <><path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a2 2 0 002 2h12a2 2 0 002-2v-3"/></>,
  image: <><rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/></>,
  video: <><rect x="3" y="6" width="13" height="12" rx="2.5"/><path d="M16 10l5-3v10l-5-3"/></>,
  refresh: <><path d="M20 11a8 8 0 00-14.9-3M4 4v4h4"/><path d="M4 13a8 8 0 0014.9 3M20 20v-4h-4"/></>,
  alert: <><path d="M12 3l9.5 17h-19L12 3z"/><path d="M12 10v4m0 3h.01"/></>,
  alertCircle: <><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5m0 3h.01"/></>,
  checkCircle: <><circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.7 2.7L16 10"/></>,
  filter: <path d="M4 5h16l-6 8v6l-4-2v-4L4 5z"/>,
  sort: <path d="M7 4v16M3 8l4-4 4 4M17 20V4M13 16l4 4 4-4"/>,
  copyDown: <><path d="M12 4v12M7 11l5 5 5-5"/><path d="M5 20h14"/></>,
  copy: <><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 00-2-2H6a2 2 0 00-2 2v8a2 2 0 002 2h2"/></>,
  wand: <><path d="M4 20L15 9M13 7l4 4"/><path d="M18 3v3M16.5 4.5h3M20 9v2M19 10h2M10 3v2M9 4h2"/></>,
  palette: <><path d="M12 3a9 9 0 100 18c1 0 1.5-.7 1.5-1.5 0-1.2-1-1.5-1-2.5s.8-1.5 2-1.5H17a4 4 0 004-4c0-4.7-4-8.5-9-8.5z"/><circle cx="7.5" cy="11" r="1.2"/><circle cx="10" cy="7" r="1.2"/><circle cx="15" cy="7" r="1.2"/></>,
  ruler: <><rect x="2" y="8" width="20" height="8" rx="1.5"/><path d="M6 8v3M10 8v4M14 8v3M18 8v4"/></>,
  star: <path d="M12 3.5l2.6 5.4 5.9.8-4.3 4.1 1 5.8L12 16.8l-5.2 2.8 1-5.8L3.5 9.7l5.9-.8L12 3.5z"/>,
  building: <><rect x="4" y="3" width="11" height="18" rx="1.5"/><path d="M15 9h5v12h-5M8 7h3M8 11h3M8 15h3"/></>,
  warehouse: <><path d="M3 9l9-5 9 5v11H3V9z"/><path d="M7 20v-7h10v7M7 16h10"/></>,
  pin: <><path d="M12 21s-7-6.2-7-12a7 7 0 0114 0c0 5.8-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/></>,
  phone: <path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z"/>,
  hash: <path d="M5 9h14M5 15h14M10 3L8 21M16 3l-2 18"/>,
  percent: <><path d="M19 5L5 19"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/></>,
  receipt: <><path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2V3z"/><path d="M9 8h6M9 12h6M9 16h3"/></>,
  rotate: <><path d="M3 12a9 9 0 109-9"/><path d="M3 4v5h5"/></>,
  cart: <><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M3 3h2.5l2.2 12h11l2-8H6.5"/></>,
  link: <><path d="M10 14a4.5 4.5 0 006.4 0l3-3a4.5 4.5 0 00-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 00-6.4 0l-3 3a4.5 4.5 0 006.4 6.4l1-1"/></>,
  globe: <><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 3.5 5.5 3.5 9s-1 6.5-3.5 9c-2.5-2.5-3.5-5.5-3.5-9s1-6.5 3.5-9z"/></>,
  clock: <><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></>,
  send: <path d="M21 3L10 14M21 3l-7 18-4-7-7-4 18-7z"/>,
  barcode: <path d="M4 5v14M7 5v14M10 5v14M14 5v14M16 5v14M20 5v14"/>,
  keyboard: <><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M7 14h10"/></>,
  folder: <path d="M3 6a2 2 0 012-2h4l2 2h8a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V6z"/>,
  folderOpen: <path d="M3 7a2 2 0 012-2h4l2 2h7a2 2 0 012 2v1H6.5L3 18V7zM3 18l3.5-8H22l-3.5 8H3z"/>,
  arrowLeft: <path d="M19 12H5M11 18l-6-6 6-6"/>,
  arrowRight: <path d="M5 12h14M13 6l6 6-6 6"/>,
  ban: <><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></>,
  wifiOff: <><path d="M3 3l18 18M8.5 16.5a5 5 0 017 0M5 12.5a10 10 0 0110-2.8M2 9a15 15 0 015.5-3.2M19 12.5a10 10 0 00-2-1.6M22 9a15 15 0 00-7-3.8"/><path d="M12 20h.01"/></>,
  sparkle: <path d="M12 3l1.8 5.4L19 10l-5.2 1.6L12 17l-1.8-5.4L5 10l5.2-1.6L12 3zM19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8L19 16z"/>,
};

function Icon({ name, size = 16, className = '', style, sw = 1.9 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor"
      strokeWidth={sw} strokeLinecap="round" strokeLinejoin="round" className={'shrink-0 ' + className} style={style} aria-hidden="true">
      {ICON_PATHS[name] || ICON_PATHS.info}
    </svg>
  );
}

function useCategoriesTree(enabled) {
  const [phase, setPhase] = useState('loading'); // loading | error | empty | ready
  const [tree, setTree] = useState([]);
  const [saved, setSaved] = useState([]);
  const [error, setError] = useState(null);
  const request = useRef(null);
  const generation = useRef(0);

  const load = useCallback(async ({ silent = false } = {}) => {
    if (!enabled) return;
    request.current?.abort();
    const controller = new AbortController();
    request.current = controller;
    const current = ++generation.current;
    if (!silent) setPhase('loading');
    try {
      const data = toUiTree(await categoriesApi.tree(controller.signal));
      if (controller.signal.aborted || current !== generation.current) return;
      setTree(data); setSaved(data); setError(null);
      setPhase(data.length ? 'ready' : 'empty');
    } catch (e) {
      if (controller.signal.aborted || current !== generation.current) return;
      const err = parseApiError(e);
      setError(err);
      if (!silent) setPhase('error'); else vtoast(err.message);
    }
  }, [enabled]);

  useEffect(() => {
    load();
    return () => { generation.current++; request.current?.abort(); };
  }, [load]);
  return { phase, tree, setTree, saved, setSaved, error, reload: load };
}

function flattenCats(nodes, depth = 0, path = []) {
  return nodes.flatMap((n) => [{ ...n, depth, path: [...path, n.ar] }, ...flattenCats(n.children || [], depth + 1, [...path, n.ar])]);
}
