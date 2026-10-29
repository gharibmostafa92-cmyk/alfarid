
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

/* Screens 18–21, 23–25: invoices, statement, payment notices, wallet, notifications, account. */

/* ---------- 18. Invoices ---------- */
export const Invoices = () => {
  const { go, toast } = useApp();
  const [f, setF] = useState('all');
  const [open, setOpen] = useState(null);
  const [dl, setDl] = useState(null);
  const list = ORDERS.filter((o) => o.status !== 'cancelled').filter((o) => f === 'all' || (f === 'paid' ? o.pay === 'paid' : o.pay !== 'paid'));
  const pdf = (o) => { setDl(o.id); setTimeout(() => { setDl(null); toast(`تم تنزيل الفاتورة ${o.number}.pdf`); }, 900); };
  return (
    <>
      <PageHead en="Invoices" ar="رقم الفاتورة هو نفس رقم الطلب" />
      <section className="panel">
        <div className="toolbar"><Seg value={f} onChange={setF} options={[{ value: 'all', label: 'الكل' }, { value: 'unpaid', label: 'غير مدفوع', n: ORDERS.filter((o) => o.pay !== 'paid' && o.status !== 'cancelled').length }, { value: 'paid', label: 'مدفوع' }]} />
          <span className="soft small">غير مدفوع: <Money v={ORDERS.filter((o) => o.pay !== 'paid' && o.status !== 'cancelled').reduce((a, o) => a + o.total, 0)} strong /></span></div>
        <Async rows={5} cols={7} isEmpty={!list.length} empty={<Empty icon="fa-file-invoice" title="لا توجد فواتير" />}>
          <div className="tbl-wrap"><table className="tbl">
            <thead><tr><th>رقم الفاتورة</th><th>التاريخ</th><th>حالة الطلب</th><th>الدفع</th><th>السداد</th><th>قبل الخصم</th><th>الخصم</th><th>الشحن</th><th>الضريبة</th><th className="t-end">الإجمالي</th><th></th></tr></thead>
            <tbody>{list.map((o) => (
              <tr key={o.id} className="is-click" onClick={() => setOpen(o)}>
                <td className="strong num">{o.number}</td><td className="num">{o.date}</td><td><Badge map={ORDER_STATUS} k={o.status} /></td><td>{PAY_METHOD[o.method]}</td><td><Badge map={PAY_STATUS} k={o.pay} /></td>
                <td className="num">{fmt(o.subtotal)}</td><td className="num" style={{ color: o.discount ? 'var(--teal)' : null }}>{o.discount ? fmt(o.discount) : '—'}</td><td className="num">{fmt(o.shipping)}</td><td className="num">{fmt(o.tax)}</td>
                <td className="t-end strong num">{fmt(o.total)}</td>
                <td onClick={(e) => e.stopPropagation()}><div className="row-actions"><button className="btn-pill sm" onClick={() => pdf(o)} disabled={dl === o.id}>{dl === o.id ? <span className="spin" /> : <i className="fa-regular fa-file-pdf" />} PDF</button></div></td>
              </tr>))}</tbody>
          </table></div>
          <Pager total={list.length} pages={1} />
        </Async>
      </section>
      {open && (
        <Modal wide title={<>فاتورة <span className="num">{open.number}</span></>} onClose={() => setOpen(null)}
          foot={<><button className="btn-pill" onClick={() => go('order', open.id)}>فتح الطلب المرتبط</button><button className="btn-pill is-dark" onClick={() => pdf(open)}><i className="fa-regular fa-file-pdf" /> تنزيل PDF</button></>}>
          <div className="inv-head">
            <div><div className="wp-logo" style={{ fontSize: 20 }}>SOPHIE</div><div className="soft small">فاتورة ضريبية مبسطة</div></div>
            <dl className="kv" style={{ gridTemplateColumns: '90px auto' }}><dt>التاريخ</dt><dd className="num">{open.date}</dd><dt>العميل</dt><dd>{ACCOUNT.company}</dd><dt>السداد</dt><dd><Badge map={PAY_STATUS} k={open.pay} /></dd></dl>
          </div>
          <table className="tbl mt-16"><thead><tr><th>المنتج</th><th>الكمية</th><th>السعر</th><th className="t-end">الإجمالي</th></tr></thead>
            <tbody>{open.items.map((it) => <tr key={it.sku}><td><div className="strong">{it.name}</div><div className="sku">{it.sku}</div></td><td className="num">{it.qty}</td><td className="num">{fmt(it.unit)}</td><td className="t-end num strong">{fmt(it.unit * it.qty)}</td></tr>)}</tbody></table>
          <div style={{ maxWidth: 320, marginInlineStart: 'auto' }} className="mt-16">
            <div className="sum-row"><span>المنتجات قبل الخصم</span><strong className="num">{fmt(open.subtotal)}</strong></div>
            <div className="sum-row is-neg"><span>الخصم</span><strong className="num">{open.discount ? '− ' + fmt(open.discount) : '—'}</strong></div>
            <div className="sum-row"><span>الشحن</span><strong className="num">{fmt(open.shipping)}</strong></div>
            <div className="sum-row"><span>الضريبة</span><strong className="num">{fmt(open.tax)}</strong></div>
            <div className="sum-total"><span>الإجمالي</span><span className="num">{fmt(open.total)}</span></div>
          </div>
        </Modal>
      )}
    </>
  );
};

/* ---------- 19. Statement ---------- */
export const Statement = () => {
  const { go } = useApp();
  const [from, setFrom] = useState('2026-10-01');
  const [to, setTo] = useState('2026-10-28');
  const bad = from > to;
  const out = ORDERS.filter((o) => o.pay === 'unpaid' && o.status !== 'cancelled');
  return (
    <>
      <PageHead en="Statement" ar="كشف الحساب والمديونية">
        <button className="btn-pill is-dark" onClick={() => go('noticeNew')}><i className="fa-solid fa-money-check-dollar" /> تسجيل إشعار سداد</button>
      </PageHead>
      <div className="kpis" style={{ gridTemplateColumns: 'repeat(4, minmax(0,1fr))' }}>
        <div className="kpi is-ink"><span className="kpi-en">CURRENT DEBT</span><span className="kpi-label">المديونية الحالية</span><span className="kpi-value">{fmt(ACCOUNT.used_credit)}<small>EGP</small></span></div>
        <div className="kpi"><span className="kpi-en">CREDIT LIMIT</span><span className="kpi-label">حد الائتمان</span><span className="kpi-value">{fmt(ACCOUNT.credit_limit)}<small>EGP</small></span></div>
        <div className="kpi"><span className="kpi-en">OPENING</span><span className="kpi-label">رصيد أول المدة</span><span className="kpi-value">{fmt(STATEMENT.opening)}<small>EGP</small></span></div>
        <div className="kpi"><span className="kpi-en">CLOSING</span><span className="kpi-label">رصيد آخر المدة</span><span className="kpi-value">{fmt(STATEMENT.closing)}<small>EGP</small></span></div>
      </div>
      <div className="grid-2" style={{ gridTemplateColumns: '1fr 320px' }}>
        <section className="panel">
          <div className="toolbar">
            <div className="row" style={{ gap: 20 }}>
              <Field label="من تاريخ"><input type="date" className={`line-input ltr${bad ? ' is-invalid' : ''}`} value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
              <Field label="إلى تاريخ"><input type="date" className={`line-input ltr${bad ? ' is-invalid' : ''}`} value={to} onChange={(e) => setTo(e.target.value)} /></Field>
            </div>
            <div className="chips">{['هذا الشهر', 'آخر 3 شهور', 'هذه السنة'].map((l, i) => <button key={l} className={`chip${i === 0 ? ' is-on' : ''}`}>{l}</button>)}</div>
          </div>
          {bad ? <div style={{ padding: 24 }}><Banner kind="error">تاريخ البداية لازم يكون قبل تاريخ النهاية.</Banner></div> : (
            <Async rows={8} cols={6} empty={<Empty icon="fa-receipt" title="لا توجد حركات في هذه الفترة" desc="جرب فترة زمنية أخرى." />}>
              <div className="tbl-wrap"><table className="tbl">
                <thead><tr><th>التاريخ</th><th>المرجع</th><th>البيان</th><th className="t-end">مدين</th><th className="t-end">دائن</th><th className="t-end">الرصيد</th></tr></thead>
                <tbody>{STATEMENT.rows.map((r, i) => (
                  <tr key={i} style={r.opening ? { background: '#fafbfc' } : null}>
                    <td className="num">{r.date}</td><td className="sku">{r.ref}</td><td className={r.opening ? 'strong' : ''}>{r.desc}</td>
                    <td className="t-end num">{r.debit ? fmt(r.debit) : ''}</td><td className="t-end num" style={{ color: 'var(--teal)' }}>{r.credit ? fmt(r.credit) : ''}</td>
                    <td className="t-end num strong">{fmt(r.bal)}</td></tr>))}</tbody>
                <tfoot><tr><td colSpan="3">رصيد آخر المدة</td><td className="t-end num">{fmt(STATEMENT.rows.reduce((a, r) => a + r.debit, 0))}</td><td className="t-end num">{fmt(STATEMENT.rows.reduce((a, r) => a + r.credit, 0))}</td><td className="t-end num">{fmt(STATEMENT.closing)}</td></tr></tfoot>
              </table></div>
            </Async>
          )}
        </section>
        <aside className="stack" style={{ position: 'sticky', top: 100 }}>
          <Panel title="مستحقات مفتوحة" en="OUTSTANDING" body={false}>
            {out.map((o) => <div key={o.id} className="out-row" onClick={() => go('order', o.id)}><div><b className="num">{o.number}</b><span className="num">{o.date}</span></div><span className="num strong">{fmt(o.total)}</span></div>)}
            <div className="panel-body" style={{ paddingTop: 12 }}><div className="sum-total" style={{ paddingTop: 0 }}><span>الإجمالي</span><span className="num" style={{ fontSize: 18 }}>{fmt(out.reduce((a, o) => a + o.total, 0))}</span></div></div>
          </Panel>
          <p className="note"><i className="fa-solid fa-circle-info" />المديونية والائتمان منفصلين تمامًا عن رصيد المحفظة.</p>
        </aside>
      </div>
    </>
  );
};

/* ---------- 20. New payment notice ---------- */
export const NoticeForm = () => {
  const { go } = useApp();
  const [f, setF] = useState({ order: '', amount: '', method: 'bank_transfer', bank: '', ref: '', date: '' });
  const [file, setFile] = useState(null);
  const [errs, setErrs] = useState({});
  const [stage, setStage] = useState('form'); // form | confirm | sending | done
  const out = ORDERS.filter((o) => o.pay === 'unpaid' && o.status !== 'cancelled');
  const set = (k, v) => { setF({ ...f, [k]: v }); setErrs({ ...errs, [k]: null }); };
  const validate = () => {
    const e = {};
    if (!f.amount || Number(f.amount) <= 0) e.amount = 'أدخل مبلغ صحيح أكبر من صفر.';
    if (!f.bank.trim()) e.bank = 'اسم البنك مطلوب.';
    if (!f.ref.trim()) e.ref = 'رقم المرجع مطلوب.';
    if (!f.date) e.date = 'تاريخ التحويل مطلوب.'; else if (f.date > '2026-10-29') e.date = 'تاريخ التحويل لا يمكن أن يكون في المستقبل.';
    if (!file) e.file = 'إيصال التحويل مطلوب.'; else if (file.bad) e.file = 'حجم الملف أكبر من 10 ميجابايت.';
    setErrs(e); return !Object.keys(e).length;
  };
  if (stage === 'done') return (
    <div className="success-wrap"><div className="order-card">
      <div className="modal-icon" style={{ width: 64, height: 64, fontSize: 26 }}><i className="fa-solid fa-paper-plane" /></div>
      <h1 className="success-title">NOTICE SENT</h1>
      <p className="muted">تم إرسال إشعار السداد للمراجعة</p>
      <p className="success-num num">PN-0432</p>
      <Banner kind="warn" icon="fa-hourglass-half">رفع الإيصال <b>لا يعني اعتماد السداد</b>. الإدارة هتراجع التحويل وتعتمده، وهيوصلك إشعار.</Banner>
      <div className="row mt-24" style={{ justifyContent: 'center' }}><button className="btn-pill is-dark" onClick={() => go('notice', 431)}>عرض الإشعار</button><button className="btn-pill" onClick={() => go('notices')}>كل الإشعارات</button></div>
    </div></div>
  );
  return (
    <>
      <PageHead en="Payment Notice" crumb={<Crumb items={[{ label: 'إشعارات السداد', go: () => go('notices') }, { label: 'إشعار جديد' }]} />} ar="سجّل تحويل أو شيك أو إيداع تم لحساب الشركة" />
      <div className="grid-2" style={{ gridTemplateColumns: '1fr 340px' }}>
        <section className="panel"><div className="panel-body">
          <div className="fields">
            <Field label="طلب مرتبط" opt span>
              <select className="line-input" value={f.order} onChange={(e) => { const o = out.find((x) => x.number === e.target.value); setF({ ...f, order: e.target.value, amount: o ? String(o.total) : f.amount }); }}>
                <option value="">بدون — سداد على الحساب</option>{out.map((o) => <option key={o.id} value={o.number}>{o.number} · {fmt(o.total)} EGP</option>)}
              </select></Field>
            <Field label="المبلغ" req error={errs.amount}><div style={{ position: 'relative' }}><input className={`line-input ltr${errs.amount ? ' is-invalid' : ''}`} style={{ textAlign: 'right', paddingLeft: 40 }} inputMode="decimal" placeholder="0.00" value={f.amount} onChange={(e) => set('amount', e.target.value.replace(/[^\d.]/g, ''))} /><span className="soft small" style={{ position: 'absolute', left: 0, top: 12 }}>EGP</span></div></Field>
            <Field label="تاريخ التحويل" req error={errs.date}><input type="date" max="2026-10-29" className={`line-input ltr${errs.date ? ' is-invalid' : ''}`} value={f.date} onChange={(e) => set('date', e.target.value)} /></Field>
            <Field label="طريقة السداد" req span>
              <div className="chips">{Object.entries(NOTICE_METHOD).map(([k, l]) => <button key={k} type="button" className={`chip${f.method === k ? ' is-on' : ''}`} onClick={() => set('method', k)}><i className={`fa-solid ${{ bank_transfer: 'fa-building-columns', cheque: 'fa-money-check', deposit: 'fa-piggy-bank' }[k]}`} />{l}</button>)}</div></Field>
            <Field label="اسم البنك" req error={errs.bank}><input className={`line-input${errs.bank ? ' is-invalid' : ''}`} placeholder="مثال: البنك الأهلي المصري" value={f.bank} onChange={(e) => set('bank', e.target.value)} /></Field>
            <Field label={f.method === 'cheque' ? 'رقم الشيك' : 'رقم المرجع'} req error={errs.ref}><input className={`line-input ltr${errs.ref ? ' is-invalid' : ''}`} style={{ textAlign: 'right' }} value={f.ref} onChange={(e) => set('ref', e.target.value)} /></Field>
            <Field label="الإيصال" req span error={errs.file}>
              {file ? (
                <div className="file-row" style={file.bad ? { borderColor: '#fecaca', background: '#fffafa' } : null}><i className="fa-regular fa-file-pdf" /><div className="grow"><b className="num">{file.name}</b> <span className="soft num">· {file.size}</span></div><button className="btn-link muted" onClick={() => setFile(null)}><i className="fa-solid fa-xmark" /></button></div>
              ) : (
                <div className={`drop${errs.file ? ' is-invalid' : ''}`} style={{ minHeight: 120 }} onClick={() => { setFile({ name: 'nbe-transfer-receipt.pdf', size: '412 KB' }); setErrs({ ...errs, file: null }); }}>
                  <i className="fa-solid fa-receipt" /><b>ارفع صورة أو PDF للإيصال</b><small>PDF · JPG · JPEG · PNG · WebP — حتى <span className="num">10</span> ميجابايت</small></div>
              )}
              {!file && <button className="btn-link muted mt-8" style={{ fontSize: 12 }} onClick={() => setFile({ name: 'scan-hq.png', size: '14.2 MB', bad: true })}>جرب ملف كبير (حالة خطأ)</button>}
            </Field>
          </div>
        </div></section>
        <aside className="stack" style={{ position: 'sticky', top: 100 }}>
          <Panel title="مراجعة">
            <div className="sum-row"><span>الطلب</span><strong className="num">{f.order || 'على الحساب'}</strong></div>
            <div className="sum-row"><span>الطريقة</span><strong>{NOTICE_METHOD[f.method]}</strong></div>
            <div className="sum-total"><span>المبلغ</span><span className="num">{f.amount ? fmt(f.amount) : '—'}</span></div>
            <button className="btn-block mt-24" onClick={() => validate() && setStage('confirm')}>إرسال للمراجعة</button>
          </Panel>
          <p className="note"><i className="fa-solid fa-circle-info" />السداد مش بيتعتمد تلقائيًا؛ الإدارة بتراجع الإيصال مع كشف البنك.</p>
        </aside>
      </div>
      {(stage === 'confirm' || stage === 'sending') && <Modal title="تأكيد إرسال إشعار السداد" onClose={() => setStage('form')} icon={<div className="modal-icon"><i className="fa-solid fa-money-check-dollar" /></div>}
        foot={<><button className="btn-pill" onClick={() => setStage('form')}>تعديل</button><button className="btn-pill is-dark" disabled={stage === 'sending'} onClick={() => { setStage('sending'); setTimeout(() => setStage('done'), 900); }}>{stage === 'sending' ? <span className="spin" /> : null} تأكيد الإرسال</button></>}>
        <dl className="kv"><dt>المبلغ</dt><dd><Money v={f.amount} strong /></dd><dt>الطريقة</dt><dd>{NOTICE_METHOD[f.method]}</dd><dt>البنك</dt><dd>{f.bank}</dd><dt>المرجع</dt><dd className="sku">{f.ref}</dd><dt>التاريخ</dt><dd className="num">{f.date}</dd><dt>الإيصال</dt><dd className="num">{file?.name}</dd></dl>
      </Modal>}
    </>
  );
};

/* ---------- 21. Notices list + detail ---------- */
export const Notices = () => {
  const { go } = useApp();
  return (
    <>
      <PageHead en="Payment Notices" ar="إشعارات السداد المرسلة وحالة مراجعتها"><button className="btn-pill is-dark" onClick={() => go('noticeNew')}><i className="fa-solid fa-plus" /> إشعار جديد</button></PageHead>
      <section className="panel">
        <Async rows={5} cols={6} empty={<Empty icon="fa-money-check" title="لا توجد إشعارات سداد" action={<button className="btn-pill is-dark" onClick={() => go('noticeNew')}>تسجيل إشعار</button>} />}>
          <div className="tbl-wrap"><table className="tbl">
            <thead><tr><th>رقم الإشعار</th><th>الطلب</th><th>الطريقة</th><th>تاريخ التحويل</th><th>الحالة</th><th>تاريخ الإنشاء</th><th className="t-end">المبلغ</th></tr></thead>
            <tbody>{NOTICES.map((n) => (
              <tr key={n.id} className="is-click" onClick={() => go('notice', n.id)}>
                <td className="strong num">{n.number}</td><td className="num">{n.order || <span className="soft">على الحساب</span>}</td><td>{NOTICE_METHOD[n.method]}</td><td className="num">{n.date}</td>
                <td><Badge map={NOTICE_STATUS} k={n.status} /></td><td className="num">{n.created}</td><td className="t-end strong num">{fmt(n.amount)}</td></tr>))}</tbody>
          </table></div>
          <Pager total={NOTICES.length} pages={1} />
        </Async>
      </section>
    </>
  );
};

export const NoticeDetail = ({ id }) => {
  const { go, toast } = useApp();
  const n = NOTICES.find((x) => x.id === id) || NOTICES[0];
  return (
    <>
      <PageHead en={n.number} crumb={<Crumb items={[{ label: 'إشعارات السداد', go: () => go('notices') }, { label: n.number, mono: true }]} />} ar={<>أُرسل في <span className="num">{n.created}</span></>}>
        <Badge map={NOTICE_STATUS} k={n.status} lg />
      </PageHead>
      {n.status === 'rejected' && <div className="mb-24"><Banner kind="error" action={<button className="btn-pill sm" onClick={() => go('noticeNew')}>إرسال إشعار جديد</button>}>تم رفض الإشعار. راجع ملاحظة الإدارة وأرسل إشعار جديد بالبيانات الصحيحة.</Banner></div>}
      {n.status === 'pending' && <div className="mb-24"><Banner kind="warn" icon="fa-hourglass-half">الإشعار قيد المراجعة من الإدارة. المبلغ لن يُخصم من المديونية قبل الاعتماد.</Banner></div>}
      <div className="grid-2e">
        <Panel title="بيانات السداد" en="DETAILS">
          <dl className="kv"><dt>المبلغ</dt><dd><Money v={n.amount} strong /></dd><dt>الطريقة</dt><dd>{NOTICE_METHOD[n.method]}</dd><dt>اسم البنك</dt><dd>{n.bank}</dd><dt>رقم المرجع</dt><dd className="sku">{n.ref}</dd><dt>تاريخ التحويل</dt><dd className="num">{n.date}</dd>
            <dt>الطلب المرتبط</dt><dd>{n.order ? <button className="btn-link num" onClick={() => go('order', 8)}>{n.order}</button> : <span className="soft">على الحساب</span>}</dd></dl>
        </Panel>
        <div className="stack">
          <Panel title="الإيصال" en="RECEIPT">
            <div className="file-row"><i className={`fa-regular ${n.receipt.endsWith('.pdf') ? 'fa-file-pdf' : 'fa-file-image'}`} /><div className="grow num">{n.receipt}</div><button className="btn-pill sm" onClick={() => toast('جارٍ تنزيل الإيصال')}><i className="fa-solid fa-download" /> تنزيل</button></div>
          </Panel>
          <Panel title="ملاحظات الإدارة" en="ADMIN NOTE">
            <div className={`note-bubble is-admin`} style={{ margin: 0 }}><p>{n.admin_note || <span className="soft">لا توجد ملاحظات</span>}</p></div>
          </Panel>
        </div>
      </div>
    </>
  );
};

/* ---------- 23. Wallet ---------- */
export const Wallet = () => (
  <>
    <PageHead en="Wallet" ar="رصيد المحفظة وحركاتها — عرض فقط" />
    <div className="wallet-hero">
      <div><span className="kpi-en" style={{ color: '#a3a3a3' }}>CURRENT BALANCE</span><div className="wallet-amt num">{fmt(ACCOUNT.wallet)} <small>EGP</small></div><span style={{ color: '#a3a3a3', fontSize: 13 }}>الرصيد الحالي</span></div>
      <p className="note" style={{ color: '#a3a3a3', maxWidth: 300 }}><i className="fa-solid fa-circle-info" />الرصيد بيتضاف من المرتجعات والتسويات، ويُستخدم كطريقة دفع عند إتمام الطلب. منفصل عن الائتمان والمديونية.</p>
    </div>
    <section className="panel mt-24">
      <div className="panel-head" style={{ paddingBottom: 6 }}><h2 className="panel-title">الحركات</h2><span className="panel-en">TRANSACTIONS</span></div>
      <Async rows={4} cols={5} empty={<Empty icon="fa-wallet" title="مفيش حركات على المحفظة" />}>
        <div className="tbl-wrap"><table className="tbl">
          <thead><tr><th>النوع</th><th>المرجع</th><th>التاريخ</th><th className="t-end">المبلغ</th><th className="t-end">الرصيد بعد الحركة</th></tr></thead>
          <tbody>{WALLET_TX.map((t, i) => (
            <tr key={i}><td><div className="row" style={{ gap: 10 }}><span className={`wtx-ic${t.amount < 0 ? ' neg' : ''}`}><i className={`fa-solid ${t.amount < 0 ? 'fa-arrow-up' : 'fa-arrow-down'}`} /></span><span className="strong">{t.label}</span></div></td>
              <td className="sku">{t.ref}</td><td className="num">{t.date}</td>
              <td className="t-end num strong" style={{ color: t.amount < 0 ? '#b91c1c' : 'var(--teal)' }}>{t.amount > 0 ? '+' : '−'}{fmt(Math.abs(t.amount))}</td>
              <td className="t-end num">{fmt(t.after)}</td></tr>))}</tbody>
        </table></div>
        <Pager total={WALLET_TX.length} pages={1} />
      </Async>
    </section>
  </>
);

/* ---------- 24. Notifications ---------- */
export const Notifications = () => {
  const { go, notifs, setNotifs } = useApp();
  const [f, setF] = useState('all');
  const unread = notifs.filter((n) => n.unread).length;
  const list = notifs.filter((n) => f === 'all' || n.unread);
  const read = (id) => setNotifs(notifs.map((n) => n.id === id ? { ...n, unread: false } : n));
  return (
    <>
      <PageHead en="Notifications" ar={<><span className="num">{unread}</span> غير مقروء</>} />
      <section className="panel">
        <div className="toolbar"><Seg value={f} onChange={setF} options={[{ value: 'all', label: 'الكل', n: notifs.length }, { value: 'unread', label: 'غير مقروء', n: unread }]} /></div>
        <Async rows={5} cols={3} isEmpty={!list.length} empty={<Empty icon="fa-bell-slash" title="مفيش إشعارات غير مقروءة" desc="كل الإشعارات اتقرت." />}>
          {list.map((n) => (
            <div key={n.id} className={`notif${n.unread ? ' is-unread' : ''}`}>
              <span className="notif-ic"><i className={`fa-solid ${n.icon}`} /></span>
              <div>
                <p className="notif-title">{n.title}</p><p className="notif-body">{n.body}</p>
                <div className="row mt-8" style={{ gap: 16 }}>
                  {n.link && <button className="btn-link" onClick={() => { read(n.id); go(n.link[0], n.link[1]); }}>فتح</button>}
                  {n.unread && <button className="btn-link muted" onClick={() => read(n.id)}>تحديد كمقروء</button>}
                </div>
              </div>
              <span className="notif-time">{n.time}</span>
            </div>))}
          <Pager total={list.length} pages={1} />
        </Async>
      </section>
    </>
  );
};

/* ---------- 25. Account ---------- */
export const Account = () => {
  const { logout } = useApp();
  return (
    <>
      <PageHead en="My Account" ar="بيانات الحساب والشركة والعناوين المحفوظة">
        <button className="btn-pill" onClick={logout}><i className="fa-solid fa-arrow-right-from-bracket" /> تسجيل الخروج</button>
      </PageHead>
      <div className="acct-hero">
        <div className="wp-avatar" style={{ width: 64, height: 64, fontSize: 20 }}>مع</div>
        <div style={{ flex: 1 }}><div className="acct-name">{ACCOUNT.name}</div><div className="muted small">{ACCOUNT.company}</div></div>
        <div style={{ textAlign: 'left' }}><Badge map={{ approved: ['حساب معتمد', 'success'] }} k="approved" lg /><div className="soft small mt-8">منذ <span className="num">{ACCOUNT.approved_at}</span></div></div>
      </div>
      <div className="grid-2e mt-24">
        <Panel title="بيانات التاجر" en="TRADER">
          <dl className="kv"><dt>الاسم</dt><dd>{ACCOUNT.name}</dd><dt>الموبايل</dt><dd className="num">{ACCOUNT.phone}</dd><dt>البريد</dt><dd className="num">{ACCOUNT.email}</dd></dl>
        </Panel>
        <Panel title="البيانات التجارية" en="COMPANY">
          <dl className="kv"><dt>الشركة</dt><dd>{ACCOUNT.company}</dd><dt>الرقم الضريبي</dt><dd className="num">{ACCOUNT.tax_number}</dd><dt>السجل التجاري</dt><dd>{ACCOUNT.commercial_register}</dd><dt>حد الائتمان</dt><dd><Money v={ACCOUNT.credit_limit} /></dd></dl>
        </Panel>
      </div>
      <Panel title="العناوين المحفوظة" en="ADDRESSES" style={{ marginTop: 24 }}>
        <Async rows={3} cols={3} empty={<Empty icon="fa-location-dot" title="لا توجد عناوين محفوظة" desc="العناوين بتتحفظ تلقائيًا من طلباتك." />}>
          <div className="addr-grid">{ADDRESSES.map((a) => (
            <div key={a.id} className={`addr-card${a.default ? ' is-def' : ''}`}><div className="row between"><b>{a.label}</b>{a.default && <span className="badge accent">الافتراضي</span>}</div><div className="muted small mt-8">{a.city}</div><div className="small">{a.address}</div></div>))}</div>
        </Async>
      </Panel>
      <p className="note mt-16"><i className="fa-solid fa-circle-info" />لتعديل البيانات أو العناوين أو تغيير كلمة المرور، تواصل مع مدير حسابك.</p>
    </>
  );
};



