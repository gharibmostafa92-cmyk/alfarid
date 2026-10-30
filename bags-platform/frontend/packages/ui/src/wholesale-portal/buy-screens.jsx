import React, { useState, useEffect, useRef } from 'react';
import { useApp } from './context';
import { wholesaleEndpoints, cartEndpoints, checkoutEndpoints, accountEndpoints } from '@alfarid/api-client';
import { useApi } from './data/useApi';
import { DataBoundary } from './data/DataBoundary';
import { apiError, saveBlob, pageMeta, num, day } from './data/helpers';
import { mapProduct, mapOrder, mapShippingOptions, mapQuickLookup } from './data/mappers';
import { ORDER_STATUS, PAY_METHOD, PAY_STATUS, QUOTE_STATUS } from './data/constants';
import { fmt, Money } from './data/format';
import {
  Badge, PageHead, Crumb, Panel, Empty,
  Async, Pager, Field, Modal, Banner, Qty, Thumb, Seg
} from './ui-primitives';

/* Screens 6–12: dashboard, catalog, quick order + import, cart, checkout, success. */

const QUOTE_ST = { priced_by_admin: 'priced', converted_to_order: 'converted' };

/* ---------- 6. Dashboard ---------- */
export const Dashboard = () => {
  const { go, account, dash } = useApp();
  const pct = account?.credit_limit ? Math.round(((account.used_credit ?? 0) / account.credit_limit) * 100) : 0;
  const invoices = (dash?.latest_invoices || []).map((o) => ({
    id: o.id, number: o.order_number, date: day(o.created_at),
    status: o.status, method: o.payment_method, pay: o.payment_status, total: num(o.grand_total),
  }));
  const quotes = (dash?.latest_quotations || []).map((q) => ({
    id: q.id, number: q.quotation_number, created: day(q.created_at), valid_until: q.valid_until,
    status: QUOTE_ST[q.status] || q.status, total: num(q.grand_total),
  }));
  const shortcuts = [
    ['catalog', 'fa-table-cells-large', 'الكتالوج وقائمة الأسعار', 'CATALOG'],
    ['quick', 'fa-bolt', 'طلب سريع بالـ SKU', 'QUICK ORDER'],
    ['quoteNew', 'fa-file-signature', 'طلب عرض سعر جديد', 'NEW QUOTE'],
    ['noticeNew', 'fa-money-check-dollar', 'تسجيل إشعار سداد', 'PAYMENT NOTICE'],
  ];
  return (
    <>
      <PageHead en="Dashboard" ar={<>أهلًا {String(account?.name || '').split(' ')[0]} — {account?.company}</>} />
      <Async rows={3} cols={4} empty={<Empty title="لا توجد بيانات بعد" />}>
        <div className="kpis">
          <div className="kpi is-ink">
            <span className="kpi-en">CREDIT LIMIT</span><span className="kpi-label">حد الائتمان</span>
            <span className="kpi-value">{fmt(account?.credit_limit ?? 0, 0)}<small>EGP</small></span>
          </div>
          <div className="kpi">
            <span className="kpi-en">USED CREDIT</span><span className="kpi-label">المستخدم من الائتمان</span>
            <span className="kpi-value">{fmt(account?.used_credit ?? 0, 0)}<small>EGP</small></span>
            <div className="kpi-bar"><span style={{ width: pct + '%' }} /></div>
            <span className="kpi-foot"><span className="num">{pct}%</span> من الحد</span>
          </div>
          <div className="kpi">
            <span className="kpi-en">AVAILABLE</span><span className="kpi-label">المتاح للشراء الآجل</span>
            <span className="kpi-value" style={{ color: 'var(--accent)' }}>{fmt(account?.available_credit ?? 0, 0)}<small>EGP</small></span>
          </div>
          <div className="kpi" style={{ cursor: 'pointer' }} onClick={() => go('orders')}>
            <span className="kpi-en">IN PROGRESS</span><span className="kpi-label">طلبات جاري تنفيذها</span>
            <span className="kpi-value">{account?.in_progress_orders_count ?? 0}</span>
            <span className="kpi-foot">عرض الطلبات <i className="fa-solid fa-arrow-left" style={{ fontSize: 10 }} /></span>
          </div>
        </div>

        <div className="dash-shortcuts">
          {shortcuts.map(([r, ic, l, en]) => (
            <button key={r} className="dash-sc" onClick={() => go(r)}>
              <i className={`fa-solid ${ic}`} />
              <span><b>{l}</b><small>{en}</small></span>
              <i className="fa-solid fa-arrow-left dash-sc-arrow" />
            </button>
          ))}
        </div>

        <div className="dash-tables">
          <Panel title="آخر الفواتير" en="LATEST 5 INVOICES" body={false} action={<button className="btn-link muted" onClick={() => go('invoices')}>عرض الكل</button>}>
            <div className="tbl-wrap"><table className="tbl">
              <thead><tr><th>رقم الطلب</th><th>الحالة</th><th>الدفع</th><th>السداد</th><th className="t-end">الإجمالي</th></tr></thead>
              <tbody>{invoices.slice(0, 5).map((o) => (
                <tr key={o.id} className="is-click" onClick={() => go('order', o.id)}>
                  <td><div className="strong num">{o.number}</div><div className="soft small num">{o.date}</div></td>
                  <td><Badge map={ORDER_STATUS} k={o.status} /></td>
                  <td>{PAY_METHOD[o.method]}</td>
                  <td><Badge map={PAY_STATUS} k={o.pay} /></td>
                  <td className="t-end strong num">{fmt(o.total)}</td>
                </tr>))}</tbody>
            </table></div>
          </Panel>
          <Panel title="آخر عروض الأسعار" en="LATEST 5 QUOTATIONS" body={false} action={<button className="btn-link muted" onClick={() => go('quotes')}>عرض الكل</button>}>
            <div className="tbl-wrap"><table className="tbl">
              <thead><tr><th>الرقم</th><th>الحالة</th><th>صالح حتى</th><th className="t-end">الإجمالي</th></tr></thead>
              <tbody>{quotes.slice(0, 5).map((q) => (
                <tr key={q.id} className="is-click" onClick={() => go('quote', q.id)}>
                  <td><div className="strong num">{q.number}</div><div className="soft small num">{q.created}</div></td>
                  <td><Badge map={QUOTE_STATUS} k={q.status} /></td>
                  <td className="num">{q.valid_until || '—'}</td>
                  <td className="t-end strong num">{q.total != null ? fmt(q.total) : '—'}</td>
                </tr>))}</tbody>
            </table></div>
          </Panel>
        </div>
      </Async>
    </>
  );
};

/* ---------- 7. Catalog ---------- */
export const Catalog = () => {
  const { addToCart, toast } = useApp();
  const [q, setQ] = useState('');
  const [debouncedQ, setDebouncedQ] = useState('');
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState(null);
  const [exporting, setExporting] = useState(null);

  useEffect(() => {
    const t = setTimeout(() => { setDebouncedQ(q); setPage(1); }, 400);
    return () => clearTimeout(t);
  }, [q]);

  const api = useApi((signal) => wholesaleEndpoints
    .products({ search: debouncedQ || undefined, page, per_page: 24 }, signal)
    .then((r) => ({ items: (r.data || []).map(mapProduct), meta: pageMeta(r.meta) })), [debouncedQ, page]);

  const list = api.data?.items || [];
  const meta = api.data?.meta || { page: 1, pages: 1, total: 0, per: 24 };

  const doExport = async (f) => {
    setExporting(f);
    try {
      await saveBlob(wholesaleEndpoints.exportPriceList(f), `price-list.${f}`);
      toast(`تم تنزيل قائمة الأسعار ${f.toUpperCase()}`);
    } catch (e) {
      toast(apiError(e).message);
    } finally {
      setExporting(null);
    }
  };

  return (
    <>
      <PageHead en="Price List" ar="كتالوج الجملة بأسعار حسابك وشرائح الكميات">
        <button className="btn-pill" onClick={() => doExport('pdf')} disabled={!!exporting}>{exporting === 'pdf' ? <span className="spin" /> : <i className="fa-regular fa-file-pdf" />} تنزيل PDF</button>
        <button className="btn-pill" onClick={() => doExport('xlsx')} disabled={!!exporting}>{exporting === 'xlsx' ? <span className="spin" /> : <i className="fa-regular fa-file-excel" />} تنزيل Excel</button>
      </PageHead>
      <section className="panel">
        <div className="toolbar">
          <div className="search"><i className="fa-solid fa-magnifying-glass" /><input className="line-input" placeholder="ابحث بالاسم أو SKU…" value={q} onChange={(e) => setQ(e.target.value)} /></div>
          <span className="soft small"><span className="num">{meta.total}</span> منتج · الأسعار شاملة خصم حسابك</span>
        </div>
        <DataBoundary api={api}>
          <Async rows={6} cols={6} isEmpty={list.length === 0} empty={<Empty icon="fa-magnifying-glass" title="مفيش نتائج" desc={q ? `مفيش منتج أو SKU يطابق «${q}».` : 'لا توجد منتجات متاحة حاليًا.'} action={q && <button className="btn-pill" onClick={() => setQ('')}>مسح البحث</button>} />}>
            <div className="tbl-wrap"><table className="tbl cat-tbl">
              <thead><tr><th>المنتج</th><th>المتغيرات</th><th>السعر الأساسي</th><th>سعرك</th><th>شرائح الكميات</th><th>المتاح</th><th></th></tr></thead>
              <tbody>{list.map((p) => {
                const v = p.variants[0];
                const stock = p.variants.reduce((a, x) => a + (x.qty || 0), 0);
                const prices = p.variants.map((x) => x.price).filter((x) => x != null);
                return (
                  <tr key={p.id}>
                    <td><div className="prod-cell"><Thumb sm /><div><div className="prod-name">{p.name_ar}</div><div className="prod-en">{p.name_en}</div></div></div></td>
                    <td><span className="num strong">{p.variants.length}</span> <span className="soft small">{p.variants.map((x) => x.label).join(' · ')}</span></td>
                    <td><span className="strike num">{fmt(p.base)}</span></td>
                    <td className="strong num">{prices.length > 1 && Math.min(...prices) !== Math.max(...prices) ? 'من ' : ''}{prices.length ? fmt(Math.min(...prices)) : '—'}</td>
                    <td>{v ? v.tiers.map(([m, pr]) => <span key={m} className="tier"><span className="num">{m}+</span> ← <b className="num">{fmt(pr, 0)}</b></span>) : '—'}</td>
                    <td>{stock === 0 ? <span className="badge danger">نفدت الكمية</span> : <span className={`num ${stock < 50 ? '' : 'strong'}`} style={stock < 50 ? { color: '#b45309', fontWeight: 600 } : null}>{stock}</span>}</td>
                    <td className="t-end"><button className="btn-pill is-dark sm" onClick={() => setOpen(p)}>اختيار الكمية</button></td>
                  </tr>);
              })}</tbody>
            </table></div>
            <Pager page={meta.page} pages={meta.pages} total={meta.total} per={meta.per} onPage={setPage} />
          </Async>
        </DataBoundary>
      </section>
      <p className="note mt-16"><i className="fa-solid fa-circle-info" />السعر النهائي لكل بند يتحسب في السلة حسب الكمية والشريحة المطبقة وقت الطلب.</p>
      {open && <VariantModal product={open} onClose={() => setOpen(null)} onAdd={(lines) => { addToCart(lines.map((l) => ({ variant_id: l.v.id, qty: l.qty }))); setOpen(null); }} />}
    </>
  );
};

export const tierPrice = (v, qty) => { let p = v.price; (v.tiers || []).forEach(([m, pr]) => { if (qty >= m) p = pr; }); return p; };

export const VariantModal = ({ product, onClose, onAdd }) => {
  const [qty, setQty] = useState(Object.fromEntries(product.variants.map((v) => [v.id, 0])));
  const lines = product.variants.filter((v) => qty[v.id] > 0).map((v) => ({ v: { ...v, product }, qty: qty[v.id] }));
  const total = lines.reduce((a, l) => a + tierPrice(l.v, l.qty) * l.qty, 0);
  const over = product.variants.some((v) => qty[v.id] > (v.qty || 0));
  return (
    <Modal wide title={product.name_ar} onClose={onClose} icon={<Thumb sm />}
      foot={<><span className="muted small" style={{ marginInlineEnd: 'auto' }}>تقديري: <Money v={total} strong /></span>
        <button className="btn-pill" onClick={onClose}>إلغاء</button>
        <button className="btn-pill is-dark" disabled={!lines.length || over} onClick={() => onAdd(lines)}><i className="fa-solid fa-cart-plus" /> إضافة للسلة {lines.length > 0 && <span className="num">({lines.reduce((a, l) => a + l.qty, 0)})</span>}</button></>}>
      <p className="prod-en" style={{ textAlign: 'right', marginTop: -10 }}>{product.name_en} · السعر الأساسي <span className="strike">{fmt(product.base)}</span></p>
      <div className="tbl-wrap mt-16"><table className="tbl">
        <thead><tr><th>المتغير</th><th>SKU / باركود</th><th>سعرك</th><th>الشرائح</th><th>المتاح</th><th>الكمية</th></tr></thead>
        <tbody>{product.variants.map((v) => {
          const q = qty[v.id]; const bad = q > (v.qty || 0);
          return (
            <tr key={v.id} className={bad ? 'is-error' : ''}>
              <td className="strong">{v.label}</td>
              <td><div className="sku">{v.sku}</div><div className="sku soft">{v.barcode}</div></td>
              <td className="strong num">{v.price != null ? fmt(q ? tierPrice(v, q) : v.price) : '—'}</td>
              <td>{(v.tiers || []).map(([m, pr]) => <span key={m} className="tier" style={q >= m ? { background: 'var(--accent)', color: '#fff', borderColor: 'var(--accent)' } : null}><span className="num">{m}+</span> <b className="num">{fmt(pr, 0)}</b></span>)}</td>
              <td className="num">{!v.qty ? <span className="badge danger">نفدت</span> : v.qty}</td>
              <td>{!v.qty ? <span className="soft small">غير متاح</span> : <><Qty value={q} onChange={(n) => setQty({ ...qty, [v.id]: n })} />{bad && <p className="f-error">المتاح <span className="num">{v.qty}</span> فقط</p>}</>}</td>
            </tr>);
        })}</tbody>
      </table></div>
    </Modal>
  );
};

/* ---------- 8–9. Quick order + Excel import ---------- */
export const QuickOrder = () => {
  const { go, toast, applyCartResponse } = useApp();
  const [tab, setTab] = useState('manual');
  const blank = () => ({ code: '', qty: '' });
  const [rows, setRows] = useState([blank()]);
  const [looked, setLooked] = useState(false);
  const [busy, setBusy] = useState(false);
  const [adding, setAdding] = useState(false);
  const [result, setResult] = useState(null);
  const [skipped, setSkipped] = useState(null);
  const set = (i, k, v) => { const r = [...rows]; r[i] = { ...r[i], [k]: v }; if (i === rows.length - 1 && v) r.push(blank()); setRows(r); setLooked(false); setResult(null); };

  const lookup = async () => {
    const items = rows.filter((r) => r.code && r.code.trim() && Number(r.qty) > 0).map((r) => {
      const code = r.code.trim();
      const isBarcode = /^[0-9]+$/.test(code) && code.length >= 8;
      return isBarcode ? { barcode: code, qty: Number(r.qty) } : { sku: code, qty: Number(r.qty) };
    });
    if (!items.length) { toast('أدخل كود وكمية لبند واحد على الأقل.'); return; }
    setBusy(true);
    try {
      const res = await wholesaleEndpoints.quickLookup(items);
      setResult(mapQuickLookup(res));
      setLooked(true);
    } catch (e) {
      toast(apiError(e).message);
    } finally {
      setBusy(false);
    }
  };

  const lines = result?.data || [];
  const checkedRows = rows.filter((r) => r.code?.trim() && Number(r.qty) > 0);
  const rowState = (row) => lines[checkedRows.indexOf(row)];
  const valid = lines.filter((l) => l.found && l.in_stock);

  const addValid = async () => {
    setAdding(true);
    setSkipped(null);
    try {
      const r = await wholesaleEndpoints.quickAddToCart(valid.map((l) => ({ sku: l.sku, qty: l.qty })));
      applyCartResponse(r.cart);
      if (r.skipped?.length) setSkipped(r.skipped);
      else { toast('تمت الإضافة إلى سلة المشتريات بنجاح'); go('cart'); }
    } catch (e) {
      toast(apiError(e).message);
    } finally {
      setAdding(false);
    }
  };

  return (
    <>
      <PageHead en="Quick Order" ar="أدخل الأكواد والكميات مباشرة، أو ارفع ملف Excel">
        <Seg value={tab} onChange={setTab} options={[{ value: 'manual', label: 'إدخال مباشر', icon: 'fa-keyboard' }, { value: 'import', label: 'استيراد ملف', icon: 'fa-file-arrow-up' }]} />
      </PageHead>
      {tab === 'manual' ? (
        <div className="grid-2" style={{ gridTemplateColumns: '1fr 320px' }}>
          <section className="panel">
            <div className="toolbar">
              <span className="muted small">الصق من Excel مباشرة: عمود SKU أو باركود، ثم الكمية. حتى <span className="num">500</span> بند.</span>
              <button className="btn-link muted" onClick={() => { setRows([blank()]); setLooked(false); setResult(null); }}>مسح الكل</button>
            </div>
            {skipped?.length > 0 && <div style={{ padding: '0 24px' }}><Banner kind="warn"><b>تم تخطي {skipped.length} بند:</b> {skipped.map((s) => `${s.sku} (${ { not_found_or_inactive: 'الصنف غير موجود أو غير متاح', insufficient_stock: 'الكمية غير متاحة' }[s.reason] || s.reason})`).join('، ')}</Banner></div>}
            <div className="tbl-wrap"><table className="tbl qo-tbl">
              <thead><tr><th style={{ width: 36 }}>#</th><th style={{ width: 210 }}>SKU أو باركود</th><th style={{ width: 96 }}>الكمية</th><th>الصنف</th><th>سعر الوحدة</th><th className="t-end">الإجمالي</th><th></th></tr></thead>
              <tbody>{rows.map((r, i) => {
                const x = looked ? rowState(r) : null;
                const st = !x ? null : !x.found ? { err: 'الصنف غير موجود' } : (x.available_qty || 0) === 0 ? { err: 'نفدت الكمية', v: x } : !x.in_stock ? { warn: `المتاح ${x.available_qty} فقط`, v: x } : { ok: true, v: x };
                return (
                  <tr key={i} className={st && (st.err ? 'is-error' : st.warn ? 'is-warn' : '')}>
                    <td className="soft num">{i + 1}</td>
                    <td><input className={`box-input ltr${st && st.err && !st.v ? ' is-invalid' : ''}`} value={r.code} placeholder="SPH-…" onChange={(e) => set(i, 'code', e.target.value)} /></td>
                    <td><input className="box-input ltr" value={r.qty} inputMode="numeric" onChange={(e) => set(i, 'qty', e.target.value.replace(/\D/g, ''))} /></td>
                    <td>{!x ? <span className="soft small">{r.code ? 'اضغط «فحص البنود»' : ''}</span> : st.v ? <><div className="strong small">{st.v.product_name} — {st.v.label || st.v.sku}</div>{st.err ? <p className="f-error" style={{ margin: 0 }}><i className="fa-solid fa-circle-exclamation" />{st.err}</p> : st.warn ? <p className="f-error" style={{ margin: 0, color: '#b45309' }}><i className="fa-solid fa-triangle-exclamation" />{st.warn}</p> : <span className="small" style={{ color: '#047857' }}><i className="fa-solid fa-check" /> متاح</span>}</> : <p className="f-error" style={{ margin: 0 }}><i className="fa-solid fa-circle-xmark" />{st.err}</p>}</td>
                    <td className="num">{x && x.unit_price != null ? fmt(x.unit_price) : '—'}</td>
                    <td className="t-end strong num">{x && x.line_total != null ? fmt(x.line_total) : '—'}</td>
                    <td>{r.code && <button className="btn-link muted" aria-label="حذف" onClick={() => { setRows(rows.filter((_, j) => j !== i)); setLooked(false); setResult(null); }}><i className="fa-solid fa-xmark" /></button>}</td>
                  </tr>);
              })}</tbody>
            </table></div>
          </section>
          <aside className="panel" style={{ position: 'sticky', top: 100 }}>
            <div className="panel-body">
              <h2 className="panel-title">ملخص الفحص</h2>
              <div className="mt-16">
                <div className="sum-row"><span>إجمالي البنود</span><strong className="num">{rows.filter((r) => r.code).length}</strong></div>
                <div className="sum-row"><span>بنود معروفة</span><strong className="num">{looked ? (result?.meta?.found ?? '—') : '—'}</strong></div>
                <div className="sum-row"><span>بنود صالحة للإضافة</span><strong className="num" style={{ color: 'var(--accent)' }}>{looked ? valid.length : '—'}</strong></div>
                <div className="sum-row"><span>بنود بها أخطاء</span><strong className="num" style={{ color: '#b91c1c' }}>{looked ? lines.filter((l) => !l.found || !l.in_stock).length : '—'}</strong></div>
                <div className="sum-total"><span>قيمة البنود الصالحة</span><span className="num">{looked ? fmt(valid.reduce((a, l) => a + (l.line_total || 0), 0)) : '—'}</span></div>
              </div>
              <button className="btn-block mt-24" onClick={lookup} disabled={busy}>{busy ? <span className="spin" /> : <i className="fa-solid fa-magnifying-glass" />} فحص البنود</button>
              <button className="btn-pill is-dark mt-8" style={{ width: '100%', justifyContent: 'center' }} disabled={!looked || !valid.length || adding} onClick={addValid}>{adding ? <span className="spin" /> : <i className="fa-solid fa-cart-plus" />} إضافة <span className="num">{valid.length}</span> بند صالح للسلة</button>
              <p className="note mt-16"><i className="fa-solid fa-circle-info" />الفحص للمعاينة فقط؛ الأسعار النهائية بتتأكد في السلة.</p>
            </div>
          </aside>
        </div>
      ) : <ImportTab />}
    </>
  );
};

const SKIP_REASON_LABEL = { not_found_or_inactive: 'الصنف غير موجود أو غير متاح', insufficient_stock: 'الكمية غير متاحة' };

const ImportTab = () => {
  const { go, applyCartResponse, toast } = useApp();
  const [stage, setStage] = useState('idle'); // idle | invalid | uploading | done
  const [file, setFile] = useState(null);
  const [report, setReport] = useState(null);
  const [err, setErr] = useState('');
  const inputRef = useRef(null);

  const pick = (f) => {
    setErr('');
    if (!f) return;
    if (f.size > 5 * 1024 * 1024) { setFile({ name: f.name }); setStage('invalid'); return; }
    setFile(f);
    setStage('ready');
  };

  const upload = async () => {
    if (!file || !(file instanceof File)) return;
    setStage('uploading');
    try {
      const r = await wholesaleEndpoints.quickImport(file);
      setReport(r);
      applyCartResponse(r.cart);
      setStage('done');
    } catch (e) {
      setErr(apiError(e).message);
      setStage('ready');
    }
  };

  return (
    <div className="grid-2" style={{ gridTemplateColumns: '1fr 340px' }}>
      <section className="panel"><div className="panel-body">
        {stage !== 'done' ? (
          <>
            <input ref={inputRef} type="file" accept=".xlsx,.xls,.csv" style={{ display: 'none' }} onChange={(e) => pick(e.target.files?.[0])} />
            <div className={`drop${stage === 'invalid' ? ' is-invalid' : ''}`} onClick={() => inputRef.current?.click()}>
              <i className="fa-solid fa-file-arrow-up" />
              <b>اسحب الملف هنا أو اضغط للاختيار</b>
              <small>XLSX · XLS · CSV — حتى <span className="num">5</span> ميجابايت</small>
            </div>
            {file && (
              <div className="file-row mt-16">
                <i className="fa-regular fa-file-excel" />
                <div className="grow"><b className="num">{file.name}</b></div>
                <button className="btn-link muted" onClick={() => { setFile(null); setStage('idle'); }}><i className="fa-solid fa-xmark" /></button>
              </div>
            )}
            {stage === 'invalid' && <p className="f-error"><i className="fa-solid fa-circle-exclamation" />حجم الملف أكبر من 5 ميجابايت. قسّم الملف وحاول تاني.</p>}
            {err && <p className="f-error"><i className="fa-solid fa-circle-exclamation" />{err}</p>}
            <Banner kind="warn"><b>الاستيراد بيضيف البنود للسلة مباشرة</b> — مش معاينة. البنود اللي فيها مشاكل هتتخطى وهيظهرلك سببها.</Banner>
            <div className="row mt-16" style={{ justifyContent: 'flex-end' }}>
              <button className="btn-pill is-dark" disabled={stage !== 'ready'} onClick={upload}>{stage === 'uploading' ? <><span className="spin" /> جارٍ الاستيراد…</> : <><i className="fa-solid fa-upload" /> استيراد وإضافة للسلة</>}</button>
            </div>
          </>
        ) : (
          <>
            <div className="row" style={{ gap: 16 }}>
              <div className="modal-icon"><i className="fa-solid fa-check" /></div>
              <div><h2 className="panel-title">تم الاستيراد</h2><p className="muted small" style={{ margin: '4px 0 0' }}><span className="num">{file?.name}</span></p></div>
            </div>
            <div className="imp-stats mt-24">
              <div><span className="num">{report?.added?.length ?? 0}</span><small>بند تمت إضافته</small></div>
              <div className="is-warn"><span className="num">{report?.skipped?.length ?? 0}</span><small>بند تم تخطيه</small></div>
            </div>
            {(report?.skipped?.length > 0) && (
              <>
                <h3 className="f-label mt-24">البنود المتخطاة وأسبابها</h3>
                <table className="tbl"><thead><tr><th>SKU</th><th>الكمية</th><th>السبب</th></tr></thead>
                  <tbody>{report.skipped.map((s, i) => <tr key={i}><td className="sku">{s.sku}</td><td className="num">{s.qty || '—'}</td><td style={{ color: '#b91c1c' }}>{SKIP_REASON_LABEL[s.reason] || s.reason}</td></tr>)}</tbody></table>
              </>
            )}
            <div className="row mt-24"><button className="btn-pill is-dark" onClick={() => go('cart')}><i className="fa-solid fa-bag-shopping" /> فتح السلة</button><button className="btn-pill" onClick={() => { setStage('idle'); setFile(null); setReport(null); }}>استيراد ملف آخر</button></div>
          </>
        )}
      </div></section>
      <aside className="panel"><div className="panel-body">
        <h2 className="panel-title">ترتيب الأعمدة</h2>
        <p className="muted small" style={{ lineHeight: 1.8 }}>العمود الأول SKU أو الباركود، والثاني الكمية. صف العناوين اختياري.</p>
        <table className="tbl imp-sample ltr"><thead><tr><th>A · sku</th><th>B · qty</th></tr></thead>
          <tbody><tr><td className="sku">SPH-TT-001-BLK</td><td className="num">24</td></tr></tbody></table>
      </div></aside>
    </div>
  );
};

/* ---------- 10. Cart ---------- */
const CartRow = ({ line, onCommit, onRemove, busy }) => {
  const [qty, setQty] = useState(line.qty);
  const timer = useRef(null);
  useEffect(() => { setQty(line.qty); }, [line.qty]);
  const change = (n) => {
    setQty(n);
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => { if (n !== line.qty) onCommit(line, n); }, 400);
  };
  useEffect(() => () => { if (timer.current) clearTimeout(timer.current); }, []);
  const shortage = !line.available || (line.max_qty > 0 && line.qty > line.max_qty);
  return (
    <div className={`cart-row${shortage ? ' is-bad' : ''}`}>
      <Thumb />
      <div className="cart-info">
        <div className="prod-name">{line.name}</div>
        <div className="cart-meta"><span>المتغير</span><b>{line.label}</b><span>SKU</span><b className="sku">{line.sku}</b><span>سعر الوحدة</span><b className="num">{fmt(line.unit)}{line.base != null && line.unit != null && line.unit < line.base && <span className="tier" style={{ marginInlineStart: 8 }}>شريحة كمية</span>}</b></div>
        {!line.priced && <p className="f-error" style={{ margin: '4px 0 0' }}>الصنف غير مسعّر — أزله من السلة للمتابعة.</p>}
        <div className="row mt-8"><Qty value={qty} min={1} onChange={change} />{shortage && <span className="f-error" style={{ margin: 0 }}>الكمية أكبر من المتاح. عدّل الكمية للمتابعة.</span>}</div>
      </div>
      <div className="cart-right">
        <span className="strong num">{fmt(line.line_total)}</span>
        <button className="btn-link muted" disabled={busy} onClick={() => onRemove(line)}>حذف</button>
      </div>
    </div>
  );
};

export const Cart = () => {
  const { cart, cartApi, go, toast, applyCartResponse } = useApp();
  const [coupon, setCoupon] = useState('');
  const [cErr, setCErr] = useState('');
  const [confirmClear, setConfirmClear] = useState(false);
  const [busy, setBusy] = useState(false);
  const lines = cart?.lines || [];
  const totals = cart?.totals || {};
  const discount = num(totals.pricing_discount ?? 0) + num(totals.coupon_discount ?? 0);

  const commit = async (line, qty) => {
    if (!qty || qty < 1) return;
    setBusy(true);
    try {
      const c = await cartEndpoints.update(line.id, qty, null);
      applyCartResponse(c);
    } catch (e) {
      toast(apiError(e).message);
    } finally {
      setBusy(false);
    }
  };
  const remove = async (line) => {
    setBusy(true);
    try {
      const c = await cartEndpoints.remove(line.id, null);
      applyCartResponse(c);
    } catch (e) {
      toast(apiError(e).message);
    } finally {
      setBusy(false);
    }
  };
  const clear = async () => {
    setBusy(true);
    try {
      const c = await cartEndpoints.clear(null);
      applyCartResponse(c);
      setConfirmClear(false);
    } catch (e) {
      toast(apiError(e).message);
    } finally {
      setBusy(false);
    }
  };
  const apply = async () => {
    if (!coupon.trim()) return;
    setCErr('');
    try {
      const r = await cartEndpoints.applyCoupon(coupon.trim(), null);
      applyCartResponse(r.cart);
    } catch (e) {
      setCErr(apiError(e).message);
    }
  };
  const unapply = async () => {
    try {
      const c = await cartEndpoints.removeCoupon(null);
      applyCartResponse(c);
      setCoupon('');
    } catch (e) {
      toast(apiError(e).message);
    }
  };

  const blocked = lines.some((l) => !l.priced || !l.available || (l.max_qty > 0 && l.qty > l.max_qty));

  if (cartApi.state !== 'ready') return <DataBoundary api={cartApi}><Async rows={4} cols={4} /></DataBoundary>;

  if (!lines.length) return (<><PageHead en="Your Bag" ar="سلة المشتريات" /><section className="panel"><Empty icon="fa-bag-shopping" title="السلة فاضية" desc="ضيف منتجات من الكتالوج أو استخدم الطلب السريع." action={<div className="row"><button className="btn-pill is-dark" onClick={() => go('catalog')}>تصفح الكتالوج</button><button className="btn-pill" onClick={() => go('quick')}>طلب سريع</button></div>} /></section></>);
  return (
    <>
      <PageHead en="Your Bag" ar={<>سلة المشتريات · <span className="num">{lines.length}</span> بند</>}>
        <button className="btn-pill is-danger" onClick={() => setConfirmClear(true)}><i className="fa-regular fa-trash-can" /> تفريغ السلة</button>
      </PageHead>
      <div className="grid-2" style={{ gridTemplateColumns: '1.35fr 1fr', gap: 48 }}>
        <section>
          {lines.map((l) => <CartRow key={l.id} line={l} onCommit={commit} onRemove={remove} busy={busy} />)}
        </section>
        <aside style={{ position: 'sticky', top: 100, alignSelf: 'start' }}>
          <h2 className="checkout-heading">ملخص الطلب</h2>
          <div className="promo">
            {totals.coupon_code ? (
              <span className="promo-chip">كوبون <b className="num">{totals.coupon_code}</b> مطبق<button onClick={unapply} aria-label="إزالة">✕</button></span>
            ) : (<>
              <div style={{ flex: 1 }}><input className={`line-input ltr${cErr ? ' is-invalid' : ''}`} placeholder="كود الخصم" value={coupon} onChange={(e) => { setCoupon(e.target.value); setCErr(''); }} />{cErr && <p className="f-error">{cErr}</p>}</div>
              <button className="btn-soft" onClick={apply}>تطبيق</button></>)}
          </div>
          <div className="sum-row"><span>قيمة المنتجات</span><strong className="num">{fmt(totals.subtotal)}</strong></div>
          <div className="sum-row is-neg"><span>الخصم</span><strong className="num">{discount ? '− ' + fmt(discount) : '—'}</strong></div>
          <div className="sum-row"><span>الشحن والضريبة</span><span className="soft small">تُحسب في الخطوة التالية</span></div>
          <div className="sum-total"><span>الإجمالي المبدئي</span><span className="num">{fmt(totals.total)}</span></div>
          <button className="btn-block mt-24" disabled={blocked || busy} onClick={() => go('checkout')}>متابعة لإتمام الطلب <i className="fa-solid fa-arrow-left" /></button>
          <button className="btn-link muted mt-16" onClick={() => go('catalog')}><i className="fa-solid fa-arrow-right" /> كمّل التسوق</button>
        </aside>
      </div>
      {confirmClear && <Modal title="تفريغ السلة؟" onClose={() => setConfirmClear(false)} icon={<div className="modal-icon is-warn"><i className="fa-solid fa-trash-can" /></div>}
        foot={<><button className="btn-pill" onClick={() => setConfirmClear(false)}>إلغاء</button><button className="btn-pill is-danger" disabled={busy} onClick={clear}>تفريغ</button></>}>
        <p className="muted">هيتم حذف كل البنود (<span className="num">{lines.length}</span>) من السلة. الإجراء ده مش ممكن التراجع عنه.</p></Modal>}
    </>
  );
};

/* ---------- 11. Checkout ---------- */
const FIELD_MAP = { recipient_name: 'name', recipient_phone: 'phone', recipient_email: 'email', city_id: 'city', shipping_address_line: 'address', shipping_notes: 'notes' };

export const Checkout = () => {
  const { cart, cartApi, go, toast, account, loadCart, refreshAccount, setLastOrder } = useApp();
  const initialApi = useApi(async () => {
    const [addresses, cities] = await Promise.all([accountEndpoints.addresses(), checkoutEndpoints.cities()]);
    return { addresses, cities };
  }, []);
  const addrs = initialApi.data?.addresses || [];
  const cities = initialApi.data?.cities || [];
  const [addrId, setAddrId] = useState(null);
  const [f, setF] = useState({ name: '', phone: '', email: '', city_id: '', address: '', notes: '' });
  const [pay, setPay] = useState('credit');
  const [ship, setShip] = useState('standard');
  const [preview, setPreview] = useState(null);
  const [options, setOptions] = useState([]);
  const [methods, setMethods] = useState([]);
  const [errs, setErrs] = useState({});
  const [creditErr, setCreditErr] = useState('');
  const [step, setStep] = useState('form'); // form | changed | submitting
  const [prevTotal, setPrevTotal] = useState(null);
  const [previewState, setPreviewState] = useState('loading');
  const [previewAttempt, setPreviewAttempt] = useState(0);
  const submitting = useRef(false);
  const idemKey = useRef(typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : String(Date.now()));

  useEffect(() => {
    if (initialApi.state !== 'ready') return;
      const d = addrs.find((x) => x.is_default) || addrs[0];
      if (d) {
        setAddrId(d.id);
        setF((s) => ({ ...s, name: s.name || account?.name || '', phone: s.phone || account?.phone || '', city_id: d.city_id, address: d.address_line }));
      } else {
        setF((s) => ({ ...s, name: s.name || account?.name || '', phone: s.phone || account?.phone || '' }));
      }
  }, [initialApi.data]);

  useEffect(() => {
    setPreview(null);
    if (!f.city_id) { setOptions([]); setMethods([]); return; }
    const ctrl = new AbortController();
    setPreviewState('loading');
    checkoutEndpoints.shippingOptions({ city_id: Number(f.city_id), payment_method: pay }, null, ctrl.signal)
      .then(mapShippingOptions)
      .then((r) => { if (!ctrl.signal.aborted) { setPreview(r.preview); setOptions(r.options || []); setMethods(r.payment_methods || []); setPreviewState('ready');
        if (r.payment_methods?.length && !r.payment_methods.includes(pay)) setPay(r.payment_methods[0]);
      } })
      .catch(() => { if (!ctrl.signal.aborted) setPreviewState('error'); });
    return () => ctrl.abort();
  }, [f.city_id, pay, previewAttempt]);

  const overCredit = pay === 'credit' && preview && account && preview.grand_total > Number(account.available_credit ?? 0);
  const walletShort = preview && account && Number(account.wallet ?? 0) < Number(preview.grand_total ?? 0);

  const pickAddr = (a) => {
    if (!a) { setAddrId(0); setF({ ...f, city_id: '', address: '' }); return; }
    setAddrId(a.id);
    setF({ ...f, city_id: a.city_id, address: a.address_line });
  };

  const mapFieldErrors = (fields) => {
    const e = {};
    Object.entries(fields || {}).forEach(([k, msgs]) => {
      const local = FIELD_MAP[k] || k;
      e[local] = Array.isArray(msgs) ? msgs[0] : String(msgs);
    });
    return e;
  };

  const submit = async () => {
    if (submitting.current) return;
    const e = {};
    if (!String(f.name).trim()) e.name = 'اسم المستلم مطلوب.';
    if (!String(f.phone).trim()) e.phone = 'رقم موبايل المستلم مطلوب.';
    if (!f.city_id) e.city = 'اختر المدينة.';
    if (!String(f.address).trim()) e.address = 'العنوان التفصيلي مطلوب.';
    setErrs(e);
    if (Object.keys(e).length) return;
    if (!preview || previewState !== 'ready' || !methods.includes(pay)) { toast('جارٍ حساب الشحن… حاول مرة أخرى.'); return; }
    submitting.current = true;
    setCreditErr('');
    setStep('submitting');
    const payload = {
      recipient_name: f.name, recipient_phone: f.phone, recipient_email: f.email || undefined,
      city_id: Number(f.city_id), shipping_address_line: f.address, shipping_notes: f.notes || undefined,
      payment_method: pay, expected_total: preview.grand_total,
    };
    try {
      const res = await checkoutEndpoints.place(payload, null, idemKey.current);
      setLastOrder(mapOrder(res.data));
      await loadCart();
      refreshAccount();
      go('success', res.data.id);
    } catch (err) {
      const info = apiError(err);
      if (info.code === 'price_changed') {
        setPrevTotal(preview.grand_total);
        try {
          const r = mapShippingOptions(await checkoutEndpoints.shippingOptions({ city_id: Number(f.city_id), payment_method: pay }, null));
          setPreview(r.preview); setOptions(r.options || []); setMethods(r.payment_methods || []);
        } catch (_) { setPreview(null); setPreviewState('error'); }
        setStep('changed');
      } else if (info.code === 'credit_limit_exceeded') {
        setCreditErr(info.message);
        setStep('form');
      } else {
        setErrs(mapFieldErrors(info.fields));
        if (!Object.keys(info.fields).length) toast(info.message);
        setStep('form');
      }
    } finally {
      submitting.current = false;
    }
  };

  const inp = (k, props = {}) => <input className={`line-input${errs[k] ? ' is-invalid' : ''}`} value={f[k]} onChange={(e) => { setF({ ...f, [k]: e.target.value }); setErrs({ ...errs, [k]: null }); }} {...props} />;
  const lines = cart?.lines || [];

  if (cartApi.state !== 'ready') return <DataBoundary api={cartApi}><Async rows={4} cols={4} /></DataBoundary>;
  if (initialApi.state !== 'ready') return <DataBoundary api={initialApi}><Async rows={4} cols={4} /></DataBoundary>;

  return (
    <>
      <PageHead en="Checkout" crumb={<Crumb items={[{ label: 'السلة', go: () => go('cart') }, { label: 'إتمام الطلب' }]} />}>
        <button className="btn-pill" onClick={() => go('cart')}><i className="fa-solid fa-arrow-right" /> العودة للسلة</button>
      </PageHead>
      <ol className="steps"><li className="step is-done"><span className="step-dot">✓</span>السلة</li><li className="step is-now"><span className="step-dot">2</span>الشحن والدفع</li><li className="step"><span className="step-dot">3</span>التأكيد</li></ol>
      {step === 'changed' && <div className="mb-24"><Banner kind="warn" icon="fa-arrows-rotate"><b>تغيّر الإجمالي قبل التأكيد.</b> الإجمالي الجديد <b className="num">{fmt(preview?.grand_total)} EGP</b>{prevTotal != null && <> بدل <span className="num">{fmt(prevTotal)} EGP</span></>} — راجعه واضغط تأكيد.</Banner></div>}
      {creditErr && <div className="mb-24"><Banner kind="error">{creditErr}</Banner></div>}
      <div className="grid-2" style={{ gridTemplateColumns: '1.22fr .95fr', gap: 64 }}>
        <div>
          <fieldset className="ck-block" disabled={step === 'submitting'}>
            <h2 className="checkout-heading">عنوان التسليم</h2>
            <div className="addr-picks">
              {addrs.map((a) => (
                <button key={a.id} type="button" className={`addr-pick${addrId === a.id ? ' is-on' : ''}`} onClick={() => pickAddr(a)}>
                  <b>{a.city_name_ar || ''}{a.is_default && <span className="badge accent" style={{ marginInlineStart: 8 }}>افتراضي</span>}</b>
                  <span>{a.address_line}</span>
                </button>
              ))}
              <button type="button" className={`addr-pick${addrId === 0 ? ' is-on' : ''}`} onClick={() => pickAddr(null)}><b>عنوان آخر</b><span>إدخال يدوي لهذا الطلب</span></button>
            </div>
            <div className="fields mt-24">
              <Field label="اسم المستلم" req error={errs.name}>{inp('name')}</Field>
              <Field label="تليفون المستلم" req error={errs.phone}>{inp('phone', { dir: 'ltr', inputMode: 'tel', style: { textAlign: 'right' } })}</Field>
              <Field label="بريد المستلم" opt error={errs.email}>{inp('email', { dir: 'ltr', placeholder: 'name@company.com', style: { textAlign: 'right' } })}</Field>
              <Field label="المدينة" req error={errs.city}>
                <select className={`line-input${errs.city ? ' is-invalid' : ''}`} value={f.city_id} onChange={(e) => { setF({ ...f, city_id: e.target.value ? Number(e.target.value) : '' }); setErrs({ ...errs, city: null }); }}>
                  <option value="">اختر المدينة</option>{cities.map((c) => <option key={c.id} value={c.id}>{c.name_ar}</option>)}
                </select></Field>
              <Field label="العنوان التفصيلي" req span error={errs.address}>{inp('address', { placeholder: 'الشارع، رقم المبنى، علامة مميزة' })}</Field>
              <Field label="ملاحظات الشحن" opt span><textarea className="line-input" rows="2" value={f.notes} placeholder="مواعيد الاستلام، رقم بديل…" onChange={(e) => setF({ ...f, notes: e.target.value })} /></Field>
            </div>
          </fieldset>
          <fieldset className="ck-block" disabled={step === 'submitting'}>
            <h2 className="checkout-heading">خيارات الشحن</h2>
            {previewState === 'error' && <Banner kind="error" action={<button className="btn-pill sm" onClick={() => setPreviewAttempt((n) => n + 1)}>إعادة المحاولة</button>}>تعذر تحميل تكلفة الشحن.</Banner>}
            <div className="radio-list">
              {(options.length ? options : [{ key: 'standard', label: 'شحن قياسي', cost: preview?.shipping }]).filter((o) => o.key === 'standard').map((o) => (
                <label key={o.key} className="radio-row"><span className="radio-left"><input type="radio" checked={ship === o.key} onChange={() => setShip(o.key)} /><span><span className="radio-title">{o.label || 'شحن قياسي'}</span><span className="radio-desc">{o.estimated_days || ''}</span></span></span><span className="radio-price num">{fmt(o.cost)}</span></label>))}
            </div>
          </fieldset>
          <fieldset className="ck-block" disabled={step === 'submitting'}>
            <h2 className="checkout-heading">طريقة الدفع</h2>
            <div className="radio-list">
              {methods.map((m) => {
                if (m === 'credit') return (
                  <label key={m} className="radio-row"><span className="radio-left"><input type="radio" checked={pay === 'credit'} onChange={() => setPay('credit')} /><span><span className="radio-title">آجل على الحساب</span><span className="radio-desc">المتاح حاليًا <span className="num">{fmt(account?.available_credit ?? 0)}</span> EGP · متاح لحسابك حسب سياسة الائتمان</span></span></span><span className="badge accent">مسموح لحسابك</span></label>);
                if (m === 'cod') return (
                  <label key={m} className="radio-row"><span className="radio-left"><input type="radio" checked={pay === 'cod'} onChange={() => setPay('cod')} /><span><span className="radio-title">الدفع عند الاستلام</span><span className="radio-desc">رسوم تحصيل <span className="num">{fmt(preview?.cod_fee ?? 0)}</span> EGP</span></span></span><span className="radio-price num">+{fmt(preview?.cod_fee ?? 0)}</span></label>);
                if (m === 'bank_transfer') return (
                  <label key={m} className="radio-row"><span className="radio-left"><input type="radio" checked={pay === 'bank_transfer'} onChange={() => setPay('bank_transfer')} /><span><span className="radio-title">تحويل بنكي مقدم</span><span className="radio-desc">سجّل إشعار السداد بعد التحويل</span></span></span></label>);
                if (m === 'wallet') return (
                  <label key={m} className={`radio-row${walletShort ? ' is-disabled' : ''}`}><span className="radio-left"><input type="radio" disabled={walletShort} checked={pay === 'wallet'} onChange={() => setPay('wallet')} /><span><span className="radio-title">المحفظة</span><span className="radio-desc">{walletShort ? <>الرصيد <span className="num">{fmt(account?.wallet ?? 0)}</span> غير كافٍ لهذا الطلب</> : <>الرصيد <span className="num">{fmt(account?.wallet ?? 0)}</span> EGP</>}</span></span></span></label>);
                return null;
              })}
            </div>
            {overCredit && <div className="mt-16"><Banner kind="error">الإجمالي أكبر من المتاح للشراء الآجل. قلل الكمية أو اختر طريقة دفع أخرى.</Banner></div>}
          </fieldset>
        </div>
        <aside style={{ position: 'sticky', top: 100, alignSelf: 'start' }}>
          <h2 className="checkout-heading">ملخص الطلب</h2>
          <div className="ck-items">
            {lines.map((l) => (
              <div key={l.id} className="ck-item"><Thumb sm /><div style={{ flex: 1, minWidth: 0 }}><div className="strong small">{l.name}</div><div className="soft small">{l.label} · <span className="num">×{l.qty}</span></div></div><span className="num strong small">{fmt(l.line_total)}</span></div>))}
          </div>
          <div className="sum-row"><span>قيمة المنتجات</span><strong className="num">{fmt(preview?.items_total)}</strong></div>
          <div className="sum-row is-neg"><span>الخصم</span><strong className="num">{preview?.coupon_discount ? '− ' + fmt(preview.coupon_discount) : '—'}</strong></div>
          <div className="sum-row"><span>الشحن</span><strong className="num">{fmt(preview?.shipping)}</strong></div>
          <div className="sum-row"><span>الضريبة</span><strong className="num">{fmt(preview?.tax ?? 0)}</strong></div>
          {Number(preview?.cod_fee ?? 0) > 0 && <div className="sum-row"><span>رسوم التحصيل</span><strong className="num">{fmt(preview.cod_fee)}</strong></div>}
          <div className="sum-total"><span>الإجمالي النهائي</span><span className="num">{fmt(preview?.grand_total)}</span></div>
          <button className="btn-block mt-24" disabled={overCredit || (pay === 'wallet' && walletShort) || !lines.length || step === 'submitting' || !preview || previewState !== 'ready' || !methods.includes(pay)} onClick={submit}>{step === 'submitting' ? <><span className="spin" /> جاري إتمام الطلب…</> : step === 'changed' ? 'تأكيد الإجمالي الجديد وإتمام الطلب' : 'إتمام الطلب'}</button>
          <p className="note mt-16"><i className="fa-solid fa-lock" />الأسعار والإجمالي النهائي بيتحسبوا من الخادم لحظة التأكيد.</p>
        </aside>
      </div>
    </>
  );
};

/* ---------- 12. Success ---------- */
export const OrderSuccess = () => {
  const { lastOrder, go, route } = useApp();
  const api = useApi((signal) => lastOrder
    ? Promise.resolve(lastOrder)
    : wholesaleEndpoints.order(route.id, signal).then(mapOrder), [lastOrder?.id, route.id]);
  const o = lastOrder || api.data;
  if (!o) {
    return (
      <DataBoundary api={api}>
        <Async rows={4} cols={4} empty={<Empty icon="fa-box-open" title="لا يوجد طلب لعرضه" />} />
      </DataBoundary>
    );
  }
  return (
    <div className="success-wrap"><div className="order-card">
      <div className="modal-icon" style={{ width: 64, height: 64, fontSize: 26 }}><i className="fa-solid fa-check" /></div>
      <h1 className="success-title">ORDER PLACED</h1>
      <p className="muted" style={{ margin: '0 0 6px' }}>تم استلام طلبك بنجاح</p>
      <p className="success-num num">{o.number}</p>
      <div className="row" style={{ justifyContent: 'center', gap: 8, marginBottom: 24 }}><Badge map={ORDER_STATUS} k={o.status} /><Badge map={PAY_STATUS} k={o.pay} /></div>
      <div style={{ textAlign: 'start' }}>
        {(o.items || []).map((it) => <div key={it.sku || it.id} className="ck-item"><Thumb sm /><div style={{ flex: 1 }}><div className="strong small">{it.name}</div><div className="sku">{it.sku}</div></div><span className="num small">×{it.qty}</span></div>)}
        <div className="sum-row"><span>طريقة الدفع</span><strong>{PAY_METHOD[o.method]}</strong></div>
        <div className="sum-row"><span>عنوان التسليم</span><strong style={{ textAlign: 'left' }}>{o.city} — {o.address}</strong></div>
        <div className="sum-total"><span>الإجمالي</span><span className="num">{fmt(o.total)}</span></div>
      </div>
      <div className="row mt-24" style={{ justifyContent: 'center' }}>
        <button className="btn-pill is-dark" onClick={() => go('order', o.id)}>فتح الطلب</button>
        <button className="btn-pill" onClick={() => go('catalog')}>الرجوع للكتالوج</button>
      </div>
    </div></div>
  );
};
