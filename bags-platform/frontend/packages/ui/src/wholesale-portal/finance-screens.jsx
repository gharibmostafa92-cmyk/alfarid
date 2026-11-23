import React, { useState, useEffect, useRef } from 'react';
import { useApp } from './context';
import { wholesaleEndpoints, accountEndpoints } from '@alfarid/api-client';
import { useApi } from './data/useApi';
import { DataBoundary } from './data/DataBoundary';
import { apiError, saveBlob, pageMeta, num, calendarDay, today, firstOfMonth, startOfYear, monthsAgoFirst } from './data/helpers';
import { mapOrder, mapInvoice, mapStatement, mapNotice, mapWalletTx, mapNotif } from './data/mappers';
import { ORDER_STATUS, PAY_METHOD, PAY_STATUS, NOTICE_STATUS, NOTICE_METHOD } from './data/constants';
import { fmt, Money } from './data/format';
import {
  Badge, PageHead, Crumb, Panel, Empty,
  Async, Pager, Field, Modal, Banner, Seg
} from './ui-primitives';

/* Screens 18–21, 23–25: invoices, statement, payment notices, wallet, notifications, account. */

/* ---------- 18. Invoices ---------- */
export const Invoices = ({ brandName }) => {
  const { go, toast, account } = useApp();
  const [f, setF] = useState('all');
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState(null);
  const [dl, setDl] = useState(null);
  const [unpaidTotal, setUnpaidTotal] = useState(0);

  const api = useApi((signal) => wholesaleEndpoints
    .invoices({ payment_status: f === 'all' ? undefined : f, page }, signal)
    .then((r) => ({ items: (r.data || []).map(mapInvoice), meta: pageMeta(r.meta) })), [f, page]);

  const list = api.data?.items || [];
  const meta = api.data?.meta || { page: 1, pages: 1, total: 0, per: 15 };
  const detailApi = useApi((signal) => wholesaleEndpoints.order(open.id, signal).then(mapOrder), [open?.id], !!open);
  const openOrder = detailApi.state === 'ready' ? detailApi.data : null;

  useEffect(() => {
    wholesaleEndpoints.outstanding().then((d) => setUnpaidTotal(num(d.outstanding_balance) ?? 0)).catch(() => {});
  }, []);

  const openInvoice = (o) => setOpen(o);

  const pdf = async (o) => {
    setDl(o.id);
    try {
      await saveBlob(wholesaleEndpoints.invoicePdf(o.id), `invoice-${o.number}.pdf`);
    } catch (e) {
      toast(apiError(e).message);
    } finally {
      setDl(null);
    }
  };

  return (
    <>
      <PageHead en="Invoices" ar="رقم الفاتورة هو نفس رقم الطلب" />
      <section className="panel ord-list-panel">
        <div className="toolbar ord-toolbar inv-toolbar">
          <Seg value={f} onChange={(v) => { setF(v); setPage(1); }} options={[{ value: 'all', label: 'الكل' }, { value: 'unpaid', label: 'غير مدفوع' }, { value: 'paid', label: 'مدفوع' }]} />
          <div className="inv-outstanding">
            <span className="soft small">إجمالي غير المدفوع</span>
            <Money v={unpaidTotal} strong />
          </div>
        </div>
        <DataBoundary api={api}>
          <Async rows={5} cols={7} isEmpty={!list.length} empty={<Empty icon="fa-file-invoice" title="لا توجد فواتير" />}>
            <div className="tbl-wrap ord-tbl-wrap"><table className="tbl">
              <thead><tr><th>رقم الفاتورة</th><th>التاريخ</th><th>حالة الطلب</th><th>الدفع</th><th>السداد</th><th>قبل الخصم</th><th>الخصم</th><th>الشحن</th><th>الضريبة</th><th className="t-end">الإجمالي</th><th></th></tr></thead>
              <tbody>{list.map((o) => (
                <tr key={o.id} className="is-click" onClick={() => openInvoice(o)}>
                  <td className="strong num">{o.number}</td><td className="num">{o.date}</td><td><Badge map={ORDER_STATUS} k={o.status} /></td><td>{PAY_METHOD[o.method]}</td><td><Badge map={PAY_STATUS} k={o.pay} /></td>
                  <td className="num">{fmt(o.subtotal)}</td><td className="num" style={{ color: o.discount ? 'var(--teal)' : null }}>{o.discount ? fmt(o.discount) : '—'}</td><td className="num">{fmt(o.shipping)}</td><td className="num">{fmt(o.tax)}</td>
                  <td className="t-end strong num">{fmt(o.total)}</td>
                  <td onClick={(e) => e.stopPropagation()}><div className="row-actions"><button className="btn-pill sm" onClick={() => pdf(o)} disabled={dl === o.id}>{dl === o.id ? <span className="spin" /> : <i className="fa-regular fa-file-pdf" />} PDF</button></div></td>
                </tr>))}</tbody>
            </table></div>
            <div className="ord-mobile-list">
              {list.map((o) => {
                const statusLabel = ORDER_STATUS[o.status]?.[0] || o.status;
                return (
                  <article
                    key={o.id}
                    className="ord-m-card"
                    onClick={() => openInvoice(o)}
                    onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openInvoice(o); } }}
                    tabIndex={0}
                    role="button"
                    aria-label={`عرض الفاتورة ${o.number}`}
                  >
                    <div className="ord-m-head">
                      <div className="ord-m-id">
                        <span className="ord-m-num num">{o.number}</span>
                        <span className="ord-m-date num">
                          {o.date}{statusLabel ? ` · ${statusLabel}` : ''}
                        </span>
                      </div>
                      <div className="ord-m-head-end">
                        <Badge map={PAY_STATUS} k={o.pay} />
                        <i className="fa-solid fa-chevron-left ord-m-chev" aria-hidden="true" />
                      </div>
                    </div>
                    <div className="ord-m-mid">
                      <div className="ord-m-pay">
                        <span className="ord-m-method">{PAY_METHOD[o.method] || o.method}</span>
                        {o.discount > 0 && (
                          <span className="badge success">خصم <span className="num">{fmt(o.discount)}</span></span>
                        )}
                      </div>
                      <div className="ord-m-total">
                        <span className="ord-m-total-lbl">الإجمالي</span>
                        <strong className="num">{fmt(o.total)} <small>EGP</small></strong>
                      </div>
                    </div>
                    <div className="ord-m-actions" onClick={(e) => e.stopPropagation()}>
                      <button type="button" className="btn-pill is-dark ord-m-btn" onClick={() => openInvoice(o)}>
                        <i className="fa-regular fa-file-lines" aria-hidden="true" /> عرض الفاتورة
                      </button>
                      <button type="button" className="btn-pill ord-m-btn ord-m-cancel" onClick={() => pdf(o)} disabled={dl === o.id}>
                        {dl === o.id ? <span className="spin" /> : <><i className="fa-regular fa-file-pdf" aria-hidden="true" /> PDF</>}
                      </button>
                    </div>
                  </article>
                );
              })}
            </div>
            <Pager page={meta.page} pages={meta.pages} total={meta.total} per={meta.per} onPage={setPage} />
          </Async>
        </DataBoundary>
      </section>
      {open && (
        <Modal wide title={<>فاتورة <span className="num">{open.number}</span></>} onClose={() => setOpen(null)}
          foot={<><button className="btn-pill" onClick={() => go('order', open.id)}>فتح الطلب المرتبط</button><button className="btn-pill is-dark" disabled={dl === open.id} onClick={() => pdf(open)}>{dl === open.id ? <span className="spin" /> : <i className="fa-regular fa-file-pdf" />} تنزيل PDF</button></>}>
          <div className="inv-head">
            <div><div className="wp-logo" style={{ fontSize: 20 }}>{brandName}</div><div className="soft small">فاتورة ضريبية مبسطة</div></div>
            <dl className="kv" style={{ gridTemplateColumns: '90px auto' }}><dt>التاريخ</dt><dd className="num">{open.date}</dd><dt>العميل</dt><dd>{account?.company}</dd><dt>السداد</dt><dd><Badge map={PAY_STATUS} k={open.pay} /></dd></dl>
          </div>
          <DataBoundary api={detailApi}><Async rows={3} cols={4}>
            <div className="tbl-wrap ord-tbl-wrap mt-16">
              <table className="tbl"><thead><tr><th>المنتج</th><th>الكمية</th><th>السعر</th><th className="t-end">الإجمالي</th></tr></thead>
                <tbody>{(openOrder?.items || []).map((it) => <tr key={it.id || it.sku}><td><div className="strong">{it.name}</div><div className="sku">{it.sku}</div></td><td className="num">{it.qty}</td><td className="num">{fmt(it.unit)}</td><td className="t-end num strong">{fmt(it.line_total)}</td></tr>)}</tbody></table>
            </div>
            <div className="ord-items-mobile inv-modal-items">
              {(openOrder?.items || []).map((it) => (
                <div key={it.id || it.sku} className="ord-item-m">
                  <div className="ord-item-m-top">
                    <div className="ord-item-m-info">
                      <div className="prod-name">{it.name}</div>
                      <div className="sku">{it.sku}</div>
                    </div>
                  </div>
                  <div className="ord-item-m-foot">
                    <span>الكمية: <strong className="num">{it.qty}</strong> × <span className="num">{fmt(it.unit)}</span></span>
                    <strong className="num">{fmt(it.line_total)} EGP</strong>
                  </div>
                </div>
              ))}
            </div>
          </Async></DataBoundary>
          <div className="inv-modal-totals mt-16">
            <div className="sum-row"><span>المنتجات قبل الخصم</span><strong className="num">{fmt(openOrder?.subtotal ?? open.subtotal)}</strong></div>
            <div className="sum-row is-neg"><span>الخصم</span><strong className="num">{(openOrder?.discount ?? open.discount) ? '− ' + fmt(openOrder?.discount ?? open.discount) : '—'}</strong></div>
            <div className="sum-row"><span>الشحن</span><strong className="num">{fmt(openOrder?.shipping ?? open.shipping)}</strong></div>
            <div className="sum-row"><span>الضريبة</span><strong className="num">{fmt(openOrder?.tax ?? open.tax)}</strong></div>
            <div className="sum-total"><span>الإجمالي</span><strong className="num">{fmt(openOrder?.total ?? open.total)}</strong></div>
          </div>
        </Modal>
      )}
    </>
  );
};

/* ---------- 19. Statement ---------- */
export const Statement = () => {
  const { go, account } = useApp();
  const [from, setFrom] = useState(firstOfMonth());
  const [to, setTo] = useState(today());
  const [chip, setChip] = useState(0);
  const bad = from > to;

  const api = useApi((signal) => bad
    ? Promise.resolve(null)
    : wholesaleEndpoints.statement({ from, to }, signal).then(mapStatement), [from, to, bad]);

  const entries = api.data?.entries || [];
  const totalDebit = entries.reduce((a, r) => a + (r.debit || 0), 0);
  const totalCredit = entries.reduce((a, r) => a + (r.credit || 0), 0);

  const outApi = useApi((signal) => wholesaleEndpoints
    .invoices({ payment_status: 'unpaid', page: 1, per_page: 100 }, signal)
    .then((r) => (r.data || []).map(mapInvoice)), []);
  const out = outApi.data || [];
  const debtApi = useApi(() => wholesaleEndpoints.outstanding().then((d) => num(d.outstanding_balance) ?? 0), []);
  const debt = debtApi.data ?? 0;

  const setRange = (i) => {
    setChip(i);
    if (i === 0) { setFrom(firstOfMonth()); setTo(today()); }
    else if (i === 1) { setFrom(monthsAgoFirst(2)); setTo(today()); }
    else { setFrom(startOfYear()); setTo(today()); }
  };

  return (
    <>
      <PageHead en="Statement" ar="كشف الحساب والمديونية">
        <button className="btn-pill is-dark" onClick={() => go('noticeNew')}><i className="fa-solid fa-money-check-dollar" /> تسجيل إشعار سداد</button>
      </PageHead>
      <div className="kpis stmt-kpis">
        <div className="kpi is-ink"><span className="kpi-en">CURRENT DEBT</span><span className="kpi-label">المديونية الحالية</span><span className="kpi-value">{fmt(debt)}<small>EGP</small></span></div>
        <div className="kpi"><span className="kpi-en">CREDIT LIMIT</span><span className="kpi-label">حد الائتمان</span><span className="kpi-value">{fmt(account?.credit_limit ?? 0)}<small>EGP</small></span></div>
        <div className="kpi"><span className="kpi-en">OPENING</span><span className="kpi-label">رصيد أول المدة</span><span className="kpi-value">{fmt(api.data?.opening_balance)}<small>EGP</small></span></div>
        <div className="kpi"><span className="kpi-en">CLOSING</span><span className="kpi-label">رصيد آخر المدة</span><span className="kpi-value">{fmt(api.data?.closing_balance)}<small>EGP</small></span></div>
      </div>
      <div className="grid-2 grid-summary stmt-grid">
        <section className="panel stmt-panel">
          <div className="toolbar stmt-toolbar">
            <div className="row stmt-dates">
              <Field label="من تاريخ"><input type="date" className={`line-input ltr${bad ? ' is-invalid' : ''}`} value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
              <Field label="إلى تاريخ"><input type="date" className={`line-input ltr${bad ? ' is-invalid' : ''}`} value={to} onChange={(e) => setTo(e.target.value)} /></Field>
            </div>
            <div className="chips stmt-chips">{['هذا الشهر', 'آخر 3 شهور', 'هذه السنة'].map((l, i) => <button key={l} type="button" className={`chip${chip === i ? ' is-on' : ''}`} onClick={() => setRange(i)}>{l}</button>)}</div>
          </div>
          {bad ? <div className="stmt-bad-banner"><Banner kind="error">تاريخ البداية لازم يكون قبل تاريخ النهاية.</Banner></div> : (
            <DataBoundary api={api}>
              <Async rows={8} cols={6} isEmpty={!entries.length} empty={<Empty icon="fa-receipt" title="لا توجد حركات في هذه الفترة" desc="جرب فترة زمنية أخرى." />}>
                <div className="tbl-wrap stmt-tbl-wrap"><table className="tbl">
                  <thead><tr><th>التاريخ</th><th>المرجع</th><th>البيان</th><th className="t-end">مدين</th><th className="t-end">دائن</th><th className="t-end">الرصيد</th></tr></thead>
                  <tbody>{entries.map((r, i) => (
                    <tr key={i}>
                      <td className="num">{r.date}</td><td className="sku">{r.ref}</td><td>{r.desc}</td>
                      <td className="t-end num">{r.debit ? fmt(r.debit) : ''}</td><td className="t-end num" style={{ color: 'var(--teal)' }}>{r.credit ? fmt(r.credit) : ''}</td>
                      <td className="t-end num strong">{fmt(r.bal)}</td></tr>))}</tbody>
                  <tfoot><tr><td colSpan="3">رصيد آخر المدة</td><td className="t-end num">{fmt(totalDebit)}</td><td className="t-end num">{fmt(totalCredit)}</td><td className="t-end num">{fmt(api.data?.closing_balance)}</td></tr></tfoot>
                </table></div>
                <div className="stmt-mobile-list">
                  {entries.map((r, i) => {
                    const hasCredit = Boolean(r.credit);
                    const hasDebit = Boolean(r.debit);
                    return (
                      <article key={`${r.date}-${r.ref}-${i}`} className="stmt-m-card">
                        <div className="stmt-m-head">
                          <div className="stmt-m-meta">
                            <span className="stmt-m-ref sku">{r.ref || '—'}</span>
                            <span className="stmt-m-date num">{r.date}</span>
                          </div>
                          <div className="stmt-m-amounts">
                            {hasDebit && (
                              <span className="stmt-m-amt is-debit num">
                                <small>مدين</small> {fmt(r.debit)}
                              </span>
                            )}
                            {hasCredit && (
                              <span className="stmt-m-amt is-credit num">
                                <small>دائن</small> {fmt(r.credit)}
                              </span>
                            )}
                          </div>
                        </div>
                        {r.desc && <p className="stmt-m-desc">{r.desc}</p>}
                        <div className="stmt-m-foot">
                          <span className="stmt-m-bal-lbl">الرصيد بعد الحركة</span>
                          <strong className="stmt-m-bal num">{fmt(r.bal)} <small>EGP</small></strong>
                        </div>
                      </article>
                    );
                  })}
                  <div className="stmt-m-summary">
                    <div className="stmt-m-sum-row">
                      <span>إجمالي المدين</span>
                      <strong className="num">{fmt(totalDebit)} <small>EGP</small></strong>
                    </div>
                    <div className="stmt-m-sum-row is-credit">
                      <span>إجمالي الدائن</span>
                      <strong className="num">{fmt(totalCredit)} <small>EGP</small></strong>
                    </div>
                    <div className="stmt-m-sum-total">
                      <span>رصيد آخر المدة</span>
                      <strong className="num">{fmt(api.data?.closing_balance)} <small>EGP</small></strong>
                    </div>
                  </div>
                </div>
              </Async>
            </DataBoundary>
          )}
        </section>
        <aside className="stack stmt-aside">
          <Panel title="مستحقات مفتوحة" en="OUTSTANDING" body={false}>
            <DataBoundary api={outApi}><Async rows={3} cols={2} isEmpty={!out.length} empty={<Empty title="لا توجد مستحقات مفتوحة" />}>
            {out.map((o) => (
              <div
                key={o.id}
                className="out-row"
                onClick={() => go('order', o.id)}
                onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go('order', o.id); } }}
                role="button"
                tabIndex={0}
                aria-label={`عرض الطلب ${o.number}`}
              >
                <div><b className="num">{o.number}</b><span className="num">{o.date}</span></div>
                <span className="out-row-end">
                  <span className="num strong">{fmt(o.total)}</span>
                  <i className="fa-solid fa-chevron-left out-row-chev" aria-hidden="true" />
                </span>
              </div>
            ))}
            <div className="panel-body" style={{ paddingTop: 12 }}><div className="sum-total" style={{ paddingTop: 0 }}><span>الإجمالي</span><span className="num" style={{ fontSize: 18 }}>{fmt(debt)}</span></div></div>
            </Async></DataBoundary>
          </Panel>
          <p className="note"><i className="fa-solid fa-circle-info" />المديونية والائتمان منفصلين تمامًا عن رصيد المحفظة.</p>
        </aside>
      </div>
    </>
  );
};

/* ---------- 20. New payment notice ---------- */
const NOTICE_FIELD_MAP = { bank_name: 'bank', reference_number: 'ref', transfer_date: 'date', receipt: 'file', order_id: 'order', payment_method: 'method' };

export const NoticeForm = () => {
  const { go, toast, route } = useApp();
  const preselectId = route.id;
  const [f, setF] = useState({ order: '', amount: '', method: 'bank_transfer', bank: '', ref: '', date: today() });
  const [file, setFile] = useState(null);
  const [errs, setErrs] = useState({});
  const [stage, setStage] = useState('form'); // form | confirm | sending | done
  const [done, setDone] = useState(null);
  const inputRef = useRef(null);

  const ordersApi = useApi((signal) => wholesaleEndpoints.invoices({ payment_status: 'unpaid', per_page: 100 }, signal)
    .then((r) => (r.data || []).map(mapInvoice)), []);
  const orders = ordersApi.data || [];
  useEffect(() => {
    const pre = preselectId ? orders.find((x) => x.id === preselectId) : null;
    if (pre) setF((s) => ({ ...s, order: String(pre.id), amount: String(pre.total ?? '') }));
  }, [preselectId, ordersApi.data]);

  const set = (k, v) => { setF({ ...f, [k]: v }); setErrs({ ...errs, [k]: null }); };

  const pickOrder = (orderId) => {
    const o = orders.find((x) => String(x.id) === orderId);
    setF({ ...f, order: orderId, amount: o ? String(o.total ?? '') : f.amount });
  };

  const validate = () => {
    const e = {};
    if (!f.amount || Number(f.amount) <= 0) e.amount = 'أدخل مبلغ صحيح أكبر من صفر.';
    if (!f.bank.trim()) e.bank = 'اسم البنك مطلوب.';
    if (!f.ref.trim()) e.ref = 'رقم المرجع مطلوب.';
    if (!f.date) e.date = 'تاريخ التحويل مطلوب.';
    else if (f.date > today()) e.date = 'تاريخ التحويل لا يمكن أن يكون في المستقبل.';
    if (!file) e.file = 'إيصال التحويل مطلوب.';
    else if (file.size > 10 * 1024 * 1024) e.file = 'حجم الملف أكبر من 10 ميجابايت.';
    else if (!['application/pdf', 'image/jpeg', 'image/png', 'image/webp'].includes(file.type)) e.file = 'النوع غير مدعوم (pdf/jpg/png/webp).';
    setErrs(e);
    if (Object.keys(e).length) {
      if (typeof document !== 'undefined') {
        requestAnimationFrame(() => {
          const firstErr = document.querySelector('.nt-workspace .is-invalid, .nt-workspace .f-error');
          firstErr?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
      }
      return false;
    }
    return true;
  };

  const send = async () => {
    setStage('sending');
    const fd = new FormData();
    if (f.order) fd.append('order_id', f.order);
    fd.append('amount', f.amount);
    fd.append('payment_method', f.method);
    fd.append('bank_name', f.bank);
    fd.append('reference_number', f.ref);
    fd.append('transfer_date', f.date);
    fd.append('receipt', file);
    try {
      const n = await wholesaleEndpoints.createNotice(fd);
      setDone(n);
      setStage('done');
    } catch (e) {
      const info = apiError(e);
      const mapped = {};
      Object.entries(info.fields || {}).forEach(([k, msgs]) => {
        mapped[NOTICE_FIELD_MAP[k] || k] = Array.isArray(msgs) ? msgs[0] : String(msgs);
      });
      setErrs(mapped);
      if (!Object.keys(mapped).length) toast(info.message);
      setStage('form');
    }
  };

  if (stage === 'done' && done) return (
    <div className="success-wrap"><div className="order-card">
      <div className="modal-icon" style={{ width: 64, height: 64, fontSize: 26 }}><i className="fa-solid fa-paper-plane" /></div>
      <h1 className="success-title">NOTICE SENT</h1>
      <p className="muted">تم إرسال إشعار السداد للمراجعة</p>
      <p className="success-num num">{done.notice_number}</p>
      <Banner kind="warn" icon="fa-hourglass-half">رفع الإيصال <b>لا يعني اعتماد السداد</b>. الإدارة هتراجع التحويل وتعتمده، وهيوصلك إشعار.</Banner>
      <div className="row mt-24 nt-done-actions" style={{ justifyContent: 'center' }}><button className="btn-pill is-dark" onClick={() => go('notice', done.id)}>عرض الإشعار</button><button className="btn-pill" onClick={() => go('notices')}>كل الإشعارات</button></div>
    </div></div>
  );

  return (
    <>
      <PageHead en="Payment Notice" crumb={<Crumb items={[{ label: 'إشعارات السداد', go: () => go('notices') }, { label: 'إشعار جديد' }]} />} ar="سجّل تحويل أو شيك أو إيداع تم لحساب الشركة" />
      <div className="grid-2 grid-summary qf-workspace nt-workspace has-float">
        <section className="panel"><div className="panel-body">
          <div className="fields">
            <Field label="طلب مرتبط" opt span error={errs.order}>
              <DataBoundary api={ordersApi}><Async rows={1} cols={1}>
              <select className="line-input" value={f.order} onChange={(e) => pickOrder(e.target.value)}>
                <option value="">بدون — سداد على الحساب</option>{orders.map((o) => <option key={o.id} value={o.id}>{o.number} · {fmt(o.total)} EGP</option>)}
              </select></Async></DataBoundary></Field>
            <Field label="المبلغ" req error={errs.amount}><div style={{ position: 'relative' }}><input className={`line-input ltr${errs.amount ? ' is-invalid' : ''}`} style={{ textAlign: 'right', paddingLeft: 40 }} inputMode="decimal" placeholder="0.00" value={f.amount} onChange={(e) => { const v = e.target.value; if (/^\d*(\.\d{0,2})?$/.test(v)) set('amount', v); }} /><span className="soft small" style={{ position: 'absolute', left: 0, top: 12 }}>EGP</span></div></Field>
            <Field label="تاريخ التحويل" req error={errs.date}><input type="date" max={today()} className={`line-input ltr${errs.date ? ' is-invalid' : ''}`} value={f.date} onChange={(e) => set('date', e.target.value)} /></Field>
            <Field label="طريقة السداد" req span error={errs.method}>
              <div className="chips nt-method-chips">{Object.entries(NOTICE_METHOD).map(([k, l]) => <button key={k} type="button" className={`chip${f.method === k ? ' is-on' : ''}`} onClick={() => set('method', k)}><i className={`fa-solid ${{ bank_transfer: 'fa-building-columns', check: 'fa-money-check', deposit: 'fa-piggy-bank' }[k]}`} />{l}</button>)}</div></Field>
            <Field label="اسم البنك" req error={errs.bank}><input className={`line-input${errs.bank ? ' is-invalid' : ''}`} placeholder="مثال: البنك الأهلي المصري" value={f.bank} onChange={(e) => set('bank', e.target.value)} /></Field>
            <Field label={f.method === 'check' ? 'رقم الشيك' : 'رقم المرجع'} req error={errs.ref}><input className={`line-input ltr${errs.ref ? ' is-invalid' : ''}`} style={{ textAlign: 'right' }} value={f.ref} onChange={(e) => set('ref', e.target.value)} /></Field>
            <Field label="الإيصال" req span error={errs.file}>
              <input ref={inputRef} type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" style={{ display: 'none' }} onChange={(e) => { setFile(e.target.files?.[0] || null); setErrs({ ...errs, file: null }); }} />
              {file ? (
                <div className="file-row"><i className="fa-regular fa-file-pdf" aria-hidden="true" /><div className="grow"><b className="num">{file.name}</b></div><button type="button" className="qf-m-del" onClick={() => setFile(null)} aria-label="حذف الإيصال"><i className="fa-solid fa-xmark" aria-hidden="true" /></button></div>
              ) : (
                <div
                  className={`drop nt-drop${errs.file ? ' is-invalid' : ''}`}
                  style={{ minHeight: 120 }}
                  role="button"
                  tabIndex={0}
                  onClick={() => inputRef.current?.click()}
                  onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); inputRef.current?.click(); } }}
                >
                  <i className="fa-solid fa-receipt" aria-hidden="true" /><b>ارفع صورة أو PDF للإيصال</b><small>PDF · JPG · JPEG · PNG · WebP — حتى <span className="num">10</span> ميجابايت</small></div>
              )}
            </Field>
          </div>
        </div></section>
        <aside className="stack qf-aside">
          <Panel title="مراجعة">
            <div className="sum-row"><span>الطلب</span><strong className="num">{f.order ? orders.find((o) => String(o.id) === f.order)?.number : 'على الحساب'}</strong></div>
            <div className="sum-row"><span>الطريقة</span><strong>{NOTICE_METHOD[f.method]}</strong></div>
            <div className="sum-total"><span>المبلغ</span><span className="num">{f.amount ? fmt(f.amount) : '—'}</span></div>
            <button className="btn-block mt-24" onClick={() => validate() && setStage('confirm')}>إرسال للمراجعة</button>
          </Panel>
          <p className="note"><i className="fa-solid fa-circle-info" />السداد مش بيتعتمد تلقائيًا؛ الإدارة بتراجع الإيصال مع كشف البنك.</p>
        </aside>
      </div>
      <div className="qf-float-bar" role="region" aria-label="ملخص وإرسال إشعار السداد">
        <div className="qf-float-info">
          <strong className="num">{f.amount ? `${fmt(f.amount)} EGP` : 'إشعار سداد'}</strong>
          <small>{NOTICE_METHOD[f.method]}</small>
        </div>
        <div className="qf-float-actions">
          <button type="button" className="btn-pill is-dark" onClick={() => validate() && setStage('confirm')}>
            <i className="fa-solid fa-paper-plane" aria-hidden="true" /> <span>إرسال للمراجعة</span>
          </button>
        </div>
      </div>
      {(stage === 'confirm' || stage === 'sending') && <Modal title="تأكيد إرسال إشعار السداد" onClose={() => setStage('form')} icon={<div className="modal-icon"><i className="fa-solid fa-money-check-dollar" /></div>}
        foot={<><button className="btn-pill" onClick={() => setStage('form')}>تعديل</button><button className="btn-pill is-dark" disabled={stage === 'sending'} onClick={send}>{stage === 'sending' ? <span className="spin" /> : null} تأكيد الإرسال</button></>}>
        <dl className="kv"><dt>المبلغ</dt><dd><Money v={f.amount} strong /></dd><dt>الطريقة</dt><dd>{NOTICE_METHOD[f.method]}</dd><dt>البنك</dt><dd>{f.bank}</dd><dt>المرجع</dt><dd className="sku">{f.ref}</dd><dt>التاريخ</dt><dd className="num">{f.date}</dd><dt>الإيصال</dt><dd className="num">{file?.name}</dd></dl>
      </Modal>}
    </>
  );
};

/* ---------- 21. Notices list + detail ---------- */
export const Notices = () => {
  const { go } = useApp();
  const [f, setF] = useState('all');
  const [page, setPage] = useState(1);

  const filterStatusMap = {
    all: undefined,
    pending: 'pending_review',
    approved: 'approved',
    rejected: 'rejected',
    reversed: 'reversed',
  };

  const api = useApi((signal) => wholesaleEndpoints
    .notices({ status: filterStatusMap[f], page }, signal)
    .then((r) => ({ items: (r.data || []).map(mapNotice), meta: pageMeta(r.meta) })), [f, page]);

  const list = api.data?.items || [];
  const meta = api.data?.meta || { page: 1, pages: 1, total: 0, per: 15 };

  return (
    <>
      <PageHead en="Payment Notices" ar="إشعارات السداد المرسلة وحالة مراجعتها"><button className="btn-pill is-dark" onClick={() => go('noticeNew')}><i className="fa-solid fa-plus" /> إشعار جديد</button></PageHead>
      <section className="panel">
        <div className="toolbar"><Seg value={f} onChange={(v) => { setF(v); setPage(1); }} options={[
          { value: 'all', label: 'الكل' },
          { value: 'pending', label: 'قيد المراجعة' },
          { value: 'approved', label: 'معتمد' },
          { value: 'rejected', label: 'مرفوض' },
          { value: 'reversed', label: 'معكوس' },
        ]} /></div>
        <DataBoundary api={api}>
          <Async rows={5} cols={6} isEmpty={!list.length} empty={<Empty icon="fa-money-check" title="لا توجد إشعارات سداد" action={<button className="btn-pill is-dark" onClick={() => go('noticeNew')}>تسجيل إشعار</button>} />}>
            <div className="tbl-wrap"><table className="tbl">
              <thead><tr><th>رقم الإشعار</th><th>الطلب</th><th>الطريقة</th><th>تاريخ التحويل</th><th>الحالة</th><th>تاريخ الإنشاء</th><th className="t-end">المبلغ</th></tr></thead>
              <tbody>{list.map((n) => (
                <tr key={n.id} className="is-click" onClick={() => go('notice', n.id)}>
                  <td className="strong num">{n.number}</td><td className="num">{n.order || <span className="soft">على الحساب</span>}</td><td>{NOTICE_METHOD[n.method]}</td><td className="num">{n.date}</td>
                  <td><Badge map={NOTICE_STATUS} k={n.status} /></td><td className="num">{n.created}</td><td className="t-end strong num">{fmt(n.amount)}</td></tr>))}</tbody>
            </table></div>
            <Pager page={meta.page} pages={meta.pages} total={meta.total} per={meta.per} onPage={setPage} />
          </Async>
        </DataBoundary>
      </section>
    </>
  );
};

export const NoticeDetail = ({ id }) => {
  const { go, toast } = useApp();
  const [dl, setDl] = useState(false);
  const api = useApi((signal) => wholesaleEndpoints.notice(id, signal).then(mapNotice), [id]);
  const n = api.data;

  const receipt = async () => {
    setDl(true);
    try {
      await saveBlob(wholesaleEndpoints.noticeReceipt(id), 'receipt');
    } catch (e) {
      toast(apiError(e).message);
    } finally {
      setDl(false);
    }
  };

  return (
    <>
      <PageHead en={n?.number || ''} crumb={<Crumb items={[{ label: 'إشعارات السداد', go: () => go('notices') }, { label: n?.number || '', mono: true }]} />} ar={n ? <>أُرسل في <span className="num">{n.created}</span></> : null}>
        {n && <Badge map={NOTICE_STATUS} k={n.status} lg />}
      </PageHead>
      <DataBoundary api={api}>
        <Async rows={4} cols={4} empty={<Empty icon="fa-money-check" title="الإشعار غير موجود" />}>
          {n && (
          <>
            {n.status === 'rejected' && <div className="mb-24"><Banner kind="error" action={<button className="btn-pill sm" onClick={() => go('noticeNew')}>إرسال إشعار جديد</button>}>تم رفض الإشعار. راجع ملاحظة الإدارة وأرسل إشعار جديد بالبيانات الصحيحة.</Banner></div>}
            {n.status === 'reversed' && <div className="mb-24"><Banner kind="error">تم عكس اعتماد هذا الإشعار من قِبل الإدارة المالية، وأُعيدت المبالغ إلى المديونية القائمة. راجع ملاحظات الإدارة أدناه.</Banner></div>}
            {n.status === 'pending' && <div className="mb-24"><Banner kind="warn" icon="fa-hourglass-half">الإشعار قيد المراجعة من الإدارة. المبلغ لن يُخصم من المديونية قبل الاعتماد.</Banner></div>}
            <div className="grid-2e">
              <Panel title="بيانات السداد" en="DETAILS">
                <dl className="kv"><dt>المبلغ</dt><dd><Money v={n.amount} strong /></dd><dt>الطريقة</dt><dd>{NOTICE_METHOD[n.method]}</dd><dt>اسم البنك</dt><dd>{n.bank}</dd><dt>رقم المرجع</dt><dd className="sku">{n.ref}</dd><dt>تاريخ التحويل</dt><dd className="num">{n.date}</dd>
                  <dt>الطلب المرتبط</dt><dd>{n.order_id ? <button className="btn-link num" onClick={() => go('order', n.order_id)}>{n.order}</button> : <span className="soft">على الحساب</span>}</dd></dl>
              </Panel>
              <div className="stack">
                <Panel title="الإيصال" en="RECEIPT">
                  {n.receipt ? <div className="file-row"><i className="fa-regular fa-file-image" /><div className="grow num">الإيصال المرفق</div><button className="btn-pill sm" disabled={dl} onClick={receipt}>{dl ? <span className="spin" /> : <i className="fa-solid fa-download" />} تنزيل</button></div> : <p className="muted">لا يوجد إيصال مرفق.</p>}
                </Panel>
                <Panel title="ملاحظات الإدارة" en="ADMIN NOTE">
                  <div className="note-bubble is-admin" style={{ margin: 0 }}><p>{n.admin_note || <span className="soft">لا توجد ملاحظات</span>}</p></div>
                </Panel>
              </div>
            </div>
          </>
          )}
        </Async>
      </DataBoundary>
    </>
  );
};

/* ---------- 23. Wallet ---------- */
export const Wallet = () => {
  const [page, setPage] = useState(1);
  const { go } = useApp();
  const [direction, setDirection] = useState('all');
  const [days, setDays] = useState('all');
  const fromDate = days === 'all' ? undefined : (() => { const d = new Date(); d.setDate(d.getDate() - Number(days)); return calendarDay(d); })();
  const api = useApi(() => accountEndpoints.wallet(page, {
    ...(direction === 'all' ? {} : { direction }),
    ...(fromDate ? { from: fromDate } : {}),
  }), [page, direction, days]);
  const balance = num(api.data?.balance) ?? 0;
  const txs = (api.data?.transactions?.data || []).map(mapWalletTx);
  const tMeta = api.data?.transactions ? pageMeta(api.data.transactions) : { page: 1, pages: 1, total: txs.length, per: 20 };

  return (
    <>
      <PageHead en="Wallet" ar="رصيد المحفظة وحركاتها — عرض فقط" />
      <div className="wallet-hero">
        <div><span className="kpi-en" style={{ color: '#a3a3a3' }}>CURRENT BALANCE</span><div className="wallet-amt num">{fmt(balance)} <small>EGP</small></div><span style={{ color: '#a3a3a3', fontSize: 13 }}>الرصيد الحالي</span></div>
        <p className="note" style={{ color: '#a3a3a3', maxWidth: 300 }}><i className="fa-solid fa-circle-info" />الرصيد بيتضاف من المرتجعات والتسويات، ويُستخدم كطريقة دفع عند إتمام الطلب. منفصل عن الائتمان والمديونية.</p>
      </div>
      <section className="panel mt-24">
        <div className="panel-head" style={{ paddingBottom: 6 }}><h2 className="panel-title">الحركات</h2><span className="panel-en">TRANSACTIONS</span></div>
        <div className="toolbar">
          <Seg value={direction} onChange={(v) => { setDirection(v); setPage(1); }} options={[{ value: 'all', label: 'الكل' }, { value: 'credit', label: 'إضافة' }, { value: 'debit', label: 'خصم' }]} />
          <Seg value={days} onChange={(v) => { setDays(v); setPage(1); }} options={[{ value: 'all', label: 'كل الفترات' }, { value: '7', label: 'آخر 7 أيام' }, { value: '30', label: 'آخر 30 يوم' }]} />
        </div>
        <DataBoundary api={api}>
          <Async rows={4} cols={5} isEmpty={!txs.length} empty={<Empty icon="fa-wallet" title="مفيش حركات على المحفظة" />}>
            <div className="tbl-wrap"><table className="tbl">
              <thead><tr><th>النوع</th><th>المرجع</th><th>التاريخ</th><th className="t-end">المبلغ</th><th className="t-end">الرصيد بعد الحركة</th></tr></thead>
              <tbody>{txs.map((t) => (
                <tr key={t.id}><td><div className="row" style={{ gap: 10 }}><span className={`wtx-ic${t.amount < 0 ? ' neg' : ''}`}><i className={`fa-solid ${t.amount < 0 ? 'fa-arrow-up' : 'fa-arrow-down'}`} /></span><span className="strong">{t.label}</span></div></td>
                  <td className="sku">{t.refLink ? <button type="button" className="btn-link num" onClick={() => go(t.refLink[0], t.refLink[1])}>{t.ref}</button> : t.ref}</td><td className="num">{t.date}</td>
                  <td className="t-end num strong" style={{ color: t.amount < 0 ? '#b91c1c' : 'var(--teal)' }}>{t.amount > 0 ? '+' : '−'}{fmt(Math.abs(t.amount ?? 0))}</td>
                  <td className="t-end num">{fmt(t.after)}</td></tr>))}</tbody>
            </table></div>
            <Pager page={tMeta.page} pages={tMeta.pages} total={tMeta.total} per={tMeta.per} onPage={setPage} />
          </Async>
        </DataBoundary>
      </section>
    </>
  );
};

/* ---------- 24. Notifications ---------- */
export const Notifications = () => {
  const { go, unread, setUnread, toast } = useApp();
  const [f, setF] = useState('all');
  const [page, setPage] = useState(1);

  const api = useApi((signal) => wholesaleEndpoints.notifications(page, signal, f === 'unread')
    .then((r) => ({ items: (r.data || []).map(mapNotif), meta: r.meta })), [page, f]);

  const list = api.data?.items || [];
  const unreadCount = api.data?.meta?.unread_count ?? unread;
  const allTotalRef = useRef(null);
  if (f === 'all' && api.data?.meta?.total != null) {
    allTotalRef.current = api.data.meta.total;
  }
  const allCount = f === 'all' ? (api.data?.meta?.total ?? (api.data ? list.length : null)) : allTotalRef.current;

  const read = async (n) => {
    try {
      const r = await wholesaleEndpoints.readNotification(n.id);
      setUnread(r.meta.unread_count);
      api.setData((d) => d && ({ ...d, meta: { ...d.meta, unread_count: r.meta.unread_count }, items: d.items.map((x) => (x.id === n.id ? { ...x, unread: false } : x)) }));
      return true;
    } catch (e) {
      toast(apiError(e).message);
    }
  };

  const open = async (n) => {
    if (await read(n) && n.link) go(n.link[0], n.link[1]);
  };

  const readAll = async () => {
    try {
      const r = await wholesaleEndpoints.readAllNotifications();
      setUnread(r.meta.unread_count);
      api.setData((d) => d && ({ ...d, meta: { ...d.meta, unread_count: r.meta.unread_count }, items: d.items.map((x) => ({ ...x, unread: false })) }));
    } catch (e) {
      toast(apiError(e).message);
    }
  };

  return (
    <>
      <PageHead en="Notifications" ar={<><span className="num">{unreadCount}</span> غير مقروء</>}>
        <button className="btn-pill" onClick={readAll}>تحديد الكل كمقروء</button>
      </PageHead>
      <section className="panel">
        <div className="toolbar"><Seg value={f} onChange={(v) => { setF(v); setPage(1); }} options={[{ value: 'all', label: 'الكل', n: allCount }, { value: 'unread', label: 'غير مقروء', n: unreadCount }]} /></div>
        <DataBoundary api={api}>
          <Async rows={5} cols={3} isEmpty={!list.length} empty={<Empty icon="fa-bell-slash" title="مفيش إشعارات غير مقروءة" desc="كل الإشعارات اتقرت." />}>
            {list.map((n) => (
              <div key={n.id} className={`notif${n.unread ? ' is-unread' : ''}`}>
                <span className="notif-ic"><i className={`fa-solid ${n.icon}`} /></span>
                <div>
                  <p className="notif-title">{n.title}</p><p className="notif-body">{n.body}</p>
                  <div className="row mt-8" style={{ gap: 16 }}>
                    {n.link && <button className="btn-link" onClick={() => open(n)}>فتح</button>}
                    {n.unread && <button className="btn-link muted" onClick={() => read(n)}>تحديد كمقروء</button>}
                  </div>
                </div>
                <span className="notif-time">{n.time}</span>
              </div>))}
            <Pager page={page} pages={api.data?.meta?.last_page ?? 1} total={api.data?.meta?.total ?? list.length} per={api.data?.meta?.per_page ?? 20} onPage={setPage} />
          </Async>
        </DataBoundary>
      </section>
    </>
  );
};

/* ---------- 25. Account ---------- */
export const Account = () => {
  const { logout, account } = useApp();
  const [addrs, setAddrs] = useState([]);
  const addrApi = useApi(() => accountEndpoints.addresses().then((a) => { setAddrs(a); return a; }), []);

  return (
    <>
      <PageHead en="My Account" ar="بيانات الحساب والشركة والعناوين المحفوظة">
        <button className="btn-pill" onClick={logout}><i className="fa-solid fa-arrow-right-from-bracket" /> تسجيل الخروج</button>
      </PageHead>
      <div className="acct-hero">
        <div className="wp-avatar acct-av">{String(account?.name || '').trim().split(/\s+/).filter(Boolean).map((s) => s[0]).slice(0, 2).join('') || '—'}</div>
        <div className="acct-hero-info"><div className="acct-name">{account?.name}</div><div className="muted small">{account?.company}</div></div>
        <div className="acct-hero-status"><Badge map={{ approved: ['حساب معتمد', 'success'] }} k="approved" lg /><div className="soft small acct-since">منذ <span className="num">{account?.approved_at}</span></div></div>
      </div>
      <div className="grid-2e mt-24">
        <Panel title="بيانات التاجر" en="TRADER">
          <dl className="kv acct-kv"><dt>الاسم</dt><dd>{account?.name}</dd><dt>الموبايل</dt><dd className="num">{account?.phone}</dd><dt>البريد</dt><dd className="num">{account?.email}</dd></dl>
        </Panel>
        <Panel title="البيانات التجارية" en="COMPANY">
          <dl className="kv acct-kv"><dt>الشركة</dt><dd>{account?.company}</dd><dt>الرقم الضريبي</dt><dd className="num">{account?.tax_number}</dd><dt>السجل التجاري</dt><dd>{account?.commercial_register}</dd><dt>حد الائتمان</dt><dd><Money v={account?.credit_limit} /></dd></dl>
        </Panel>
      </div>
      <Panel title="العناوين المحفوظة" en="ADDRESSES" style={{ marginTop: 24 }}>
        <DataBoundary api={addrApi}>
          <Async rows={3} cols={3} isEmpty={!addrs.length} empty={<Empty icon="fa-location-dot" title="لا توجد عناوين محفوظة" desc="العناوين بتتحفظ تلقائيًا من طلباتك." />}>
            <div className="addr-grid">{addrs.map((a) => (
              <div key={a.id} className={`addr-card${a.is_default ? ' is-def' : ''}`}><div className="row between"><b>{a.city_name_ar}</b>{a.is_default && <span className="badge accent">الافتراضي</span>}</div><div className="small">{a.address_line}</div></div>))}</div>
          </Async>
        </DataBoundary>
      </Panel>
      <p className="note mt-16"><i className="fa-solid fa-circle-info" />لتعديل البيانات أو العناوين أو تغيير كلمة المرور، تواصل مع مدير حسابك.</p>
    </>
  );
};
