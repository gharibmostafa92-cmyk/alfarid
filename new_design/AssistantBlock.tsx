import { useEffect, useMemo, useRef, useState } from 'react';
import { isSensitive, permLabel } from '../admin-mock/api/permission-catalog';
import type { UiBlock } from './api';
import { Icon, Ring, entityIcon, entityLabel } from './icons';

interface Props {
  block: UiBlock;
  busy: boolean;
  status?: string;
  execute: boolean;
  onPick: (entity: string, id: string | number, label: string) => void;
  onAction: (kind: 'confirm' | 'cancel' | 'undo', id: string, typed?: string, selected?: string[]) => void;
}

const fieldLabels: Record<string, string> = { is_active: 'حالة التفعيل', name: 'الاسم', code: 'الكود', roles: 'الأدوار', permissions: 'الصلاحيات', email: 'البريد الإلكتروني', branch_id: 'الفرع', type: 'نوع الفرع', city: 'المدينة', phone: 'الهاتف' };
const statusLabels: Record<string, string> = { executed: 'تم التنفيذ', cancelled: 'تم الإلغاء', failed: 'فشل التنفيذ', expired: 'انتهت المهلة', undone: 'تم التراجع' };
const statusTone: Record<string, string> = { executed: 'success', cancelled: '', failed: 'danger', expired: 'warning', undone: 'info' };

function formatValue(key: string, item: unknown): string {
  if (key === 'is_active') return item ? 'نشط' : 'متوقف';
  if (Array.isArray(item)) return item.map(String).map(v => (key === 'permissions' ? permLabel(v) : v)).join('، ') || 'بدون';
  if (item == null || item === '') return 'بدون';
  return String(item);
}
const asRecord = (v: unknown): Record<string, unknown> | null => (v && typeof v === 'object' && !Array.isArray(v) ? (v as Record<string, unknown>) : null);

// جدول مقارنة قبل/بعد — بيعلّم الحقول اللي اتغيرت فعلاً.
function DiffTable({ before, after }: { before: unknown; after: unknown }) {
  const b = asRecord(before); const a = asRecord(after);
  if (!b && !a) return null;
  const keys = Array.from(new Set([...Object.keys(b ?? {}), ...Object.keys(a ?? {})]));
  // عنصر جديد (مفيش «قبل») — نعرض القيم بس بدل عمود فاضي.
  if (!b && a) {
    return (
      <div className="as-diff as-diff--new" aria-label="بيانات العنصر الجديد">
        <div className="as-diff-head"><span>الحقل</span><span>القيمة الجديدة</span></div>
        <dl>
          {keys.map(key => (
            <div className="as-diff-row" key={key}>
              <dt>{fieldLabels[key] ?? key}</dt>
              <dd className="as-after">{formatValue(key, a[key])}</dd>
            </div>
          ))}
        </dl>
      </div>
    );
  }
  return (
    <div className="as-diff" role="table" aria-label="مقارنة قبل وبعد التعديل">
      <div className="as-diff-head" role="row"><span role="columnheader">الحقل</span><span role="columnheader">قبل التعديل</span><span role="columnheader">بعد التعديل</span></div>
      <dl>
        {keys.map(key => {
          const bv = b ? formatValue(key, b[key]) : '—';
          const av = a && key in a ? formatValue(key, a[key]) : bv;
          const changed = !!a && key in a && (!b || bv !== av);
          return (
            <div className="as-diff-row" role="row" key={key} data-changed={changed}>
              <dt role="cell">{fieldLabels[key] ?? key}</dt>
              <dd role="cell" className="as-before">{b ? <span className={bv === 'بدون' ? 'as-empty-val' : ''}>{bv}</span> : <span className="as-empty-val">جديد</span>}</dd>
              <dd role="cell" className="as-after"><span className={av === 'بدون' ? 'as-empty-val' : ''}>{av}</span></dd>
            </div>
          );
        })}
      </dl>
    </div>
  );
}

const fmtClock = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

export default function AssistantBlock({ block, busy, status, execute, onPick, onAction }: Props) {
  const [typed, setTyped] = useState('');
  const [query, setQuery] = useState('');
  const [now, setNow] = useState(Date.now());
  const [open, setOpen] = useState<Record<string, boolean>>({});
  const items = (block.items ?? []) as { key: string; label: string; change: string; before?: unknown; after?: unknown; ok?: boolean; message?: string }[];
  const [selected, setSelected] = useState(() => items.map(item => item.key));
  // أول قيمة للمهلة المتبقية — بنستخدمها كـ "إجمالي" لحلقة العدّاد.
  const ttlTotal = useRef<number | null>(null);
  const undoTotal = useRef<number | null>(null);

  useEffect(() => {
    if (!block.expires_at && !block.undo_available_until) return;
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, [block.expires_at, block.undo_available_until]);

  const options = useMemo(() => (block.options ?? []) as { id: string | number; label: string; meta?: string; disabled?: string }[], [block.options]);

  /* ---------------- Entity picker ---------------- */
  if (block.type === 'entity_picker') {
    const entity = String(block.entity ?? '');
    const q = query.trim();
    const visible = options.filter(o => `${o.label} ${o.meta ?? ''}`.includes(q));
    return (
      <section className="as-block" aria-label="اختيار كيان">
        <header className="as-block-head">
          <span className="as-block-ic"><Icon name={entityIcon[entity] ?? 'layers'} /></span>
          <div className="as-block-title">
            <h3>{String(block.purpose ?? 'اختار من القائمة')}</h3>
            <span>{options.length} {entityLabel[entity] ? `${entityLabel[entity]} متاح` : 'اختيار متاح'} · اختار واحد للمتابعة</span>
          </div>
        </header>
        <div className="as-block-body">
          {options.length > 5 && (
            <div className="as-searchfield">
              <Icon name="search" size={16} />
              <input className="as-input" type="search" aria-label="بحث في الاختيارات" placeholder="بحث في القائمة…" value={query} onChange={e => setQuery(e.target.value)} />
            </div>
          )}
          {visible.length > 0 ? (
            <ul className="as-picker-list">
              {visible.map(o => (
                <li key={o.id}>
                  <button type="button" className="as-picker-opt" disabled={busy || !!o.disabled} title={o.disabled} onClick={() => onPick(entity, o.id, o.label)}>
                    <span className="as-opt-ic"><Icon name={o.disabled ? 'lock' : entityIcon[entity] ?? 'layers'} size={16} /></span>
                    <span className="as-opt-txt">
                      <span className="as-opt-label">{o.label}</span>
                      {(o.disabled ?? o.meta) && <span className="as-opt-meta">{o.disabled ?? o.meta}</span>}
                    </span>
                    {!o.disabled && <Icon name="chevronStart" size={16} className="as-chev" />}
                  </button>
                </li>
              ))}
            </ul>
          ) : (
            <div className="as-pop-empty">
              <Icon name="search" size={22} />
              <strong>مفيش نتائج مطابقة</strong>
              <span>جرّب كلمة تانية{q ? ` غير «${q}»` : ''}.</span>
              {q && <button type="button" className="as-btn as-btn--sm" onClick={() => setQuery('')}>مسح البحث</button>}
            </div>
          )}
        </div>
      </section>
    );
  }

  /* ---------------- Confirm / permission diff / bulk ---------------- */
  if (block.type === 'confirm_action' || block.type === 'permission_diff' || block.type === 'bulk_plan') {
    const expires = Date.parse(String(block.expires_at));
    const seconds = Math.max(0, Math.ceil((expires - now) / 1000));
    if (ttlTotal.current === null && seconds > 0) ttlTotal.current = seconds;
    const expired = !Number.isFinite(expires) || seconds === 0;
    const pending = !status || status === 'pending';
    const required = typeof block.typed_confirmation === 'string' ? block.typed_confirmation : '';
    const disabled = busy || !execute || !pending || expired;
    const warnings = (block.warnings ?? []) as string[];
    const addList = (block.add ?? []) as string[];
    const removeList = (block.remove ?? []) as string[];
    const sensitive = !!required || warnings.length > 0 || addList.some(isSensitive);
    const state = !pending ? status! : expired ? 'expired' : 'pending';
    const level = seconds <= 15 ? 'crit' : seconds <= 60 ? 'warn' : 'ok';
    const typedOk = !required || typed === required;
    const icon = block.type === 'permission_diff' ? 'key' : block.type === 'bulk_plan' ? 'layers' : 'shield';
    const kindLabel = block.type === 'permission_diff' ? 'تعديل صلاحيات' : block.type === 'bulk_plan' ? 'عملية جماعية' : 'تعديل يحتاج تأكيد';
    const allOn = selected.length === items.length;

    return (
      <section className="as-block" aria-label="مراجعة وتأكيد العملية" data-state={state} data-sensitive={pending && !expired && sensitive}>
        <header className="as-block-head">
          <span className="as-block-ic"><Icon name={state === 'executed' ? 'check' : state === 'expired' ? 'clock' : icon} /></span>
          <div className="as-block-title">
            <h3>{String(block.title ?? 'تأكيد العملية')}</h3>
            <span>{kindLabel}{sensitive && pending && !expired ? ' · عملية حساسة' : ''}</span>
          </div>
          {!pending && <span className={`as-badge as-badge--${statusTone[status!] ?? ''}`}>{statusLabels[status!] ?? status}</span>}
          {pending && expired && <span className="as-badge as-badge--warning">انتهت المهلة</span>}
        </header>

        <div className="as-block-body">
          {block.type === 'permission_diff' && (
            <>
              <div className="as-perm-grid">
                {(['add', 'remove'] as const).map(key => {
                  const list = key === 'add' ? addList : removeList;
                  return (
                    <div className="as-perm-col" data-kind={key} key={key}>
                      <h4><Icon name={key === 'add' ? 'plus' : 'minus'} size={14} />{key === 'add' ? 'إضافة' : 'إزالة'} <span className="as-num">({list.length})</span></h4>
                      {list.length ? (
                        <ul>
                          {list.map(p => (
                            <li key={p}>
                              <div>{permLabel(p)} {isSensitive(p) && <span className="as-badge as-badge--warning"><Icon name="alert" size={12} />حساسة</span>}</div>
                              <code>{p}</code>
                            </li>
                          ))}
                        </ul>
                      ) : <p className="as-none">لا يوجد</p>}
                    </div>
                  );
                })}
              </div>
              <span className="as-affected"><Icon name="user" size={15} />الموظفين المتأثرين: <b className="as-num">{String(block.affected_users ?? 0)}</b></span>
            </>
          )}

          {block.type === 'bulk_plan' && (
            <fieldset disabled={disabled}>
              <legend className="as-sr">العناصر المشمولة بالعملية</legend>
              <div className="as-bulk-bar" style={{ marginBottom: '.6rem' }}>
                <label style={{ display: 'inline-flex', alignItems: 'center', gap: '.45rem', cursor: 'pointer' }}>
                  <input type="checkbox" className="as-check" checked={allOn} ref={el => { if (el) el.indeterminate = selected.length > 0 && !allOn; }} onChange={() => setSelected(allOn ? [] : items.map(i => i.key))} />
                  تحديد الكل
                </label>
                <span className="as-progress" aria-hidden="true"><span style={{ width: `${items.length ? (selected.length / items.length) * 100 : 0}%` }} /></span>
                <span className="as-num">{selected.length} من {items.length} مختار</span>
              </div>
              <div className="as-bulk-list">
                {items.map(item => {
                  const on = selected.includes(item.key);
                  return (
                    <div className="as-bulk-item" key={item.key} data-on={on}>
                      <label className="as-bulk-item-head">
                        <input type="checkbox" className="as-check" checked={on} onChange={e => setSelected(ids => (e.target.checked ? [...ids, item.key] : ids.filter(id => id !== item.key)))} />
                        <span>{item.label}{item.change && <small style={{ display: 'block', color: 'var(--as-muted)', fontWeight: 400, fontSize: '.76rem' }}>{item.change}</small>}</span>
                        <button type="button" className="as-iconbtn" aria-expanded={!!open[item.key]} aria-label={`عرض تفاصيل ${item.label}`} onClick={e => { e.preventDefault(); setOpen(o => ({ ...o, [item.key]: !o[item.key] })); }}>
                          <Icon name="chevron" size={16} />
                        </button>
                      </label>
                      {open[item.key] && <DiffTable before={item.before} after={item.after} />}
                    </div>
                  );
                })}
              </div>
            </fieldset>
          )}

          {block.type === 'confirm_action' && Boolean(block.before || block.after) && <DiffTable before={block.before} after={block.after} />}

          {warnings.map((w, i) => (
            <div className="as-alert as-alert--warning" role="note" key={i}>
              <Icon name="alert" size={16} />
              <div className="as-alert-body"><strong>تنبيه أمني</strong><span>{w}</span></div>
            </div>
          ))}

          {pending && !expired && required && (
            <div className="as-typed">
              <label htmlFor={`typed-${String(block.action_id)}`}><Icon name="lock" size={15} />للتأكيد اكتب <b dir="ltr">{required}</b> في الخانة</label>
              <div className="as-typed-field" data-match={typed === required}>
                <input id={`typed-${String(block.action_id)}`} className="as-input" value={typed} onChange={e => setTyped(e.target.value)} disabled={disabled} dir="ltr" autoComplete="off" spellCheck={false} placeholder={required} onPaste={e => e.preventDefault()} />
                {typed === required && <Icon name="check" size={16} />}
              </div>
            </div>
          )}

          {pending && expired && (
            <div className="as-alert as-alert--info" role="status">
              <Icon name="refresh" size={16} />
              <div className="as-alert-body"><strong>انتهت صلاحية التأكيد</strong><span>لحماية بياناتك، الاقتراح ده مبقاش قابل للتنفيذ. اطلب من المساعد اقتراح جديد.</span></div>
            </div>
          )}
          {pending && !expired && !execute && (
            <div className="as-alert as-alert--info" role="note">
              <Icon name="lock" size={16} />
              <div className="as-alert-body"><span>معندكش صلاحية تنفيذ عمليات المساعد — تقدر تراجع التفاصيل بس.</span></div>
            </div>
          )}
        </div>

        {pending && !expired && (
          <footer className="as-block-foot">
            <button
              type="button"
              className="as-btn as-btn--primary"
              disabled={disabled || !typedOk || (block.type === 'bulk_plan' && !selected.length)}
              onClick={() => onAction('confirm', String(block.action_id), required ? typed : undefined, block.type === 'bulk_plan' ? selected : undefined)}
            >
              <Icon name="check" size={16} />
              {block.type === 'bulk_plan' ? `تنفيذ على ${selected.length}` : 'تأكيد التنفيذ'}
            </button>
            <button type="button" className="as-btn" disabled={busy || !pending || expired} onClick={() => onAction('cancel', String(block.action_id))}>إلغاء</button>
            <span className="as-spacer" />
            <span className="as-timer as-num" data-level={level} role="timer" aria-label={`متبقي ${fmtClock(seconds)} على انتهاء المهلة`}>
              <Ring fraction={ttlTotal.current ? seconds / ttlTotal.current : 1} size={18} />
              متبقي {fmtClock(seconds)}
            </span>
          </footer>
        )}
        <span className="as-sr" role="status">{!pending ? statusLabels[status!] ?? status : expired ? 'انتهت صلاحية التأكيد؛ اطلب اقتراح جديد.' : ''}</span>
      </section>
    );
  }

  /* ---------------- Result ---------------- */
  if (block.type === 'result') {
    // L7: روابط الفروع والمواقع كانت تُسقط — الـ regex يقبلها الآن.
    const link = typeof block.deep_link === 'string' && /^\/(users|roles|settings|branches)(\?|$)/.test(block.deep_link) ? block.deep_link : undefined;
    const undo = typeof block.undo_action_id === 'string' ? block.undo_action_id : undefined;
    const undoUntil = Date.parse(String(block.undo_available_until));
    const undoSec = Math.max(0, Math.ceil((undoUntil - now) / 1000));
    if (undoTotal.current === null && undoSec > 0) undoTotal.current = undoSec;
    const undone = status === 'undone';
    const undoLive = undo && !undone && undoUntil > now;
    const ok = !!block.ok;
    const okCount = items.filter(i => i.ok).length;
    return (
      <section className="as-block" role="status" data-state={undone ? 'undone' : ok ? 'executed' : 'failed'}>
        <header className="as-block-head">
          <span className="as-block-ic"><Icon name={undone ? 'undo' : ok ? 'check' : 'alert'} /></span>
          <div className="as-block-title">
            <h3>{undone ? 'تم التراجع عن العملية' : ok ? 'تم التنفيذ بنجاح' : 'تعذر التنفيذ'}</h3>
            <span>{String(block.message ?? '')}</span>
          </div>
          {items.length > 0 && <span className={`as-badge as-badge--${okCount === items.length ? 'success' : okCount ? 'warning' : 'danger'}`}><span className="as-num">{okCount}/{items.length}</span> نجح</span>}
        </header>
        {items.length > 0 && (
          <div className="as-block-body">
            <ul className="as-result-list">
              {items.map((item, index) => (
                <li key={index} data-ok={!!item.ok}><Icon name={item.ok ? 'check' : 'x'} size={15} /><span>{item.message}</span></li>
              ))}
            </ul>
          </div>
        )}
        {(link || (undoLive && execute)) && (
          <>
            {undoLive && execute && <div className="as-undo-bar" aria-hidden="true"><span style={{ width: `${undoTotal.current ? (undoSec / undoTotal.current) * 100 : 100}%` }} /></div>}
            <footer className="as-block-foot">
              {link && <a className="as-btn as-btn--sm" href={`#${link}`} style={{ textDecoration: 'none' }}><Icon name="external" size={15} />افتح في الشاشة</a>}
              <span className="as-spacer" />
              {undoLive && execute && (
                <span className="as-undo">
                  <span className="as-num" style={{ fontSize: '.78rem', color: 'var(--as-muted)' }}>متاح {fmtClock(undoSec)}</span>
                  <button type="button" className="as-btn as-btn--sm" disabled={busy} onClick={() => onAction('undo', undo!)}><Icon name="undo" size={15} />تراجع</button>
                </span>
              )}
            </footer>
          </>
        )}
      </section>
    );
  }

  /* ---------------- Table ---------------- */
  if (block.type === 'table') {
    const columns = (block.columns ?? []) as string[];
    const rows = (block.rows ?? []) as (string | number | null)[][];
    return (
      <div className="as-table-wrap">
        <table className="as-table">
          <thead><tr>{columns.map((c, i) => <th key={i} scope="col">{c}</th>)}</tr></thead>
          <tbody>
            {rows.map((r, i) => <tr key={i}>{r.map((c, j) => <td key={j} className={typeof c === 'number' ? 'as-num' : undefined}>{c ?? '—'}</td>)}</tr>)}
            {!rows.length && <tr><td colSpan={columns.length || 1} style={{ textAlign: 'center', color: 'var(--as-muted)' }}>لا توجد بيانات</td></tr>}
          </tbody>
        </table>
      </div>
    );
  }
  return null;
}
