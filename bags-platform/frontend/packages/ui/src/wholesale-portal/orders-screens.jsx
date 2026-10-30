import React, { useState, useEffect } from 'react';
import { useApp } from './context';
import { wholesaleEndpoints, accountEndpoints, defaultApiClient } from '@alfarid/api-client';
import { useApi } from './data/useApi';
import { DataBoundary } from './data/DataBoundary';
import { apiError, pageMeta, saveBlob, allPages } from './data/helpers';
import { mapOrder, mapQuote, mapReturn } from './data/mappers';
import { validateReturnFiles } from './data/validation';
import { ORDER_STATUS, PAY_METHOD, PAY_STATUS, QUOTE_STATUS, RETURN_STATUS, REFUND_METHOD } from './data/constants';
import { fmt, Money } from './data/format';
import {
  Badge, PageHead, Crumb, Panel, SkeletonRows, Empty,
  Async, Pager, Field, Modal, Banner, Thumb, Qty
} from './ui-primitives';

/* Screens 13–17 + 22: orders, order detail, quotations, returns. */

const REORDER_SKIP_LABEL = { not_found_or_inactive: 'الصنف غير موجود أو غير متاح', insufficient_stock: 'الكمية غير متاحة' };

export const ReorderModal = ({ orderId, onClose }) => {
  const { go, applyCartResponse } = useApp();
  const [busy, setBusy] = useState(true);
  const [report, setReport] = useState(null);
  const [err, setErr] = useState('');
  const request = React.useRef(null);
  useEffect(() => {
    let active = true;
    setBusy(true);
    setErr('');
    setReport(null);
    // StrictMode replays effects; re-use the same mutation rather than adding twice.
    if (request.current?.id !== orderId) request.current = {
      id: orderId,
      promise: wholesaleEndpoints.reorder(orderId).then((r) => { applyCartResponse(r.cart); return r; }),
    };
    request.current.promise
      .then((r) => { if (active) { setReport(r); setBusy(false); } })
      .catch((e) => { if (active) { setErr(apiError(e).message); setBusy(false); } });
    return () => { active = false; };
  }, [orderId, applyCartResponse]);
  return (
    <Modal title="إعادة الطلب" onClose={onClose} icon={<div className="modal-icon"><i className="fa-solid fa-rotate" /></div>}
      foot={<><button className="btn-pill" onClick={onClose}>إغلاق</button><button className="btn-pill is-dark" disabled={busy || !report} onClick={() => go('cart')}><i className="fa-solid fa-bag-shopping" /> فتح السلة</button></>}>
      <Banner kind="info">تمت إضافة البنود المتاحة للسلة <b>بأسعار اليوم</b>، مش بأسعار الطلب القديم.</Banner>
      <div className="mt-16">
        {busy && <SkeletonRows rows={3} cols={3} />}
        {err && <p className="f-error">{err}</p>}
        {report && (
          <>
            {(report.added || []).map((a, i) => (
              <div key={`a-${i}`} className="sum-row"><span><b style={{ color: 'var(--ink)' }}>{a.sku}</b></span>
                <span className="badge success">أضيف <span className="num">×{a.qty}</span></span></div>))}
            {(report.skipped || []).map((s, i) => (
              <div key={`s-${i}`} className="sum-row"><span><b style={{ color: 'var(--ink)' }}>{s.sku}</b></span>
                <span className="badge danger">تم تخطيه · {REORDER_SKIP_LABEL[s.reason] || s.reason}</span></div>))}
            {!report.added?.length && !report.skipped?.length && <p className="muted">لا توجد بنود قابلة للإضافة.</p>}
          </>
        )}
      </div>
    </Modal>
  );
};

export const CancelModal = ({ order, onClose, onDone }) => {
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const confirm = async () => {
    setBusy(true);
    setErr('');
    try {
      await accountEndpoints.cancelOrder(order.id);
      onDone();
    } catch (e) {
      setErr(apiError(e).message);
      setBusy(false);
    }
  };
  return (
    <Modal title={`إلغاء الطلب ${order.number}؟`} onClose={onClose} icon={<div className="modal-icon is-err"><i className="fa-solid fa-ban" /></div>}
      foot={<><button className="btn-pill" onClick={onClose}>تراجع</button><button className="btn-pill is-danger" disabled={busy} onClick={confirm}>{busy ? <span className="spin" /> : null} تأكيد الإلغاء</button></>}>
      {order.method === 'credit' && <p className="muted" style={{ lineHeight: 1.9 }}>هيتم إلغاء الطلب وتحرير مبلغ <b className="num" style={{ color: 'var(--ink)' }}>{fmt(order.total)} EGP</b> من الائتمان المستخدم. الإلغاء متاح طالما الطلب لم يُشحن.</p>}
      {order.method !== 'credit' && <p className="muted" style={{ lineHeight: 1.9 }}>هيتم إلغاء الطلب. الإلغاء متاح طالما الطلب لم يُشحن.</p>}
      {err && <p className="f-error" style={{ marginTop: 8 }}>{err}</p>}
    </Modal>
  );
};

/* ---------- 13. Orders ---------- */
const GROUPS = { all: null, active: 'active', delivered: 'delivered', cancelled: 'cancelled' };

export const Orders = () => {
  const { go, toast, refreshAccount } = useApp();
  const [f, setF] = useState('all');
  const [page, setPage] = useState(1);
  const [re, setRe] = useState(null);
  const [cancel, setCancel] = useState(null);

  const api = useApi((signal) => wholesaleEndpoints
    .orders({ group: GROUPS[f] || undefined, page }, signal)
    .then((r) => ({ items: (r.data || []).map(mapOrder), meta: pageMeta(r.meta) })), [f, page]);

  const list = api.data?.items || [];
  const meta = api.data?.meta || { page: 1, pages: 1, total: 0, per: 15 };

  const onCancelled = () => {
    setCancel(null);
    refreshAccount();
    api.reload();
    toast('تم إلغاء الطلب');
  };

  return (
    <>
      <PageHead en="My Orders" ar="كل طلبات حساب الجملة" />
      <section className="panel">
        <div className="toolbar">
          <div className="chips">{[['all', 'الكل'], ['active', 'جاري التنفيذ'], ['delivered', 'تم التسليم'], ['cancelled', 'ملغي']].map(([k, l]) => <button key={k} className={`chip${f === k ? ' is-on' : ''}`} onClick={() => { setF(k); setPage(1); }}>{l}</button>)}</div>
        </div>
        <DataBoundary api={api}>
          <Async rows={6} cols={6} isEmpty={!list.length} empty={<Empty icon="fa-box-open" title="مفيش طلبات" desc="لما تعمل أول طلب هيظهر هنا." action={<button className="btn-pill is-dark" onClick={() => go('catalog')}>تصفح الكتالوج</button>} />}>
            <div className="tbl-wrap"><table className="tbl">
              <thead><tr><th>رقم الطلب</th><th>التاريخ</th><th>حالة التنفيذ</th><th>طريقة الدفع</th><th>السداد</th><th className="t-end">الإجمالي</th><th></th></tr></thead>
              <tbody>{list.map((o) => (
                <tr key={o.id} className="is-click" onClick={() => go('order', o.id)}>
                  <td className="strong num">{o.number}</td>
                  <td className="num">{o.date}</td>
                  <td><Badge map={ORDER_STATUS} k={o.status} /></td>
                  <td>{PAY_METHOD[o.method]}</td>
                  <td><Badge map={PAY_STATUS} k={o.pay} /></td>
                  <td className="t-end strong num">{fmt(o.total)}</td>
                  <td onClick={(e) => e.stopPropagation()}><div className="row-actions">
                    <button className="btn-link" onClick={() => setRe(o.id)}>إعادة الطلب</button>
                    {o.cancellable && <button className="btn-link danger" onClick={() => setCancel(o)}>إلغاء</button>}
                    <button className="btn-link muted" onClick={() => go('order', o.id)}>التفاصيل</button>
                  </div></td>
                </tr>))}</tbody>
            </table></div>
            <Pager page={meta.page} pages={meta.pages} total={meta.total} per={meta.per} onPage={setPage} />
          </Async>
        </DataBoundary>
      </section>
      {re && <ReorderModal orderId={re} onClose={() => setRe(null)} />}
      {cancel && <CancelModal order={cancel} onClose={() => setCancel(null)} onDone={onCancelled} />}
    </>
  );
};

/* ---------- 14. Order detail + tracking ---------- */
export const OrderDetail = ({ id }) => {
  const { go, refreshAccount, route } = useApp();
  const [re, setRe] = useState(false);
  const [cancel, setCancel] = useState(false);

  const api = useApi((signal) => wholesaleEndpoints.order(id, signal).then(mapOrder), [id]);
  const o = api.data;

  const onCancelled = () => {
    setCancel(false);
    refreshAccount();
    api.reload();
  };

  return (
    <>
      <PageHead en={o?.number || ''} crumb={<Crumb items={[{ label: 'طلباتي', go: () => go('orders') }, { label: o?.number || '', mono: true }]} />} ar={o ? <>تاريخ الطلب <span className="num">{o.date}</span></> : null}>
        {o && <Badge map={ORDER_STATUS} k={o.status} lg />}
        {o && <button className="btn-pill" onClick={() => setRe(true)}><i className="fa-solid fa-rotate" /> إعادة الطلب</button>}
        {o?.returnable && <button className="btn-pill" onClick={() => go('returnNew', o.id)}><i className="fa-solid fa-rotate-left" /> طلب إرجاع</button>}
        {o?.cancellable && <button className="btn-pill is-danger" onClick={() => setCancel(true)}>إلغاء الطلب</button>}
      </PageHead>
      <DataBoundary api={api}>
        <Async rows={5} cols={4} empty={<Empty icon="fa-box-open" title="الطلب غير موجود" />}>
          {o && (
          <div className="grid-2">
            <div className="stack">
              <Panel title="البنود" en="ITEMS" body={false}>
                <div className="tbl-wrap"><table className="tbl">
                  <thead><tr><th>المنتج</th><th>الكمية</th><th>سعر الوحدة</th><th>الخصم</th><th className="t-end">الإجمالي</th></tr></thead>
                  <tbody>{o.items.map((it) => (
                    <tr key={it.id || it.sku}><td><div className="prod-cell"><Thumb sm /><div><div className="prod-name">{it.name}</div><div className="sku">{it.sku}</div></div></div></td>
                      <td className="num">{it.qty}</td><td className="num">{fmt(it.unit)}</td><td className="num" style={{ color: it.discount ? 'var(--teal)' : null }}>{it.discount ? '− ' + fmt(it.discount) : '—'}</td>
                      <td className="t-end strong num">{fmt(it.line_total)}</td></tr>))}</tbody>
                </table></div>
              </Panel>
              <div className="grid-2e" style={{ gap: 24 }}>
                <Panel title="المستلم والعنوان" en="DELIVERY">
                  <dl className="kv"><dt>المستلم</dt><dd>{o.recipient}</dd><dt>التليفون</dt><dd className="num">{o.phone}</dd><dt>المدينة</dt><dd>{o.city}</dd><dt>العنوان</dt><dd>{o.address}</dd></dl>
                </Panel>
                <Panel title="الدفع" en="PAYMENT">
                  <dl className="kv"><dt>الطريقة</dt><dd>{PAY_METHOD[o.method]}</dd><dt>حالة السداد</dt><dd><Badge map={PAY_STATUS} k={o.pay} /></dd></dl>
                  {o.pay === 'unpaid' && o.method === 'credit' && <button className="btn-link mt-16" onClick={() => go('noticeNew', o.id)}>تسجيل إشعار سداد لهذا الطلب</button>}
                </Panel>
              </div>
            </div>
            <div className="stack">
              <Panel title="تفصيل المبالغ" en="TOTALS">
                <div className="sum-row"><span>المنتجات</span><strong className="num">{fmt(o.subtotal)}</strong></div>
                <div className="sum-row is-neg"><span>الخصم</span><strong className="num">{o.discount ? '− ' + fmt(o.discount) : '—'}</strong></div>
                <div className="sum-row"><span>الشحن</span><strong className="num">{fmt(o.shipping)}</strong></div>
                <div className="sum-row"><span>رسوم التحصيل</span><strong className="num">{fmt(o.cod_fee)}</strong></div>
                <div className="sum-row"><span>الضريبة</span><strong className="num">{fmt(o.tax)}</strong></div>
                <div className="sum-total"><span>الإجمالي</span><span className="num">{fmt(o.total)}</span></div>
              </Panel>
              <Panel title="سجل الحالة" en="TRACKING">
                <ul className="tl">{(o.track || []).map(([s, t, st], i) => (
                  <li key={i} className={st === 'now' ? 'is-now' : st === 'done' ? 'is-done' : 'is-future'}>
                    <div className="tl-title">{ORDER_STATUS[s] ? ORDER_STATUS[s][0] : s}</div>{t && <div className="tl-meta num">{t}</div>}</li>))}</ul>
                <p className="note"><i className="fa-solid fa-circle-info" />التتبع من خدمة الشحن برقم الطلب ورقم الهاتف.</p>
              </Panel>
            </div>
          </div>
          )}
        </Async>
      </DataBoundary>
      {re && o && <ReorderModal orderId={o.id} onClose={() => setRe(false)} />}
      {cancel && o && <CancelModal order={o} onClose={() => setCancel(false)} onDone={onCancelled} />}
    </>
  );
};

/* ---------- 15. Quotations list ---------- */
const QUOTE_FILTER_REV = { priced: 'priced_by_admin', converted: 'converted_to_order' };

export const Quotes = () => {
  const { go } = useApp();
  const [f, setF] = useState('all');
  const [page, setPage] = useState(1);

  const api = useApi((signal) => wholesaleEndpoints
    .quotes({ status: QUOTE_FILTER_REV[f] || (f === 'all' ? undefined : f), page }, signal)
    .then((r) => ({ items: (r.data || []).map(mapQuote), meta: pageMeta(r.meta) })), [f, page]);

  const list = api.data?.items || [];
  const meta = api.data?.meta || { page: 1, pages: 1, total: 0, per: 15 };

  return (
    <>
      <PageHead en="Quotations" ar="اطلب تسعير خاص للكميات الكبيرة وتابع رد الإدارة">
        <button className="btn-pill is-dark" onClick={() => go('quoteNew')}><i className="fa-solid fa-plus" /> طلب عرض سعر</button>
      </PageHead>
      <section className="panel">
        <div className="toolbar"><div className="chips">
          <button className={`chip${f === 'all' ? ' is-on' : ''}`} onClick={() => { setF('all'); setPage(1); }}>الكل</button>
          {Object.entries(QUOTE_STATUS).map(([k, [l]]) => <button key={k} className={`chip${f === k ? ' is-on' : ''}`} onClick={() => { setF(k); setPage(1); }}>{l}</button>)}
        </div></div>
        <DataBoundary api={api}>
          <Async rows={6} cols={5} isEmpty={!list.length} empty={<Empty icon="fa-file-signature" title="لا توجد عروض أسعار بهذه الحالة" action={<button className="btn-pill is-dark" onClick={() => go('quoteNew')}>طلب عرض سعر</button>} />}>
            <div className="tbl-wrap"><table className="tbl">
              <thead><tr><th>رقم العرض</th><th>تاريخ الإنشاء</th><th>الحالة</th><th>صالح حتى</th><th className="t-end">الإجمالي</th><th></th></tr></thead>
              <tbody>{list.map((q) => {
                const daysLeft = q.valid_until ? Math.ceil((new Date(q.valid_until).getTime() - Date.now()) / 864e5) : null;
                return (
                  <tr key={q.id} className="is-click" onClick={() => go(q.status === 'draft' ? 'quoteEdit' : 'quote', q.id)}>
                    <td className="strong num">{q.number}</td><td className="num">{q.created}</td><td><Badge map={QUOTE_STATUS} k={q.status} /></td>
                    <td className="num">{q.valid_until || '—'}{q.status === 'priced' && daysLeft != null && daysLeft >= 0 && <span className="badge warning" style={{ marginInlineStart: 8 }}>باقي <span className="num">{daysLeft}</span> يوم</span>}</td>
                    <td className="t-end strong num">{q.total != null ? fmt(q.total) : '—'}</td>
                    <td className="t-end"><button className="btn-link muted">{q.status === 'draft' ? 'تعديل' : q.status === 'priced' ? 'مراجعة والرد' : 'فتح'}</button></td>
                  </tr>);
              })}</tbody>
            </table></div>
            <Pager page={meta.page} pages={meta.pages} total={meta.total} per={meta.per} onPage={setPage} />
          </Async>
        </DataBoundary>
      </section>
    </>
  );
};

/* ---------- 16. Create / edit quote ---------- */
export const QuoteForm = ({ id }) => {
  const { go, toast, route } = useApp();
  const editingId = id ?? route.id;
  const [items, setItems] = useState([]);
  const [note, setNote] = useState('');
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [matches, setMatches] = useState([]);
  const [errs, setErrs] = useState({});
  const [confirm, setConfirm] = useState(null);
  const [busy, setBusy] = useState(false);
  const savedId = React.useRef(editingId);
  const quoteApi = useApi((signal) => wholesaleEndpoints.quote(editingId, signal).then(mapQuote), [editingId], !!editingId);

  useEffect(() => {
    if (quoteApi.state === 'ready' && quoteApi.data) {
        const m = quoteApi.data;
        setItems((m.items || []).map((it) => ({ variant_id: it.variant_id, name: it.name, sku: it.sku, qty: it.qty, target: it.target != null ? String(it.target) : '' })));
        setNote(m.trader_note || '');
    }
    savedId.current = editingId;
  }, [editingId, quoteApi.data]);

  useEffect(() => {
    const t = setTimeout(() => setDebounced(search), 400);
    return () => clearTimeout(t);
  }, [search]);

  useEffect(() => {
    if (!debounced.trim()) { setMatches([]); return; }
    const ctrl = new AbortController();
    wholesaleEndpoints.products({ search: debounced.trim(), per_page: 5 }, ctrl.signal)
      .then((r) => {
        const vars = (r.data || []).flatMap((p) => (p.variants || []).map((v) => ({
          variant_id: v.id, name: p.name, sku: v.sku, price: v.your_price != null ? Number(v.your_price) : null,
        })));
        if (!ctrl.signal.aborted) setMatches(vars);
      })
      .catch(() => {});
    return () => ctrl.abort();
  }, [debounced]);

  const upd = (i, k, val) => setItems(items.map((it, j) => j === i ? { ...it, [k]: val } : it));

  const save = async () => {
    const e = {};
    if (!items.length) e.items = 'أضف بند واحد على الأقل.';
    items.forEach((it, i) => { if (!it.qty || Number(it.qty) <= 0) e[`items.${i}.qty`] = 'الكمية مطلوبة.'; });
    if (note.length > 2000) e.note = 'الحد الأقصى 2000 حرف.';
    setErrs(e);
    if (Object.keys(e).length) return null;
    const body = {
      customer_notes: note || null,
      items: items.map((it) => ({ product_variant_id: it.variant_id, qty: Number(it.qty), requested_target_price: it.target ? Number(it.target) : null })),
    };
    setBusy(true);
    try {
      const saved = savedId.current ? await wholesaleEndpoints.updateQuote(savedId.current, body) : await wholesaleEndpoints.createQuote(body);
      savedId.current = saved.id;
      return saved;
    } catch (err) {
      const info = apiError(err);
      const mapped = {};
      Object.entries(info.fields || {}).forEach(([k, msgs]) => { mapped[k === 'customer_notes' ? 'note' : k] = Array.isArray(msgs) ? msgs[0] : String(msgs); });
      setErrs(mapped);
      if (!Object.keys(mapped).length) toast(info.message);
      return null;
    } finally {
      setBusy(false);
    }
  };

  const saveDraft = async () => {
    const saved = await save();
    if (saved) { toast('تم حفظ المسودة'); go('quotes'); }
  };

  const sendForPricing = async () => {
    const saved = await save();
    if (!saved) return;
    setBusy(true);
    try {
      const submitted = await wholesaleEndpoints.submitQuote(saved.id);
      go('quote', submitted.id);
    } catch (err) {
      toast(apiError(err).message);
    } finally {
      setBusy(false);
      setConfirm(null);
    }
  };

  const removeDraft = async () => {
    setBusy(true);
    try {
      await wholesaleEndpoints.deleteQuote(editingId);
      toast('تم حذف المسودة');
      go('quotes');
    } catch (err) {
      toast(apiError(err).message);
    } finally {
      setBusy(false);
      setConfirm(null);
    }
  };

  if (editingId && quoteApi.state !== 'ready') return (<><PageHead en="Quote" /><section className="panel"><DataBoundary api={quoteApi}><Async rows={5} cols={5} /></DataBoundary></section></>);

  return (
    <>
      <PageHead en={editingId ? 'تعديل مسودة' : 'New Quote'} crumb={<Crumb items={[{ label: 'عروض الأسعار', go: () => go('quotes') }, { label: editingId ? 'تعديل مسودة' : 'طلب جديد' }]} />} ar={editingId ? 'مسودة — يمكنك التعديل قبل الإرسال' : 'اختر المنتجات والكميات، واقترح السعر المستهدف لو حابب'}>
        {editingId && <button className="btn-pill is-danger" onClick={() => setConfirm('delete')}><i className="fa-regular fa-trash-can" /> حذف المسودة</button>}
      </PageHead>
      <div className="grid-2" style={{ gridTemplateColumns: '1fr 340px' }}>
        <section className="panel">
          <div className="toolbar" style={{ position: 'relative' }}>
            <div className="search" style={{ flex: 1 }}><i className="fa-solid fa-plus" /><input className="line-input" placeholder="أضف منتج — ابحث بالاسم أو SKU" value={search} onChange={(e) => setSearch(e.target.value)} /></div>
            <span className="soft small"><span className="num">{items.length}</span> / <span className="num">100</span> بند</span>
            {matches.length > 0 && (
              <div className="qf-suggest">{matches.map((v) => (
                <button key={v.variant_id} onClick={() => { setItems([...items, { variant_id: v.variant_id, name: v.name, sku: v.sku, qty: 1, target: '' }]); setSearch(''); setMatches([]); }}>
                  <Thumb sm /><span style={{ flex: 1 }}><b>{v.name}</b><span className="sku">{v.sku}</span></span><span className="num small">{v.price != null ? fmt(v.price) : '—'}</span></button>))}</div>
            )}
          </div>
          {items.length === 0 ? <Empty icon="fa-list" title="مفيش بنود" desc="ابحث وأضف المنتجات اللي عايز تسعيرها." /> : (
            <div className="tbl-wrap"><table className="tbl">
              <thead><tr><th>المتغير</th><th>الكمية</th><th>السعر المستهدف <span className="f-opt">(اختياري)</span></th><th></th></tr></thead>
              <tbody>{items.map((it, i) => (
                <tr key={i} className={errs[`items.${i}.qty`] ? 'is-error' : ''}>
                  <td><div className="prod-cell"><Thumb sm /><div><div className="prod-name">{it.name}</div><div className="soft small"><span className="sku">{it.sku}</span></div></div></div></td>
                  <td><Qty value={it.qty} onChange={(n) => upd(i, 'qty', n)} />{errs[`items.${i}.qty`] && <p className="f-error">{errs[`items.${i}.qty`]}</p>}</td>
                  <td><input className="box-input ltr" style={{ width: 120 }} placeholder="0.00" value={it.target} onChange={(e) => upd(i, 'target', e.target.value.replace(/[^\d.]/g, ''))} />{errs[`items.${i}.requested_target_price`] && <p className="f-error">{errs[`items.${i}.requested_target_price`]}</p>}{errs[`items.${i}.product_variant_id`] && <p className="f-error">{errs[`items.${i}.product_variant_id`]}</p>}</td>
                  <td><button className="btn-link muted" onClick={() => setItems(items.filter((_, j) => j !== i))} aria-label="حذف"><i className="fa-solid fa-xmark" /></button></td>
                </tr>))}</tbody>
            </table></div>
          )}
          {errs.items && <p className="f-error" style={{ padding: '0 24px 16px' }}>{errs.items}</p>}
        </section>
        <aside className="stack" style={{ position: 'sticky', top: 100 }}>
          <Panel title="ملاحظات للإدارة">
            <Field label="ملاحظات التاجر" opt counter={<span className="f-counter" style={{ color: note.length > 2000 ? '#b91c1c' : null }}>{note.length}/2000</span>} error={errs.note}>
              <textarea className="line-input" rows="5" value={note} placeholder="مواعيد التسليم، تغليف خاص، شروط الدفع…" onChange={(e) => setNote(e.target.value)} />
            </Field>
            <div className="divider" />
            <div className="sum-row"><span>عدد البنود</span><strong className="num">{items.length}</strong></div>
            <div className="sum-row"><span>إجمالي الكميات</span><strong className="num">{items.reduce((a, it) => a + Number(it.qty || 0), 0)}</strong></div>
            <button className="btn-block mt-24" disabled={busy} onClick={() => setConfirm('submit')}><i className="fa-solid fa-paper-plane" /> إرسال للإدارة للتسعير</button>
            <button className="btn-pill mt-8" style={{ width: '100%', justifyContent: 'center' }} disabled={busy} onClick={saveDraft}><i className="fa-regular fa-floppy-disk" /> حفظ كمسودة</button>
          </Panel>
          <p className="note"><i className="fa-solid fa-circle-info" />بعد الإرسال مش هتقدر تعدّل الطلب. الإدارة هترد بالأسعار وتاريخ صلاحية العرض.</p>
        </aside>
      </div>
      {confirm === 'submit' && <Modal title="إرسال طلب التسعير؟" onClose={() => setConfirm(null)} icon={<div className="modal-icon"><i className="fa-solid fa-paper-plane" /></div>}
        foot={<><button className="btn-pill" onClick={() => setConfirm(null)}>رجوع</button><button className="btn-pill is-dark" disabled={busy} onClick={sendForPricing}>{busy ? <span className="spin" /> : 'إرسال'}</button></>}>
        <p className="muted">هيتم إرسال <span className="num">{items.length}</span> بند للإدارة. الحالة هتتغير إلى «مطلوب تسعيره».</p></Modal>}
      {confirm === 'delete' && <Modal title="حذف المسودة؟" onClose={() => setConfirm(null)} icon={<div className="modal-icon is-err"><i className="fa-solid fa-trash-can" /></div>}
        foot={<><button className="btn-pill" onClick={() => setConfirm(null)}>رجوع</button><button className="btn-pill is-danger" disabled={busy} onClick={removeDraft}>{busy ? <span className="spin" /> : 'حذف'}</button></>}>
        <p className="muted">الإجراء ده نهائي.</p></Modal>}
    </>
  );
};

/* ---------- 17. Quote detail ---------- */
export const QuoteDetail = ({ id }) => {
  const { go, toast, refreshAccount } = useApp();
  const [confirm, setConfirm] = useState(null);
  const [busy, setBusy] = useState(false);

  const api = useApi((signal) => wholesaleEndpoints.quote(id, signal).then(mapQuote), [id]);
  const q = api.data;

  const act = async (kind) => {
    setBusy(true);
    try {
      if (kind === 'accept') {
        await wholesaleEndpoints.acceptQuote(id);
        toast('تم قبول العرض');
      } else {
        await wholesaleEndpoints.rejectQuote(id);
        toast('تم رفض العرض');
      }
      await api.reload();
      refreshAccount();
    } catch (e) {
      toast(apiError(e).message);
      await api.reload();
    } finally {
      setBusy(false);
      setConfirm(null);
    }
  };

  return (
    <>
      <PageHead en={q?.number || ''} crumb={<Crumb items={[{ label: 'عروض الأسعار', go: () => go('quotes') }, { label: q?.number || '', mono: true }]} />} ar={q ? <>أُنشئ في <span className="num">{q.created}</span>{q.valid_until ? <> · صالح حتى <span className="num">{q.valid_until}</span></> : null}</> : null}>
        {q && <Badge map={QUOTE_STATUS} k={q.status} lg />}
      </PageHead>
      <DataBoundary api={api}>
        <Async rows={5} cols={4} empty={<Empty icon="fa-file-signature" title="العرض غير موجود" />}>
          {q && (
          <>
            {q.status === 'priced' && <div className="mb-24"><Banner kind="info" icon="fa-clock" action={<div className="row"><button className="btn-pill is-danger sm" onClick={() => setConfirm('reject')}>رفض</button><button className="btn-pill is-dark sm" onClick={() => setConfirm('accept')}>قبول العرض</button></div>}><b>الإدارة سعّرت العرض.</b> راجع الأسعار وقرر قبل <span className="num">{q.valid_until}</span> — بعدها العرض بينتهي تلقائيًا.</Banner></div>}
            {q.status === 'requested' && <div className="mb-24"><Banner kind="warn" icon="fa-hourglass-half">الطلب عند الإدارة للتسعير. هيوصلك إشعار لما يتم الرد.</Banner></div>}
            {q.status === 'expired' && <div className="mb-24"><Banner kind="error" icon="fa-calendar-xmark">انتهت صلاحية العرض ولم يعد قابلًا للقبول. تقدر تطلب عرض جديد.</Banner></div>}
            {q.status === 'converted' && <div className="mb-24"><Banner kind="ok" action={q.converted_order_id && <button className="btn-pill sm" onClick={() => go('order', q.converted_order_id)}>فتح الطلب <span className="num">{q.converted_order_id}</span></button>}>تم تحويل العرض لطلب.</Banner></div>}
            {q.status === 'accepted' && <div className="mb-24"><Banner kind="ok">تم قبول العرض. الإدارة هتحوله لطلب وهيظهر في طلباتك.</Banner></div>}
            <div className="grid-2">
              <Panel title="البنود" en="LINE ITEMS" body={false}>
                <div className="tbl-wrap"><table className="tbl">
                  <thead><tr><th>المنتج</th><th>الكمية</th><th>السعر المطلوب</th><th>السعر المعروض</th><th className="t-end">إجمالي البند</th></tr></thead>
                  <tbody>{q.items.map((it) => (
                    <tr key={it.id || it.sku}><td><div className="prod-cell"><Thumb sm /><div><div className="prod-name">{it.name}</div><div className="sku">{it.sku}</div></div></div></td>
                      <td className="num">{it.qty}</td><td className="num muted">{it.target != null ? fmt(it.target) : '—'}</td>
                      <td className="num strong">{it.offered != null ? fmt(it.offered) : <span className="soft">في الانتظار</span>}</td>
                      <td className="t-end strong num">{it.offered != null ? fmt(it.line_total) : '—'}</td></tr>))}</tbody>
                </table></div>
              </Panel>
              <div className="stack">
                <Panel title="الإجماليات" en="TOTALS">
                  <div className="sum-row"><span>الإجمالي قبل الخصم</span><strong className="num">{q.subtotal != null ? fmt(q.subtotal) : '—'}</strong></div>
                  <div className="sum-row is-neg"><span>الخصم</span><strong className="num">{q.discount ? '− ' + fmt(q.discount) : '—'}</strong></div>
                  <div className="sum-row"><span>الضريبة</span><strong className="num">{q.tax != null ? fmt(q.tax) : '—'}</strong></div>
                  <div className="sum-total"><span>الإجمالي النهائي</span><strong className="num">{q.total != null ? fmt(q.total) : '—'}</strong></div>
                </Panel>
                <Panel title="الملاحظات" en="NOTES">
                  <div className="note-bubble"><span>ملاحظات التاجر</span><p>{q.trader_note || <span className="soft">—</span>}</p></div>
                  <div className="note-bubble is-admin"><span>رد الإدارة</span><p>{q.admin_note || <span className="soft">لم يتم الرد بعد</span>}</p></div>
                </Panel>
              </div>
            </div>
          </>
          )}
        </Async>
      </DataBoundary>
      {confirm && <Modal title={confirm === 'accept' ? 'قبول عرض السعر؟' : 'رفض عرض السعر؟'} onClose={() => setConfirm(null)} icon={<div className={`modal-icon${confirm === 'reject' ? ' is-err' : ''}`}><i className={`fa-solid ${confirm === 'accept' ? 'fa-check' : 'fa-xmark'}`} /></div>}
        foot={<><button className="btn-pill" onClick={() => setConfirm(null)}>رجوع</button><button className={`btn-pill ${confirm === 'accept' ? 'is-dark' : 'is-danger'}`} disabled={busy} onClick={() => act(confirm)}>{busy ? <span className="spin" /> : confirm === 'accept' ? 'قبول' : 'رفض'}</button></>}>
        <p className="muted" style={{ lineHeight: 1.9 }}>{confirm === 'accept' && q ? <>بقبولك العرض بإجمالي <b className="num" style={{ color: 'var(--ink)' }}>{fmt(q.total)} EGP</b> الإدارة هتحوله لطلب.</> : 'الإجراء نهائي، ولن يكون العرض قابلًا للقبول بعد الرفض.'}</p></Modal>}
    </>
  );
};

/* ---------- 22. Returns ---------- */
export const Returns = () => {
  const { go } = useApp();
  const [page, setPage] = useState(1);

  const api = useApi(() => accountEndpoints.returns(page)
    .then((r) => ({ items: (r.data || []).map(mapReturn), meta: pageMeta(r.meta) })), [page]);
  const list = api.data?.items || [];
  const meta = api.data?.meta || { page: 1, pages: 1, total: 0, per: 20 };

  return (
    <>
      <PageHead en="Returns" ar="طلبات الإرجاع والاسترداد"><button className="btn-pill is-dark" onClick={() => go('returnNew')}><i className="fa-solid fa-plus" /> طلب إرجاع</button></PageHead>
      <section className="panel">
        <DataBoundary api={api}>
          <Async rows={4} cols={5} isEmpty={!list.length} empty={<Empty icon="fa-rotate-left" title="مفيش مرتجعات" desc="تقدر تطلب إرجاع لأي طلب مؤهل بعد التسليم." />}>
            <div className="tbl-wrap"><table className="tbl">
              <thead><tr><th>رقم المرتجع</th><th>الطلب</th><th>الحالة</th><th>مبلغ الاسترداد</th><th>التاريخ</th><th></th></tr></thead>
              <tbody>{list.map((r) => (
                <tr key={r.id} className="is-click" onClick={() => go('return', r.id)}>
                  <td className="strong num">{r.number}</td><td className="num">{r.order}</td><td><Badge map={RETURN_STATUS} k={r.status} /></td>
                  <td className="strong num">{fmt(r.refund)}</td><td className="num">{r.date}</td><td className="t-end"><button className="btn-link muted">التفاصيل</button></td></tr>))}</tbody>
            </table></div>
            <Pager page={meta.page} pages={meta.pages} total={meta.total} per={meta.per} onPage={setPage} />
          </Async>
        </DataBoundary>
      </section>
    </>
  );
};

export const ReturnForm = ({ orderId }) => {
  const { go, toast, route } = useApp();
  const preselect = orderId ?? route.id;
  const [oid, setOid] = useState(preselect || null);
  const [sel, setSel] = useState({});
  const [reason, setReason] = useState('');
  const [method, setMethod] = useState('');
  const [errs, setErrs] = useState({});
  const [busy, setBusy] = useState(false);

  const eligibleApi = useApi((signal) => allPages((page, signal) => wholesaleEndpoints.orders({ group: 'delivered', page }, signal), signal)
    .then((rows) => rows.map(mapOrder).filter((o) => o.returnable)), []);
  const eligible = eligibleApi.data || [];
  useEffect(() => { if (!oid && eligible.length) setOid(eligible[0].id); }, [oid, eligible]);

  const orderApi = useApi((signal) => wholesaleEndpoints.order(oid, signal).then(mapOrder), [oid], !!oid);
  const order = orderApi.state === 'ready' && orderApi.data?.id === oid ? orderApi.data : null;
  useEffect(() => { setSel({}); }, [oid]);

  const toggle = (id) => setSel((s) => ({ ...s, [id]: s[id] ? undefined : { qty: 1, reason: '', files: [] } }));
  const set = (id, k, v) => setSel((s) => ({ ...s, [id]: { ...s[id], [k]: v } }));
  const chosen = Object.entries(sel).filter(([, v]) => v);
  const totalFiles = chosen.reduce((a, [, v]) => a + v.files.length, 0);

  const addFiles = (id, list) => {
    const cur = sel[id];
    if (!cur) return;
    const next = [...cur.files, ...list];
    const error = validateReturnFiles(chosen.map(([itemId, value]) => String(itemId) === String(id) ? next : value.files));
    setErrs((previous) => ({ ...previous, files: error }));
    if (error) { toast(error); return; }
    set(id, 'files', next);
  };

  const submit = async () => {
    const e = {};
    if (!oid || !order || !order.returnable) e.order = 'اختر طلبًا مؤهلًا للإرجاع.';
    if (!chosen.length) e.items = 'اختر بند واحد على الأقل.';
    if (!reason.trim()) e.reason = 'السبب العام مطلوب.';
    chosen.forEach(([itemId, v]) => {
      if (v.reason.length > 255) e[itemId] = 'الحد الأقصى 255 حرف.';
      if (!Number.isInteger(Number(v.qty)) || Number(v.qty) < 1) e[`${itemId}-qty`] = 'كمية صحيحة مطلوبة.';
    });
    const fileError = validateReturnFiles(chosen.map(([, v]) => v.files));
    if (fileError) e.files = fileError;
    if (method && !REFUND_METHOD[method]) e.method = 'طريقة استرداد غير صالحة.';
    setErrs(e);
    if (Object.keys(e).length) return;
    const fd = new FormData();
    fd.append('order_id', String(oid));
    fd.append('reason', reason);
    if (method) fd.append('refund_method', method);
    chosen.forEach(([itemId, v], i) => {
      fd.append(`items[${i}][order_item_id]`, String(itemId));
      fd.append(`items[${i}][requested_qty]`, String(Math.trunc(Number(v.qty))));
      if (v.reason) fd.append(`items[${i}][item_reason]`, v.reason);
      v.files.forEach((fl) => fd.append(`items[${i}][attachments][]`, fl));
    });
    setBusy(true);
    try {
      const r = await accountEndpoints.createReturn(fd);
      go('return', r.id);
    } catch (err) {
      const info = apiError(err);
      const mapped = {};
      Object.entries(info.fields || {}).forEach(([k, msgs]) => {
        const parts = k.split('.');
        const itemId = parts[0] === 'items' ? chosen[Number(parts[1])]?.[0] : null;
        const key = k === 'order_id' ? 'order' : k === 'refund_method' ? 'method'
          : itemId ? (parts[2] === 'requested_qty' ? `${itemId}-qty` : parts[2] === 'item_reason' ? itemId : parts[2] === 'attachments' ? 'files' : 'items') : k;
        mapped[key] = Array.isArray(msgs) ? msgs[0] : String(msgs);
      });
      setErrs(mapped);
      if (!Object.keys(mapped).length) toast(info.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <PageHead en="New Return" crumb={<Crumb items={[{ label: 'المرتجعات', go: () => go('returns') }, { label: 'طلب جديد' }]} />} ar="اختر الطلب والبنود، ووضح السبب" />
      <div className="grid-2" style={{ gridTemplateColumns: '1fr 340px' }}>
        <div className="stack">
          <Panel title="الطلب المؤهل" en="ORDER">
            {errs.order && <p className="f-error">{errs.order}</p>}
            <DataBoundary api={eligibleApi}><Async rows={2} cols={2} isEmpty={!eligible.length} empty={<Empty title="لا توجد طلبات مؤهلة للإرجاع حاليًا." />}>
            <div className="addr-picks">{eligible.map((x) => (
              <button key={x.id} className={`addr-pick${oid === x.id ? ' is-on' : ''}`} onClick={() => { setOid(x.id); }}><b className="num">{x.number}</b><span>تم التسليم · <span className="num">{x.date}</span> · <span className="num">{fmt(x.total)}</span> EGP</span></button>))}
              {!eligible.length && <p className="muted">لا توجد طلبات مؤهلة للإرجاع حاليًا.</p>}</div>
            </Async></DataBoundary>
            <p className="note mt-16"><i className="fa-solid fa-circle-info" />الطلبات المؤهلة بس هي اللي بتظهر هنا (تم تسليمها وضمن مدة الإرجاع).</p>
          </Panel>
          <Panel title="البنود المرتجعة" en="ITEMS">
            {errs.items && <p className="f-error" style={{ marginTop: 0 }}>{errs.items}</p>}
            {oid && <DataBoundary api={orderApi}><Async rows={3} cols={3}>
            {(order?.items || []).map((it) => {
              const s = sel[it.id];
              return (
                <div key={it.id} className={`ret-item${s ? ' is-on' : ''}`}>
                  <label className="row" style={{ cursor: 'pointer' }}>
                    <input type="checkbox" checked={!!s} onChange={() => toggle(it.id)} style={{ accentColor: '#111', width: 16, height: 16 }} />
                    <Thumb sm /><div style={{ flex: 1 }}><div className="prod-name">{it.name}</div><div className="soft small"><span className="sku">{it.sku}</span> · تم شراء <span className="num">{it.qty}</span> · <span className="num">{fmt(it.unit)}</span></div></div>
                  </label>
                  {s && (
                    <div className="fields mt-16" style={{ paddingInlineStart: 42 }}>
                      <Field label="الكمية المرتجعة" req hint={`حتى ${it.qty}`} error={errs[`${it.id}-qty`]}><Qty value={s.qty} min={1} onChange={(n) => set(it.id, 'qty', Math.min(n, it.qty))} /></Field>
                      <Field label="سبب البند" opt error={errs[it.id]}><input className={`line-input${errs[it.id] ? ' is-invalid' : ''}`} value={s.reason} placeholder="مثال: عيب في السحّاب" onChange={(e) => set(it.id, 'reason', e.target.value)} /></Field>
                      <Field label="صور" opt span hint="حتى 5 صور للبند · 5 ميجابايت للصورة · JPG / PNG / WebP" error={errs.files}>
                        <div className="row wrap">
                          {s.files.map((fl, k) => <button key={k} type="button" className="photo-chip" title={`حذف ${fl.name}`} onClick={() => { set(it.id, 'files', s.files.filter((_, index) => index !== k)); setErrs((previous) => ({ ...previous, files: null })); }}><i className="fa-regular fa-image" /></button>)}
                          {s.files.length < 5 && totalFiles < 10 && <label className="drop sm" style={{ minHeight: 52, width: 52, padding: 0, justifyContent: 'center', cursor: 'pointer' }}><input type="file" accept=".jpg,.jpeg,.png,.webp" multiple style={{ display: 'none' }} onChange={(e) => { addFiles(it.id, Array.from(e.target.files || [])); e.target.value = ''; }} /><i className="fa-solid fa-plus" /></label>}
                        </div>
                      </Field>
                    </div>
                  )}
                </div>);
            })}
            </Async></DataBoundary>}
            {errs.files && <p className="f-error" role="alert">{errs.files}</p>}
          </Panel>
        </div>
        <aside className="stack" style={{ position: 'sticky', top: 100 }}>
          <Panel title="السبب والاسترداد">
            <Field label="السبب العام" req error={errs.reason}><textarea className={`line-input${errs.reason ? ' is-invalid' : ''}`} rows="3" value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
            <div className="f-label mt-24">طريقة الاسترداد</div>
            <div className="radio-list">{Object.entries(REFUND_METHOD).map(([k, l]) => <label key={k} className="radio-row"><span className="radio-left"><input type="radio" checked={method === k} onChange={() => setMethod(k)} /><span className="radio-title">{l}</span></span></label>)}</div>
            {errs.method && <p className="f-error">{errs.method}</p>}
            <div className="sum-row mt-16"><span>صور مرفقة</span><strong className="num" style={{ color: totalFiles > 10 ? '#b91c1c' : null }}>{totalFiles} / 10</strong></div>
            <button className="btn-block mt-24" disabled={busy || !order || !order.returnable} onClick={submit}>{busy ? <><span className="spin" /> جارٍ التقديم…</> : 'تقديم طلب الإرجاع'}</button>
          </Panel>
          <p className="note"><i className="fa-solid fa-circle-info" />إجمالي حجم الصور حتى 25 ميجابايت. مبلغ الاسترداد النهائي بيتحدد بعد فحص المرتجع.</p>
        </aside>
      </div>
    </>
  );
};

export const ReturnDetail = ({ id }) => {
  const { go } = useApp();
  const api = useApi(() => accountEndpoints.returnDetail(id).then(mapReturn), [id]);
  const r = api.data;
  const steps = r ? [['requested', r.date], ['approved', r.approved_at], ['received', r.received_at], ['refunded', r.refunded_at]] : [];

  const { toast } = useApp();
  const dlPhoto = async (url) => {
    try {
      await saveBlob(defaultApiClient.get(url, { responseType: 'blob', params: { signed: 1 } }), 'photo.jpg');
    } catch (e) {
      toast(apiError(e).message);
    }
  };

  return (
    <>
      <PageHead en={r?.number || ''} crumb={<Crumb items={[{ label: 'المرتجعات', go: () => go('returns') }, { label: r?.number || '', mono: true }]} />} ar={r ? <>على الطلب <button className="btn-link" onClick={() => go('order', r.order_id)}><span className="num">{r.order}</span></button></> : null}>
        {r && <Badge map={RETURN_STATUS} k={r.status} lg />}
      </PageHead>
      <DataBoundary api={api}>
        <Async rows={4} cols={4} empty={<Empty icon="fa-rotate-left" title="المرتجع غير موجود" />}>
          {r && (
          <div className="grid-2">
            <div className="stack">
              <Panel title="البنود" en="ITEMS" body={false}>
                <table className="tbl"><thead><tr><th>المنتج</th><th>الكمية</th><th>السبب</th><th>صور</th></tr></thead>
                  <tbody>{r.items.map((it, i) => <tr key={i}><td><div className="prod-cell"><Thumb sm /><div><div className="prod-name">{it.name}</div><div className="sku">{it.sku}</div></div></div></td><td className="num">{it.qty}</td><td>{it.reason}</td><td><div className="row" style={{ gap: 6 }}>{(it.attachments || []).map((u, k) => <button key={k} className="photo-chip" style={{ width: 36, height: 36 }} title="تنزيل الصورة" onClick={() => dlPhoto(u)}><i className="fa-regular fa-image" /></button>)}{it.photos > 0 && !it.attachments?.length && <span className="soft small num">{it.photos}</span>}</div></td></tr>)}</tbody></table>
              </Panel>
              <Panel title="السبب العام"><p style={{ margin: 0 }}>{r.reason}</p></Panel>
            </div>
            <div className="stack">
              <Panel title="الاسترداد" en="REFUND">
                <dl className="kv"><dt>المبلغ</dt><dd><Money v={r.refund} strong /></dd><dt>الطريقة</dt><dd>{REFUND_METHOD[r.method] || r.method || '—'}</dd><dt>المرجع</dt><dd className="sku">{r.ref || '—'}</dd></dl>
              </Panel>
              <Panel title="المراحل" en="TIMELINE">
                <ul className="tl">{steps.map(([s, d], i) => <li key={s} className={d ? (steps[i + 1] && steps[i + 1][1] ? 'is-done' : 'is-now') : 'is-future'}><div className="tl-title">{RETURN_STATUS[s] ? RETURN_STATUS[s][0] : s}</div>{d && <div className="tl-meta num">{d}</div>}</li>)}</ul>
              </Panel>
            </div>
          </div>
          )}
        </Async>
      </DataBoundary>
    </>
  );
};
