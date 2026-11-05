import { useEffect, useMemo, useRef, useState } from 'react';
import { isSensitive, permLabel } from '../admin-mock/api/permission-catalog';
import { defaultApiClient } from '@alfarid/api-client';
import type { UiBlock } from './api';
import { Icon, Ring, entityIcon, entityLabel } from './icons';

interface Props {
  block: UiBlock;
  busy: boolean;
  status?: string;
  execute: boolean;
  confirmationBlocked?: boolean;
  onPick: (entity: string, id: string | number, label: string) => void;
  onAction: (kind: 'confirm' | 'cancel' | 'undo', id: string, typed?: string, selected?: string[]) => void;
  /** يملأ صندوق الكتابة برسالة جاهزة (مثلاً زرار «جهّز الاعتماد»). */
  onPrompt?: (text: string) => void;
}

const fieldLabels: Record<string, string> = { is_active: 'حالة التفعيل', name: 'الاسم', name_ar: 'الاسم بالعربية', name_en: 'الاسم بالإنجليزية', slug: 'الرابط (slug)', parent_id: 'القسم الأب', sort_order: 'الترتيب', description_ar: 'الوصف بالعربية', description_en: 'الوصف بالإنجليزية', category_id: 'القسم', sales_channel: 'قناة البيع', is_featured: 'منتج مميز', min_wholesale_qty: 'أقل كمية جملة', sku: 'كود التشكيلة (SKU)', barcode: 'الباركود', color_ar: 'اللون (عربي)', color_en: 'اللون (إنجليزي)', color_hex: 'درجة اللون', size: 'المقاس', capacity: 'السعة', retail_price: 'سعر القطاعي', wholesale_price: 'سعر الجملة', compare_at_price: 'سعر قبل الخصم', cost_price: 'سعر التكلفة', weight_grams: 'الوزن (جرام)', code: 'الكود', roles: 'الأدوار', permissions: 'الصلاحيات', email: 'البريد الإلكتروني', branch_id: 'الفرع', type: 'نوع الفرع', city: 'المدينة', phone: 'الهاتف', status: 'الحالة', credit_limit: 'حد الائتمان', used_credit: 'المستخدم من الائتمان', customer_group: 'المجموعة', customer_group_id: 'المجموعة', company: 'بيانات الشركة', company_name: 'اسم الشركة', tax_number: 'الرقم الضريبي', commercial_register: 'السجل التجاري', rejection_reason: 'سبب الرفض', suspension_reason: 'سبب الإيقاف', previous_status: 'الحالة السابقة', documents_to_reject: 'مستندات هتترفض' };
const customerStatusLabels: Record<string, string> = { pending: 'قيد المراجعة', approved: 'معتمد', rejected: 'مرفوض', suspended: 'موقوف', draft: 'مسودة', active: 'منشور / نشط', published: 'منشور', archived: 'مؤرشف', inactive: 'متوقف' };
const customerTypeLabels: Record<string, string> = { retail: 'تجزئة', wholesale: 'جملة', distributor: 'موزّع', both: 'قطاعي وجملة' };
const statusBadgeTone: Record<string, string> = { pending: 'warning', approved: 'success', rejected: 'danger', suspended: 'danger', draft: 'warning', active: 'success', published: 'success', archived: '', inactive: 'danger' };
const verdictMeta: Record<string, { label: string; tone: string }> = { ready: { label: 'جاهز', tone: 'success' }, blocked: { label: 'متعطّل (blocked)', tone: 'danger' }, needs_attention: { label: 'محتاج انتباه', tone: 'warning' } };
const severityRank: Record<string, number> = { blocker: 0, warning: 1, info: 2 };
const severityLabel: Record<string, string> = { blocker: 'مانع', warning: 'تحذير', info: 'معلومة' };
// الحقول اللي بتتعرض في أقسام مخصصة وماينفعش تتعرض كجدول قبل/بعد.
const sectionOnlyKeys = ['documents_to_verify', 'suggestion'];
function stripSections(v: unknown): unknown {
  const r = asRecord(v);
  if (!r) return v;
  return Object.fromEntries(Object.entries(r).filter(([k]) => !sectionOnlyKeys.includes(k)));
}
const money = (n: unknown) => Number(n ?? 0).toLocaleString('ar-EG', { maximumFractionDigits: 2 });
const statusLabels: Record<string, string> = { executed: 'تم التنفيذ', cancelled: 'تم الإلغاء', failed: 'فشل التنفيذ', expired: 'انتهت المهلة', undone: 'تم التراجع' };
const statusTone: Record<string, string> = { executed: 'success', cancelled: '', failed: 'danger', expired: 'warning', undone: 'info' };

interface CategoryTreeNode {
  id: number;
  name?: string;
  name_ar?: string;
  active?: boolean;
  is_active?: boolean;
  products_count?: number;
  children?: CategoryTreeNode[];
}

function CategoryTreeView({ nodes, movedId }: { nodes: CategoryTreeNode[]; movedId?: number }) {
  const [collapsedIds, setCollapsedIds] = useState<Record<number, boolean>>({});
  const renderNode = (node: CategoryTreeNode, depth = 0) => {
    const label = node.name ?? node.name_ar ?? `#${node.id}`;
    const active = node.active ?? node.is_active ?? true;
    const children = node.children ?? [];
    const hasChildren = children.length > 0;
    const isCollapsed = !!collapsedIds[node.id];
    const isMoved = movedId != null && Number(node.id) === Number(movedId);
    return (
      <li key={node.id} style={{ listStyle: 'none', marginTop: '.25rem' }}>
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: '.45rem',
            padding: '.3rem .5rem',
            paddingInlineStart: `${depth * 1.1 + 0.5}rem`,
            borderRadius: 6,
            opacity: active ? 1 : 0.55,
            background: isMoved ? 'var(--as-accent-soft, rgba(59,130,246,.12))' : undefined,
            border: isMoved ? '1px solid var(--as-accent, #3b82f6)' : '1px solid transparent',
          }}
        >
          {hasChildren ? (
            <button
              type="button"
              className="as-iconbtn as-iconbtn--sm"
              aria-expanded={!isCollapsed}
              aria-label={`${isCollapsed ? 'فتح' : 'طي'} قسم ${label}`}
              onClick={() => setCollapsedIds(prev => ({ ...prev, [node.id]: !prev[node.id] }))}
            >
              <Icon name={isCollapsed ? 'chevronStart' : 'chevron'} size={14} />
            </button>
          ) : (
            <span style={{ width: 22, display: 'inline-flex', justifyContent: 'center' }}><Icon name="folder" size={14} /></span>
          )}
          <span style={{ fontWeight: isMoved ? 600 : 500 }}>{label}</span>
          {!active && <span className="as-badge">متوقف</span>}
          {isMoved && <span className="as-badge as-badge--info">منقول</span>}
          {node.products_count != null && (
            <span className="as-num" style={{ marginInlineStart: 'auto', fontSize: '.78rem', color: 'var(--as-muted)' }}>
              {node.products_count} منتج
            </span>
          )}
        </div>
        {hasChildren && !isCollapsed && (
          <ul style={{ margin: 0, padding: 0 }}>
            {children.map(child => renderNode(child, depth + 1))}
          </ul>
        )}
      </li>
    );
  };
  return (
    <ul style={{ margin: 0, padding: 0 }}>
      {nodes.map(n => renderNode(n, 0))}
    </ul>
  );
}

function formatValue(key: string, item: unknown): string {
  if (key === 'is_active' || key === 'is_featured') return item ? 'نعم / نشط' : 'لا / متوقف';
  if (key === 'status' || key === 'previous_status') return customerStatusLabels[String(item)] ?? String(item ?? 'بدون');
  if (key === 'sales_channel') return customerTypeLabels[String(item)] ?? String(item ?? 'بدون');
  if (key === 'credit_limit' || key === 'used_credit' || key === 'retail_price' || key === 'wholesale_price' || key === 'compare_at_price' || key === 'cost_price') return item != null && item !== '' ? money(item) : 'بدون';
  if (item && typeof item === 'object' && !Array.isArray(item)) {
    const rec = item as Record<string, unknown>;
    if ('untrusted_text' in rec) return String(rec.untrusted_text ?? 'بدون');
    const parts = Object.entries(rec).filter(([, v]) => v != null && v !== '').map(([k, v]) => `${fieldLabels[k] ?? k}: ${typeof v === 'object' ? JSON.stringify(v) : String(v)}`);
    return parts.join(' · ') || 'بدون';
  }
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
              <dt>{fieldLabels[key] ?? inventoryBucketLabels[key] ?? key}</dt>
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
              <dt role="cell">{fieldLabels[key] ?? inventoryBucketLabels[key] ?? key}</dt>
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
const inventoryBucketLabels: Record<string, string> = { on_hand: 'الرصيد الفعلي', reserved: 'المحجوز', damaged: 'التالف', in_transit: 'في الطريق', available: 'المتاح' };

async function downloadDocument(url: string, name: string) {
  const r = await defaultApiClient.get(url, { responseType: 'blob' });
  const href = URL.createObjectURL(r.data as Blob);
  const a = document.createElement('a');
  a.href = href; a.download = name; a.click();
  setTimeout(() => URL.revokeObjectURL(href), 1000);
}

function normalizeDeepLink(raw: unknown): string | undefined {
  if (typeof raw !== 'string' || !raw) return undefined;
  let clean = raw.startsWith('#') ? raw.slice(1) : raw;
  clean = clean.replace(/^\/transfers\/(\d+)$/, '/inventory/transfer?id=$1')
    .replace(/^\/stocktakes\/(\d+)$/, '/inventory/count?id=$1')
    .replace(/^\/production\/(\d+)$/, '/production?receipt_id=$1');
  if (/^\/(users|roles|settings|branches|dealers|pricing|products|categories|transfers|stocktakes|production|inventory)(\/|\?|$)/.test(clean)) {
    return `#${clean}`;
  }
  return undefined;
}

export default function AssistantBlock({ block, busy, status, execute, confirmationBlocked, onPick, onAction, onPrompt }: Props) {
  const [dlError, setDlError] = useState('');
  const [typed, setTyped] = useState('');
  const [query, setQuery] = useState('');
  const [now, setNow] = useState(Date.now());
  const [open, setOpen] = useState<Record<string, boolean>>({});
  const [showAllPrices, setShowAllPrices] = useState(false);
  const [countEdits, setCountEdits] = useState<Record<number, string>>({});
  const [countsReviewed, setCountsReviewed] = useState(false);
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

  // Inventory previews use the same authorization, expiry and typed-confirmation
  // controls as every other proposal. Read-only cards have no action_id.
  if (block.action_id && ['stock_diff', 'count_sheet', 'cost_impact', 'transfer_timeline'].includes(block.type)) {
    const props = { busy, status, execute, onPick, onAction, onPrompt };
    const counts = block.type === 'count_sheet' ? (block.rows ?? []) as { product_variant_id?: number; variant_id?: number; sku?: string; counted_qty?: number; confidence?: number }[] : [];
    const needsReview = counts.some(r => (r.confidence ?? 1) < 0.8);
    const edited = Object.keys(countEdits).length > 0;
    return (
      <div>
        <AssistantBlock {...props} block={{ ...block, action_id: undefined }} />
        {counts.length > 0 && (!status || status === 'pending') && (
          <fieldset className="as-block as-block-body" disabled={busy || !execute}>
            <legend>مراجعة وتعديل العد</legend>
            {counts.map((row, i) => (
              <label className="as-field" key={i}>
                <span>{row.sku ?? `صنف ${i + 1}`}</span>
                <input className="as-input" type="number" min="0" step="1" aria-label={`تعديل عد ${row.sku ?? i + 1}`} value={countEdits[i] ?? row.counted_qty ?? ''} onChange={e => setCountEdits(current => ({ ...current, [i]: e.target.value }))} />
              </label>
            ))}
            {needsReview && <label><input type="checkbox" checked={countsReviewed} onChange={e => setCountsReviewed(e.target.checked)} /> راجعت السطور ذات الثقة المنخفضة</label>}
            {edited && onPrompt && <button type="button" className="as-btn" disabled={counts.some((r, i) => { const n = Number(countEdits[i] ?? r.counted_qty); return (countEdits[i] === '') || !Number.isSafeInteger(n) || n < 0; })} onClick={() => {
              const rows = counts.map((row, i) => `${row.sku ?? `variant_id=${row.product_variant_id ?? row.variant_id}`}: ${countEdits[i] ?? row.counted_qty}`).join('، ');
              onAction('cancel', String(block.action_id));
              onPrompt(`جهز اقتراح تسجيل عد جديد للجرد ${block.stocktake_number ?? block.stocktake_id ?? ''} بالكميات المعدلة: ${rows}`);
            }}>جهز اقتراحًا بالعد المعدّل</button>}
          </fieldset>
        )}
        <AssistantBlock {...props} confirmationBlocked={edited || (needsReview && !countsReviewed)} block={{ ...block, type: 'confirm_action', before: block.type === 'transfer_timeline' ? block.before : undefined, after: block.type === 'transfer_timeline' ? block.after : undefined }} />
      </div>
    );
  }

  /* ---------------- Category tree (B.1) ---------------- */
  if (block.type === 'category_tree') {
    const nodes = (block.nodes ?? []) as CategoryTreeNode[];
    return (
      <section className="as-block" aria-label="شجرة الأقسام">
        <header className="as-block-head">
          <span className="as-block-ic"><Icon name="folder" /></span>
          <div className="as-block-title">
            <h3>{String(block.title ?? 'شجرة الأقسام')}</h3>
            <span>{nodes.length} قسم رئيسي</span>
          </div>
        </header>
        <div className="as-block-body">
          {nodes.length ? <CategoryTreeView nodes={nodes} /> : <p className="as-none">لا توجد أقسام</p>}
        </div>
      </section>
    );
  }

  /* ---------------- Product card (B.3) ---------------- */
  if (block.type === 'product_card') {
    const st = String(block.status ?? 'draft');
    const readiness = (asRecord(block.readiness)?.checks ?? []) as { key: string; ok: boolean; label: string; hint?: string }[];
    const variantsList = (block.variants ?? []) as { id: number; sku: string; color_name_ar?: string; color_hex?: string; size?: string; retail_price?: number; wholesale_price?: number; is_active: boolean }[];
    const priceRange = asRecord(block.price_range);
    const link = normalizeDeepLink(block.deep_link ?? (block.product_id ? `#/products/${String(block.product_id)}/edit` : undefined));
    const thumb = typeof block.thumb_url === 'string' && block.thumb_url ? block.thumb_url : undefined;
    return (
      <section className="as-block" aria-label="كارت المنتج">
        <header className="as-block-head">
          {thumb ? (
            <img src={thumb} alt={String(block.name_ar ?? '')} style={{ width: 42, height: 42, objectFit: 'cover', borderRadius: 8, border: '1px solid var(--as-border)' }} />
          ) : (
            <span className="as-block-ic"><Icon name="box" /></span>
          )}
          <div className="as-block-title">
            <h3>{String(block.name_ar ?? block.name ?? '')}</h3>
            <span>
              <code dir="ltr">{String(block.code ?? '')}</code>
              {block.category_name ? ` · ${String(block.category_name)}` : ''}
            </span>
          </div>
          <span className={`as-badge as-badge--${statusBadgeTone[st] ?? ''}`}>{customerStatusLabels[st] ?? st}</span>
        </header>
        <div className="as-block-body">
          {priceRange && (
            <dl className="as-diff">
              <div className="as-diff-row">
                <dt>نطاق القطاعي</dt>
                <dd className="as-num">{priceRange.min_retail != null ? `${money(priceRange.min_retail)} – ${money(priceRange.max_retail)} ج.م` : 'غير محدد'}</dd>
              </div>
              <div className="as-diff-row">
                <dt>نطاق الجملة</dt>
                <dd className="as-num">{priceRange.min_wholesale != null ? `${money(priceRange.min_wholesale)} – ${money(priceRange.max_wholesale)} ج.م` : 'غير محدد'}</dd>
              </div>
            </dl>
          )}
          {variantsList.length > 0 && (
            <div className="as-table-wrap" style={{ marginTop: '.5rem' }}>
              <table className="as-table">
                <thead>
                  <tr><th>SKU</th><th>اللون / المقاس</th><th className="as-num">قطاعي</th><th className="as-num">جملة</th><th>الحالة</th></tr>
                </thead>
                <tbody>
                  {variantsList.slice(0, 8).map(v => (
                    <tr key={v.id}>
                      <td><code dir="ltr">{v.sku}</code></td>
                      <td>
                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: '.35rem' }}>
                          {v.color_hex && <i style={{ width: 12, height: 12, borderRadius: '50%', background: v.color_hex, display: 'inline-block', border: '1px solid var(--as-border)' }} />}
                          {[v.color_name_ar, v.size].filter(Boolean).join(' · ') || '—'}
                        </span>
                      </td>
                      <td className="as-num">{v.retail_price != null ? money(v.retail_price) : '—'}</td>
                      <td className="as-num">{v.wholesale_price != null ? money(v.wholesale_price) : '—'}</td>
                      <td><span className={`as-badge as-badge--${v.is_active ? 'success' : 'danger'}`}>{v.is_active ? 'نشط' : 'متوقف'}</span></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {readiness.length > 0 && (
            <ul className="as-result-list" style={{ marginTop: '.5rem' }}>
              {readiness.map(r => (
                <li key={r.key} data-ok={r.ok}>
                  <Icon name={r.ok ? 'check' : 'alert'} size={15} />
                  <span>{r.label}{!r.ok && r.hint ? ` — ${r.hint}` : ''}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
        {link && (
          <footer className="as-block-foot">
            <a className="as-btn as-btn--sm" href={link} style={{ textDecoration: 'none' }}>
              <Icon name="external" size={15} />افتح في المنتجات
            </a>
          </footer>
        )}
      </section>
    );
  }

  /* ---------------- Media gallery (B.6) ---------------- */
  if (block.type === 'media_gallery') {
    const galleryItems = (block.items ?? []) as { id: number; thumb_url?: string; url?: string; order?: number; sort_order?: number; variant_label?: string | null; is_cover?: boolean }[];
    return (
      <section className="as-block" aria-label="صور المنتج">
        <header className="as-block-head">
          <span className="as-block-ic"><Icon name="image" /></span>
          <div className="as-block-title">
            <h3>{String(block.title ?? 'معرض صور المنتج')}</h3>
            <span>{galleryItems.length} صورة</span>
          </div>
        </header>
        <div className="as-block-body">
          {galleryItems.length ? (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(110px, 1fr))', gap: '.65rem' }}>
              {galleryItems.map((img, idx) => {
                const isCover = img.is_cover ?? idx === 0;
                const src = img.thumb_url ?? img.url ?? '';
                return (
                  <div key={img.id} style={{ border: isCover ? '2px solid var(--as-accent, #3b82f6)' : '1px solid var(--as-border)', borderRadius: 8, padding: '.35rem', display: 'grid', gap: '.3rem', position: 'relative' }}>
                    {src ? (
                      <img src={src} alt={`صورة #${img.id}`} style={{ width: '100%', height: 84, objectFit: 'cover', borderRadius: 6 }} />
                    ) : (
                      <div style={{ height: 84, display: 'grid', placeItems: 'center', background: 'var(--as-surface-2)', borderRadius: 6 }}><Icon name="image" size={22} /></div>
                    )}
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontSize: '.75rem' }}>
                      <code dir="ltr">#{img.id}</code>
                      {isCover ? <span className="as-badge as-badge--success">غلاف</span> : <span className="as-num">ترتيب {img.order ?? img.sort_order ?? (idx + 1)}</span>}
                    </div>
                    {img.variant_label && <span style={{ fontSize: '.72rem', color: 'var(--as-muted)' }}>{img.variant_label}</span>}
                  </div>
                );
              })}
            </div>
          ) : (
            <p className="as-none">لا توجد صور مرفوعة لهذا المنتج.</p>
          )}
        </div>
      </section>
    );
  }

  /* ---------------- Checklist (فحص طلب تاجر / جاهزية منتج) ---------------- */
  if (block.type === 'checklist') {
    const rawChecks = (block.checks ?? []) as { key: string; ok: boolean; severity?: string; message?: string; label?: string; hint?: string }[];
    const checks = rawChecks
      .map((c, i) => ({
        key: c.key,
        ok: c.ok,
        severity: c.severity ?? (c.ok ? 'info' : 'blocker'),
        message: c.message ?? (c.hint && !c.ok ? `${c.label ?? c.key} — ${c.hint}` : (c.label ?? c.key)),
        i,
      }))
      .sort((a, b) => (Number(a.ok) - Number(b.ok)) || ((severityRank[a.severity] ?? 3) - (severityRank[b.severity] ?? 3)) || a.i - b.i);
    const verdict = String(block.verdict ?? (checks.every(c => c.ok) ? 'ready' : 'blocked'));
    const meta = verdictMeta[verdict] ?? verdictMeta.blocked;
    const suggestion = asRecord(block.suggestion);
    const nextActions = (block.next_actions ?? []) as { label: string; prompt: string }[];
    const title = String(block.title ?? 'فحص طلب التاجر');
    return (
      <section className="as-block" aria-label={title} data-verdict={verdict}>
        <header className="as-block-head">
          <span className="as-block-ic"><Icon name={verdict === 'ready' ? 'check' : verdict === 'blocked' ? 'alert' : 'shield'} /></span>
          <div className="as-block-title"><h3>{title}</h3><span>{checks.filter(c => !c.ok).length} ملاحظة من {checks.length} فحص</span></div>
          <span className={`as-badge as-badge--${meta.tone}`}>{meta.label}</span>
        </header>
        <div className="as-block-body">
          <ul className="as-result-list">
            {checks.map(c => (
              <li key={c.key} data-ok={c.ok}>
                <Icon name={c.ok ? 'check' : c.severity === 'blocker' ? 'x' : 'alert'} size={15} />
                <span>{c.message} {!c.ok && <span className={`as-badge as-badge--${c.severity === 'blocker' ? 'danger' : c.severity === 'warning' ? 'warning' : 'info'}`}>{severityLabel[c.severity] ?? c.severity}</span>}</span>
              </li>
            ))}
          </ul>
          {suggestion && typeof suggestion.source === 'string' && <div className="as-alert as-alert--info" role="note"><Icon name="info" size={16} /><div className="as-alert-body"><span>{suggestion.source}</span></div></div>}
        </div>
        {onPrompt && (nextActions.length > 0 || (verdict === 'ready' && block.customer_id != null)) && (
          <footer className="as-block-foot" style={{ flexWrap: 'wrap', gap: '.4rem' }}>
            {verdict === 'ready' && block.customer_id != null && (
              <button type="button" className="as-btn as-btn--primary" disabled={busy || !execute} onClick={() => onPrompt(`جهّز اعتماد التاجر رقم ${String(block.customer_id)}`)}><Icon name="check" size={16} />جهّز الاعتماد</button>
            )}
            {nextActions.map((act, idx) => (
              <button key={idx} type="button" className="as-btn as-btn--sm" disabled={busy} onClick={() => onPrompt(act.prompt)}>
                {act.label}
              </button>
            ))}
          </footer>
        )}
      </section>
    );
  }

  /* ---------------- Customer card ---------------- */
  if (block.type === 'customer_card') {
    const limit = Number(block.credit_limit ?? 0);
    const used = Number(block.used_credit ?? 0);
    const pct = limit > 0 ? Math.min(100, Math.round((used / limit) * 100)) : 0;
    const high = limit > 0 && used / limit > 0.9;
    const docs = asRecord(block.documents_counts) ?? {};
    const st = String(block.status ?? '');
    const link = typeof block.deep_link === 'string' && /^\/(dealers|pricing)(\?|$)/.test(block.deep_link) ? block.deep_link : undefined;
    return (
      <section className="as-block" aria-label="كارت العميل">
        <header className="as-block-head">
          <span className="as-block-ic"><Icon name="store" /></span>
          <div className="as-block-title"><h3>{String(block.name ?? '')}</h3><span>{String(block.company_name ?? 'بدون شركة')} · {customerTypeLabels[String(block.customer_type ?? block.type)] ?? String(block.customer_type ?? block.type ?? '')}</span></div>
          <span className={`as-badge as-badge--${statusBadgeTone[st] ?? ''}`}>{customerStatusLabels[st] ?? st}</span>
        </header>
        <div className="as-block-body">
          <dl className="as-diff"><div className="as-diff-row"><dt>المجموعة</dt><dd>{String(block.group ?? 'بدون')}</dd></div><div className="as-diff-row"><dt>المستندات</dt><dd>{String(docs.total ?? 0)} (موثّق {String(docs.verified ?? 0)} · معلّق {String(docs.pending ?? 0)} · مرفوض {String(docs.rejected ?? 0)})</dd></div></dl>
          <div role="group" aria-label="استخدام حد الائتمان">
            <div className="as-num" style={{ fontSize: '.82rem', marginBottom: '.25rem' }}>الائتمان: {money(used)} من {money(limit)} {limit > 0 ? `(${pct}%)` : ''}</div>
            <div className="as-progress" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={pct}><span style={{ width: `${pct}%`, background: high ? 'var(--as-danger)' : undefined }} /></div>
            {high && <span className="as-badge as-badge--danger" style={{ marginTop: '.3rem' }}><Icon name="alert" size={12} />فوق 90% من الحد</span>}
          </div>
        </div>
        {link && <footer className="as-block-foot"><a className="as-btn as-btn--sm" href={`#${link}`} style={{ textDecoration: 'none' }}><Icon name="external" size={15} />افتح في شاشة التجار</a></footer>}
      </section>
    );
  }

  /* ---------------- Welcome card (ملخص طابور التجار والكتالوج — بدون Gemini) ---------------- */
  if (block.type === 'welcome_card') {
    const rows = (block.rows ?? []) as { label: string; value: number }[];
    const quickActions = (block.quick_actions ?? []) as { label: string; prompt: string }[];
    return (
      <section className="as-block" aria-label="ملخص اليوم">
        <header className="as-block-head">
          <span className="as-block-ic"><Icon name="store" /></span>
          <div className="as-block-title"><h3>{String(block.title ?? 'ملخص طابور التجار')}</h3><span>جاهز من غير أي تكلفة توكنز</span></div>
        </header>
        <div className="as-block-body">
          <dl className="as-diff">{rows.map(r => <div className="as-diff-row" key={r.label}><dt>{r.label}</dt><dd className="as-num">{r.value}</dd></div>)}</dl>
        </div>
        {onPrompt && (
          <footer className="as-block-foot" style={{ flexWrap: 'wrap', gap: '.4rem' }}>
            {quickActions.length > 0 ? (
              quickActions.map((qa, i) => (
                <button key={i} type="button" className="as-btn as-btn--sm" onClick={() => onPrompt(qa.prompt)}>{qa.label}</button>
              ))
            ) : (
              <>
                <button type="button" className="as-btn as-btn--sm" onClick={() => onPrompt('طلبات التجار المتأخرة')}>المتأخرين</button>
                <button type="button" className="as-btn as-btn--sm" onClick={() => onPrompt('مين جاهز للاعتماد؟')}>الجاهزين للاعتماد</button>
              </>
            )}
          </footer>
        )}
      </section>
    );
  }

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

  /* ---------------- Confirm / permission diff / bulk / catalog confirm blocks ---------------- */
  if (
    block.type === 'confirm_action' ||
    block.type === 'permission_diff' ||
    block.type === 'bulk_plan' ||
    block.type === 'category_tree_diff' ||
    block.type === 'variant_matrix' ||
    block.type === 'price_diff'
  ) {
    const expires = Date.parse(String(block.expires_at));
    const seconds = Math.max(0, Math.ceil((expires - now) / 1000));
    if (ttlTotal.current === null && seconds > 0) ttlTotal.current = seconds;
    const expired = !Number.isFinite(expires) || seconds === 0;
    const pending = !status || status === 'pending';
    const required = typeof block.typed_confirmation === 'string' ? block.typed_confirmation : '';
    const displayRequired = typeof block.typed_confirmation_hint === 'string' && block.typed_confirmation_hint ? block.typed_confirmation_hint : required;
    const disabled = busy || !execute || !pending || expired;
    const warnings = (block.warnings ?? []) as string[];
    const addList = (block.add ?? []) as string[];
    const removeList = (block.remove ?? []) as string[];
    const sensitive = !!required || warnings.length > 0 || addList.some(isSensitive);
    const state = !pending ? status! : expired ? 'expired' : 'pending';
    const level = seconds <= 15 ? 'crit' : seconds <= 60 ? 'warn' : 'ok';
    const typedOk = !required || typed.trim().toLocaleLowerCase() === required.trim().toLocaleLowerCase();
    const icon = block.type === 'permission_diff' ? 'key' : block.type === 'bulk_plan' ? 'layers' : block.type === 'category_tree_diff' ? 'folder' : block.type === 'variant_matrix' || block.type === 'price_diff' ? 'tag' : 'shield';
    const kindLabel = block.type === 'permission_diff' ? 'تعديل صلاحيات' : block.type === 'bulk_plan' ? 'عملية جماعية' : block.type === 'category_tree_diff' ? 'تعديل شجرة الأقسام' : block.type === 'variant_matrix' ? 'مصفوفة التشكيلات' : block.type === 'price_diff' ? 'تعديل أسعار جماعي' : 'تعديل يحتاج تأكيد';
    const allOn = selected.length === items.length;
    const afterRec = asRecord(block.after);
    const docsToVerify = (Array.isArray(afterRec?.documents_to_verify) ? afterRec!.documents_to_verify : []) as { id: number; name?: string; download_url?: string }[];
    const afterSuggestion = asRecord(afterRec?.suggestion);

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

          {block.type === 'category_tree_diff' && (
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '.75rem' }}>
              <div style={{ border: '1px solid var(--as-border)', borderRadius: 8, padding: '.6rem' }}>
                <strong style={{ display: 'block', marginBottom: '.4rem', fontSize: '.82rem', color: 'var(--as-muted)' }}>قبل التعديل</strong>
                {Array.isArray(block.before) ? (
                  <CategoryTreeView nodes={block.before as CategoryTreeNode[]} movedId={block.moved_id ? Number(block.moved_id) : undefined} />
                ) : (
                  <DiffTable before={block.before} after={null} />
                )}
              </div>
              <div style={{ border: '1px solid var(--as-border)', borderRadius: 8, padding: '.6rem' }}>
                <strong style={{ display: 'block', marginBottom: '.4rem', fontSize: '.82rem', color: 'var(--as-muted)' }}>بعد التعديل</strong>
                {Array.isArray(block.after) ? (
                  <CategoryTreeView nodes={block.after as CategoryTreeNode[]} movedId={block.moved_id ? Number(block.moved_id) : undefined} />
                ) : (
                  <DiffTable before={null} after={block.after} />
                )}
              </div>
            </div>
          )}

          {block.type === 'variant_matrix' && (() => {
            const matrixRows = (block.rows ?? []) as { sku?: string; color?: string; color_ar?: string; color_hex?: string; size?: string; capacity?: string; retail?: number; retail_price?: number; wholesale?: number; wholesale_price?: number; status?: string; state?: string }[];
            return (
              <div className="as-table-wrap">
                <table className="as-table">
                  <thead>
                    <tr><th>اللون</th><th>المقاس / السعة</th><th className="as-num">قطاعي</th><th className="as-num">جملة</th><th>الحالة</th></tr>
                  </thead>
                  <tbody>
                    {matrixRows.map((r, i) => {
                      const rowState = r.state ?? r.status ?? 'new';
                      const isSkipped = rowState === 'skipped' || rowState === 'duplicate';
                      return (
                        <tr key={i} style={{ opacity: isSkipped ? 0.55 : 1 }}>
                          <td>
                            <span style={{ display: 'inline-flex', alignItems: 'center', gap: '.4rem' }}>
                              {r.color_hex && <i style={{ width: 13, height: 13, borderRadius: '50%', background: r.color_hex, display: 'inline-block', border: '1px solid var(--as-border)' }} />}
                              <span>{r.color ?? r.color_ar ?? '—'}</span>
                            </span>
                          </td>
                          <td>{[r.size, r.capacity].filter(Boolean).join(' · ') || '—'}</td>
                          <td className="as-num">{money(r.retail ?? r.retail_price ?? 0)}</td>
                          <td className="as-num">{money(r.wholesale ?? r.wholesale_price ?? 0)}</td>
                          <td>
                            <span className={`as-badge as-badge--${isSkipped ? 'warning' : 'success'}`}>
                              {isSkipped ? 'موجود (هيتم تخطيه)' : 'جديد'}
                            </span>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            );
          })()}

          {block.type === 'price_diff' && (() => {
            const summary = asRecord(block.summary);
            const allRows = (block.rows ?? []) as { variant_id: number; sku: string; product_name?: string; old_price: number | null; new_price: number; delta: number; delta_pct: number | null }[];
            const topRows = (block.top ?? allRows.slice(0, 10)) as typeof allRows;
            const blockedRows = (block.blocked ?? []) as { sku: string; reason: string }[];
            const displayRows = showAllPrices ? allRows : topRows;
            return (
              <div style={{ display: 'grid', gap: '.65rem' }}>
                {summary && (
                  <dl className="as-diff">
                    {summary.updated_count != null && <div className="as-diff-row"><dt>عدد التشكيلات المتأثرة</dt><dd className="as-num">{String(summary.updated_count)}</dd></div>}
                    {summary.field != null && <div className="as-diff-row"><dt>الحقل</dt><dd>{fieldLabels[String(summary.field)] ?? String(summary.field)}</dd></div>}
                    {summary.average_delta != null && <div className="as-diff-row"><dt>متوسط التغيير</dt><dd className="as-num">{money(summary.average_delta)} ج.م</dd></div>}
                    {summary.total_delta != null && <div className="as-diff-row"><dt>إجمالي التغيير</dt><dd className="as-num">{money(summary.total_delta)} ج.م</dd></div>}
                  </dl>
                )}
                {displayRows.length > 0 && (
                  <div className="as-table-wrap">
                    <table className="as-table">
                      <thead>
                        <tr><th>SKU</th><th>المنتج</th><th className="as-num">السعر (قبل → بعد)</th><th className="as-num">التغيير</th></tr>
                      </thead>
                      <tbody>
                        {displayRows.map((r, i) => (
                          <tr key={r.variant_id ?? i}>
                            <td><code dir="ltr">{r.sku}</code></td>
                            <td>{r.product_name ?? '—'}</td>
                            <td className="as-num" dir="ltr">{r.old_price != null ? money(r.old_price) : '—'} → {money(r.new_price)}</td>
                            <td className="as-num">{money(r.delta)} ج.م{r.delta_pct != null ? ` (${money(r.delta_pct)}٪)` : ''}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
                {allRows.length > topRows.length && (
                  <button type="button" className="as-btn as-btn--sm" onClick={() => setShowAllPrices(s => !s)}>
                    {showAllPrices ? 'إخفاء باقي الصفوف' : `اعرض كل الصفوف (${allRows.length})`}
                  </button>
                )}
                {blockedRows.length > 0 && (
                  <div className="as-alert as-alert--danger" role="alert">
                    <Icon name="alert" size={16} />
                    <div className="as-alert-body">
                      <strong>صفوف مرفوضة ({blockedRows.length})</strong>
                      <span>{blockedRows.map(b => `${b.sku}: ${b.reason}`).join(' · ')}</span>
                    </div>
                  </div>
                )}
              </div>
            );
          })()}

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

          {block.type === 'confirm_action' && Boolean(block.before || block.after) && <DiffTable before={stripSections(block.before)} after={stripSections(block.after)} />}

          {block.type === 'confirm_action' && docsToVerify.length > 0 && (
            <div className="as-diff" aria-label="المستندات اللي هتتوثق">
              <div className="as-diff-head"><span>المستندات اللي هتتوثق</span><span /></div>
              <dl>
                {docsToVerify.map(d => (
                  <div className="as-diff-row" key={d.id}>
                    <dt>{d.name || `مستند #${d.id}`}</dt>
                    <dd>{d.download_url && <button type="button" className="as-btn as-btn--sm" disabled={busy} onClick={() => { setDlError(''); downloadDocument(d.download_url!, d.name || `document-${d.id}`).catch(() => setDlError('تعذّر تنزيل المستند.')); }}><Icon name="arrowDown" size={14} />تنزيل</button>}</dd>
                  </div>
                ))}
              </dl>
              {dlError && <div className="as-alert as-alert--danger" role="alert"><Icon name="alert" size={16} /><div className="as-alert-body"><span>{dlError}</span></div></div>}
            </div>
          )}
          {block.type === 'confirm_action' && typeof afterSuggestion?.source === 'string' && (
            <div className="as-alert as-alert--info" role="note"><Icon name="info" size={16} /><div className="as-alert-body"><span>{afterSuggestion.source}</span></div></div>
          )}

          {warnings.map((w, i) => (
            <div className="as-alert as-alert--warning" role="note" key={i}>
              <Icon name="alert" size={16} />
              <div className="as-alert-body"><strong>تنبيه أمني</strong><span>{w}</span></div>
            </div>
          ))}

          {pending && !expired && required && (
            <div className="as-typed">
              <label htmlFor={`typed-${String(block.action_id)}`}><Icon name="lock" size={15} />للتأكيد اكتب <b dir="ltr">{displayRequired}</b> في الخانة</label>
              <div className="as-typed-field" data-match={typedOk}>
                <input id={`typed-${String(block.action_id)}`} className="as-input" value={typed} onChange={e => setTyped(e.target.value)} disabled={disabled} dir="ltr" autoComplete="off" spellCheck={false} placeholder={displayRequired} onPaste={e => e.preventDefault()} />
                {typedOk && <Icon name="check" size={16} />}
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
              disabled={disabled || confirmationBlocked || !typedOk || (block.type === 'bulk_plan' && !selected.length)}
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

  /* ---------------- Reason picker (inventory) ---------------- */
  if (block.type === 'reason_picker') {
    const options = (block.options ?? []) as { id: string; label: string }[];
    return (
      <section className="as-block" aria-label="اختيار سبب">
        <header className="as-block-head">
          <span className="as-block-ic"><Icon name="tag" /></span>
          <div className="as-block-title">
            <h3>{String(block.purpose ?? 'اختار السبب')}</h3>
            <span>{options.length} أسباب متاحة · اختار واحد للمتابعة</span>
          </div>
        </header>
        <ul className="as-pick">
          {options.map(o => (
            <li key={o.id}>
              <button type="button" className="as-pickbtn" disabled={busy} onClick={() => onPick('reason', o.id, o.label)}>
                <span className="as-pickmain"><strong>{o.label}</strong></span>
              </button>
            </li>
          ))}
        </ul>
      </section>
    );
  }

  /* ---------------- Stock matrix (variant x branch heatmap) ---------------- */
  if (block.type === 'stock_matrix') {
    const columns = (block.columns ?? []) as string[];
    const rows = (block.rows ?? []) as (string | number)[][];
    const link = normalizeDeepLink(block.deep_link);
    const cells = (block.cell_meta ?? []) as { variant_id: number; branch_id: number; coverage_days: number | null }[][];
    return (
      <section className="as-block" aria-label="مصفوفة المخزون">
        <header className="as-block-head">
          <span className="as-block-ic"><Icon name="box" /></span>
          <div className="as-block-title"><h3>توزيع الأصناف على الفروع</h3><span>{rows.length} أصناف</span></div>
          {link ? <a className="as-linkbtn" href={link}>فتح في الشاشة</a> : null}
        </header>
        <div className="as-tablewrap" role="region" aria-label="مصفوفة المخزون" tabIndex={0}>
          <table className="as-table as-num">
            <thead><tr>{columns.map((c, i) => <th key={i} scope="col">{c}</th>)}</tr></thead>
            <tbody>
              {rows.map((r, i) => (
                <tr key={i}>
                  {r.map((v, j) => {
                    if (j === 0) return <th key={j} scope="row">{v}</th>;
                    const n = Number(v) || 0;
                    const cell = cells[i]?.[j - 1];
                    const coverage = cell?.coverage_days;
                    const color = coverage != null ? coverage < 3 ? 'rgba(200, 55, 55, .2)' : coverage < 7 ? 'rgba(180, 120, 40, .2)' : coverage > 60 ? 'rgba(45, 135, 95, .2)' : undefined : undefined;
                    return <td key={j} style={{ backgroundColor: color }}>
                      {cell && onPrompt ? <button type="button" className="as-linkbtn" title={coverage != null ? `تغطية ${coverage} يوم — اعرض الحركات` : 'بلا مبيعات مسجلة — اعرض الحركات'} onClick={() => onPrompt(`اعرض حركات الصنف variant_id=${cell.variant_id} في الفرع branch_id=${cell.branch_id}`)}>{n === 0 ? 'صفر' : v}</button> : n === 0 ? 'صفر' : v}
                    </td>;
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    );
  }

  /* ---------------- Stock diff (before/after buckets) ---------------- */
  if (block.type === 'stock_diff') {
    const before = (block.before ?? {}) as Record<string, unknown>;
    const after = (block.after ?? {}) as Record<string, unknown>;
    const keys = Array.from(new Set([...Object.keys(before), ...Object.keys(after)]));
    return (
      <section className="as-block" aria-label="فرق الرصيد">
        <header className="as-block-head">
          <span className="as-block-ic"><Icon name="layers" /></span>
          <div className="as-block-title"><h3>قبل وبعد التسوية</h3></div>
        </header>
        <div className="as-tablewrap">
          <table className="as-table as-num">
            <thead><tr><th scope="col">البند</th><th scope="col">قبل</th><th scope="col">بعد</th></tr></thead>
            <tbody>
              {keys.map(k => (
                <tr key={k}>
                  <th scope="row">{inventoryBucketLabels[k] ?? k}</th>
                  <td>{String(before[k] ?? '—')}</td>
                  <td><strong>{String(after[k] ?? '—')}</strong></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    );
  }

  /* ---------------- Balance story (grouped movements + timeline) ---------------- */
  if (block.type === 'balance_story') {
    const groups = (block.groups ?? []) as { type: string; reference_number?: string; reference_link?: string; count: number; quantity_total: number }[];
    const timeline = (block.timeline ?? []) as { id: number; created_at?: string; type: string; quantity: number; balance_after: number; reference_number?: string; reference_link?: string }[];
    const start = block.start_balance;
    return (
      <section className="as-block" aria-label="قصة الرصيد">
        <header className="as-block-head">
          <span className="as-block-ic"><Icon name="clock" /></span>
          <div className="as-block-title"><h3>إزاي الرصيد وصل لكده</h3>{start !== undefined && start !== null ? <span>بداية الفترة: {String(start)}</span> : null}</div>
        </header>
        {groups.length > 0 ? (
          <ul className="as-list">
            {groups.map((g, i) => (
              <li key={i}>
                <span>{g.type} × {g.count}</span>
                <span className="as-num">{g.quantity_total}</span>
                {g.reference_link && normalizeDeepLink(g.reference_link) ? <a className="as-linkbtn" href={normalizeDeepLink(g.reference_link)!}>{g.reference_number ?? 'المستند'}</a> : <span>{g.reference_number ?? ''}</span>}
              </li>
            ))}
          </ul>
        ) : null}
        {timeline.length > 0 ? (
          <div className="as-tablewrap">
            <table className="as-table as-num">
              <thead><tr><th scope="col">#</th><th scope="col">التاريخ</th><th scope="col">النوع</th><th scope="col">الكمية</th><th scope="col">الرصيد بعد</th><th scope="col">المرجع</th></tr></thead>
              <tbody>
                {timeline.map(t => (
                  <tr key={t.id}>
                    <td>{t.id}</td><td>{t.created_at ? new Date(t.created_at).toLocaleString('ar-EG') : '—'}</td>
                    <td>{t.type}</td><td>{t.quantity}</td><td>{t.balance_after}</td>
                    <td>{t.reference_link && normalizeDeepLink(t.reference_link) ? <a className="as-linkbtn" href={normalizeDeepLink(t.reference_link)!}>{t.reference_number ?? 'فتح'}</a> : (t.reference_number ?? '—')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
      </section>
    );
  }

  /* ---------------- Transfer timeline (4 stages + item qtys) ---------------- */
  if (block.type === 'transfer_timeline') {
    const stages = (block.stages ?? []) as { stage: string; done: boolean; at?: string; actor?: string }[];
    const items = (block.items ?? []) as { sku?: string; requested_qty?: number; approved_qty?: number; shipped_qty?: number; received_qty?: number; shortage?: number }[];
    const link = normalizeDeepLink(block.deep_link);
    const stageLabel: Record<string, string> = { draft: 'مسودة', approved: 'معتمد', shipped: 'مشحون', received: 'مستلم' };
    return (
      <section className="as-block" aria-label="مراحل التحويل">
        <header className="as-block-head">
          <span className="as-block-ic"><Icon name="refresh" /></span>
          <div className="as-block-title"><h3>مسار التحويل</h3></div>
          {link ? <a className="as-linkbtn" href={link}>فتح في الشاشة</a> : null}
        </header>
        {stages.length > 0 ? (
          <ol className="as-steps">
            {stages.map((s, i) => (
              <li key={i} data-done={s.done ? '1' : '0'}>
                <span className="as-stepdot" aria-hidden="true" />
                <span>{stageLabel[s.stage] ?? s.stage}</span>
                {s.actor ? <span>{s.actor}</span> : null}
                {s.at ? <time className="as-num">{new Date(s.at).toLocaleString('ar-EG')}</time> : null}
              </li>
            ))}
          </ol>
        ) : null}
        {items.length > 0 ? (
          <div className="as-tablewrap">
            <table className="as-table as-num">
              <thead><tr><th scope="col">الصنف</th><th scope="col">مطلوب</th><th scope="col">معتمد</th><th scope="col">مشحون</th><th scope="col">مستلم</th><th scope="col">عجز</th></tr></thead>
              <tbody>
                {items.map((it, i) => (
                  <tr key={i}>
                    <th scope="row">{it.sku ?? '—'}</th>
                    <td>{it.requested_qty ?? '—'}</td><td>{it.approved_qty ?? '—'}</td>
                    <td>{it.shipped_qty ?? '—'}</td><td>{it.received_qty ?? '—'}</td>
                    <td>{it.shortage != null && it.shortage !== 0 ? <strong>{it.shortage}</strong> : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
      </section>
    );
  }

  /* ---------------- Count sheet (parsed counts + confidence) ---------------- */
  if (block.type === 'count_sheet') {
    const rows = (block.rows ?? []) as { sku?: string; variant_id?: number; counted_qty?: number; system_qty?: number; difference?: number; confidence?: number }[];
    return (
      <section className="as-block" aria-label="ورقة العد">
        <header className="as-block-head">
          <span className="as-block-ic"><Icon name="check" /></span>
          <div className="as-block-title"><h3>بنود العد</h3><span>{rows.length} بنود</span></div>
        </header>
        <div className="as-tablewrap">
          <table className="as-table as-num">
            <thead><tr><th scope="col">الصنف</th><th scope="col">العد</th><th scope="col">النظام</th><th scope="col">الفرق</th><th scope="col">الثقة</th></tr></thead>
            <tbody>
              {rows.map((r, i) => {
                const low = (r.confidence ?? 1) < 0.8;
                return (
                  <tr key={i} data-low={low ? '1' : '0'}>
                    <th scope="row">{r.sku ?? (r.variant_id ? `#${r.variant_id}` : '—')}</th>
                    <td><strong>{r.counted_qty ?? '—'}</strong></td>
                    <td>{r.system_qty ?? '—'}</td>
                    <td>{r.difference ?? '—'}</td>
                    <td>{r.confidence != null ? `${Math.round(r.confidence * 100)}%${low ? ' — راجع' : ''}` : '—'}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </section>
    );
  }

  /* ---------------- Cost impact (WAC before/after) ---------------- */
  if (block.type === 'cost_impact') {
    const rows = (block.rows ?? []) as { sku?: string; quantity?: number; unit_cost?: number; cost_before?: number; cost_after?: number; change_pct?: number }[];
    return (
      <section className="as-block" aria-label="أثر التكلفة">
        <header className="as-block-head">
          <span className="as-block-ic"><Icon name="chart" /></span>
          <div className="as-block-title"><h3>أثر التكلفة</h3></div>
        </header>
        <div className="as-tablewrap">
          <table className="as-table as-num">
            <thead><tr><th scope="col">الصنف</th><th scope="col">الكمية</th><th scope="col">التكلفة قبل</th><th scope="col">التكلفة بعد</th><th scope="col">التغير</th></tr></thead>
            <tbody>
              {rows.map((r, i) => (
                <tr key={i}>
                  <th scope="row">{r.sku ?? '—'}</th>
                  <td>{r.quantity ?? '—'}</td>
                  <td>{r.cost_before ?? '—'}</td>
                  <td><strong>{r.cost_after ?? '—'}</strong></td>
                  <td>{r.change_pct != null ? `${r.change_pct >= 0 ? '+' : ''}${r.change_pct}%` : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    );
  }

  /* ---------------- Result ---------------- */
  if (block.type === 'result') {
    const link = normalizeDeepLink(block.deep_link);
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
              {link && <a className="as-btn as-btn--sm" href={link} style={{ textDecoration: 'none' }}><Icon name="external" size={15} />افتح في الشاشة</a>}
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
