
import React, { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import { AppCtx, useApp } from './context';
import {
  fmt, Money, D, ACCOUNT, APP_STATUS, DOCS, DOC_STATUS, ORDER_STATUS, PAY_METHOD,
  PAY_STATUS, QUOTE_STATUS, NOTICE_STATUS, RETURN_STATUS, NOTICE_METHOD, REFUND_METHOD,
  PRODUCTS, ALL_VARIANTS, findVariant, ORDERS, QUOTES, STATEMENT, NOTICES, RETURNS,
  WALLET_TX, NOTIFS, ADDRESSES
} from './mock-data';
import {
  Badge, PageHead, Crumb, Panel, SkeletonRows, Empty, ErrorState,
  DataStateCtx, Async, Pager, Field, Modal, Banner, Qty, Thumb, Seg
} from './ui-primitives';

/* Screens 13–17 + 22: orders, order detail, quotations, returns. */

export const ReorderModal = ({ order, onClose }) => {
  const { go } = useApp();
  return (
    <Modal title={`إعادة الطلب ${order.number}`} onClose={onClose} icon={<div className="modal-icon"><i className="fa-solid fa-rotate" /></div>}
      foot={<><button className="btn-pill" onClick={onClose}>إغلاق</button><button className="btn-pill is-dark" onClick={() => go('cart')}><i className="fa-solid fa-bag-shopping" /> فتح السلة</button></>}>
      <Banner kind="info">تمت إضافة البنود المتاحة للسلة <b>بأسعار اليوم</b>، مش بأسعار الطلب القديم.</Banner>
      <div className="mt-16">
        {order.items.map((it, i) => (
          <div key={it.sku} className="sum-row"><span><b style={{ color: 'var(--ink)' }}>{it.name}</b> <span className="sku">{it.sku}</span></span>
            {i === 1 ? <span className="badge danger">تم تخطيه · الكمية غير متاحة</span> : <span className="badge success">أضيف <span className="num">×{it.qty}</span></span>}</div>))}
      </div>
    </Modal>
  );
};
export const CancelModal = ({ order, onClose, onDone }) => {
  const [busy, setBusy] = useState(false);
  return (
    <Modal title={`إلغاء الطلب ${order.number}؟`} onClose={onClose} icon={<div className="modal-icon is-err"><i className="fa-solid fa-ban" /></div>}
      foot={<><button className="btn-pill" onClick={onClose}>تراجع</button><button className="btn-pill is-danger" disabled={busy} onClick={() => { setBusy(true); setTimeout(onDone, 800); }}>{busy ? <span className="spin" /> : null} تأكيد الإلغاء</button></>}>
      <p className="muted" style={{ lineHeight: 1.9 }}>هيتم إلغاء الطلب وتحرير مبلغ <b className="num" style={{ color: 'var(--ink)' }}>{fmt(order.total)} EGP</b> من الائتمان المستخدم. الإلغاء متاح طالما الطلب لم يُشحن.</p>
    </Modal>
  );
};

/* ---------- 13. Orders ---------- */
export const Orders = () => {
  const { go, toast } = useApp();
  const [f, setF] = useState('all');
  const [re, setRe] = useState(null);
  const [cancel, setCancel] = useState(null);
  const [cancelled, setCancelled] = useState([]);
  const groups = { all: () => true, active: (o) => ['pending', 'confirmed', 'processing', 'shipped'].includes(o.status), delivered: (o) => o.status === 'delivered', cancelled: (o) => o.status === 'cancelled' };
  const list = ORDERS.map((o) => cancelled.includes(o.id) ? { ...o, status: 'cancelled', cancellable: false } : o).filter(groups[f]);
  return (
    <>
      <PageHead en="My Orders" ar="كل طلبات حساب الجملة" />
      <section className="panel">
        <div className="toolbar">
          <div className="chips">{[['all', 'الكل'], ['active', 'جاري التنفيذ'], ['delivered', 'تم التسليم'], ['cancelled', 'ملغي']].map(([k, l]) => <button key={k} className={`chip${f === k ? ' is-on' : ''}`} onClick={() => setF(k)}>{l}<span className="num">{ORDERS.filter(groups[k]).length}</span></button>)}</div>
        </div>
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
                  <button className="btn-link" onClick={() => setRe(o)}>إعادة الطلب</button>
                  {o.cancellable && <button className="btn-link danger" onClick={() => setCancel(o)}>إلغاء</button>}
                  <button className="btn-link muted" onClick={() => go('order', o.id)}>التفاصيل</button>
                </div></td>
              </tr>))}</tbody>
          </table></div>
          <Pager total={list.length} pages={1} per={15} />
        </Async>
      </section>
      {re && <ReorderModal order={re} onClose={() => setRe(null)} />}
      {cancel && <CancelModal order={cancel} onClose={() => setCancel(null)} onDone={() => { setCancelled([...cancelled, cancel.id]); setCancel(null); toast(`تم إلغاء الطلب ${cancel.number}`); }} />}
    </>
  );
};

/* ---------- 14. Order detail + tracking ---------- */
export const OrderDetail = ({ id }) => {
  const { go } = useApp();
  const o = ORDERS.find((x) => x.id === id) || ORDERS[0];
  const [re, setRe] = useState(false);
  const [cancel, setCancel] = useState(false);
  return (
    <>
      <PageHead en={o.number} crumb={<Crumb items={[{ label: 'طلباتي', go: () => go('orders') }, { label: o.number, mono: true }]} />} ar={<>تاريخ الطلب <span className="num">{o.date}</span></>}>
        <Badge map={ORDER_STATUS} k={o.status} lg />
        <button className="btn-pill" onClick={() => setRe(true)}><i className="fa-solid fa-rotate" /> إعادة الطلب</button>
        {o.returnable && <button className="btn-pill" onClick={() => go('returnNew', o.id)}><i className="fa-solid fa-rotate-left" /> طلب إرجاع</button>}
        {o.cancellable && <button className="btn-pill is-danger" onClick={() => setCancel(true)}>إلغاء الطلب</button>}
      </PageHead>
      <Async rows={5} cols={4}>
        <div className="grid-2">
          <div className="stack">
            <Panel title="البنود" en="ITEMS" body={false}>
              <div className="tbl-wrap"><table className="tbl">
                <thead><tr><th>المنتج</th><th>الكمية</th><th>سعر الوحدة</th><th>الخصم</th><th className="t-end">الإجمالي</th></tr></thead>
                <tbody>{o.items.map((it) => (
                  <tr key={it.sku}><td><div className="prod-cell"><Thumb sm /><div><div className="prod-name">{it.name}</div><div className="sku">{it.sku}</div></div></div></td>
                    <td className="num">{it.qty}</td><td className="num">{fmt(it.unit)}</td><td className="num" style={{ color: it.discount ? 'var(--teal)' : null }}>{it.discount ? '− ' + fmt(it.discount) : '—'}</td>
                    <td className="t-end strong num">{fmt(it.unit * it.qty - it.discount)}</td></tr>))}</tbody>
              </table></div>
            </Panel>
            <div className="grid-2e" style={{ gap: 24 }}>
              <Panel title="المستلم والعنوان" en="DELIVERY">
                <dl className="kv"><dt>المستلم</dt><dd>{o.recipient}</dd><dt>التليفون</dt><dd className="num">{o.phone}</dd><dt>المدينة</dt><dd>{o.city}</dd><dt>العنوان</dt><dd>{o.address}</dd></dl>
              </Panel>
              <Panel title="الدفع" en="PAYMENT">
                <dl className="kv"><dt>الطريقة</dt><dd>{PAY_METHOD[o.method]}</dd><dt>حالة السداد</dt><dd><Badge map={PAY_STATUS} k={o.pay} /></dd></dl>
                {o.pay === 'unpaid' && o.method === 'credit' && <button className="btn-link mt-16" onClick={() => go('noticeNew')}>تسجيل إشعار سداد لهذا الطلب</button>}
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
              <ul className="tl">{o.track.map(([s, t, st], i) => (
                <li key={i} className={st === 'now' ? 'is-now' : st ? 'is-done' : 'is-future'}>
                  <div className="tl-title">{ORDER_STATUS[s][0]}</div>{t && <div className="tl-meta num">{t}</div>}</li>))}</ul>
              <p className="note"><i className="fa-solid fa-circle-info" />التتبع من خدمة الشحن برقم الطلب ورقم الهاتف.</p>
            </Panel>
          </div>
        </div>
      </Async>
      {re && <ReorderModal order={o} onClose={() => setRe(false)} />}
      {cancel && <CancelModal order={o} onClose={() => setCancel(false)} onDone={() => { setCancel(false); go('orders'); }} />}
    </>
  );
};

/* ---------- 15. Quotations list ---------- */
export const Quotes = () => {
  const { go } = useApp();
  const [f, setF] = useState('all');
  const list = QUOTES.filter((q) => f === 'all' || q.status === f);
  return (
    <>
      <PageHead en="Quotations" ar="اطلب تسعير خاص للكميات الكبيرة وتابع رد الإدارة">
        <button className="btn-pill is-dark" onClick={() => go('quoteNew')}><i className="fa-solid fa-plus" /> طلب عرض سعر</button>
      </PageHead>
      <section className="panel">
        <div className="toolbar"><div className="chips">
          <button className={`chip${f === 'all' ? ' is-on' : ''}`} onClick={() => setF('all')}>الكل <span className="num">{QUOTES.length}</span></button>
          {Object.entries(QUOTE_STATUS).map(([k, [l]]) => <button key={k} className={`chip${f === k ? ' is-on' : ''}`} onClick={() => setF(k)}>{l}</button>)}
        </div></div>
        <Async rows={6} cols={5} isEmpty={!list.length} empty={<Empty icon="fa-file-signature" title="لا توجد عروض أسعار بهذه الحالة" action={<button className="btn-pill is-dark" onClick={() => go('quoteNew')}>طلب عرض سعر</button>} />}>
          <div className="tbl-wrap"><table className="tbl">
            <thead><tr><th>رقم العرض</th><th>تاريخ الإنشاء</th><th>الحالة</th><th>صالح حتى</th><th className="t-end">الإجمالي</th><th></th></tr></thead>
            <tbody>{list.map((q) => (
              <tr key={q.id} className="is-click" onClick={() => go(q.status === 'draft' ? 'quoteEdit' : 'quote', q.id)}>
                <td className="strong num">{q.number}</td><td className="num">{q.created}</td><td><Badge map={QUOTE_STATUS} k={q.status} /></td>
                <td className="num">{q.valid_until || '—'}{q.status === 'priced' && <span className="badge warning" style={{ marginInlineStart: 8 }}>باقي 10 أيام</span>}</td>
                <td className="t-end strong num">{q.total != null ? fmt(q.total) : '—'}</td>
                <td className="t-end"><button className="btn-link muted">{q.status === 'draft' ? 'تعديل' : q.status === 'priced' ? 'مراجعة والرد' : 'فتح'}</button></td>
              </tr>))}</tbody>
          </table></div>
          <Pager total={list.length} pages={1} />
        </Async>
      </section>
    </>
  );
};

/* ---------- 16. Create / edit quote ---------- */
export const QuoteForm = ({ id }) => {
  const { go, toast } = useApp();
  const editing = QUOTES.find((q) => q.id === id);
  const init = editing ? editing.items.map((it) => ({ v: ALL_VARIANTS.find((v) => v.sku === it.sku), qty: it.qty, target: String(it.target || '') }))
    : [{ v: ALL_VARIANTS[0], qty: 80, target: '950' }, { v: ALL_VARIANTS[5], qty: 50, target: '' }];
  const [items, setItems] = useState(init);
  const [note, setNote] = useState(editing?.trader_note || '');
  const [search, setSearch] = useState('');
  const [errs, setErrs] = useState({});
  const [confirm, setConfirm] = useState(null);
  const matches = search ? ALL_VARIANTS.filter((v) => (v.product.name_ar + v.sku + v.label).toLowerCase().includes(search.toLowerCase())).slice(0, 5) : [];
  const upd = (i, k, val) => setItems(items.map((it, j) => j === i ? { ...it, [k]: val } : it));
  const validate = () => { const e = {}; items.forEach((it, i) => { if (!it.qty) e[i] = 'الكمية مطلوبة'; }); if (!items.length) e.items = 'أضف بند واحد على الأقل.'; setErrs(e); return !Object.keys(e).length; };
  return (
    <>
      <PageHead en={editing ? editing.number : 'New Quote'} crumb={<Crumb items={[{ label: 'عروض الأسعار', go: () => go('quotes') }, { label: editing ? 'تعديل مسودة' : 'طلب جديد' }]} />} ar={editing ? 'مسودة — يمكنك التعديل قبل الإرسال' : 'اختر المنتجات والكميات، واقترح السعر المستهدف لو حابب'}>
        {editing && <button className="btn-pill is-danger" onClick={() => setConfirm('delete')}><i className="fa-regular fa-trash-can" /> حذف المسودة</button>}
      </PageHead>
      <div className="grid-2" style={{ gridTemplateColumns: '1fr 340px' }}>
        <section className="panel">
          <div className="toolbar" style={{ position: 'relative' }}>
            <div className="search" style={{ flex: 1 }}><i className="fa-solid fa-plus" /><input className="line-input" placeholder="أضف منتج — ابحث بالاسم أو SKU" value={search} onChange={(e) => setSearch(e.target.value)} /></div>
            <span className="soft small"><span className="num">{items.length}</span> / <span className="num">100</span> بند</span>
            {matches.length > 0 && (
              <div className="qf-suggest">{matches.map((v) => (
                <button key={v.id} onClick={() => { setItems([...items, { v, qty: v.tiers[0][0], target: '' }]); setSearch(''); }}>
                  <Thumb sm /><span style={{ flex: 1 }}><b>{v.product.name_ar} — {v.label}</b><span className="sku">{v.sku}</span></span><span className="num small">{fmt(v.price)}</span></button>))}</div>
            )}
          </div>
          {items.length === 0 ? <Empty icon="fa-list" title="مفيش بنود" desc="ابحث وأضف المنتجات اللي عايز تسعيرها." /> : (
            <div className="tbl-wrap"><table className="tbl">
              <thead><tr><th>المتغير</th><th>سعرك الحالي</th><th>الكمية</th><th>السعر المستهدف <span className="f-opt">(اختياري)</span></th><th></th></tr></thead>
              <tbody>{items.map((it, i) => (
                <tr key={i} className={errs[i] ? 'is-error' : ''}>
                  <td><div className="prod-cell"><Thumb sm /><div><div className="prod-name">{it.v.product.name_ar}</div><div className="soft small">{it.v.label} · <span className="sku">{it.v.sku}</span></div></div></div></td>
                  <td className="num">{fmt(it.v.price)}</td>
                  <td><Qty value={it.qty} onChange={(n) => upd(i, 'qty', n)} />{errs[i] && <p className="f-error">{errs[i]}</p>}</td>
                  <td><input className="box-input ltr" style={{ width: 120 }} placeholder="0.00" value={it.target} onChange={(e) => upd(i, 'target', e.target.value.replace(/[^\d.]/g, ''))} /></td>
                  <td><button className="btn-link muted" onClick={() => setItems(items.filter((_, j) => j !== i))} aria-label="حذف"><i className="fa-solid fa-xmark" /></button></td>
                </tr>))}</tbody>
            </table></div>
          )}
          {errs.items && <p className="f-error" style={{ padding: '0 24px 16px' }}>{errs.items}</p>}
        </section>
        <aside className="stack" style={{ position: 'sticky', top: 100 }}>
          <Panel title="ملاحظات للإدارة">
            <Field label="ملاحظات التاجر" opt counter={<span className="f-counter" style={{ color: note.length > 2000 ? '#b91c1c' : null }}>{note.length}/2000</span>} error={note.length > 2000 ? 'الحد الأقصى 2000 حرف.' : null}>
              <textarea className="line-input" rows="5" value={note} placeholder="مواعيد التسليم، تغليف خاص، شروط الدفع…" onChange={(e) => setNote(e.target.value)} />
            </Field>
            <div className="divider" />
            <div className="sum-row"><span>عدد البنود</span><strong className="num">{items.length}</strong></div>
            <div className="sum-row"><span>إجمالي الكميات</span><strong className="num">{items.reduce((a, it) => a + it.qty, 0)}</strong></div>
            <button className="btn-block mt-24" onClick={() => validate() && setConfirm('submit')}><i className="fa-solid fa-paper-plane" /> إرسال للإدارة للتسعير</button>
            <button className="btn-pill mt-8" style={{ width: '100%', justifyContent: 'center' }} onClick={() => { toast('تم حفظ المسودة'); go('quotes'); }}><i className="fa-regular fa-floppy-disk" /> حفظ كمسودة</button>
          </Panel>
          <p className="note"><i className="fa-solid fa-circle-info" />بعد الإرسال مش هتقدر تعدّل الطلب. الإدارة هترد بالأسعار وتاريخ صلاحية العرض.</p>
        </aside>
      </div>
      {confirm === 'submit' && <Modal title="إرسال طلب التسعير؟" onClose={() => setConfirm(null)} icon={<div className="modal-icon"><i className="fa-solid fa-paper-plane" /></div>}
        foot={<><button className="btn-pill" onClick={() => setConfirm(null)}>رجوع</button><button className="btn-pill is-dark" onClick={() => { toast('تم إرسال الطلب للإدارة'); go('quote', 30); }}>إرسال</button></>}>
        <p className="muted">هيتم إرسال <span className="num">{items.length}</span> بند للإدارة. الحالة هتتغير إلى «مطلوب تسعيره».</p></Modal>}
      {confirm === 'delete' && <Modal title="حذف المسودة؟" onClose={() => setConfirm(null)} icon={<div className="modal-icon is-err"><i className="fa-solid fa-trash-can" /></div>}
        foot={<><button className="btn-pill" onClick={() => setConfirm(null)}>رجوع</button><button className="btn-pill is-danger" onClick={() => { toast('تم حذف المسودة'); go('quotes'); }}>حذف</button></>}>
        <p className="muted">الإجراء ده نهائي.</p></Modal>}
    </>
  );
};

/* ---------- 17. Quote detail ---------- */
export const QuoteDetail = ({ id }) => {
  const { go, toast } = useApp();
  const q0 = QUOTES.find((x) => x.id === id) || QUOTES[0];
  const [status, setStatus] = useState(q0.status);
  const [confirm, setConfirm] = useState(null);
  const q = { ...q0, status };
  return (
    <>
      <PageHead en={q.number} crumb={<Crumb items={[{ label: 'عروض الأسعار', go: () => go('quotes') }, { label: q.number, mono: true }]} />} ar={<>أُنشئ في <span className="num">{q.created}</span>{q.valid_until && <> · صالح حتى <span className="num">{q.valid_until}</span></>}</>}>
        <Badge map={QUOTE_STATUS} k={q.status} lg />
      </PageHead>
      {q.status === 'priced' && <div className="mb-24"><Banner kind="info" icon="fa-clock" action={<div className="row"><button className="btn-pill is-danger sm" onClick={() => setConfirm('reject')}>رفض</button><button className="btn-pill is-dark sm" onClick={() => setConfirm('accept')}>قبول العرض</button></div>}><b>الإدارة سعّرت العرض.</b> راجع الأسعار وقرر قبل <span className="num">{q.valid_until}</span> — بعدها العرض بينتهي تلقائيًا.</Banner></div>}
      {q.status === 'requested' && <div className="mb-24"><Banner kind="warn" icon="fa-hourglass-half">الطلب عند الإدارة للتسعير. هيوصلك إشعار لما يتم الرد.</Banner></div>}
      {q.status === 'expired' && <div className="mb-24"><Banner kind="error" icon="fa-calendar-xmark">انتهت صلاحية العرض ولم يعد قابلًا للقبول. تقدر تطلب عرض جديد.</Banner></div>}
      {q.status === 'converted' && <div className="mb-24"><Banner kind="ok" action={<button className="btn-pill sm" onClick={() => go('order', 7)}>فتح الطلب <span className="num">{q.converted_order_id}</span></button>}>تم تحويل العرض لطلب.</Banner></div>}
      {q.status === 'accepted' && <div className="mb-24"><Banner kind="ok">تم قبول العرض. الإدارة هتحوله لطلب وهيظهر في طلباتك.</Banner></div>}
      <div className="grid-2">
        <Panel title="البنود" en="LINE ITEMS" body={false}>
          <div className="tbl-wrap"><table className="tbl">
            <thead><tr><th>المنتج</th><th>الكمية</th><th>السعر المطلوب</th><th>السعر المعروض</th><th className="t-end">إجمالي البند</th></tr></thead>
            <tbody>{q.items.map((it) => (
              <tr key={it.sku}><td><div className="prod-cell"><Thumb sm /><div><div className="prod-name">{it.name}</div><div className="sku">{it.sku}</div></div></div></td>
                <td className="num">{it.qty}</td><td className="num muted">{it.target ? fmt(it.target) : '—'}</td>
                <td className="num strong">{it.offered ? fmt(it.offered) : <span className="soft">في الانتظار</span>}</td>
                <td className="t-end strong num">{it.offered ? fmt(it.offered * it.qty) : '—'}</td></tr>))}</tbody>
          </table></div>
        </Panel>
        <div className="stack">
          <Panel title="الإجماليات" en="TOTALS">
            <div className="sum-row"><span>الإجمالي قبل الخصم</span><strong className="num">{q.total != null ? fmt(q.subtotal) : '—'}</strong></div>
            <div className="sum-row is-neg"><span>الخصم</span><strong className="num">{q.discount ? '− ' + fmt(q.discount) : '—'}</strong></div>
            <div className="sum-row"><span>الضريبة</span><strong className="num">{q.total != null ? fmt(q.tax) : '—'}</strong></div>
            <div className="sum-total"><span>الإجمالي النهائي</span><span className="num">{q.total != null ? fmt(q.total) : '—'}</span></div>
          </Panel>
          <Panel title="الملاحظات" en="NOTES">
            <div className="note-bubble"><span>ملاحظات التاجر</span><p>{q.trader_note || <span className="soft">—</span>}</p></div>
            <div className="note-bubble is-admin"><span>رد الإدارة</span><p>{q.admin_note || <span className="soft">لم يتم الرد بعد</span>}</p></div>
          </Panel>
        </div>
      </div>
      {confirm && <Modal title={confirm === 'accept' ? 'قبول عرض السعر؟' : 'رفض عرض السعر؟'} onClose={() => setConfirm(null)} icon={<div className={`modal-icon${confirm === 'reject' ? ' is-err' : ''}`}><i className={`fa-solid ${confirm === 'accept' ? 'fa-check' : 'fa-xmark'}`} /></div>}
        foot={<><button className="btn-pill" onClick={() => setConfirm(null)}>رجوع</button><button className={`btn-pill ${confirm === 'accept' ? 'is-dark' : 'is-danger'}`} onClick={() => { setStatus(confirm === 'accept' ? 'accepted' : 'rejected'); toast(confirm === 'accept' ? 'تم قبول العرض' : 'تم رفض العرض'); setConfirm(null); }}>{confirm === 'accept' ? 'قبول' : 'رفض'}</button></>}>
        <p className="muted" style={{ lineHeight: 1.9 }}>{confirm === 'accept' ? <>بقبولك العرض بإجمالي <b className="num" style={{ color: 'var(--ink)' }}>{fmt(q.total)} EGP</b> الإدارة هتحوله لطلب.</> : 'الإجراء نهائي، ولن يكون العرض قابلًا للقبول بعد الرفض.'}</p></Modal>}
    </>
  );
};

/* ---------- 22. Returns ---------- */
export const Returns = () => {
  const { go } = useApp();
  return (
    <>
      <PageHead en="Returns" ar="طلبات الإرجاع والاسترداد"><button className="btn-pill is-dark" onClick={() => go('returnNew')}><i className="fa-solid fa-plus" /> طلب إرجاع</button></PageHead>
      <section className="panel">
        <Async rows={4} cols={5} empty={<Empty icon="fa-rotate-left" title="مفيش مرتجعات" desc="تقدر تطلب إرجاع لأي طلب مؤهل بعد التسليم." />}>
          <div className="tbl-wrap"><table className="tbl">
            <thead><tr><th>رقم المرتجع</th><th>الطلب</th><th>الحالة</th><th>مبلغ الاسترداد</th><th>التاريخ</th><th></th></tr></thead>
            <tbody>{RETURNS.map((r) => (
              <tr key={r.id} className="is-click" onClick={() => go('return', r.id)}>
                <td className="strong num">{r.number}</td><td className="num">{r.order}</td><td><Badge map={RETURN_STATUS} k={r.status} /></td>
                <td className="strong num">{fmt(r.refund)}</td><td className="num">{r.date}</td><td className="t-end"><button className="btn-link muted">التفاصيل</button></td></tr>))}</tbody>
          </table></div>
        </Async>
      </section>
    </>
  );
};

export const ReturnForm = ({ orderId }) => {
  const { go, toast } = useApp();
  const eligible = ORDERS.filter((o) => o.returnable);
  const [oid, setOid] = useState(orderId || eligible[0].id);
  const o = ORDERS.find((x) => x.id === oid);
  const [sel, setSel] = useState({});
  const [reason, setReason] = useState('');
  const [method, setMethod] = useState('wallet');
  const [errs, setErrs] = useState({});
  const toggle = (sku) => setSel({ ...sel, [sku]: sel[sku] ? undefined : { qty: 1, reason: '', photos: 0 } });
  const set = (sku, k, v) => setSel({ ...sel, [sku]: { ...sel[sku], [k]: v } });
  const chosen = Object.entries(sel).filter(([, v]) => v);
  const totalPhotos = chosen.reduce((a, [, v]) => a + v.photos, 0);
  const submit = () => {
    const e = {};
    if (!chosen.length) e.items = 'اختر بند واحد على الأقل.';
    if (!reason.trim()) e.reason = 'السبب العام مطلوب.';
    chosen.forEach(([s, v]) => { if (!v.reason.trim()) e[s] = 'سبب البند مطلوب.'; });
    setErrs(e); if (!Object.keys(e).length) { toast('تم تقديم طلب الإرجاع'); go('return', 95); }
  };
  return (
    <>
      <PageHead en="New Return" crumb={<Crumb items={[{ label: 'المرتجعات', go: () => go('returns') }, { label: 'طلب جديد' }]} />} ar="اختر الطلب والبنود، ووضح السبب" />
      <div className="grid-2" style={{ gridTemplateColumns: '1fr 340px' }}>
        <div className="stack">
          <Panel title="الطلب المؤهل" en="ORDER">
            <div className="addr-picks">{eligible.map((x) => (
              <button key={x.id} className={`addr-pick${oid === x.id ? ' is-on' : ''}`} onClick={() => { setOid(x.id); setSel({}); }}><b className="num">{x.number}</b><span>تم التسليم · <span className="num">{x.date}</span> · <span className="num">{fmt(x.total)}</span> EGP</span></button>))}</div>
            <p className="note mt-16"><i className="fa-solid fa-circle-info" />الطلبات المؤهلة بس هي اللي بتظهر هنا (تم تسليمها وضمن مدة الإرجاع).</p>
          </Panel>
          <Panel title="البنود المرتجعة" en="ITEMS">
            {errs.items && <p className="f-error" style={{ marginTop: 0 }}>{errs.items}</p>}
            {o.items.map((it) => {
              const s = sel[it.sku];
              return (
                <div key={it.sku} className={`ret-item${s ? ' is-on' : ''}`}>
                  <label className="row" style={{ cursor: 'pointer' }}>
                    <input type="checkbox" checked={!!s} onChange={() => toggle(it.sku)} style={{ accentColor: '#111', width: 16, height: 16 }} />
                    <Thumb sm /><div style={{ flex: 1 }}><div className="prod-name">{it.name}</div><div className="soft small"><span className="sku">{it.sku}</span> · تم شراء <span className="num">{it.qty}</span> · <span className="num">{fmt(it.unit)}</span></div></div>
                  </label>
                  {s && (
                    <div className="fields mt-16" style={{ paddingInlineStart: 42 }}>
                      <Field label="الكمية المرتجعة" req hint={`حتى ${it.qty}`}><Qty value={s.qty} min={1} onChange={(n) => set(it.sku, 'qty', Math.min(n, it.qty))} /></Field>
                      <Field label="سبب البند" req error={errs[it.sku]}><input className={`line-input${errs[it.sku] ? ' is-invalid' : ''}`} value={s.reason} placeholder="مثال: عيب في السحّاب" onChange={(e) => set(it.sku, 'reason', e.target.value)} /></Field>
                      <Field label="صور" opt span hint="حتى 5 صور للبند · 5 ميجابايت للصورة · JPG / PNG / WebP">
                        <div className="row wrap">
                          {Array.from({ length: s.photos }).map((_, k) => <span key={k} className="photo-chip"><i className="fa-regular fa-image" /></span>)}
                          {s.photos < 5 && totalPhotos < 10 && <button className="drop sm" style={{ minHeight: 52, width: 52, padding: 0, justifyContent: 'center' }} onClick={() => set(it.sku, 'photos', s.photos + 1)} aria-label="إضافة صورة"><i className="fa-solid fa-plus" /></button>}
                        </div>
                      </Field>
                    </div>
                  )}
                </div>);
            })}
          </Panel>
        </div>
        <aside className="stack" style={{ position: 'sticky', top: 100 }}>
          <Panel title="السبب والاسترداد">
            <Field label="السبب العام" req error={errs.reason}><textarea className={`line-input${errs.reason ? ' is-invalid' : ''}`} rows="3" value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
            <div className="f-label mt-24">طريقة الاسترداد</div>
            <div className="radio-list">{Object.entries(REFUND_METHOD).map(([k, l]) => <label key={k} className="radio-row"><span className="radio-left"><input type="radio" checked={method === k} onChange={() => setMethod(k)} /><span className="radio-title">{l}</span></span></label>)}</div>
            <div className="sum-row mt-16"><span>صور مرفقة</span><strong className="num" style={{ color: totalPhotos > 10 ? '#b91c1c' : null }}>{totalPhotos} / 10</strong></div>
            <button className="btn-block mt-24" onClick={submit}>تقديم طلب الإرجاع</button>
          </Panel>
          <p className="note"><i className="fa-solid fa-circle-info" />إجمالي حجم الصور حتى 25 ميجابايت. مبلغ الاسترداد النهائي بيتحدد بعد فحص المرتجع.</p>
        </aside>
      </div>
    </>
  );
};

export const ReturnDetail = ({ id }) => {
  const { go } = useApp();
  const r = RETURNS.find((x) => x.id === id) || RETURNS[0];
  const steps = [['requested', r.date], ['approved', r.approved_at], ['received', r.received_at], ['refunded', r.refunded_at]];
  return (
    <>
      <PageHead en={r.number} crumb={<Crumb items={[{ label: 'المرتجعات', go: () => go('returns') }, { label: r.number, mono: true }]} />} ar={<>على الطلب <button className="btn-link" onClick={() => go('order', 6)}><span className="num">{r.order}</span></button></>}>
        <Badge map={RETURN_STATUS} k={r.status} lg />
      </PageHead>
      <div className="grid-2">
        <div className="stack">
          <Panel title="البنود" en="ITEMS" body={false}>
            <table className="tbl"><thead><tr><th>المنتج</th><th>الكمية</th><th>السبب</th><th>صور</th></tr></thead>
              <tbody>{r.items.map((it) => <tr key={it.sku}><td><div className="prod-cell"><Thumb sm /><div><div className="prod-name">{it.name}</div><div className="sku">{it.sku}</div></div></div></td><td className="num">{it.qty}</td><td>{it.reason}</td><td><div className="row" style={{ gap: 6 }}>{Array.from({ length: it.photos }).map((_, k) => <span key={k} className="photo-chip" style={{ width: 36, height: 36 }}><i className="fa-regular fa-image" /></span>)}</div></td></tr>)}</tbody></table>
          </Panel>
          <Panel title="السبب العام"><p style={{ margin: 0 }}>{r.reason}</p></Panel>
        </div>
        <div className="stack">
          <Panel title="الاسترداد" en="REFUND">
            <dl className="kv"><dt>المبلغ</dt><dd><Money v={r.refund} strong /></dd><dt>الطريقة</dt><dd>{REFUND_METHOD[r.method]}</dd><dt>المرجع</dt><dd className="sku">{r.ref || '—'}</dd></dl>
          </Panel>
          <Panel title="المراحل" en="TIMELINE">
            <ul className="tl">{steps.map(([s, d], i) => <li key={s} className={d ? (steps[i + 1] && steps[i + 1][1] ? 'is-done' : 'is-now') : 'is-future'}><div className="tl-title">{RETURN_STATUS[s][0]}</div>{d && <div className="tl-meta num">{d}</div>}</li>)}</ul>
          </Panel>
        </div>
      </div>
    </>
  );
};



