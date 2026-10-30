import { useEffect, useState } from 'react';
import { isSensitive, permLabel } from '../admin-mock/api/permission-catalog';
import type { UiBlock } from './api';

interface Props {
  block: UiBlock;
  busy: boolean;
  status?: string;
  execute: boolean;
  onPick: (entity: string, id: string | number, label: string) => void;
  onAction: (kind: 'confirm' | 'cancel' | 'undo', id: string, typed?: string, selected?: string[]) => void;
}
function ChangeValues({ value }: { value: unknown }) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
  const labels: Record<string, string> = { is_active: 'حالة التفعيل', name: 'الاسم', code: 'الكود', roles: 'الأدوار', permissions: 'الصلاحيات', email: 'البريد الإلكتروني', branch_id: 'الفرع', type: 'نوع الفرع', city: 'المدينة', phone: 'الهاتف' };
  return <dl>{Object.entries(value).map(([key, item]) => <div key={key}><dt>{labels[key] ?? key}</dt><dd>{key === 'is_active' ? (item ? 'نشط' : 'متوقف') : Array.isArray(item) ? item.map(String).map(v => key === 'permissions' ? permLabel(v) : v).join('، ') || 'بدون' : item == null ? 'بدون' : String(item)}</dd></div>)}</dl>;
}

export default function AssistantBlock({ block, busy, status, execute, onPick, onAction }: Props) {
  const [typed, setTyped] = useState('');
  const [query, setQuery] = useState('');
  const [now, setNow] = useState(Date.now());
  const items = (block.items ?? []) as { key: string; label: string; change: string; before?: unknown; after?: unknown; ok?: boolean; message?: string }[];
  const [selected, setSelected] = useState(() => items.map(item => item.key));
  useEffect(() => {
    if (!block.expires_at && !block.undo_available_until) return;
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(timer);
  }, [block.expires_at, block.undo_available_until]);
  if (block.type === 'entity_picker') {
    const options = (block.options ?? []) as { id: string | number; label: string; meta?: string; disabled?: string }[];
    const visible = options.filter(o => `${o.label} ${o.meta ?? ''}`.includes(query));
    return <section className="assistant-block v-subcard" aria-label="اختيار كيان">
      <h3>{String(block.purpose ?? 'اختار من القائمة')}</h3>
      <input type="search" aria-label="بحث في الاختيارات" placeholder="بحث في القائمة…" value={query} onChange={e => setQuery(e.target.value)} />
      <div className="assistant-options">{visible.map(o => <button key={o.id} disabled={busy || !!o.disabled} title={o.disabled} onClick={() => onPick(String(block.entity), o.id, o.label)}>{o.label}<small>{o.disabled ?? o.meta}</small></button>)}</div>
      {!visible.length && <p>مفيش نتائج مطابقة.</p>}
    </section>;
  }
  if (block.type === 'confirm_action' || block.type === 'permission_diff' || block.type === 'bulk_plan') {
    const expires = Date.parse(String(block.expires_at));
    const seconds = Math.max(0, Math.ceil((expires - now) / 1000));
    const expired = !Number.isFinite(expires) || seconds === 0;
    const pending = !status || status === 'pending';
    const required = typeof block.typed_confirmation === 'string' ? block.typed_confirmation : '';
    const disabled = busy || !execute || !pending || expired;
    const warnings = (block.warnings ?? []) as string[];
    const labels: Record<string, string> = { executed: 'تم التنفيذ', cancelled: 'تم الإلغاء', failed: 'فشل التنفيذ', expired: 'انتهت المهلة', undone: 'تم التراجع' };
    return <section className="assistant-block v-subcard" aria-label="مراجعة وتأكيد العملية">
      <h3>{String(block.title ?? 'تأكيد العملية')}</h3>
      {block.type === 'permission_diff' && <div className="assistant-diff">{(['add', 'remove'] as const).map(key => <div key={key}><strong>{key === 'add' ? 'إضافة' : 'إزالة'}</strong><ul>{((block[key] ?? []) as string[]).map(p => <li key={p}>{permLabel(p)} {isSensitive(p) && <b>· حساسة</b>} <code>{p}</code></li>)}</ul></div>)}<small>الموظفين المتأثرين: {String(block.affected_users ?? 0)}</small></div>}
      {block.type === 'bulk_plan' && <fieldset disabled={disabled}>{items.map(item => <label key={item.key}><input type="checkbox" checked={selected.includes(item.key)} onChange={e => setSelected(ids => e.target.checked ? [...ids, item.key] : ids.filter(id => id !== item.key))} />{item.label}<div className="assistant-diff"><div><strong>قبل التعديل</strong><ChangeValues value={item.before} /></div><div><strong>بعد التعديل</strong><ChangeValues value={item.after} /></div></div></label>)}</fieldset>}
      {block.type === 'confirm_action' && <div className="assistant-diff">{(['before', 'after'] as const).map(key => block[key] ? <div key={key}><strong>{key === 'before' ? 'قبل التعديل' : 'بعد التعديل'}</strong><ChangeValues value={block[key]} /></div> : null)}</div>}
      {warnings.map((w, i) => <p className="assistant-warning" key={i}>تنبيه: {w}</p>)}
      {pending && !expired && required && <label>اكتب <b dir="ltr">{required}</b> للتأكيد<input value={typed} onChange={e => setTyped(e.target.value)} disabled={disabled} dir="ltr" autoComplete="off" /></label>}
      <div className="assistant-actions"><button className="assistant-primary" disabled={disabled || (!!required && typed !== required) || (block.type === 'bulk_plan' && !selected.length)} onClick={() => onAction('confirm', String(block.action_id), required ? typed : undefined, block.type === 'bulk_plan' ? selected : undefined)}>تأكيد التنفيذ</button><button disabled={busy || !pending || expired} onClick={() => onAction('cancel', String(block.action_id))}>إلغاء</button></div>
      <small role="status">{!pending ? labels[status!] ?? status : expired ? 'انتهت صلاحية التأكيد؛ اطلب اقتراح جديد.' : `متبقي ${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`}</small>
    </section>;
  }
  if (block.type === 'result') {
    // L7: روابط الفروع والمواقع كانت تُسقط — الـ regex يقبلها الآن.
    const link = typeof block.deep_link === 'string' && /^\/(users|roles|settings|branches)(\?|$)/.test(block.deep_link) ? block.deep_link : undefined;
    const undo = typeof block.undo_action_id === 'string' ? block.undo_action_id : undefined;
    const undoLive = undo && status !== 'undone' && Date.parse(String(block.undo_available_until)) > now;
    return <section className="assistant-block v-subcard" role="status"><strong>{block.ok ? 'نجح: ' : 'تعذر التنفيذ: '}{String(block.message ?? '')}</strong>{items.map((item, index) => <p key={index}>{item.ok ? 'نجح' : 'فشل'}: {item.message}</p>)}<div className="assistant-actions">{link && <a href={`#${link}`}>افتح في الشاشة</a>}{undoLive && execute && <button disabled={busy} onClick={() => onAction('undo', undo)}>تراجع</button>}</div></section>;
  }
  if (block.type === 'table') return <div className="assistant-table"><table><thead><tr>{(block.columns as string[]).map((c, i) => <th key={i}>{c}</th>)}</tr></thead><tbody>{(block.rows as (string | number | null)[][]).map((r, i) => <tr key={i}>{r.map((c, j) => <td key={j}>{c}</td>)}</tr>)}</tbody></table></div>;
  return null;
}
