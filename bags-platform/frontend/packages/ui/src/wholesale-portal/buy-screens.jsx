
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

/* Screens 6–12: dashboard, catalog, quick order + import, cart, checkout, success. */



/* ---------- 6. Dashboard ---------- */
export const Dashboard = () => {
  const { go } = useApp();
  const pct = Math.round(ACCOUNT.used_credit / ACCOUNT.credit_limit * 100);
  const shortcuts = [
    ['catalog', 'fa-table-cells-large', 'الكتالوج وقائمة الأسعار', 'CATALOG'],
    ['quick', 'fa-bolt', 'طلب سريع بالـ SKU', 'QUICK ORDER'],
    ['quoteNew', 'fa-file-signature', 'طلب عرض سعر جديد', 'NEW QUOTE'],
    ['noticeNew', 'fa-money-check-dollar', 'تسجيل إشعار سداد', 'PAYMENT NOTICE'],
  ];
  return (
    <>
      <PageHead en="Dashboard" ar={<>أهلًا {ACCOUNT.name.split(' ')[0]} — {ACCOUNT.company}</>} />
      <Async rows={3} cols={4} empty={<Empty title="لا توجد بيانات بعد" />}>
        <div className="kpis">
          <div className="kpi is-ink">
            <span className="kpi-en">CREDIT LIMIT</span><span className="kpi-label">حد الائتمان</span>
            <span className="kpi-value">{fmt(ACCOUNT.credit_limit, 0)}<small>EGP</small></span>
          </div>
          <div className="kpi">
            <span className="kpi-en">USED CREDIT</span><span className="kpi-label">المستخدم من الائتمان</span>
            <span className="kpi-value">{fmt(ACCOUNT.used_credit, 0)}<small>EGP</small></span>
            <div className="kpi-bar"><span style={{ width: pct + '%' }} /></div>
            <span className="kpi-foot"><span className="num">{pct}%</span> من الحد</span>
          </div>
          <div className="kpi">
            <span className="kpi-en">AVAILABLE</span><span className="kpi-label">المتاح للشراء الآجل</span>
            <span className="kpi-value" style={{ color: 'var(--accent)' }}>{fmt(ACCOUNT.available_credit, 0)}<small>EGP</small></span>
          </div>
          <div className="kpi" style={{ cursor: 'pointer' }} onClick={() => go('orders')}>
            <span className="kpi-en">IN PROGRESS</span><span className="kpi-label">طلبات جاري تنفيذها</span>
            <span className="kpi-value">{ACCOUNT.in_progress_orders_count}</span>
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
              <tbody>{ORDERS.slice(0, 5).map((o) => (
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
              <tbody>{QUOTES.slice(0, 5).map((q) => (
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
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState(null);
  const [exporting, setExporting] = useState(null);
  const list = PRODUCTS.filter((p) => !q || [p.name_ar, p.name_en, ...p.variants.map((v) => v.sku)].join(' ').toLowerCase().includes(q.toLowerCase()));
  const doExport = (f) => { setExporting(f); setTimeout(() => { setExporting(null); toast(`تم تنزيل قائمة الأسعار ${f.toUpperCase()}`); }, 1100); };
  return (
    <>
      <PageHead en="Price List" ar="كتالوج الجملة بأسعار حسابك وشرائح الكميات">
        <button className="btn-pill" onClick={() => doExport('pdf')} disabled={!!exporting}>{exporting === 'pdf' ? <span className="spin" /> : <i className="fa-regular fa-file-pdf" />} تنزيل PDF</button>
        <button className="btn-pill" onClick={() => doExport('xlsx')} disabled={!!exporting}>{exporting === 'xlsx' ? <span className="spin" /> : <i className="fa-regular fa-file-excel" />} تنزيل Excel</button>
      </PageHead>
      <section className="panel">
        <div className="toolbar">
          <div className="search"><i className="fa-solid fa-magnifying-glass" /><input className="line-input" placeholder="ابحث بالاسم أو SKU…" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} /></div>
          <span className="soft small"><span className="num">{list.length * 12}</span> منتج · الأسعار شاملة خصم حسابك</span>
        </div>
        <Async rows={6} cols={6} isEmpty={list.length === 0} empty={<Empty icon="fa-magnifying-glass" title="مفيش نتائج" desc={q ? `مفيش منتج أو SKU يطابق «${q}».` : 'لا توجد منتجات متاحة حاليًا.'} action={q && <button className="btn-pill" onClick={() => setQ('')}>مسح البحث</button>} />}>
          <div className="tbl-wrap"><table className="tbl cat-tbl">
            <thead><tr><th>المنتج</th><th>المتغيرات</th><th>السعر الأساسي</th><th>سعرك</th><th>شرائح الكميات</th><th>المتاح</th><th></th></tr></thead>
            <tbody>{list.map((p) => {
              const v = p.variants[0];
              const stock = p.variants.reduce((a, x) => a + x.qty, 0);
              return (
                <tr key={p.id}>
                  <td><div className="prod-cell"><Thumb sm /><div><div className="prod-name">{p.name_ar}</div><div className="prod-en">{p.name_en}</div></div></div></td>
                  <td><span className="num strong">{p.variants.length}</span> <span className="soft small">{p.variants.map((x) => x.label).join(' · ')}</span></td>
                  <td><span className="strike num">{fmt(p.base)}</span></td>
                  <td className="strong num">{p.variants.length > 1 && Math.min(...p.variants.map((x) => x.price)) !== Math.max(...p.variants.map((x) => x.price)) ? 'من ' : ''}{fmt(Math.min(...p.variants.map((x) => x.price)))}</td>
                  <td>{v.tiers.map(([m, pr]) => <span key={m} className="tier"><span className="num">{m}+</span> ← <b className="num">{fmt(pr, 0)}</b></span>)}</td>
                  <td>{stock === 0 ? <span className="badge danger">نفدت الكمية</span> : <span className={`num ${stock < 50 ? '' : 'strong'}`} style={stock < 50 ? { color: '#b45309', fontWeight: 600 } : null}>{stock}</span>}</td>
                  <td className="t-end"><button className="btn-pill is-dark sm" onClick={() => setOpen(p)}>اختيار الكمية</button></td>
                </tr>);
            })}</tbody>
          </table></div>
          <Pager page={page} pages={4} total={96} per={24} onPage={setPage} />
        </Async>
      </section>
      <p className="note mt-16"><i className="fa-solid fa-circle-info" />السعر النهائي لكل بند يتحسب في السلة حسب الكمية والشريحة المطبقة وقت الطلب.</p>
      {open && <VariantModal product={open} onClose={() => setOpen(null)} onAdd={(lines) => { addToCart(lines); setOpen(null); }} />}
    </>
  );
};

export const tierPrice = (v, qty) => { let p = v.price; v.tiers.forEach(([m, pr]) => { if (qty >= m) p = pr; }); return p; };

export const VariantModal = ({ product, onClose, onAdd }) => {
  const [qty, setQty] = useState(Object.fromEntries(product.variants.map((v) => [v.id, 0])));
  const lines = product.variants.filter((v) => qty[v.id] > 0).map((v) => ({ v: { ...v, product }, qty: qty[v.id] }));
  const total = lines.reduce((a, l) => a + tierPrice(l.v, l.qty) * l.qty, 0);
  const over = product.variants.some((v) => qty[v.id] > v.qty);
  return (
    <Modal wide title={product.name_ar} onClose={onClose} icon={<Thumb sm />}
      foot={<><span className="muted small" style={{ marginInlineEnd: 'auto' }}>تقديري: <Money v={total} strong /></span>
        <button className="btn-pill" onClick={onClose}>إلغاء</button>
        <button className="btn-pill is-dark" disabled={!lines.length || over} onClick={() => onAdd(lines)}><i className="fa-solid fa-cart-plus" /> إضافة للسلة {lines.length > 0 && <span className="num">({lines.reduce((a, l) => a + l.qty, 0)})</span>}</button></>}>
      <p className="prod-en" style={{ textAlign: 'right', marginTop: -10 }}>{product.name_en} · السعر الأساسي <span className="strike">{fmt(product.base)}</span></p>
      <div className="tbl-wrap mt-16"><table className="tbl">
        <thead><tr><th>المتغير</th><th>SKU / باركود</th><th>سعرك</th><th>الشرائح</th><th>المتاح</th><th>الكمية</th></tr></thead>
        <tbody>{product.variants.map((v) => {
          const q = qty[v.id]; const bad = q > v.qty;
          return (
            <tr key={v.id} className={bad ? 'is-error' : ''}>
              <td className="strong">{v.label}</td>
              <td><div className="sku">{v.sku}</div><div className="sku soft">{v.barcode}</div></td>
              <td className="strong num">{fmt(q ? tierPrice(v, q) : v.price)}</td>
              <td>{v.tiers.map(([m, pr]) => <span key={m} className="tier" style={q >= m ? { background: 'var(--accent)', color: '#fff', borderColor: 'var(--accent)' } : null}><span className="num">{m}+</span> <b className="num">{fmt(pr, 0)}</b></span>)}</td>
              <td className="num">{v.qty === 0 ? <span className="badge danger">نفدت</span> : v.qty}</td>
              <td>{v.qty === 0 ? <span className="soft small">غير متاح</span> : <><Qty value={q} onChange={(n) => setQty({ ...qty, [v.id]: n })} />{bad && <p className="f-error">المتاح <span className="num">{v.qty}</span> فقط</p>}</>}</td>
            </tr>);
        })}</tbody>
      </table></div>
    </Modal>
  );
};

/* ---------- 8–9. Quick order + Excel import ---------- */
export const QuickOrder = () => {
  const { addToCart, go, toast } = useApp();
  const [tab, setTab] = useState('manual');
  const blank = () => ({ code: '', qty: '' });
  const [rows, setRows] = useState([{ code: 'SPH-TT-001-BLK', qty: '24' }, { code: '6224001014011', qty: '40' }, { code: 'SPH-BP-007-GRY-M', qty: '30' }, { code: 'SPH-XX-999', qty: '10' }, { code: 'SPH-TT-001-CRM', qty: '12' }, blank()]);
  const [looked, setLooked] = useState(false);
  const [busy, setBusy] = useState(false);
  const set = (i, k, v) => { const r = [...rows]; r[i] = { ...r[i], [k]: v }; if (i === rows.length - 1 && v) r.push(blank()); setRows(r); setLooked(false); };
  const res = rows.map((r) => {
    if (!r.code.trim()) return null;
    const v = findVariant(r.code); const q = parseInt(r.qty || '0', 10);
    if (!v) return { err: 'الصنف غير موجود' };
    if (!q) return { v, err: 'أدخل الكمية' };
    if (v.qty === 0) return { v, q, err: 'نفدت الكمية' };
    if (q > v.qty) return { v, q, warn: `المتاح ${v.qty} فقط` };
    const unit = tierPrice(v, q);
    return { v, q, unit, total: unit * q, ok: true };
  });
  const valid = res.filter((x) => x && x.ok);
  const known = res.filter((x) => x && x.v && x.unit);
  const lookup = () => { setBusy(true); setTimeout(() => { setBusy(false); setLooked(true); }, 700); };
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
              <button className="btn-link muted" onClick={() => { setRows([blank()]); setLooked(false); }}>مسح الكل</button>
            </div>
            <div className="tbl-wrap"><table className="tbl qo-tbl">
              <thead><tr><th style={{ width: 36 }}>#</th><th style={{ width: 210 }}>SKU أو باركود</th><th style={{ width: 96 }}>الكمية</th><th>الصنف</th><th>سعر الوحدة</th><th className="t-end">الإجمالي</th><th></th></tr></thead>
              <tbody>{rows.map((r, i) => {
                const x = looked ? res[i] : null;
                return (
                  <tr key={i} className={x && x.err ? 'is-error' : x && x.warn ? 'is-warn' : ''}>
                    <td className="soft num">{i + 1}</td>
                    <td><input className={`box-input ltr${x && x.err && !x.v ? ' is-invalid' : ''}`} value={r.code} placeholder="SPH-…" onChange={(e) => set(i, 'code', e.target.value)} /></td>
                    <td><input className={`box-input ltr${x && x.err === 'أدخل الكمية' ? ' is-invalid' : ''}`} value={r.qty} inputMode="numeric" onChange={(e) => set(i, 'qty', e.target.value.replace(/\D/g, ''))} /></td>
                    <td>{!x ? <span className="soft small">{r.code ? 'اضغط «فحص البنود»' : ''}</span> : x.v ? <><div className="strong small">{x.v.product.name_ar} — {x.v.label}</div>{x.err ? <p className="f-error" style={{ margin: 0 }}><i className="fa-solid fa-circle-exclamation" />{x.err}</p> : x.warn ? <p className="f-error" style={{ margin: 0, color: '#b45309' }}><i className="fa-solid fa-triangle-exclamation" />{x.warn}</p> : <span className="small" style={{ color: '#047857' }}><i className="fa-solid fa-check" /> متاح</span>}</> : <p className="f-error" style={{ margin: 0 }}><i className="fa-solid fa-circle-xmark" />{x.err}</p>}</td>
                    <td className="num">{x && x.unit ? fmt(x.unit) : '—'}</td>
                    <td className="t-end strong num">{x && x.total ? fmt(x.total) : '—'}</td>
                    <td>{r.code && <button className="btn-link muted" aria-label="حذف" onClick={() => setRows(rows.filter((_, j) => j !== i))}><i className="fa-solid fa-xmark" /></button>}</td>
                  </tr>);
              })}</tbody>
            </table></div>
          </section>
          <aside className="panel" style={{ position: 'sticky', top: 100 }}>
            <div className="panel-body">
              <h2 className="panel-title">ملخص الفحص</h2>
              <div className="mt-16">
                <div className="sum-row"><span>إجمالي البنود</span><strong className="num">{rows.filter((r) => r.code).length}</strong></div>
                <div className="sum-row"><span>بنود معروفة</span><strong className="num">{looked ? known.length : '—'}</strong></div>
                <div className="sum-row"><span>بنود صالحة للإضافة</span><strong className="num" style={{ color: 'var(--accent)' }}>{looked ? valid.length : '—'}</strong></div>
                <div className="sum-row"><span>بنود بها أخطاء</span><strong className="num" style={{ color: '#b91c1c' }}>{looked ? res.filter((x) => x && !x.ok).length : '—'}</strong></div>
                <div className="sum-total"><span>قيمة البنود الصالحة</span><span className="num">{looked ? fmt(valid.reduce((a, x) => a + x.total, 0)) : '—'}</span></div>
              </div>
              <button className="btn-block mt-24" onClick={lookup} disabled={busy}>{busy ? <span className="spin" /> : <i className="fa-solid fa-magnifying-glass" />} فحص البنود</button>
              <button className="btn-pill is-dark mt-8" style={{ width: '100%', justifyContent: 'center' }} disabled={!looked || !valid.length} onClick={() => addToCart(valid.map((x) => ({ v: x.v, qty: x.q })))}><i className="fa-solid fa-cart-plus" /> إضافة <span className="num">{valid.length}</span> بند صالح للسلة</button>
              <p className="note mt-16"><i className="fa-solid fa-circle-info" />الفحص للمعاينة فقط؛ الأسعار النهائية بتتأكد في السلة.</p>
            </div>
          </aside>
        </div>
      ) : <ImportTab />}
    </>
  );
};

const ImportTab = () => {
  const { go } = useApp();
  const [stage, setStage] = useState('idle'); // idle | invalid | uploading | done
  const [file, setFile] = useState(null);
  const pick = (bad) => { if (bad) { setFile({ name: 'big-order-oct.xlsx', size: '7.4 MB' }); setStage('invalid'); return; } setFile({ name: 'order-nov-week1.xlsx', size: '38 KB' }); setStage('ready'); };
  const upload = () => { setStage('uploading'); setTimeout(() => setStage('done'), 1300); };
  const skipped = [['SPH-XX-999', 10, 'الصنف غير موجود'], ['SPH-TT-001-CRM', 12, 'نفدت الكمية'], ['SPH-BP-007-GRY-M', 30, 'الكمية أكبر من المتاح (18)'], ['—', 0, 'صف 14: الكمية غير صالحة']];
  return (
    <div className="grid-2" style={{ gridTemplateColumns: '1fr 340px' }}>
      <section className="panel"><div className="panel-body">
        {stage !== 'done' ? (
          <>
            <div className={`drop${stage === 'invalid' ? ' is-invalid' : ''}`} onClick={() => pick(false)}>
              <i className="fa-solid fa-file-arrow-up" />
              <b>اسحب الملف هنا أو اضغط للاختيار</b>
              <small>XLSX · XLS · CSV · TXT — حتى <span className="num">5</span> ميجابايت</small>
            </div>
            {file && (
              <div className="file-row mt-16">
                <i className="fa-regular fa-file-excel" />
                <div className="grow"><b className="num">{file.name}</b> <span className="soft num">· {file.size}</span></div>
                <button className="btn-link muted" onClick={() => { setFile(null); setStage('idle'); }}><i className="fa-solid fa-xmark" /></button>
              </div>
            )}
            {stage === 'invalid' && <p className="f-error"><i className="fa-solid fa-circle-exclamation" />حجم الملف أكبر من 5 ميجابايت. قسّم الملف وحاول تاني.</p>}
            <Banner kind="warn" ><b>الاستيراد بيضيف البنود للسلة مباشرة</b> — مش معاينة. البنود اللي فيها مشاكل هتتخطى وهيظهرلك سببها.</Banner>
            <div className="row mt-16" style={{ justifyContent: 'space-between' }}>
              <button className="btn-link muted" onClick={() => pick(true)}>جرب ملف كبير (حالة خطأ)</button>
              <button className="btn-pill is-dark" disabled={stage !== 'ready'} onClick={upload}>{stage === 'uploading' ? <><span className="spin" /> جارٍ الاستيراد…</> : <><i className="fa-solid fa-upload" /> استيراد وإضافة للسلة</>}</button>
            </div>
          </>
        ) : (
          <>
            <div className="row" style={{ gap: 16 }}>
              <div className="modal-icon"><i className="fa-solid fa-check" /></div>
              <div><h2 className="panel-title">تم الاستيراد</h2><p className="muted small" style={{ margin: '4px 0 0' }}><span className="num">{file.name}</span></p></div>
            </div>
            <div className="imp-stats mt-24">
              <div><span className="num">38</span><small>بند تمت إضافته</small></div>
              <div className="is-warn"><span className="num">4</span><small>بند تم تخطيه</small></div>
            </div>
            <h3 className="f-label mt-24">البنود المتخطاة وأسبابها</h3>
            <table className="tbl"><thead><tr><th>SKU</th><th>الكمية</th><th>السبب</th></tr></thead>
              <tbody>{skipped.map(([s, q, r], i) => <tr key={i}><td className="sku">{s}</td><td className="num">{q || '—'}</td><td style={{ color: '#b91c1c' }}>{r}</td></tr>)}</tbody></table>
            <div className="row mt-24"><button className="btn-pill is-dark" onClick={() => go('cart')}><i className="fa-solid fa-bag-shopping" /> فتح السلة</button><button className="btn-pill" onClick={() => { setStage('idle'); setFile(null); }}>استيراد ملف آخر</button></div>
          </>
        )}
      </div></section>
      <aside className="panel"><div className="panel-body">
        <h2 className="panel-title">ترتيب الأعمدة</h2>
        <p className="muted small" style={{ lineHeight: 1.8 }}>العمود الأول SKU أو الباركود، والثاني الكمية. صف العناوين اختياري.</p>
        <table className="tbl imp-sample ltr"><thead><tr><th>A · sku</th><th>B · qty</th></tr></thead>
          <tbody><tr><td className="sku">SPH-TT-001-BLK</td><td className="num">24</td></tr><tr><td className="sku">6224001014011</td><td className="num">40</td></tr><tr><td className="sku">SPH-WL-040-BRG</td><td className="num">120</td></tr></tbody></table>
      </div></aside>
    </div>
  );
};

/* ---------- 10. Cart ---------- */
export const Cart = () => {
  const { cart, setCart, go } = useApp();
  const [coupon, setCoupon] = useState('');
  const [applied, setApplied] = useState(null);
  const [cErr, setCErr] = useState('');
  const [confirmClear, setConfirmClear] = useState(false);
  const lines = cart.map((l) => ({ ...l, unit: tierPrice(l.v, l.qty), total: tierPrice(l.v, l.qty) * l.qty }));
  const sub = lines.reduce((a, l) => a + l.total, 0);
  const disc = applied ? Math.round(sub * 0.03) : 0;
  const upd = (i, q) => setCart(cart.map((l, j) => j === i ? { ...l, qty: q } : l).filter((l) => l.qty > 0));
  const apply = () => { if (coupon.trim().toUpperCase() === 'WS3') { setApplied('WS3'); setCErr(''); } else setCErr('الكوبون غير صالح أو غير مقبول لطلبات الجملة.'); };
  const changed = lines.find((l) => l.v.sku === 'SPH-BP-007-NVY-L');
  const short = lines.find((l) => l.qty > l.v.qty);
  if (!cart.length) return (<><PageHead en="Your Bag" ar="سلة المشتريات" /><section className="panel"><Empty icon="fa-bag-shopping" title="السلة فاضية" desc="ضيف منتجات من الكتالوج أو استخدم الطلب السريع." action={<div className="row"><button className="btn-pill is-dark" onClick={() => go('catalog')}>تصفح الكتالوج</button><button className="btn-pill" onClick={() => go('quick')}>طلب سريع</button></div>} /></section></>);
  return (
    <>
      <PageHead en="Your Bag" ar={<>سلة المشتريات · <span className="num">{lines.length}</span> بند</>}>
        <button className="btn-pill is-danger" onClick={() => setConfirmClear(true)}><i className="fa-regular fa-trash-can" /> تفريغ السلة</button>
      </PageHead>
      <div className="stack" style={{ gap: 12, marginBottom: 24 }}>
        {changed && <Banner kind="info" icon="fa-tag">تغير سعر <b>{changed.v.product.name_ar}</b> من <span className="num strike">{fmt(910)}</span> إلى <b className="num">{fmt(changed.unit)}</b> منذ إضافته للسلة.</Banner>}
        {short && <Banner kind="error">الكمية المطلوبة من <b>{short.v.product.name_ar} — {short.v.label}</b> أكبر من المخزون المتاح (<span className="num">{short.v.qty}</span>). عدّل الكمية للمتابعة.</Banner>}
      </div>
      <div className="grid-2" style={{ gridTemplateColumns: '1.35fr 1fr', gap: 48 }}>
        <section>
          {lines.map((l, i) => (
            <div key={l.v.id} className={`cart-row${l.qty > l.v.qty ? ' is-bad' : ''}`}>
              <Thumb />
              <div className="cart-info">
                <div className="prod-name">{l.v.product.name_ar}</div>
                <div className="cart-meta"><span>المتغير</span><b>{l.v.label}</b><span>SKU</span><b className="sku">{l.v.sku}</b><span>سعر الوحدة</span><b className="num">{fmt(l.unit)}{l.unit < l.v.price && <span className="tier" style={{ marginInlineStart: 8 }}>شريحة كمية</span>}</b></div>
                <div className="row mt-8"><Qty value={l.qty} onChange={(q) => upd(i, q)} />{l.qty > l.v.qty && <span className="f-error" style={{ margin: 0 }}>المتاح <span className="num">{l.v.qty}</span></span>}</div>
              </div>
              <div className="cart-right">
                <span className="strong num">{fmt(l.total)}</span>
                <button className="btn-link muted" onClick={() => upd(i, 0)}>حذف</button>
              </div>
            </div>
          ))}
        </section>
        <aside style={{ position: 'sticky', top: 100, alignSelf: 'start' }}>
          <h2 className="checkout-heading">ملخص الطلب</h2>
          <div className="promo">
            {applied ? (
              <span className="promo-chip">كوبون <b className="num">{applied}</b> مطبق<button onClick={() => setApplied(null)} aria-label="إزالة">✕</button></span>
            ) : (<>
              <div style={{ flex: 1 }}><input className={`line-input ltr${cErr ? ' is-invalid' : ''}`} placeholder="كود الخصم" value={coupon} onChange={(e) => { setCoupon(e.target.value); setCErr(''); }} />{cErr && <p className="f-error">{cErr}</p>}</div>
              <button className="btn-soft" onClick={apply}>تطبيق</button></>)}
          </div>
          <div className="sum-row"><span>قيمة المنتجات</span><strong className="num">{fmt(sub)}</strong></div>
          <div className="sum-row is-neg"><span>الخصم</span><strong className="num">{disc ? '− ' + fmt(disc) : '—'}</strong></div>
          <div className="sum-row"><span>الشحن والضريبة</span><span className="soft small">تُحسب في الخطوة التالية</span></div>
          <div className="sum-total"><span>الإجمالي المبدئي</span><span className="num">{fmt(sub - disc)}</span></div>
          <button className="btn-block mt-24" disabled={!!short} onClick={() => go('checkout')}>متابعة لإتمام الطلب <i className="fa-solid fa-arrow-left" /></button>
          <button className="btn-link muted mt-16" onClick={() => go('catalog')}><i className="fa-solid fa-arrow-right" /> كمّل التسوق</button>
        </aside>
      </div>
      {confirmClear && <Modal title="تفريغ السلة؟" onClose={() => setConfirmClear(false)} icon={<div className="modal-icon is-warn"><i className="fa-solid fa-trash-can" /></div>}
        foot={<><button className="btn-pill" onClick={() => setConfirmClear(false)}>إلغاء</button><button className="btn-pill is-danger" onClick={() => { setCart([]); setConfirmClear(false); }}>تفريغ</button></>}>
        <p className="muted">هيتم حذف كل البنود (<span className="num">{lines.length}</span>) من السلة. الإجراء ده مش ممكن التراجع عنه.</p></Modal>}
    </>
  );
};

/* ---------- 11. Checkout ---------- */
export const Checkout = () => {
  const { cart, go, placeOrder } = useApp();
  const [addr, setAddr] = useState(1);
  const [f, setF] = useState({ name: ACCOUNT.name, phone: '01004821190', email: '', city: 'القاهرة', address: '14 ش التحرير، الدقي، الجيزة', notes: '' });
  const [pay, setPay] = useState('credit');
  const [ship, setShip] = useState('standard');
  const [errs, setErrs] = useState({});
  const [step, setStep] = useState('form'); // form | changed | submitting
  const lines = cart.map((l) => ({ ...l, total: tierPrice(l.v, l.qty) * l.qty }));
  const sub = lines.reduce((a, l) => a + l.total, 0) || 46200;
  const shipping = ship === 'express' ? 650 : 350;
  const codFee = pay === 'cod' ? 150 : 0;
  const total = sub + shipping + codFee;
  const overCredit = pay === 'credit' && total > ACCOUNT.available_credit;
  const pickAddr = (a) => { setAddr(a.id); setF({ ...f, city: a.city, address: a.address }); };
  const submit = () => {
    const e = {};
    if (!f.name.trim()) e.name = 'اسم المستلم مطلوب.';
    if (!/^01\d{9}$/.test(f.phone)) e.phone = 'رقم موبايل مصري غير صحيح (11 رقم يبدأ بـ 01).';
    if (!f.city) e.city = 'اختر المدينة.';
    if (!f.address.trim()) e.address = 'العنوان التفصيلي مطلوب.';
    setErrs(e); if (Object.keys(e).length) return;
    if (step === 'form') { setStep('changed'); return; }
    setStep('submitting'); setTimeout(() => placeOrder({ total: total + 120, pay }), 1000);
  };
  const inp = (k, props = {}) => <input className={`line-input${errs[k] ? ' is-invalid' : ''}`} value={f[k]} onChange={(e) => { setF({ ...f, [k]: e.target.value }); setErrs({ ...errs, [k]: null }); }} {...props} />;
  return (
    <>
      <PageHead en="Checkout" crumb={<Crumb items={[{ label: 'السلة', go: () => go('cart') }, { label: 'إتمام الطلب' }]} />}>
        <button className="btn-pill" onClick={() => go('cart')}><i className="fa-solid fa-arrow-right" /> العودة للسلة</button>
      </PageHead>
      <ol className="steps"><li className="step is-done"><span className="step-dot">✓</span>السلة</li><li className="step is-now"><span className="step-dot">2</span>الشحن والدفع</li><li className="step"><span className="step-dot">3</span>التأكيد</li></ol>
      {step === 'changed' && <div className="mb-24"><Banner kind="warn" icon="fa-arrows-rotate"><b>تغير الإجمالي قبل التأكيد.</b> تمت إعادة حساب رسوم الشحن من <span className="num">{fmt(shipping)}</span> إلى <span className="num">{fmt(shipping + 120)}</span> حسب الوزن الفعلي. الإجمالي الجديد <b className="num">{fmt(total + 120)} EGP</b> — راجعه واضغط تأكيد.</Banner></div>}
      <div className="grid-2" style={{ gridTemplateColumns: '1.22fr .95fr', gap: 64 }}>
        <div>
          <fieldset className="ck-block" disabled={step === 'submitting'}>
            <h2 className="checkout-heading">عنوان التسليم</h2>
            <div className="addr-picks">
              {ADDRESSES.map((a) => (
                <button key={a.id} type="button" className={`addr-pick${addr === a.id ? ' is-on' : ''}`} onClick={() => pickAddr(a)}>
                  <b>{a.label}{a.default && <span className="badge accent" style={{ marginInlineStart: 8 }}>افتراضي</span>}</b>
                  <span>{a.city} — {a.address}</span>
                </button>
              ))}
              <button type="button" className={`addr-pick${addr === 0 ? ' is-on' : ''}`} onClick={() => { setAddr(0); setF({ ...f, city: '', address: '' }); }}><b>عنوان آخر</b><span>إدخال يدوي لهذا الطلب</span></button>
            </div>
            <div className="fields mt-24">
              <Field label="اسم المستلم" req error={errs.name}>{inp('name')}</Field>
              <Field label="تليفون المستلم" req error={errs.phone}>{inp('phone', { dir: 'ltr', inputMode: 'tel', style: { textAlign: 'right' } })}</Field>
              <Field label="بريد المستلم" opt error={errs.email}>{inp('email', { dir: 'ltr', placeholder: 'name@company.com', style: { textAlign: 'right' } })}</Field>
              <Field label="المدينة" req error={errs.city}>
                <select className={`line-input${errs.city ? ' is-invalid' : ''}`} value={f.city} onChange={(e) => { setF({ ...f, city: e.target.value }); setErrs({ ...errs, city: null }); }}>
                  <option value="">اختر المدينة</option>{['القاهرة', 'الجيزة', 'الإسكندرية', 'المنصورة', 'طنطا', 'أسيوط'].map((c) => <option key={c}>{c}</option>)}
                </select></Field>
              <Field label="العنوان التفصيلي" req span error={errs.address}>{inp('address', { placeholder: 'الشارع، رقم المبنى، علامة مميزة' })}</Field>
              <Field label="ملاحظات الشحن" opt span><textarea className="line-input" rows="2" value={f.notes} placeholder="مواعيد الاستلام، رقم بديل…" onChange={(e) => setF({ ...f, notes: e.target.value })} /></Field>
            </div>
          </fieldset>
          <fieldset className="ck-block" disabled={step === 'submitting'}>
            <h2 className="checkout-heading">خيارات الشحن</h2>
            <div className="radio-list">
              {[['standard', 'شحن قياسي', '3–5 أيام عمل', 350], ['express', 'شحن سريع', '1–2 يوم عمل · القاهرة والجيزة', 650]].map(([k, t, d, p]) => (
                <label key={k} className="radio-row"><span className="radio-left"><input type="radio" checked={ship === k} onChange={() => setShip(k)} /><span><span className="radio-title">{t}</span><span className="radio-desc">{d}</span></span></span><span className="radio-price num">{fmt(p)}</span></label>))}
            </div>
          </fieldset>
          <fieldset className="ck-block" disabled={step === 'submitting'}>
            <h2 className="checkout-heading">طريقة الدفع</h2>
            <div className="radio-list">
              <label className="radio-row"><span className="radio-left"><input type="radio" checked={pay === 'credit'} onChange={() => setPay('credit')} /><span><span className="radio-title">آجل على الحساب</span><span className="radio-desc">المتاح حاليًا <span className="num">{fmt(ACCOUNT.available_credit)}</span> EGP · متاح لحسابك حسب سياسة الائتمان</span></span></span><span className="badge accent">مسموح لحسابك</span></label>
              <label className="radio-row"><span className="radio-left"><input type="radio" checked={pay === 'cod'} onChange={() => setPay('cod')} /><span><span className="radio-title">الدفع عند الاستلام</span><span className="radio-desc">رسوم تحصيل <span className="num">150.00</span> EGP</span></span></span><span className="radio-price num">+150.00</span></label>
              <label className="radio-row"><span className="radio-left"><input type="radio" checked={pay === 'bank_transfer'} onChange={() => setPay('bank_transfer')} /><span><span className="radio-title">تحويل بنكي مقدم</span><span className="radio-desc">سجّل إشعار السداد بعد التحويل</span></span></span></label>
              <label className="radio-row is-disabled"><span className="radio-left"><input type="radio" disabled /><span><span className="radio-title">المحفظة</span><span className="radio-desc">الرصيد <span className="num">{fmt(ACCOUNT.wallet)}</span> غير كافٍ لهذا الطلب</span></span></span></label>
            </div>
            {overCredit && <div className="mt-16"><Banner kind="error">الإجمالي أكبر من المتاح للشراء الآجل. قلل الكمية أو اختر طريقة دفع أخرى.</Banner></div>}
          </fieldset>
        </div>
        <aside style={{ position: 'sticky', top: 100, alignSelf: 'start' }}>
          <h2 className="checkout-heading">ملخص الطلب</h2>
          <div className="ck-items">
            {(lines.length ? lines : [{ v: ALL_VARIANTS[0], qty: 30, total: 31200 }, { v: ALL_VARIANTS[10], qty: 60, total: 15300 }]).map((l) => (
              <div key={l.v.id} className="ck-item"><Thumb sm /><div style={{ flex: 1, minWidth: 0 }}><div className="strong small">{l.v.product.name_ar}</div><div className="soft small">{l.v.label} · <span className="num">×{l.qty}</span></div></div><span className="num strong small">{fmt(l.total)}</span></div>))}
          </div>
          <div className="sum-row"><span>قيمة المنتجات</span><strong className="num">{fmt(sub)}</strong></div>
          <div className="sum-row is-neg"><span>الخصم</span><strong className="num">—</strong></div>
          <div className="sum-row"><span>الشحن</span><strong className="num">{fmt(shipping + (step !== 'form' ? 120 : 0))}</strong></div>
          <div className="sum-row"><span>الضريبة</span><strong className="num">0.00</strong></div>
          {codFee > 0 && <div className="sum-row"><span>رسوم التحصيل</span><strong className="num">{fmt(codFee)}</strong></div>}
          <div className="sum-total"><span>الإجمالي النهائي</span><span className="num">{fmt(total + (step !== 'form' ? 120 : 0))}</span></div>
          <button className="btn-block mt-24" disabled={overCredit || step === 'submitting'} onClick={submit}>{step === 'submitting' ? <><span className="spin" /> جاري إتمام الطلب…</> : step === 'changed' ? 'تأكيد الإجمالي الجديد وإتمام الطلب' : 'إتمام الطلب'}</button>
          <p className="note mt-16"><i className="fa-solid fa-lock" />الأسعار والإجمالي النهائي بيتحسبوا من الخادم لحظة التأكيد.</p>
        </aside>
      </div>
    </>
  );
};

/* ---------- 12. Success ---------- */
export const OrderSuccess = () => {
  const { lastOrder, go } = useApp();
  const o = ORDERS[0];
  const pay = lastOrder?.pay || 'credit';
  return (
    <div className="success-wrap">
      <div className="order-card">
        <div className="modal-icon" style={{ width: 64, height: 64, fontSize: 26 }}><i className="fa-solid fa-check" /></div>
        <h1 className="success-title">ORDER PLACED</h1>
        <p className="muted" style={{ margin: '0 0 6px' }}>تم استلام طلبك بنجاح</p>
        <p className="success-num num">WS-260431</p>
        <div className="row" style={{ justifyContent: 'center', gap: 8, marginBottom: 24 }}><Badge map={ORDER_STATUS} k="pending" /><Badge map={PAY_STATUS} k={pay === 'cod' ? 'pending' : 'unpaid'} /></div>
        <div style={{ textAlign: 'start' }}>
          {o.items.map((it) => <div key={it.sku} className="ck-item"><Thumb sm /><div style={{ flex: 1 }}><div className="strong small">{it.name}</div><div className="sku">{it.sku}</div></div><span className="num small">×{it.qty}</span></div>)}
          <div className="sum-row"><span>طريقة الدفع</span><strong>{PAY_METHOD[pay]}</strong></div>
          <div className="sum-row"><span>عنوان التسليم</span><strong style={{ textAlign: 'left' }}>القاهرة — {o.address.split('—')[0]}</strong></div>
          <div className="sum-total"><span>الإجمالي</span><span className="num">{fmt(lastOrder?.total || o.total)}</span></div>
        </div>
        <div className="row mt-24" style={{ justifyContent: 'center' }}>
          <button className="btn-pill is-dark" onClick={() => go('order', 9)}>فتح الطلب</button>
          <button className="btn-pill" onClick={() => go('catalog')}>الرجوع للكتالوج</button>
        </div>
      </div>
    </div>
  );
};



