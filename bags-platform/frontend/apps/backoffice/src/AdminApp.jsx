// @ts-nocheck
import React, { useState, useEffect, useRef, useMemo, useCallback, createContext, useContext } from 'react';
import { useAuthStore } from './stores/auth.store';
import PosPage from './pages/pos/PosPage';
import {
  DashboardPage,
  OrdersPage,
  CustomersPage,
  InventoryPage,
  PurchasesPage,
  TreasuryPage,
  ReportsPage,
  CommandPalette,
  NotificationsPopover,
} from './PlatformExtensions';


// ===== Product form: Variants & pricing tab, Media tab =====
const COLOR_PRESETS = [
  { ar: 'أسود', en: 'Black', hex: '#151515' }, { ar: 'جملي', en: 'Camel', hex: '#b98a5a' }, { ar: 'عنابي', en: 'Burgundy', hex: '#6d1f2c' },
  { ar: 'بيج', en: 'Beige', hex: '#d9c7b0' }, { ar: 'كحلي', en: 'Navy', hex: '#1f2a44' }, { ar: 'زيتي', en: 'Olive', hex: '#5b5e3a' }, { ar: 'وردي فاتح', en: 'Blush', hex: '#e8b8bd' },
];
const SIZE_PRESETS = ['صغير', 'وسط', 'كبير', 'Mini'];
const CAP_PRESETS = ['10L', '15L', '20L', '30L'];
const PRICE_RE = /^\d+(\.\d{1,2})?$/;
const vKey = v => [(v.color_en || '').trim().toLowerCase(), (v.size || '').trim().toLowerCase(), (v.capacity || '').trim().toLowerCase()].join('|');
const blankVariant = (o = {}) => ({ uid: Math.random().toString(36).slice(2, 8), color_ar: '', color_en: '', hex: '', size: '', capacity: '', sku: '', barcode: '', retail: '', wholesale: '', cost: '', compare: '', weight: '', reorder: '', order: '', active: true, ...o });

// client-side validation mirrors API rules; returns { idx: { field: msg } }
function validateVariants(vs, channel) {
  const errs = {}; const seen = {};
  const put = (i, k, m) => { errs[i] = errs[i] || {}; errs[i][k] = m; };
  vs.forEach((v, i) => {
    if ((v.color_ar || v.hex) && !v.color_en) put(i, 'color_en', 'اسم اللون بالإنجليزية مطلوب');
    const k = vKey(v); if (seen[k] != null) { put(i, 'combo', 'تركيبة مكررة مع الصف ' + (seen[k] + 1)); put(seen[k], 'combo', 'تركيبة مكررة مع الصف ' + (i + 1)); } else seen[k] = i;
    ['retail', 'wholesale', 'cost', 'compare'].forEach(f => { if (v[f] !== '' && !PRICE_RE.test(String(v[f]))) put(i, f, 'رقم بحد أقصى منزلتين عشريتين'); });
    if (v.active) {
      if ((channel === 'retail' || channel === 'both') && v.retail === '') put(i, 'retail', 'مطلوب للتجزئة');
      if ((channel === 'wholesale' || channel === 'both') && v.wholesale === '') put(i, 'wholesale', 'مطلوب للجملة');
    }
    if (v.compare !== '' && v.retail !== '' && +v.compare <= +v.retail) put(i, 'compare', 'يجب أن يكون أعلى من سعر التجزئة');
  });
  return errs;
}

function ChipAxis({ label, hint, values, onChange, presets, placeholder }) {
  const [t, setT] = useState('');
  const add = v => { v = v.trim(); if (v && !values.includes(v)) onChange([...values, v]); setT(''); };
  return (
    <div className="gen-row">
      <div className="gl">{label}<small>{hint}</small></div>
      <div>
        <div className="chips-input">
          {values.map(v => <span key={v} className="vchip">{v}<button onClick={() => onChange(values.filter(x => x !== v))} aria-label="إزالة"><i className="fa-solid fa-xmark"></i></button></span>)}
          <input value={t} onChange={e => setT(e.target.value)} placeholder={values.length ? '' : placeholder} onKeyDown={e => { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add(t); } if (e.key === 'Backspace' && !t && values.length) onChange(values.slice(0, -1)); }} />
        </div>
        <div style={{ display: 'flex', gap: 6, marginTop: 8, flexWrap: 'wrap' }}>
          {presets.filter(p => !values.includes(p)).map(p => <button key={p} className="btn text sm" style={{ border: '1px dashed var(--line-2)' }} onClick={() => add(p)}>+ {p}</button>)}
        </div>
      </div>
    </div>
  );
}

function ColorAxis({ colors, onChange }) {
  const [n, setN] = useState({ ar: '', en: '', hex: '#8a6a4a' }); const [err, setErr] = useState(null);
  const add = c => {
    if (!c.en) { setErr('اسم اللون بالإنجليزية مطلوب لأنه جزء من التركيبة الفريدة.'); return; }
    if (colors.some(x => x.en.toLowerCase() === c.en.toLowerCase())) { setErr('هذا اللون مضاف بالفعل.'); return; }
    onChange([...colors, c]); setN({ ar: '', en: '', hex: '#8a6a4a' }); setErr(null);
  };
  return (
    <div className="gen-row">
      <div className="gl">الألوان<small>اختياري</small></div>
      <div>
        <div className="chips-input" style={{ minHeight: 38 }}>
          {colors.map(c => <span key={c.en} className="vchip"><span className="sw" style={{ background: c.hex }}></span>{c.ar || c.en} <span className="muted ltr" style={{ fontSize: 11 }}>{c.ar ? c.en : ''}</span><button onClick={() => onChange(colors.filter(x => x !== c))}><i className="fa-solid fa-xmark"></i></button></span>)}
          {!colors.length && <span className="muted" style={{ fontSize: 12.5, padding: '0 4px' }}>لم تُضف ألوان — اختر من الجاهز أو أضف لونًا</span>}
        </div>
        <div style={{ display: 'flex', gap: 6, marginTop: 8, flexWrap: 'wrap' }}>
          {COLOR_PRESETS.filter(p => !colors.some(c => c.en === p.en)).map(p => <button key={p.en} className="btn text sm" style={{ border: '1px dashed var(--line-2)', gap: 6 }} onClick={() => add(p)}><span className="sw" style={{ width: 12, height: 12, borderRadius: '50%', background: p.hex, display: 'inline-block', border: '1px solid rgba(0,0,0,.1)' }}></span>{p.ar}</button>)}
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '40px 1fr 1fr auto', gap: 8, marginTop: 10, alignItems: 'center' }}>
          <label className="swatch-in" style={{ width: 40, height: 40, borderRadius: 2, border: '1px solid var(--line-2)', background: n.hex, position: 'relative', overflow: 'hidden', cursor: 'pointer' }}>
            <input type="color" value={n.hex} onChange={e => setN({ ...n, hex: e.target.value })} style={{ position: 'absolute', inset: -6, opacity: 0, cursor: 'pointer' }} aria-label="قيمة اللون" /></label>
          <input className="inp" placeholder="اسم اللون بالعربي" value={n.ar} onChange={e => setN({ ...n, ar: e.target.value })} />
          <input className={'inp ltr ' + (err ? 'err' : '')} dir="ltr" placeholder="English name *" value={n.en} onChange={e => { setN({ ...n, en: e.target.value }); setErr(null); }} onKeyDown={e => e.key === 'Enter' && add(n)} />
          <Btn kind="ghost" onClick={() => add(n)}>إضافة</Btn>
        </div>
        {err && <div className="field-err" style={{ marginTop: 6 }}><i className="fa-solid fa-circle-exclamation"></i>{err}</div>}
      </div>
    </div>
  );
}

function VariantsTab({ p, setP, variants, setVariants, vErrs, canCost, isNew }) {
  const [axes, setAxes] = useState(p.axes);
  const [sel, setSel] = useState(new Set()); const [exp, setExp] = useState(new Set());
  const [bulk, setBulk] = useState({ field: 'retail', val: '' }); const [confirmMode, setConfirmMode] = useState(null);
  const cache = useRef({});
  useEffect(() => { variants.forEach(v => { cache.current[vKey(v)] = v; }); }, [variants]);
  const combos = useMemo(() => {
    const C = axes.colors.length ? axes.colors : [null], S = axes.sizes.length ? axes.sizes : [null], K = axes.caps.length ? axes.caps : [null];
    const out = []; C.forEach(c => S.forEach(s => K.forEach(k => out.push({ color_ar: c ? c.ar : '', color_en: c ? c.en : '', hex: c ? c.hex : '', size: s || '', capacity: k || '' })))); return out;
  }, [axes]);
  const existingKeys = new Set(variants.map(vKey));
  const toAdd = combos.filter(c => !existingKeys.has(vKey(c)));
  const comboKeys = new Set(combos.map(vKey));
  const orphan = variants.filter(v => !comboKeys.has(vKey(v)));
  const generate = () => {
    // preserves typed values: keeps existing rows, restores cached rows, appends new
    const keep = variants.filter(v => comboKeys.has(vKey(v)));
    const added = toAdd.map(c => cache.current[vKey(c)] ? { ...cache.current[vKey(c)] } : blankVariant(c));
    const next = [...keep, ...added].slice(0, 100);
    setVariants(next); setP(x => ({ ...x, axes })); setSel(new Set());
  };
  const upd = (i, k, v) => setVariants(vs => vs.map((r, j) => j === i ? { ...r, [k]: v } : r));
  const fillDown = k => { const from = sel.size ? Math.min(...sel) : 0; const val = variants[from][k]; setVariants(vs => vs.map((r, j) => j > from && (!sel.size || sel.has(j)) ? { ...r, [k]: val } : r)); };
  const applyBulk = () => { if (!PRICE_RE.test(bulk.val)) return; setVariants(vs => vs.map((r, j) => sel.has(j) ? { ...r, [bulk.field]: bulk.val } : r)); };
  const removeSel = () => { setVariants(vs => vs.filter((_, j) => !sel.has(j))); setSel(new Set()); };
  const nav = (e, r, c) => {
    const m = { ArrowDown: 1, Enter: 1, ArrowUp: -1 }[e.key]; if (!m) return; e.preventDefault();
    const el = document.querySelector(`[data-vr="${r + m}"][data-vc="${c}"]`); if (el) { el.focus(); el.select && el.select(); }
  };
  const chReq = { retail: p.channel !== 'wholesale', wholesale: p.channel !== 'retail' };
  const priceCols = [['retail', 'سعر التجزئة', chReq.retail], ['wholesale', 'سعر الجملة', chReq.wholesale], ...(canCost ? [['cost', 'التكلفة', false]] : []), ['compare', 'سعر المقارنة', false]];
  const allSel = variants.length > 0 && sel.size === variants.length;
  const usedAxes = { color: variants.some(v => v.color_en || v.color_ar), size: variants.some(v => v.size), cap: variants.some(v => v.capacity) };

  if (!p.mode) return (
    <div className="panel" style={{ maxWidth: 760 }}>
      <div className="panel-h"><div><h3>هل المنتج له أكثر من شكل؟</h3><p>اختر طريقة الإدخال. يمكنك التغيير لاحقًا قبل الحفظ.</p></div></div>
      <div className="panel-b radio-cards g2" style={{ display: 'grid' }}>
        <div className="rcard" onClick={() => { setP(x => ({ ...x, mode: 'single' })); setVariants([blankVariant()]); }}><span className="dot"></span><div><b>منتج بمتغير واحد</b><span>شكل واحد وسعر واحد — مثل محفظة كروت بلون واحد.</span></div></div>
        <div className="rcard" onClick={() => setP(x => ({ ...x, mode: 'multi' }))}><span className="dot"></span><div><b>ألوان ومقاسات وسعات متعددة</b><span>ولّد التركيبات تلقائيًا: اللون × المقاس × السعة.</span></div></div>
      </div>
    </div>
  );

  const errCount = Object.keys(vErrs).length;
  return <div className="grid" style={{ gap: 22 }}>
    <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
      <div className="seg">
        <button className={p.mode === 'single' ? 'on' : ''} onClick={() => p.mode !== 'single' && setConfirmMode('single')}>متغير واحد</button>
        <button className={p.mode === 'multi' ? 'on' : ''} onClick={() => p.mode !== 'multi' && setP(x => ({ ...x, mode: 'multi' }))}>متغيرات متعددة</button>
      </div>
      <span style={{ flex: 1 }}></span>
      <span className="muted" style={{ fontSize: 12 }}>قناة البيع: <b style={{ color: 'var(--ink)' }}>{CHANNELS[p.channel]}</b> — الأسعار المطلوبة تتبعها</span>
    </div>

    {!canCost && <Alert tone="info" icon="fa-solid fa-eye-slash">عمود «التكلفة» مخفي لأنك لا تملك صلاحية <span className="mono">products.view_cost</span>. القيم الحالية لن تتغير عند الحفظ.</Alert>}

    {p.mode === 'single' ? (
      <div className="panel"><div className="panel-h"><h3>بيانات المتغير</h3></div><div className="panel-b grid g4">
        {(() => { const v = variants[0] || blankVariant(); const e = vErrs[0] || {}; return <>
          <Field label="SKU" opt help={isNew ? 'يتولد تلقائيًا عند تركه فارغًا.' : null}><Input ltr className="mono" value={v.sku} onChange={x => upd(0, 'sku', x.target.value)} placeholder="AUTO" /></Field>
          <Field label="الباركود" opt help={isNew ? 'يتولد من الخادم عند تركه فارغًا.' : null}><Input ltr className="mono" value={v.barcode} onChange={x => upd(0, 'barcode', x.target.value)} placeholder="AUTO" /></Field>
          <Field label="اللون (عربي)" opt><Input value={v.color_ar} onChange={x => upd(0, 'color_ar', x.target.value)} /></Field>
          <Field label="اللون (إنجليزي)" req={!!(v.color_ar || v.hex)} error={e.color_en}><Input ltr value={v.color_en} error={e.color_en} onChange={x => upd(0, 'color_en', x.target.value)} /></Field>
          {priceCols.map(([k, l, r]) => <Field key={k} label={l} req={r} error={e[k]}><div className="inp-wrap"><Input ltr value={v[k]} error={e[k]} onChange={x => upd(0, k, x.target.value)} placeholder="0.00" inputMode="decimal" /><span className="adorn" style={{ fontSize: 10.5 }}>EGP</span></div></Field>)}
          <Field label="وزن المتغير (كجم)" opt><Input ltr value={v.weight} onChange={x => upd(0, 'weight', x.target.value)} /></Field>
          <Field label="حد إعادة الطلب" opt help="الكمية الافتتاحية تُضاف من حركة المخزون."><Input ltr value={v.reorder} onChange={x => upd(0, 'reorder', x.target.value)} /></Field>
        </>; })()}
      </div></div>
    ) : <>
      <div className="gen-box">
        <div className="panel-h" style={{ background: '#fff', borderRadius: '4px 4px 0 0' }}><div><h3>مولّد المتغيرات</h3><p>أداة إدخال فقط — يتم إرسال الصفوف الناتجة كمتغيرات.</p></div></div>
        <ColorAxis colors={axes.colors} onChange={c => setAxes(a => ({ ...a, colors: c }))} />
        <ChipAxis label="المقاسات" hint="اختياري" values={axes.sizes} onChange={s => setAxes(a => ({ ...a, sizes: s }))} presets={SIZE_PRESETS} placeholder="اكتب مقاسًا واضغط Enter" />
        <ChipAxis label="السعة" hint="اختياري" values={axes.caps} onChange={s => setAxes(a => ({ ...a, caps: s }))} presets={CAP_PRESETS} placeholder="مثال: 20L" />
        <div className="gen-foot">
          <span className="mono" style={{ fontSize: 12.5 }}>{Math.max(axes.colors.length, 1)} × {Math.max(axes.sizes.length, 1)} × {Math.max(axes.caps.length, 1)} = <b>{combos.length}</b></span>
          <span className="muted" style={{ fontSize: 12 }}>{toAdd.length ? `${toAdd.length} تركيبة جديدة` : 'لا تركيبات جديدة'}{orphan.length ? ` · ${orphan.length} ستُزال` : ''} · القيم المكتوبة تبقى كما هي</span>
          <span style={{ flex: 1 }}></span>
          {combos.length > 100 && <span className="field-err">الحد الأقصى ١٠٠ متغير في الطلب الواحد</span>}
          <Btn icon="fa-solid fa-wand-magic-sparkles" disabled={(!toAdd.length && !orphan.length) || combos.length > 100} onClick={generate}>{variants.length ? 'تحديث التركيبات' : 'توليد التركيبات'}</Btn>
        </div>
      </div>

      {variants.length > 0 && <div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 14, marginBottom: 10 }}>
          <h3 className="disp" style={{ fontSize: 16, fontWeight: 600 }}>المتغيرات</h3>
          <div className="cnt-meter"><div className="bar"><i style={{ width: variants.length + '%' }}></i></div>{variants.length} / 100</div>
          {errCount > 0 && <Badge tone="danger">{errCount} صفوف بها أخطاء</Badge>}
          <span style={{ flex: 1 }}></span>
          <span className="muted" style={{ fontSize: 11.5 }}><i className="fa-regular fa-keyboard"></i> ↑ ↓ Enter للتنقل بين الخلايا</span>
        </div>
        {sel.size > 0 && <div className="bulk-bar">
          <b>{sel.size} محدد</b><span style={{ opacity: .4 }}>|</span>
          <span>تطبيق</span>
          <select className="inp" value={bulk.field} onChange={e => setBulk({ ...bulk, field: e.target.value })}>{priceCols.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
          <input className="inp ltr" dir="ltr" placeholder="0.00" value={bulk.val} onChange={e => setBulk({ ...bulk, val: e.target.value })} onKeyDown={e => e.key === 'Enter' && applyBulk()} />
          <Btn kind="accent" size="sm" onClick={applyBulk} disabled={!PRICE_RE.test(bulk.val)}>على الصفوف المحددة</Btn>
          <span style={{ flex: 1 }}></span>
          <Btn kind="ghost" size="sm" icon="fa-solid fa-toggle-off" onClick={() => setVariants(vs => vs.map((r, j) => sel.has(j) ? { ...r, active: false } : r))}>تعطيل</Btn>
          <Btn kind="ghost" size="sm" icon="fa-regular fa-trash-can" onClick={removeSel}>حذف التركيبات غير المتاحة</Btn>
          <button className="icon-btn" style={{ color: '#fff' }} onClick={() => setSel(new Set())}><i className="fa-solid fa-xmark"></i></button>
        </div>}
        <div className="vt-wrap">
          <table className="vt">
            <thead><tr>
              <th style={{ width: 36 }}><Check checked={allSel} indeterminate={sel.size > 0 && !allSel} onChange={v => setSel(v ? new Set(variants.map((_, i) => i)) : new Set())} /></th>
              {usedAxes.color && <th style={{ width: 210 }}>اللون</th>}
              {usedAxes.size && <th style={{ width: 90 }}>المقاس</th>}
              {usedAxes.cap && <th style={{ width: 80 }}>السعة</th>}
              <th style={{ width: 150 }}>SKU</th><th style={{ width: 150 }}>الباركود</th>
              {priceCols.map(([k, l, r]) => <th key={k} style={{ width: 118 }}>{l}{r && <span style={{ color: 'var(--danger)' }}> *</span>}<button className="fd" title={sel.size ? 'نسخ القيمة لأسفل في المحدد' : 'نسخ قيمة الصف الأول لكل الصفوف'} onClick={() => fillDown(k)}><i className="fa-solid fa-arrow-down-long"></i></button></th>)}
              <th style={{ width: 60 }}>نشط</th><th style={{ width: 70 }}></th>
            </tr></thead>
            <tbody>
              {variants.map((v, i) => {
                const e = vErrs[i] || {}; const open = exp.has(i); let c = 0;
                const cell = (k, opts = {}) => { const ci = c++; return <td data-l={opts.l} className={opts.cls}><input className={'cell ' + (opts.price ? 'price ' : '') + (opts.mono ? 'mono ' : '') + (e[k] ? 'err' : '')} dir={opts.ltr || opts.price ? 'ltr' : undefined} data-vr={i} data-vc={ci} value={v[k]} placeholder={opts.ph} inputMode={opts.price ? 'decimal' : undefined} onChange={x => upd(i, k, x.target.value)} onKeyDown={x => nav(x, i, ci)} aria-label={opts.l} aria-invalid={!!e[k]} />{e[k] && <div className="ce">{e[k]}</div>}</td>; };
                return <React.Fragment key={v.uid}>
                  <tr className={'vrow ' + (e.combo ? 'dup ' : '') + (sel.has(i) ? 'sel ' : '') + (!v.active ? 'inactive' : '')}>
                    <td className="mob-hide"><Check checked={sel.has(i)} onChange={on => setSel(s => { const n = new Set(s); on ? n.add(i) : n.delete(i); return n; })} /></td>
                    {usedAxes.color && <td className="mob-full" data-l="اللون"><div className="colorcell">
                      <label className="swatch-in" style={{ background: v.hex || '#fff' }} title="قيمة اللون"><input type="color" value={v.hex || '#ffffff'} onChange={x => upd(i, 'hex', x.target.value)} /></label>
                      <input className="cell" style={{ width: 80 }} value={v.color_ar} onChange={x => upd(i, 'color_ar', x.target.value)} placeholder="عربي" />
                      <input className={'cell ' + (e.color_en ? 'err' : '')} dir="ltr" style={{ width: 90 }} value={v.color_en} onChange={x => upd(i, 'color_en', x.target.value)} placeholder="English" />
                    </div>{(e.color_en || e.combo) && <div className="ce">{e.color_en || e.combo}</div>}</td>}
                    {usedAxes.size && cell('size', { l: 'المقاس' })}
                    {usedAxes.cap && cell('capacity', { l: 'السعة', ltr: true })}
                    {cell('sku', { l: 'SKU', mono: true, ltr: true, ph: isNew ? 'تلقائي' : '' })}
                    {cell('barcode', { l: 'الباركود', mono: true, ltr: true, ph: isNew ? 'من الخادم' : '' })}
                    {priceCols.map(([k, l]) => <React.Fragment key={k}>{cell(k, { l, price: true, ph: '0.00' })}</React.Fragment>)}
                    <td data-l="نشط"><div style={{ paddingTop: 7 }}><Toggle checked={v.active} onChange={on => upd(i, 'active', on)} /></div></td>
                    <td className="mob-full" style={{ whiteSpace: 'nowrap' }}><div style={{ paddingTop: 2 }}>
                      <button className="icon-btn" title="تفاصيل إضافية" onClick={() => setExp(s => { const n = new Set(s); open ? n.delete(i) : n.add(i); return n; })}><i className={'fa-solid fa-chevron-' + (open ? 'up' : 'down')}></i></button>
                      <button className="icon-btn danger" title="حذف التركيبة" onClick={() => setVariants(vs => vs.filter((_, j) => j !== i))}><i className="fa-regular fa-trash-can"></i></button></div></td>
                  </tr>
                  {open && <tr className="exp"><td colSpan={20}><div className="grid g4" style={{ maxWidth: 760 }}>
                    <Field label="وزن المتغير (كجم)" opt><Input ltr value={v.weight} onChange={x => upd(i, 'weight', x.target.value)} placeholder="0.45" /></Field>
                    <Field label="حد إعادة الطلب" opt><Input ltr value={v.reorder} onChange={x => upd(i, 'reorder', x.target.value)} placeholder="5" /></Field>
                    <Field label="الترتيب" opt><Input ltr value={v.order} onChange={x => upd(i, 'order', x.target.value)} placeholder={String(i + 1)} /></Field>
                    <Field label="الحالة"><div style={{ paddingTop: 8 }}><Toggle checked={v.active} onChange={on => upd(i, 'active', on)} label={v.active ? 'نشط' : 'معطل'} /></div></Field>
                  </div><div className="field-help" style={{ marginTop: 10 }}><i className="fa-solid fa-circle-info"></i> الكمية الافتتاحية لا تُدخل هنا — تُضاف من «حركة المخزون» بعد حفظ المنتج.</div></td></tr>}
                </React.Fragment>;
              })}
            </tbody>
          </table>
        </div>
        <div style={{ marginTop: 10 }}><Btn kind="text" size="sm" icon="fa-solid fa-plus" onClick={() => setVariants(vs => [...vs, blankVariant()])} disabled={variants.length >= 100}>إضافة صف يدويًا</Btn></div>
      </div>}
    </>}
    <ConfirmModal open={!!confirmMode} tone="warn" icon="fa-solid fa-layer-group" title="التحويل لمتغير واحد؟" text={`سيتم الإبقاء على الصف الأول فقط وحذف ${Math.max(variants.length - 1, 0)} تركيبة أخرى من النموذج.`} confirmLabel="تحويل"
      onClose={() => setConfirmMode(null)} onConfirm={() => { setP(x => ({ ...x, mode: 'single' })); setVariants(vs => [vs[0] || blankVariant()]); }} />
  </div>;
}

// ---------------- Media ----------------
function MediaTab({ media, setMedia, variants, isNew, savedOnce }) {
  const [dragI, setDragI] = useState(null); const [overI, setOverI] = useState(null);
  const pendingNote = isNew && !savedOnce;
  const addFiles = (target) => {
    const pool = [IMG.bag1, IMG.bag2, IMG.bag3, IMG.shoulder];
    const n = Math.min(3, 10 - media.general.length);
    const files = Array.from({ length: n }).map((_, k) => ({ id: Math.random().toString(36).slice(2), src: pool[(media.general.length + k) % pool.length], name: `laila-${media.general.length + k + 1}.jpg`, size: (1.2 + k * .7).toFixed(1) + 'MB', state: pendingNote ? 'pending' : 'uploading', p: 0, variant: target || null }));
    setMedia(m => ({ ...m, general: [...m.general, ...files] }));
    if (!pendingNote) files.forEach(f => simulate(f.id));
  };
  const simulate = id => { let v = 0; const t = setInterval(() => { v += 12 + Math.random() * 18; setMedia(m => ({ ...m, general: m.general.map(f => f.id === id ? { ...f, p: Math.min(v, 100), state: v >= 100 ? 'done' : 'uploading' } : f) })); if (v >= 100) clearInterval(t); }, 220); };
  const reorder = (from, to) => setMedia(m => { const g = [...m.general]; const [x] = g.splice(from, 1); g.splice(to, 0, x); return { ...m, general: g }; });
  const generalOnly = media.general.filter(f => !f.variant);
  const colorGroups = [...new Set(variants.map(v => v.color_en).filter(Boolean))];
  const Tile = ({ f, idx }) => {
    const gi = media.general.indexOf(f);
    return <div className={'img-tile ' + (dragI === gi ? 'dragging ' : '') + (overI === gi ? 'over ' : '') + (f.state === 'failed' ? 'failed' : '')} draggable
      onDragStart={() => setDragI(gi)} onDragOver={e => { e.preventDefault(); setOverI(gi); }} onDragLeave={() => setOverI(null)} onDrop={() => { reorder(dragI, gi); setDragI(null); setOverI(null); }} onDragEnd={() => { setDragI(null); setOverI(null); }}>
      <img src={f.src} alt="" />
      <span className="ord">{idx + 1}</span>
      {idx === 0 && !f.variant && <span className="cover"><Badge tone="ink" nodot>الغلاف</Badge></span>}
      {f.state === 'uploading' && <div className="veil"><div style={{ width: '70%' }}><div className="prog"><i style={{ width: f.p + '%' }}></i></div><div style={{ fontSize: 11, textAlign: 'center', marginTop: 4 }}>{Math.round(f.p)}%</div></div></div>}
      {f.state === 'failed' && <div className="veil" style={{ background: 'rgba(255,245,247,.85)' }}><div style={{ textAlign: 'center', padding: 8 }}><i className="fa-solid fa-circle-exclamation" style={{ color: 'var(--danger)' }}></i><div style={{ fontSize: 11, margin: '4px 0 6px', color: 'var(--danger)' }}>{f.err || 'فشل الرفع'}</div><Btn size="sm" kind="ghost" onClick={() => { setMedia(m => ({ ...m, general: m.general.map(x => x.id === f.id ? { ...x, state: 'uploading', p: 0 } : x) })); simulate(f.id); }}>إعادة المحاولة</Btn></div></div>}
      <div className="ov">
        {f.state === 'pending' ? <span className="muted"><i className="fa-regular fa-clock"></i> بانتظار الحفظ</span> : f.state === 'done' ? <span style={{ color: 'var(--ok)' }}><i className="fa-solid fa-check"></i> مرفوعة</span> : <span></span>}
        <span className="sp"></span>
        <button className="icon-btn danger" style={{ width: 24, height: 24 }} onClick={() => setMedia(m => ({ ...m, general: m.general.filter(x => x.id !== f.id) }))} aria-label="حذف"><i className="fa-regular fa-trash-can" style={{ fontSize: 11 }}></i></button>
      </div>
    </div>;
  };
  return <div className="grid" style={{ gap: 22 }}>
    {pendingNote && <Alert tone="info" icon="fa-solid fa-cloud-arrow-up" title="يمكنك اختيار الصور الآن">الرفع الفعلي يبدأ تلقائيًا بعد حفظ المنتج ومتغيراته — الصور المختارة محفوظة في النموذج.</Alert>}
    <div className="panel">
      <div className="panel-h"><div><h3>صور المنتج العامة</h3><p>اسحب لإعادة الترتيب — الصورة الأولى هي الغلاف في المتجر.</p></div><span className="sp"></span><span className="muted" style={{ fontSize: 12 }}>{generalOnly.length} صورة</span></div>
      <div className="panel-b">
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(140px,1fr))', gap: 14 }}>
          {generalOnly.map((f, i) => <Tile key={f.id} f={f} idx={i} />)}
          <div className="drop" style={{ aspectRatio: '1', justifyContent: 'center', padding: 12 }} onClick={() => addFiles(null)}>
            <div className="di"><i className="fa-solid fa-plus"></i></div><b style={{ fontSize: 12 }}>إضافة صور</b>
          </div>
        </div>
        <div className="drop" style={{ marginTop: 14, padding: 12, flexDirection: 'row', justifyContent: 'center', cursor: 'default' }}>
          <div className="rules" style={{ margin: 0 }}><span>JPG · PNG · WebP</span><span>حتى 10 صور في الرفعة</span><span>5MB لكل صورة</span><span>حتى 6000×6000</span></div>
        </div>
      </div>
    </div>
    {colorGroups.length > 0 && <div className="panel">
      <div className="panel-h"><div><h3>صور مرتبطة بالمتغيرات</h3><p>تظهر عند اختيار العميل لهذا المتغير. اختر المتغير ثم أضف صوره.</p></div></div>
      <div className="panel-b grid" style={{ gap: 0 }}>
        {colorGroups.map(c => { const vs = variants.filter(v => v.color_en === c); const imgs = media.general.filter(f => f.variant === c); return (
          <div key={c} style={{ display: 'flex', gap: 16, alignItems: 'center', padding: '12px 0', borderBottom: '1px solid var(--line)' }}>
            <span className="vchip" style={{ minWidth: 150 }}><span className="sw" style={{ background: vs[0].hex }}></span>{vs[0].color_ar || c} <span className="muted ltr" style={{ fontSize: 11 }}>{c}</span></span>
            <span className="muted" style={{ fontSize: 11.5, width: 90 }}>{vs.length} متغيرات</span>
            <div style={{ display: 'flex', gap: 8, flex: 1, flexWrap: 'wrap' }}>
              {imgs.map((f, i) => <div key={f.id} style={{ width: 64 }}><Tile f={f} idx={i} /></div>)}
              <button className="drop" style={{ width: 64, height: 64, padding: 0, justifyContent: 'center' }} onClick={() => addFiles(c)}><i className="fa-solid fa-plus muted"></i></button>
            </div>
          </div>); })}
      </div>
    </div>}
    <div className="panel">
      <div className="panel-h"><div><h3>فيديو المنتج</h3><p>فيديو واحد للمنتج — غير مرتبط بمتغير.</p></div></div>
      <div className="panel-b">
        {media.video ? <div className="file">
          <div className="thumb" style={{ width: 80, height: 54, background: 'var(--ink)', color: '#fff' }}><i className="fa-solid fa-play"></i></div>
          <div className="fmeta"><b className="ltr" style={{ textAlign: 'right' }}>laila-360-spin.mp4</b><small>38.4MB · 0:24</small>{media.video === 'uploading' && <div className="prog"><i style={{ width: '64%' }}></i></div>}</div>
          {media.video === 'uploading' ? <span className="muted" style={{ fontSize: 12 }}>64%</span> : media.video === 'pending' ? <Badge>بانتظار الحفظ</Badge> : <Badge tone="ok">مرفوع</Badge>}
          <button className="icon-btn danger" onClick={() => setMedia(m => ({ ...m, video: null }))}><i className="fa-regular fa-trash-can"></i></button>
        </div> : <div className="drop" onClick={() => setMedia(m => ({ ...m, video: pendingNote ? 'pending' : 'uploading' }))}>
          <div className="di"><i className="fa-solid fa-film"></i></div><b>أضف فيديو للمنتج</b><div className="rules"><span>MP4 · MOV · WebM</span><span>حتى 50MB</span></div></div>}
      </div>
    </div>
  </div>;
}

Object.assign(window, { VariantsTab, MediaTab, validateVariants, blankVariant, vKey, PRICE_RE, COLOR_PRESETS });// ===== Shared component library (Phase 0) =====
// const { useState, useEffect, useRef, useMemo, useCallback, createContext, useContext } = React;

const AppCtx = createContext(null);
const useApp = () => useContext(AppCtx);

function Field({ label, req, opt, help, error, children, className = '', htmlFor }) {
  return (
    <div className={'field ' + className}>
      {label && <label className="field-label" htmlFor={htmlFor}>{label}{req && <span className="req" aria-label="مطلوب">*</span>}{opt && <span className="opt">(اختياري)</span>}</label>}
      {children}
      {error ? <div className="field-err" role="alert"><i className="fa-solid fa-circle-exclamation"></i><span>{error}</span></div>
        : help ? <div className="field-help">{help}</div> : null}
    </div>
  );
}

function Input({ error, ltr, className = '', ...p }) {
  return <input className={'inp ' + (error ? 'err ' : '') + (ltr ? 'ltr ' : '') + className} dir={ltr ? 'ltr' : undefined} {...p} />;
}
function TextArea({ error, ltr, ...p }) {
  return <textarea className={'inp ' + (error ? 'err' : '')} dir={ltr ? 'ltr' : undefined} {...p} />;
}
function PasswordInput({ error, ...p }) {
  const [show, setShow] = useState(false);
  return (
    <div className="inp-wrap">
      <input className={'inp ltr ' + (error ? 'err' : '')} dir="ltr" type={show ? 'text' : 'password'} {...p} />
      <button type="button" className="adorn" onClick={() => setShow(s => !s)} aria-label={show ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'}>
        <i className={'fa-regular ' + (show ? 'fa-eye-slash' : 'fa-eye')}></i>
      </button>
    </div>
  );
}
function Toggle({ checked, onChange, label, disabled }) {
  return (
    <label className={'tgl ' + (disabled ? 'disabled' : '')}>
      <input type="checkbox" checked={!!checked} onChange={e => onChange && onChange(e.target.checked)} disabled={disabled} />
      <span className="tr"></span>{label && <span>{label}</span>}
    </label>
  );
}
function Check({ checked, indeterminate, onChange, label, disabled }) {
  const r = useRef();
  useEffect(() => { if (r.current) r.current.indeterminate = !!indeterminate; }, [indeterminate]);
  return (
    <label className="chk" onClick={e => e.stopPropagation()}>
      <input ref={r} type="checkbox" checked={!!checked} disabled={disabled} onChange={e => onChange && onChange(e.target.checked)} />
      {label && <span>{label}</span>}
    </label>
  );
}
function Badge({ tone = '', children, nodot }) { return <span className={'badge ' + tone + (nodot ? ' nodot' : '')}>{children}</span>; }
function StatusBadge({ active }) { return active ? <Badge tone="ok">نشط</Badge> : <Badge>غير نشط</Badge>; }

function Btn({ kind = '', size = '', icon, loading, children, ...p }) {
  return (
    <button className={['btn', kind, size].join(' ')} disabled={loading || p.disabled} {...p}>
      {loading ? <span className="spin"></span> : icon ? <i className={icon}></i> : null}
      {children}
    </button>
  );
}

// ---- Searchable select (single / multi), with loading + empty + disabled options
function SearchSelect({ options = [], value, onChange, placeholder = 'اختر…', multi, error, loadingDelay = 450, emptyText = 'لا توجد نتائج مطابقة', disabled, renderOpt, searchPh = 'ابحث…', clearable }) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const [loading, setLoading] = useState(false);
  const [hl, setHl] = useState(0);
  const ref = useRef();
  useEffect(() => {
    if (!open) return;
    const h = e => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', h); return () => document.removeEventListener('mousedown', h);
  }, [open]);
  useEffect(() => { if (open) { setLoading(true); const t = setTimeout(() => setLoading(false), loadingDelay); return () => clearTimeout(t); } }, [open, q]);
  const filtered = options.filter(o => !q || (o.label + ' ' + (o.sub || '')).toLowerCase().includes(q.toLowerCase()));
  const sel = multi ? options.filter(o => (value || []).includes(o.value)) : options.find(o => o.value === value);
  const pick = o => {
    if (o.disabled) return;
    if (multi) { const v = value || []; onChange(v.includes(o.value) ? v.filter(x => x !== o.value) : [...v, o.value]); }
    else { onChange(o.value); setOpen(false); }
  };
  return (
    <div className="ss" ref={ref}>
      <button type="button" disabled={disabled} className={'inp ss-trigger ' + (error ? 'err' : '')} onClick={() => setOpen(o => !o)} aria-haspopup="listbox" aria-expanded={open}>
        <span className="val">
          {multi ? (sel.length ? sel.map(s => s.label).join('، ') : <span className="ph">{placeholder}</span>)
            : sel ? sel.label : <span className="ph">{placeholder}</span>}
        </span>
        {clearable && !multi && sel && <i className="fa-solid fa-xmark muted" onClick={e => { e.stopPropagation(); onChange(null); }}></i>}
        <i className="fa-solid fa-chevron-down" style={{ fontSize: 10, color: 'var(--ink-4)' }}></i>
      </button>
      {open && (
        <div className="ss-pop" role="listbox">
          <div className="ss-search"><i className="fa-solid fa-magnifying-glass muted" style={{ fontSize: 12 }}></i>
            <input autoFocus value={q} placeholder={searchPh} onChange={e => { setQ(e.target.value); setHl(0); }}
              onKeyDown={e => {
                if (e.key === 'ArrowDown') { e.preventDefault(); setHl(h => Math.min(h + 1, filtered.length - 1)); }
                if (e.key === 'ArrowUp') { e.preventDefault(); setHl(h => Math.max(h - 1, 0)); }
                if (e.key === 'Enter') { e.preventDefault(); filtered[hl] && pick(filtered[hl]); }
                if (e.key === 'Escape') setOpen(false);
              }} />
          </div>
          {loading ? (
            <div className="ss-loading">{[70, 55, 80].map((w, i) => <div key={i} style={{ padding: '10px' }}><div className="sk" style={{ width: w + '%' }}></div></div>)}</div>
          ) : filtered.length === 0 ? (
            <div className="ss-empty"><i className="fa-regular fa-folder-open" style={{ display: 'block', fontSize: 18, marginBottom: 6 }}></i>{emptyText}{q && <> عن «{q}»</>}</div>
          ) : (
            <div className="ss-list">
              {filtered.map((o, i) => {
                const isSel = multi ? (value || []).includes(o.value) : o.value === value;
                return (
                  <div key={o.value ?? 'null'} className={'ss-opt ' + (isSel ? 'sel ' : '') + (o.disabled ? 'dis ' : '') + (i === hl ? 'hl' : '')} onMouseEnter={() => setHl(i)} onClick={() => pick(o)} title={o.why}>
                    {renderOpt ? renderOpt(o) : <><span>{o.label}</span>{o.sub && <span className="muted" style={{ fontSize: 11.5 }}>{o.sub}</span>}</>}
                    {o.why && <span className="why">{o.why}</span>}
                  </div>
                );
              })}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// ---- states
function EmptyState({ icon = 'fa-regular fa-folder-open', title, text, action }) {
  return <div className="state"><div className="glyph"><i className={icon}></i></div><h4>{title}</h4>{text && <p>{text}</p>}{action && <div className="row">{action}</div>}</div>;
}
function ErrorState({ onRetry, code = '500', text = 'تعذّر تحميل البيانات من الخادم. تحقق من اتصالك وحاول مرة أخرى.' }) {
  return <div className="state err"><div className="glyph"><i className="fa-solid fa-triangle-exclamation"></i></div><h4>حدث خطأ أثناء التحميل</h4><p>{text}</p><span className="code">HTTP {code}</span><div className="row"><Btn kind="ghost" icon="fa-solid fa-rotate-right" onClick={onRetry}>إعادة المحاولة</Btn></div></div>;
}
function NoAccessState({ perm, go }) {
  return <div className="state lock"><div className="glyph"><i className="fa-solid fa-lock"></i></div><h4>ليس لديك صلاحية لعرض هذه الصفحة</h4><p>هذه الصفحة تتطلب صلاحية خاصة. تواصل مع مدير النظام لإضافتها إلى دورك.</p>{perm && <span className="code ltr">403 · {perm}</span>}<div className="row"><Btn kind="ghost" onClick={() => go && go('/products')}>العودة للمنتجات</Btn></div></div>;
}

function Alert({ tone = 'info', icon, title, children, action, onClose }) {
  const ic = icon || { info: 'fa-solid fa-circle-info', warn: 'fa-solid fa-triangle-exclamation', danger: 'fa-solid fa-circle-exclamation', ok: 'fa-solid fa-circle-check', ink: 'fa-solid fa-circle-info' }[tone];
  return (
    <div className={'alert ' + tone} role={tone === 'danger' ? 'alert' : 'status'}>
      <i className={ic}></i>
      <div className="grow">{title && <b>{title}</b>}{title && children ? <div>{children}</div> : children}</div>
      {action}
      {onClose && <button className="icon-btn" onClick={onClose} aria-label="إغلاق"><i className="fa-solid fa-xmark"></i></button>}
    </div>
  );
}

// ---- Confirm modal; supports server rejection state
function ConfirmModal({ open, tone = 'danger', icon, title, text, confirmLabel = 'تأكيد', onConfirm, onClose, rejectWith }) {
  const [state, setState] = useState('idle'); const [err, setErr] = useState(null);
  useEffect(() => { if (open) { setState('idle'); setErr(null); } }, [open]);
  if (!open) return null;
  const go = async () => {
    setState('loading'); await sleep(700);
    if (rejectWith) { setErr(rejectWith); setState('rejected'); return; }
    onConfirm && onConfirm(); onClose();
  };
  return (
    <div className="scrim" onMouseDown={e => e.target === e.currentTarget && onClose()}>
      <div className="modal" role="dialog" aria-modal="true">
        <div className="modal-b">
          <div className={'mi ' + tone}><i className={icon || 'fa-regular fa-trash-can'}></i></div>
          <div style={{ flex: 1 }}>
            <h4>{title}</h4><p>{text}</p>
            {state === 'rejected' && <div style={{ marginTop: 14 }}><Alert tone="danger" title="رفض الخادم العملية">{err}</Alert></div>}
          </div>
        </div>
        <div className="modal-f">
          <Btn kind="ghost" onClick={onClose}>{state === 'rejected' ? 'إغلاق' : 'إلغاء'}</Btn>
          {state !== 'rejected' && <Btn kind={tone === 'danger' ? 'danger' : ''} loading={state === 'loading'} onClick={go}>{confirmLabel}</Btn>}
        </div>
      </div>
    </div>
  );
}

function Drawer({ open, title, crumbs, onClose, children, footer, dirty }) {
  const [ask, setAsk] = useState(false);
  if (!open) return null;
  const tryClose = () => dirty ? setAsk(true) : onClose();
  return (
    <div className="scrim drawer-scrim" onMouseDown={e => e.target === e.currentTarget && tryClose()}>
      <div className="drawer" role="dialog" aria-modal="true">
        <div className="drawer-h">
          <div style={{ flex: 1 }}>{crumbs && <div className="crumbs">{crumbs}</div>}<h3>{title}</h3></div>
          <button className="icon-btn" onClick={tryClose} aria-label="إغلاق"><i className="fa-solid fa-xmark"></i></button>
        </div>
        <div className="drawer-b">{children}</div>
        {footer && <div className="drawer-f">{footer}</div>}
      </div>
      <ConfirmModal open={ask} tone="warn" icon="fa-solid fa-pen-to-square" title="تغييرات غير محفوظة"
        text="لديك تعديلات لم تُحفظ بعد. إذا أغلقت الآن ستفقد هذه التعديلات." confirmLabel="تجاهل التغييرات"
        onConfirm={onClose} onClose={() => setAsk(false)} />
    </div>
  );
}

// ---- Page header (breadcrumb + title + primary action), echoes storefront hero band
function PageHeader({ crumbs = [], light, bold, sub, actions, decor }) {
  const { go } = useApp();
  return (
    <div className={'page-band ' + (decor ? 'has-decor' : '')}>
      <div className="page-band-in">
        <div>
          <nav className="crumbs" aria-label="breadcrumb">
            {crumbs.map((c, i) => <React.Fragment key={i}>
              {i > 0 && <span className="sep">/</span>}
              {i === crumbs.length - 1 ? <span className="cur">{c[0]}</span> : <a onClick={() => c[1] && go(c[1])}>{c[0]}</a>}
            </React.Fragment>)}
          </nav>
          <h1 className="page-title">{light && <span className="lt">{light} </span>}<span className="bd">{bold}</span></h1>
          {sub && <p className="page-sub">{sub}</p>}
        </div>
        {actions && <div className="page-actions">{actions}</div>}
      </div>
      {decor && <img className="decor" src={decor} alt="" />}
    </div>
  );
}

// ---- DataTable with server pagination, page size, loading/empty/error
function DataTable({ columns, rows, query, setQuery, searchPh = 'بحث…', filters, activeFilters, onClearFilters, rowKey = 'id', empty, onRowClick, forceState }) {
  const page = +query.page || 1, per = +query.per_page || 10;
  const [res, setRes] = useState(null); const [state, setState] = useState('loading'); const [nonce, setNonce] = useState(0);
  const [qLocal, setQLocal] = useState(query.q || '');
  useEffect(() => { const t = setTimeout(() => { if ((query.q || '') !== qLocal) setQuery({ q: qLocal, page: 1 }); }, 350); return () => clearTimeout(t); }, [qLocal]);
  useEffect(() => {
    let live = true; setState('loading');
    apiClient.list(rows, { page, per_page: per, fail: forceState === 'error' }).then(r => { if (live) { setRes(r); setState('ok'); } }).catch(() => live && setState('error'));
    return () => { live = false; };
  }, [rows, page, per, nonce, forceState]);
  const st = forceState && forceState !== 'error' ? forceState : state;
  const meta = res && res.meta;
  return (
    <div className="dt">
      <div className="dt-toolbar">
        <div className="dt-search"><i className="fa-solid fa-magnifying-glass"></i><input className="inp" value={qLocal} placeholder={searchPh} onChange={e => setQLocal(e.target.value)} /></div>
        {filters && <div className="dt-filters">{filters}</div>}
      </div>
      {activeFilters && activeFilters.length > 0 && (
        <div className="active-filters"><span className="lbl">الفلاتر النشطة:</span>
          {activeFilters.map(f => <span key={f.k} className="tag">{f.label}<button onClick={f.clear} aria-label="إزالة"><i className="fa-solid fa-xmark"></i></button></span>)}
          <button className="btn text sm" onClick={onClearFilters}>مسح الكل</button>
        </div>
      )}
      {st === 'error' ? <ErrorState onRetry={() => setNonce(n => n + 1)} /> :
        st === 'empty' || (st === 'ok' && meta.total === 0) ? (empty || <EmptyState title="لا توجد نتائج" text="جرّب تعديل البحث أو الفلاتر." />) : (
          <div style={{ overflowX: 'auto' }}>
            <table className="tbl">
              <thead><tr>{columns.map(c => <th key={c.k} style={c.w ? { width: c.w } : null} className={c.cls}>{c.label}</th>)}</tr></thead>
              <tbody>
                {st === 'loading' ? Array.from({ length: Math.min(per, 6) }).map((_, i) => (
                  <tr key={i}>{columns.map((c, j) => <td key={c.k}>{j === 0 ? <div className="cell-main"><div className="thumb sk" style={{ height: 44 }}></div><div style={{ flex: 1 }}><div className="sk" style={{ width: 140, marginBottom: 6 }}></div><div className="sk" style={{ width: 80, height: 9 }}></div></div></div> : <div className="sk" style={{ width: 40 + ((i * 7 + j * 13) % 50) }}></div>}</td>)}</tr>
                )) : res.data.map(r => (
                  <tr key={r[rowKey]} onClick={onRowClick ? () => onRowClick(r) : undefined} style={onRowClick ? { cursor: 'pointer' } : null}>
                    {columns.map(c => <td key={c.k} className={c.cls}>{c.render ? c.render(r) : r[c.k]}</td>)}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      {st !== 'error' && meta && meta.total > 0 && (
        <div className="dt-foot">
          <span>عرض <b className="num">{meta.from}–{meta.to}</b> من <b className="num">{meta.total}</b></span>
          <span className="muted">|</span>
          <label style={{ display: 'flex', gap: 8, alignItems: 'center' }}>حجم الصفحة
            <select className="mini-sel" value={per} onChange={e => setQuery({ per_page: e.target.value, page: 1 })}>{[5, 10, 25, 50].map(n => <option key={n}>{n}</option>)}</select>
          </label>
          <div className="pager">
            <button disabled={!res.links.prev} onClick={() => setQuery({ page: page - 1 })} aria-label="السابق"><i className="fa-solid fa-chevron-right"></i></button>
            {Array.from({ length: meta.last_page }).map((_, i) => <button key={i} className={i + 1 === page ? 'on' : ''} onClick={() => setQuery({ page: i + 1 })}>{i + 1}</button>)}
            <button disabled={!res.links.next} onClick={() => setQuery({ page: page + 1 })} aria-label="التالي"><i className="fa-solid fa-chevron-left"></i></button>
          </div>
        </div>
      )}
    </div>
  );
}

function FilterSelect({ label, value, onChange, options }) {
  const on = value !== '' && value != null;
  return (
    <label className={'fchip ' + (on ? 'on' : '')}>
      <select value={value || ''} onChange={e => onChange(e.target.value)} aria-label={label}>
        <option value="">{label}</option>
        {options.map(o => <option key={o[0]} value={o[0]}>{o[1]}</option>)}
      </select>
      <i className="fa-solid fa-chevron-down car"></i>
    </label>
  );
}

// ---- Image uploader for single (category/brand logo) with constraints shown upfront
function SingleImageUpload({ value, onChange, rules = ['JPG · PNG · WebP', 'حتى 4MB', 'حتى 6000×6000', 'SVG غير مدعوم'], error }) {
  const [st, setSt] = useState(value ? 'done' : 'idle'); const [p, setP] = useState(0);
  const pick = () => {
    setSt('up'); setP(0);
    let v = 0; const t = setInterval(() => { v += 18; setP(Math.min(v, 100)); if (v >= 100) { clearInterval(t); setSt('done'); onChange && onChange(IMG.bag3); } }, 120);
  };
  if (st === 'done' && value) return (
    <div className="file">
      <div className="thumb" style={{ width: 64, height: 64 }}><img src={value} alt="" /></div>
      <div className="fmeta"><b className="ltr" style={{ textAlign: 'right' }}>category-cover.webp</b><small>1200×1200 · 312KB</small></div>
      <Btn kind="ghost" size="sm" onClick={pick}>استبدال</Btn>
      <button className="icon-btn danger" onClick={() => { onChange(null); setSt('idle'); }} aria-label="إزالة الصورة"><i className="fa-regular fa-trash-can"></i></button>
    </div>
  );
  return (
    <div>
      <div className="drop" onClick={st === 'idle' ? pick : undefined} style={error ? { borderColor: 'var(--danger)' } : null}>
        {st === 'up' ? <>
          <b>جاري الرفع… {p}%</b><div className="prog" style={{ width: 200 }}><i style={{ width: p + '%' }}></i></div>
        </> : <>
          <div className="di"><i className="fa-solid fa-arrow-up-from-bracket"></i></div>
          <b>اسحب الصورة هنا أو اضغط للاختيار</b>
          <div className="rules">{rules.map(r => <span key={r}>{r}</span>)}</div>
        </>}
      </div>
      {error && <div className="field-err" style={{ marginTop: 6 }}><i className="fa-solid fa-circle-exclamation"></i>{error}</div>}
    </div>
  );
}

function Toasts({ list }) {
  return <div className="toasts">{list.map(t => <div key={t.id} className={'toast ' + (t.tone || '')}><i className={t.tone === 'err' ? 'fa-solid fa-circle-exclamation' : 'fa-solid fa-circle-check'}></i>{t.text}</div>)}</div>;
}

const LogoMark = ({ size = 22 }) => (
  <svg viewBox="0 0 24 24" width={size} height={size} aria-hidden="true">
    <path d="M5 9h14l-1.2 11.2a1 1 0 0 1-1 .8H7.2a1 1 0 0 1-1-.8z" fill="#111" />
    <path d="M9 9V7a3 3 0 0 1 6 0v2" fill="none" stroke="#111" strokeWidth="1.6" />
    <path d="M8.5 13.5 12 17l3.5-3.5" fill="none" stroke="#fff" strokeWidth="1.4" />
  </svg>
);

Object.assign(window, { AppCtx, useApp, Field, Input, TextArea, PasswordInput, Toggle, Check, Badge, StatusBadge, Btn, SearchSelect,
  EmptyState, ErrorState, NoAccessState, Alert, ConfirmModal, Drawer, PageHeader, DataTable, FilterSelect, SingleImageUpload, Toasts, LogoMark });
// tweaks-panel.jsx
// Reusable Tweaks shell + form-control helpers.
//
// Owns the host protocol (listens for __activate_edit_mode / __deactivate_edit_mode,
// posts __edit_mode_available / __edit_mode_set_keys / __edit_mode_dismissed) so
// individual prototypes don't re-roll it. Ships a consistent set of controls so you
// don't hand-draw <input type="range">, segmented radios, steppers, etc.
//
// Usage (in an HTML file that loads React + Babel):
//
//   const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
//     "primaryColor": "#7A5AE0",
//     "palette": ["#7A5AE0", "#29261b", "#f6f4ef"],
//     "fontSize": 16,
//     "density": "regular",
//     "dark": false
//   }/*EDITMODE-END*/;
//
//   function App() {
//     const [t, setTweak] = useTweaks(TWEAK_DEFAULTS);
//     return (
//       <div style={{ fontSize: t.fontSize, color: t.primaryColor }}>
//         Hello
//         <TweaksPanel>
//           <TweakSection label="Typography" />
//           <TweakSlider label="Font size" value={t.fontSize} min={10} max={32} unit="px"
//                        onChange={(v) => setTweak('fontSize', v)} />
//           <TweakRadio  label="Density" value={t.density}
//                        options={['compact', 'regular', 'comfy']}
//                        onChange={(v) => setTweak('density', v)} />
//           <TweakSection label="Theme" />
//           <TweakColor  label="Primary" value={t.primaryColor}
//                        options={['#0F766E', '#2A6FDB', '#1F8A5B', '#7A5AE0']}
//                        onChange={(v) => setTweak('primaryColor', v)} />
//           <TweakColor  label="Palette" value={t.palette}
//                        options={[['#7A5AE0', '#29261b', '#f6f4ef'],
//                                  ['#475569', '#0f172a', '#f1f5f9']]}
//                        onChange={(v) => setTweak('palette', v)} />
//           <TweakToggle label="Dark mode" value={t.dark}
//                        onChange={(v) => setTweak('dark', v)} />
//         </TweaksPanel>
//       </div>
//     );
//   }
//
// ─────────────────────────────────────────────────────────────────────────────

const __TWEAKS_STYLE = `
  .twk-panel{position:fixed;right:16px;bottom:16px;z-index:2147483646;width:280px;
    max-height:calc(100vh - 32px);display:flex;flex-direction:column;
    transform:scale(var(--dc-inv-zoom,1));transform-origin:bottom right;
    background:rgba(250,249,247,.78);color:#29261b;
    -webkit-backdrop-filter:blur(24px) saturate(160%);backdrop-filter:blur(24px) saturate(160%);
    border:.5px solid rgba(255,255,255,.6);border-radius:14px;
    box-shadow:0 1px 0 rgba(255,255,255,.5) inset,0 12px 40px rgba(0,0,0,.18);
    font:11.5px/1.4 ui-sans-serif,system-ui,-apple-system,sans-serif;overflow:hidden}
  .twk-hd{display:flex;align-items:center;justify-content:space-between;
    padding:10px 8px 10px 14px;cursor:move;user-select:none}
  .twk-hd b{font-size:12px;font-weight:600;letter-spacing:.01em}
  .twk-x{appearance:none;border:0;background:transparent;color:rgba(41,38,27,.55);
    width:22px;height:22px;border-radius:6px;cursor:default;font-size:13px;line-height:1}
  .twk-x:hover{background:rgba(0,0,0,.06);color:#29261b}
  .twk-body{padding:2px 14px 14px;display:flex;flex-direction:column;gap:10px;
    overflow-y:auto;overflow-x:hidden;min-height:0;
    scrollbar-width:thin;scrollbar-color:rgba(0,0,0,.15) transparent}
  .twk-body::-webkit-scrollbar{width:8px}
  .twk-body::-webkit-scrollbar-track{background:transparent;margin:2px}
  .twk-body::-webkit-scrollbar-thumb{background:rgba(0,0,0,.15);border-radius:4px;
    border:2px solid transparent;background-clip:content-box}
  .twk-body::-webkit-scrollbar-thumb:hover{background:rgba(0,0,0,.25);
    border:2px solid transparent;background-clip:content-box}
  .twk-row{display:flex;flex-direction:column;gap:5px}
  .twk-row-h{flex-direction:row;align-items:center;justify-content:space-between;gap:10px}
  .twk-lbl{display:flex;justify-content:space-between;align-items:baseline;
    color:rgba(41,38,27,.72)}
  .twk-lbl>span:first-child{font-weight:500}
  .twk-val{color:rgba(41,38,27,.5);font-variant-numeric:tabular-nums}

  .twk-sect{font-size:10px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;
    color:rgba(41,38,27,.45);padding:10px 0 0}
  .twk-sect:first-child{padding-top:0}

  .twk-field{appearance:none;box-sizing:border-box;width:100%;min-width:0;height:26px;padding:0 8px;
    border:.5px solid rgba(0,0,0,.1);border-radius:7px;
    background:rgba(255,255,255,.6);color:inherit;font:inherit;outline:none}
  .twk-field:focus{border-color:rgba(0,0,0,.25);background:rgba(255,255,255,.85)}
  select.twk-field{padding-right:22px;
    background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'><path fill='rgba(0,0,0,.5)' d='M0 0h10L5 6z'/></svg>");
    background-repeat:no-repeat;background-position:right 8px center}

  .twk-slider{appearance:none;-webkit-appearance:none;width:100%;height:4px;margin:6px 0;
    border-radius:999px;background:rgba(0,0,0,.12);outline:none}
  .twk-slider::-webkit-slider-thumb{-webkit-appearance:none;appearance:none;
    width:14px;height:14px;border-radius:50%;background:#fff;
    border:.5px solid rgba(0,0,0,.12);box-shadow:0 1px 3px rgba(0,0,0,.2);cursor:default}
  .twk-slider::-moz-range-thumb{width:14px;height:14px;border-radius:50%;
    background:#fff;border:.5px solid rgba(0,0,0,.12);box-shadow:0 1px 3px rgba(0,0,0,.2);cursor:default}

  .twk-seg{position:relative;display:flex;padding:2px;border-radius:8px;
    background:rgba(0,0,0,.06);user-select:none}
  .twk-seg-thumb{position:absolute;top:2px;bottom:2px;border-radius:6px;
    background:rgba(255,255,255,.9);box-shadow:0 1px 2px rgba(0,0,0,.12);
    transition:left .15s cubic-bezier(.3,.7,.4,1),width .15s}
  .twk-seg.dragging .twk-seg-thumb{transition:none}
  .twk-seg button{appearance:none;position:relative;z-index:1;flex:1;border:0;
    background:transparent;color:inherit;font:inherit;font-weight:500;min-height:22px;
    border-radius:6px;cursor:default;padding:4px 6px;line-height:1.2;
    overflow-wrap:anywhere}

  .twk-toggle{position:relative;width:32px;height:18px;border:0;border-radius:999px;
    background:rgba(0,0,0,.15);transition:background .15s;cursor:default;padding:0}
  .twk-toggle[data-on="1"]{background:#34c759}
  .twk-toggle i{position:absolute;top:2px;left:2px;width:14px;height:14px;border-radius:50%;
    background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.25);transition:transform .15s}
  .twk-toggle[data-on="1"] i{transform:translateX(14px)}

  .twk-num{display:flex;align-items:center;box-sizing:border-box;min-width:0;height:26px;padding:0 0 0 8px;
    border:.5px solid rgba(0,0,0,.1);border-radius:7px;background:rgba(255,255,255,.6)}
  .twk-num-lbl{font-weight:500;color:rgba(41,38,27,.6);cursor:ew-resize;
    user-select:none;padding-right:8px}
  .twk-num input{flex:1;min-width:0;height:100%;border:0;background:transparent;
    font:inherit;font-variant-numeric:tabular-nums;text-align:right;padding:0 8px 0 0;
    outline:none;color:inherit;-moz-appearance:textfield}
  .twk-num input::-webkit-inner-spin-button,.twk-num input::-webkit-outer-spin-button{
    -webkit-appearance:none;margin:0}
  .twk-num-unit{padding-right:8px;color:rgba(41,38,27,.45)}

  .twk-btn{appearance:none;height:26px;padding:0 12px;border:0;border-radius:7px;
    background:rgba(0,0,0,.78);color:#fff;font:inherit;font-weight:500;cursor:default}
  .twk-btn:hover{background:rgba(0,0,0,.88)}
  .twk-btn.secondary{background:rgba(0,0,0,.06);color:inherit}
  .twk-btn.secondary:hover{background:rgba(0,0,0,.1)}

  .twk-swatch{appearance:none;-webkit-appearance:none;width:56px;height:22px;
    border:.5px solid rgba(0,0,0,.1);border-radius:6px;padding:0;cursor:default;
    background:transparent;flex-shrink:0}
  .twk-swatch::-webkit-color-swatch-wrapper{padding:0}
  .twk-swatch::-webkit-color-swatch{border:0;border-radius:5.5px}
  .twk-swatch::-moz-color-swatch{border:0;border-radius:5.5px}

  .twk-chips{display:flex;gap:6px}
  .twk-chip{position:relative;appearance:none;flex:1;min-width:0;height:46px;
    padding:0;border:0;border-radius:6px;overflow:hidden;cursor:default;
    box-shadow:0 0 0 .5px rgba(0,0,0,.12),0 1px 2px rgba(0,0,0,.06);
    transition:transform .12s cubic-bezier(.3,.7,.4,1),box-shadow .12s}
  .twk-chip:hover{transform:translateY(-1px);
    box-shadow:0 0 0 .5px rgba(0,0,0,.18),0 4px 10px rgba(0,0,0,.12)}
  .twk-chip[data-on="1"]{box-shadow:0 0 0 1.5px rgba(0,0,0,.85),
    0 2px 6px rgba(0,0,0,.15)}
  .twk-chip>span{position:absolute;top:0;bottom:0;right:0;width:34%;
    display:flex;flex-direction:column;box-shadow:-1px 0 0 rgba(0,0,0,.1)}
  .twk-chip>span>i{flex:1;box-shadow:0 -1px 0 rgba(0,0,0,.1)}
  .twk-chip>span>i:first-child{box-shadow:none}
  .twk-chip svg{position:absolute;top:6px;left:6px;width:13px;height:13px;
    filter:drop-shadow(0 1px 1px rgba(0,0,0,.3))}
`;

// ── useTweaks ───────────────────────────────────────────────────────────────
// Single source of truth for tweak values. setTweak persists via the host
// (__edit_mode_set_keys → host rewrites the EDITMODE block on disk).
function useTweaks(defaults) {
  const [values, setValues] = React.useState(defaults);
  const setTweak = React.useCallback((keyOrEdits, val) => {
    const edits = typeof keyOrEdits === 'object' && keyOrEdits !== null
      ? keyOrEdits : { [keyOrEdits]: val };
    setValues((prev) => ({ ...prev, ...edits }));
    window.parent.postMessage({ type: '__edit_mode_set_keys', edits }, '*');
    window.dispatchEvent(new CustomEvent('tweakchange', { detail: edits }));
  }, []);
  return [values, setTweak];
}

// ── TweaksPanel ─────────────────────────────────────────────────────────────
// Floating shell. Registers the protocol listener BEFORE announcing
// availability — if the announce ran first, the host's activate could land
// before our handler exists and the toolbar toggle would silently no-op.
// The close button posts __edit_mode_dismissed so the host's toolbar toggle
// flips off in lockstep; the host echoes __deactivate_edit_mode back which
// is what actually hides the panel.
function TweaksPanel({ title = 'Tweaks', noDeckControls = false, children }) {
  const [open, setOpen] = React.useState(false);
  const dragRef = React.useRef(null);
  // Auto-inject a rail toggle when a <deck-stage> is on the page. The
  // toggle drives the deck's per-viewer _railVisible via window message;
  // state is mirrored from the same localStorage key the deck reads so
  // the control reflects reality across reloads. The mechanism is the
  // message — authors who want custom placement can post it directly
  // and pass noDeckControls to suppress this one.
  const hasDeckStage = React.useMemo(
    () => typeof document !== 'undefined' && !!document.querySelector('deck-stage'),
    [],
  );
  // deck-stage enables its rail in connectedCallback, but this panel can
  // mount before that element has upgraded. The initial read catches the
  // common case; the listener covers mounting first. (Older deck-stage.js
  // copies still wait for the host's __omelette_rail_enabled postMessage —
  // same listener handles those.)
  const [railEnabled, setRailEnabled] = React.useState(
    () => hasDeckStage && !!document.querySelector('deck-stage')?._railEnabled,
  );
  React.useEffect(() => {
    if (!hasDeckStage || railEnabled) return undefined;
    const onMsg = (e) => {
      if (e.data && e.data.type === '__omelette_rail_enabled') setRailEnabled(true);
    };
    window.addEventListener('message', onMsg);
    return () => window.removeEventListener('message', onMsg);
  }, [hasDeckStage, railEnabled]);
  const [railVisible, setRailVisible] = React.useState(() => {
    try { return localStorage.getItem('deck-stage.railVisible') !== '0'; } catch (e) { return true; }
  });
  const toggleRail = (on) => {
    setRailVisible(on);
    window.postMessage({ type: '__deck_rail_visible', on }, '*');
  };
  const offsetRef = React.useRef({ x: 16, y: 16 });
  const PAD = 16;

  const clampToViewport = React.useCallback(() => {
    const panel = dragRef.current;
    if (!panel) return;
    const w = panel.offsetWidth, h = panel.offsetHeight;
    const maxRight = Math.max(PAD, window.innerWidth - w - PAD);
    const maxBottom = Math.max(PAD, window.innerHeight - h - PAD);
    offsetRef.current = {
      x: Math.min(maxRight, Math.max(PAD, offsetRef.current.x)),
      y: Math.min(maxBottom, Math.max(PAD, offsetRef.current.y)),
    };
    panel.style.right = offsetRef.current.x + 'px';
    panel.style.bottom = offsetRef.current.y + 'px';
  }, []);

  React.useEffect(() => {
    if (!open) return;
    clampToViewport();
    if (typeof ResizeObserver === 'undefined') {
      window.addEventListener('resize', clampToViewport);
      return () => window.removeEventListener('resize', clampToViewport);
    }
    const ro = new ResizeObserver(clampToViewport);
    ro.observe(document.documentElement);
    return () => ro.disconnect();
  }, [open, clampToViewport]);

  React.useEffect(() => {
    const onMsg = (e) => {
      const t = e?.data?.type;
      if (t === '__activate_edit_mode') setOpen(true);
      else if (t === '__deactivate_edit_mode') setOpen(false);
    };
    window.addEventListener('message', onMsg);
    window.parent.postMessage({ type: '__edit_mode_available' }, '*');
    return () => window.removeEventListener('message', onMsg);
  }, []);

  const dismiss = () => {
    setOpen(false);
    window.parent.postMessage({ type: '__edit_mode_dismissed' }, '*');
  };

  const onDragStart = (e) => {
    const panel = dragRef.current;
    if (!panel) return;
    const r = panel.getBoundingClientRect();
    const sx = e.clientX, sy = e.clientY;
    const startRight = window.innerWidth - r.right;
    const startBottom = window.innerHeight - r.bottom;
    const move = (ev) => {
      offsetRef.current = {
        x: startRight - (ev.clientX - sx),
        y: startBottom - (ev.clientY - sy),
      };
      clampToViewport();
    };
    const up = () => {
      window.removeEventListener('mousemove', move);
      window.removeEventListener('mouseup', up);
    };
    window.addEventListener('mousemove', move);
    window.addEventListener('mouseup', up);
  };

  if (!open) return null;
  return (
    <>
      <style>{__TWEAKS_STYLE}</style>
      <div ref={dragRef} className="twk-panel" data-noncommentable=""
           style={{ right: offsetRef.current.x, bottom: offsetRef.current.y }}>
        <div className="twk-hd" onMouseDown={onDragStart}>
          <b>{title}</b>
          <button className="twk-x" aria-label="Close tweaks"
                  onMouseDown={(e) => e.stopPropagation()}
                  onClick={dismiss}>✕</button>
        </div>
        <div className="twk-body">
          {children}
          {hasDeckStage && railEnabled && !noDeckControls && (
            <TweakSection label="Deck">
              <TweakToggle label="Thumbnail rail" value={railVisible} onChange={toggleRail} />
            </TweakSection>
          )}
        </div>
      </div>
    </>
  );
}

// ── Layout helpers ──────────────────────────────────────────────────────────

function TweakSection({ label, children }) {
  return (
    <>
      <div className="twk-sect">{label}</div>
      {children}
    </>
  );
}

function TweakRow({ label, value, children, inline = false }) {
  return (
    <div className={inline ? 'twk-row twk-row-h' : 'twk-row'}>
      <div className="twk-lbl">
        <span>{label}</span>
        {value != null && <span className="twk-val">{value}</span>}
      </div>
      {children}
    </div>
  );
}

// ── Controls ────────────────────────────────────────────────────────────────

function TweakSlider({ label, value, min = 0, max = 100, step = 1, unit = '', onChange }) {
  return (
    <TweakRow label={label} value={`${value}${unit}`}>
      <input type="range" className="twk-slider" min={min} max={max} step={step}
             value={value} onChange={(e) => onChange(Number(e.target.value))} />
    </TweakRow>
  );
}

function TweakToggle({ label, value, onChange }) {
  return (
    <div className="twk-row twk-row-h">
      <div className="twk-lbl"><span>{label}</span></div>
      <button type="button" className="twk-toggle" data-on={value ? '1' : '0'}
              role="switch" aria-checked={!!value}
              onClick={() => onChange(!value)}><i /></button>
    </div>
  );
}

function TweakRadio({ label, value, options, onChange }) {
  const trackRef = React.useRef(null);
  const [dragging, setDragging] = React.useState(false);
  // The active value is read by pointer-move handlers attached for the lifetime
  // of a drag — ref it so a stale closure doesn't fire onChange for every move.
  const valueRef = React.useRef(value);
  valueRef.current = value;

  // Segments wrap mid-word once per-segment width runs out. The track is
  // ~248px (280 panel − 28 body pad − 4 seg pad), each button loses 12px
  // to its own padding, and 11.5px system-ui averages ~6.3px/char — so 2
  // options fit ~16 chars each, 3 fit ~10. Past that (or >3 options), fall
  // back to a dropdown rather than wrap.
  const labelLen = (o) => String(typeof o === 'object' ? o.label : o).length;
  const maxLen = options.reduce((m, o) => Math.max(m, labelLen(o)), 0);
  const fitsAsSegments = maxLen <= ({ 2: 16, 3: 10 }[options.length] ?? 0);
  if (!fitsAsSegments) {
    // <select> emits strings — map back to the original option value so the
    // fallback stays type-preserving (numbers, booleans) like the segment path.
    const resolve = (s) => {
      const m = options.find((o) => String(typeof o === 'object' ? o.value : o) === s);
      return m === undefined ? s : typeof m === 'object' ? m.value : m;
    };
    return <TweakSelect label={label} value={value} options={options}
                        onChange={(s) => onChange(resolve(s))} />;
  }
  const opts = options.map((o) => (typeof o === 'object' ? o : { value: o, label: o }));
  const idx = Math.max(0, opts.findIndex((o) => o.value === value));
  const n = opts.length;

  const segAt = (clientX) => {
    const r = trackRef.current.getBoundingClientRect();
    const inner = r.width - 4;
    const i = Math.floor(((clientX - r.left - 2) / inner) * n);
    return opts[Math.max(0, Math.min(n - 1, i))].value;
  };

  const onPointerDown = (e) => {
    setDragging(true);
    const v0 = segAt(e.clientX);
    if (v0 !== valueRef.current) onChange(v0);
    const move = (ev) => {
      if (!trackRef.current) return;
      const v = segAt(ev.clientX);
      if (v !== valueRef.current) onChange(v);
    };
    const up = () => {
      setDragging(false);
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', up);
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
  };

  return (
    <TweakRow label={label}>
      <div ref={trackRef} role="radiogroup" onPointerDown={onPointerDown}
           className={dragging ? 'twk-seg dragging' : 'twk-seg'}>
        <div className="twk-seg-thumb"
             style={{ left: `calc(2px + ${idx} * (100% - 4px) / ${n})`,
                      width: `calc((100% - 4px) / ${n})` }} />
        {opts.map((o) => (
          <button key={o.value} type="button" role="radio" aria-checked={o.value === value}>
            {o.label}
          </button>
        ))}
      </div>
    </TweakRow>
  );
}

function TweakSelect({ label, value, options, onChange }) {
  return (
    <TweakRow label={label}>
      <select className="twk-field" value={value} onChange={(e) => onChange(e.target.value)}>
        {options.map((o) => {
          const v = typeof o === 'object' ? o.value : o;
          const l = typeof o === 'object' ? o.label : o;
          return <option key={v} value={v}>{l}</option>;
        })}
      </select>
    </TweakRow>
  );
}

function TweakText({ label, value, placeholder, onChange }) {
  return (
    <TweakRow label={label}>
      <input className="twk-field" type="text" value={value} placeholder={placeholder}
             onChange={(e) => onChange(e.target.value)} />
    </TweakRow>
  );
}

function TweakNumber({ label, value, min, max, step = 1, unit = '', onChange }) {
  const clamp = (n) => {
    if (min != null && n < min) return min;
    if (max != null && n > max) return max;
    return n;
  };
  const startRef = React.useRef({ x: 0, val: 0 });
  const onScrubStart = (e) => {
    e.preventDefault();
    startRef.current = { x: e.clientX, val: value };
    const decimals = (String(step).split('.')[1] || '').length;
    const move = (ev) => {
      const dx = ev.clientX - startRef.current.x;
      const raw = startRef.current.val + dx * step;
      const snapped = Math.round(raw / step) * step;
      onChange(clamp(Number(snapped.toFixed(decimals))));
    };
    const up = () => {
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', up);
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
  };
  return (
    <div className="twk-num">
      <span className="twk-num-lbl" onPointerDown={onScrubStart}>{label}</span>
      <input type="number" value={value} min={min} max={max} step={step}
             onChange={(e) => onChange(clamp(Number(e.target.value)))} />
      {unit && <span className="twk-num-unit">{unit}</span>}
    </div>
  );
}

// Relative-luminance contrast pick — checkmarks drawn over a swatch need to
// read on both #111 and #fafafa without per-option configuration. Hex input
// only (#rgb / #rrggbb); named or rgb()/hsl() colors fall through to "light".
function __twkIsLight(hex) {
  const h = String(hex).replace('#', '');
  const x = h.length === 3 ? h.replace(/./g, (c) => c + c) : h.padEnd(6, '0');
  const n = parseInt(x.slice(0, 6), 16);
  if (Number.isNaN(n)) return true;
  const r = (n >> 16) & 255, g = (n >> 8) & 255, b = n & 255;
  return r * 299 + g * 587 + b * 114 > 148000;
}

const __TwkCheck = ({ light }) => (
  <svg viewBox="0 0 14 14" aria-hidden="true">
    <path d="M3 7.2 5.8 10 11 4.2" fill="none" strokeWidth="2.2"
          strokeLinecap="round" strokeLinejoin="round"
          stroke={light ? 'rgba(0,0,0,.78)' : '#fff'} />
  </svg>
);

// TweakColor — curated color/palette picker. Each option is either a single
// hex string or an array of 1-5 hex strings; the card adapts — a lone color
// renders solid, a palette renders colors[0] as the hero (left ~2/3) with the
// rest stacked in a sharp column on the right. onChange emits the
// option in the shape it was passed (string stays string, array stays array).
// Without options it falls back to the native color input for back-compat.
function TweakColor({ label, value, options, onChange }) {
  if (!options || !options.length) {
    return (
      <div className="twk-row twk-row-h">
        <div className="twk-lbl"><span>{label}</span></div>
        <input type="color" className="twk-swatch" value={value}
               onChange={(e) => onChange(e.target.value)} />
      </div>
    );
  }
  // Native <input type=color> emits lowercase hex per the HTML spec, so
  // compare case-insensitively. String() guards JSON.stringify(undefined),
  // which returns the primitive undefined (no .toLowerCase).
  const key = (o) => String(JSON.stringify(o)).toLowerCase();
  const cur = key(value);
  return (
    <TweakRow label={label}>
      <div className="twk-chips" role="radiogroup">
        {options.map((o, i) => {
          const colors = Array.isArray(o) ? o : [o];
          const [hero, ...rest] = colors;
          const sup = rest.slice(0, 4);
          const on = key(o) === cur;
          return (
            <button key={i} type="button" className="twk-chip" role="radio"
                    aria-checked={on} data-on={on ? '1' : '0'}
                    aria-label={colors.join(', ')} title={colors.join(' · ')}
                    style={{ background: hero }}
                    onClick={() => onChange(o)}>
              {sup.length > 0 && (
                <span>
                  {sup.map((c, j) => <i key={j} style={{ background: c }} />)}
                </span>
              )}
              {on && <__TwkCheck light={__twkIsLight(hero)} />}
            </button>
          );
        })}
      </div>
    </TweakRow>
  );
}

function TweakButton({ label, onClick, secondary = false }) {
  return (
    <button type="button" className={secondary ? 'twk-btn secondary' : 'twk-btn'}
            onClick={onClick}>{label}</button>
  );
}

Object.assign(window, {
  useTweaks, TweaksPanel, TweakSection, TweakRow,
  TweakSlider, TweakToggle, TweakRadio, TweakSelect,
  TweakText, TweakNumber, TweakColor, TweakButton,
});

// ── TweakSuggestionBar (flag-gated addon) ───────────────────────────────────
(function () {
  const s = document.createElement('style');
  s.textContent = `
    @keyframes twk-blink{50%{opacity:0}}
    @keyframes twk-fadein{from{opacity:0;transform:translateX(4px)}to{opacity:1;transform:none}}
    .twk-sugg{display:flex;align-items:center;gap:6px;padding:5px 8px;border-radius:8px;
      background:rgba(0,0,0,.04);border:.5px solid rgba(0,0,0,.06);transition:all .15s}
    .twk-sugg:focus-within{background:rgba(0,0,0,.06);border-color:rgba(0,0,0,.12)}
    .twk-sugg-field{position:relative;flex:1;min-width:0}
    .twk-sugg-field input{width:100%;height:20px;border:0;background:transparent;
      font:inherit;outline:none;color:inherit}
    .twk-sugg-ghost{position:absolute;inset:0;display:flex;align-items:center;
      color:rgba(41,38,27,.42);pointer-events:none;white-space:nowrap;overflow:hidden}
    .twk-sugg-ghost.hint{color:rgba(41,38,27,.28)}
    .twk-sugg-caret{display:inline-block;width:1px;height:13px;margin-left:1px;
      border-right:1.5px solid currentColor;opacity:.5;animation:twk-blink 1s step-end infinite}
    .twk-sugg-ideas{appearance:none;border:0;background:transparent;font:inherit;
      font-size:10.5px;font-weight:600;color:rgba(41,38,27,.6);cursor:default;padding:0 2px;
      white-space:nowrap;animation:twk-fadein .25s ease}
    .twk-sugg-ideas:hover{color:rgba(41,38,27,.85)}
    .twk-sugg-ideas svg{color:#7A5AE0}
    .twk-sugg-send{appearance:none;border:0;height:20px;padding:0 8px;border-radius:5px;
      background:#29261b;color:#fff;font:inherit;font-size:10px;font-weight:600;cursor:default}
  `;
  document.head.appendChild(s);
})();

const __twkSendChat = (text) =>
  window.parent.postMessage({ type: '__edit_mode_chat', text }, '*');

const __TWK_SPARK_STAR = 'M14.8299 10.378C15.1555 10.499 15.1827 10.9337 14.8747 11.0918L11.401 12.8747C11.3125 12.9202 11.2445 12.9955 11.2099 13.0864L9.95228 16.3963C9.82177 16.7397 9.31884 16.7397 9.18834 16.3963L7.93069 13.0864C7.89616 12.9955 7.82814 12.9202 7.73965 12.8747L4.2659 11.0918C3.95787 10.9337 3.98513 10.499 4.31067 10.378L7.69556 9.11947C7.81035 9.0768 7.89899 8.98625 7.93669 8.87317L9.18301 5.13503C9.30417 4.77165 9.83645 4.77165 9.9576 5.13503L11.2039 8.87317C11.2416 8.98626 11.3303 9.0768 11.4451 9.11947L14.8299 10.378Z';
const __TWK_SPARK_DOT = 'M6.0114 3.58637C6.35897 3.71564 6.35897 4.19061 6.0114 4.31988L4.98024 4.70337C4.86547 4.74605 4.77685 4.83659 4.73915 4.94965L4.33943 6.14853C4.21828 6.51191 3.686 6.51191 3.56485 6.14853L3.16513 4.94965C3.12743 4.83659 3.03881 4.74605 2.92404 4.70337L1.89288 4.31988C1.54531 4.19061 1.54531 3.71564 1.89288 3.58637L2.92404 3.20288C3.03881 3.1602 3.12743 3.06966 3.16513 2.9566L3.56485 1.75772C3.686 1.39434 4.21828 1.39434 4.33943 1.75772L4.73915 2.9566C4.77685 3.06966 4.86547 3.1602 4.98024 3.20288L6.0114 3.58637Z';

function __TwkSpark({ size = 12 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 17 18" fill="currentColor"
         style={{ display: 'inline-block', verticalAlign: '-1px' }}>
      <path d={__TWK_SPARK_STAR} />
      <path d={__TWK_SPARK_DOT} />
    </svg>
  );
}

// Typewriter-cycles through `suggestions`. Clicking the field while a
// suggestion is animating freezes it as ghost text; Tab accepts it into the
// input. Enter posts __edit_mode_chat (host drops the text into the chat
// composer for the user to send). After the cycle the static placeholder
// types in and "Ideas" appears — clicking asks for three more suggestions.
function TweakSuggestionBar({
  suggestions = [],
  placeholder = 'Describe a tweak…',
  ideasPrompt = 'Suggest three more tweak ideas for this design and update the suggestions on TweakSuggestionBar.',
}) {
  const [val, setVal] = React.useState('');
  const [ghost, setGhost] = React.useState('');
  const [focused, setFocused] = React.useState(false);
  const inputRef = React.useRef(null);
  const tw = useTwkTypewriter(suggestions, { placeholder, enabled: !val && !ghost && !focused });

  const freeze = () => {
    tw.markPlayed();
    if (val || ghost) return;
    const target = !tw.done ? suggestions[tw.idx] : '';
    if (target) setGhost(target);
    inputRef.current?.focus();
  };

  const submit = () => {
    const v = (val || ghost).trim();
    if (!v) return;
    __twkSendChat(v);
    setVal('');
    setGhost('');
  };

  const onKeyDown = (e) => {
    if (e.key === 'Tab' && ghost && !val) {
      e.preventDefault();
      setVal(ghost);
      setGhost('');
    } else if (e.key === 'Enter') {
      e.preventDefault();
      submit();
    } else if (e.key === 'Escape') {
      setGhost('');
    }
  };

  const requestIdeas = () => __twkSendChat(ideasPrompt);

  const showAnim = !val && !ghost && !focused && !tw.done;
  const showStatic = !val && !ghost && !focused && tw.done;

  return (
    <div className="twk-sugg" onMouseDown={freeze}>
      <div className="twk-sugg-field">
        <input
          ref={inputRef}
          value={val}
          placeholder={focused && !ghost ? placeholder : ''}
          onChange={(e) => { setVal(e.target.value); setGhost(''); }}
          onFocus={() => { setFocused(true); tw.markPlayed(); }}
          onBlur={() => { setFocused(false); if (!val) setGhost(''); }}
          onKeyDown={onKeyDown}
        />
        {showAnim && (
          <div className="twk-sugg-ghost">
            {tw.text}<span className="twk-sugg-caret" />
          </div>
        )}
        {showStatic && (
          <div className="twk-sugg-ghost">
            {tw.tail}{tw.tail.length < placeholder.length && <span className="twk-sugg-caret" />}
          </div>
        )}
        {ghost && !val && (
          <div className="twk-sugg-ghost hint">{ghost}</div>
        )}
      </div>
      {val || ghost ? (
        <button className="twk-sugg-send"
                onMouseDown={(e) => { e.stopPropagation(); e.preventDefault(); }}
                onClick={submit}>
          Add
        </button>
      ) : tw.done && !focused ? (
        <button className="twk-sugg-ideas"
                onMouseDown={(e) => { e.stopPropagation(); e.preventDefault(); }}
                onClick={requestIdeas}>
          Ideas <__TwkSpark />
        </button>
      ) : null}
    </div>
  );
}

// Minimal type→pause→erase cycler. Plays once per unique `items` content per
// session — a reload from a tweak-value write skips straight to done; a new
// suggestion set (after "Ideas") gets a fresh animation.
function useTwkTypewriter(items, { placeholder, typeMs = 35, eraseMs = 22, pauseMs = 1800, enabled = true } = {}) {
  // Dep mirrors the memo body verbatim so collidable joins like
  // ['a\nb','c'] vs ['a','b','c'] don't share a stale sessionStorage key
  // (gen-spark PR #28600 Bugbot R3 — minor divergence from Tiffany's
  // `items.join('\n')` dep; upstream report pending).
  const key = React.useMemo(() => '__twk_played:' + JSON.stringify(items), [JSON.stringify(items)]);
  const played = () => { try { return sessionStorage.getItem(key) === '1'; } catch { return false; } };

  const [text, setText] = React.useState('');
  const [tail, setTail] = React.useState(() => (items.length === 0 || played() ? placeholder : ''));
  const [idx, setIdx] = React.useState(0);
  const [done, setDone] = React.useState(() => items.length === 0 || played());
  const phase = React.useRef('type');
  const n = React.useRef(0);

  const markPlayed = React.useCallback(() => {
    try { sessionStorage.setItem(key, '1'); } catch {}
    setDone(true);
  }, [key]);

  React.useEffect(() => {
    const skip = items.length === 0 || played();
    setText(''); setIdx(0);
    setDone(skip);
    setTail(skip ? placeholder : '');
    phase.current = 'type'; n.current = 0;
  }, [key]);

  React.useEffect(() => {
    if (done || !enabled) return;
    const item = items[idx] ?? '';
    let t;
    const tick = () => {
      if (phase.current === 'type') {
        n.current++;
        setText(item.slice(0, n.current));
        if (n.current >= item.length) { phase.current = 'pause'; t = setTimeout(tick, pauseMs); }
        else t = setTimeout(tick, typeMs + Math.random() * 20);
      } else if (phase.current === 'pause') {
        phase.current = 'erase'; t = setTimeout(tick, eraseMs);
      } else {
        n.current--;
        setText(item.slice(0, n.current));
        if (n.current <= 0) {
          if (idx === items.length - 1) { markPlayed(); return; }
          phase.current = 'type'; setIdx((i) => i + 1);
        } else t = setTimeout(tick, eraseMs);
      }
    };
    phase.current = 'type'; n.current = 0; setText('');
    t = setTimeout(tick, 400);
    return () => clearTimeout(t);
  }, [idx, done, key, enabled, typeMs, eraseMs, pauseMs]);

  React.useEffect(() => {
    if (!done || tail === placeholder) return;
    let i = 0;
    const t = setInterval(() => {
      i++; setTail(placeholder.slice(0, i));
      if (i >= placeholder.length) clearInterval(t);
    }, 28);
    return () => clearInterval(t);
  }, [done, placeholder]);

  return { text, tail, idx, done, markPlayed };
}

Object.assign(window, { TweakSuggestionBar });// ===== Login, layout, router =====
function LoginPage() {
  // الدخول الموحد انتقل لتطبيق الويب — لوحة الأدمن تستقبل handoff عبر /auth/callback فقط
  useEffect(() => {
    const webUrl = (typeof import.meta !== 'undefined' && import.meta.env && import.meta.env.VITE_WEB_URL) || 'http://localhost:3000';
    window.location.replace(`${webUrl}/auth/login`);
  }, []);

  return null;
}

const NAV = [
  { g: 'الرئيسية', items: [
    ['/dashboard', 'fa-solid fa-chart-pie', 'لوحة المعلومات', null],
  ]},
  { g: 'الكتالوج', items: [
    ['/products', 'fa-solid fa-bag-shopping', 'المنتجات', 'products.view'],
    ['/categories', 'fa-solid fa-sitemap', 'الأقسام', 'categories.view'],
    ['/brands', 'fa-solid fa-copyright', 'البراندات', 'brands.view'],
  ]},
  { g: 'المبيعات والعملاء', items: [
    ['/orders', 'fa-solid fa-receipt', 'الطلبات والمبيعات', 'orders.view'],
    ['/customers', 'fa-solid fa-users', 'العملاء والجملة', 'customers.view'],
  ]},
  { g: 'التشغيل والمخزون', items: [
    ['/branches', 'fa-solid fa-store', 'الفروع والمخازن', 'branches.view'],
    ['/inventory', 'fa-solid fa-boxes-stacked', 'المخزون والتحويلات', 'inventory.view'],
    ['/purchases', 'fa-solid fa-file-invoice-dollar', 'أوامر الشراء', 'purchases.view'],
  ]},
  { g: 'المالية والتقارير', items: [
    ['/treasury', 'fa-solid fa-vault', 'الخزائن والمالية', 'treasuries.view'],
    ['/reports', 'fa-solid fa-chart-line', 'التقارير التحليلية', 'reports.view'],
  ]},
  { g: 'الإدارة والنظام', items: [
    ['/users', 'fa-solid fa-user-group', 'المستخدمون', 'users.view'],
    ['/roles', 'fa-solid fa-shield-halved', 'الأدوار والصلاحيات', 'roles.manage'],
    ['/settings', 'fa-solid fa-sliders', 'الإعدادات العامة', 'settings.view'],
  ]},
  { g: 'مرجع التصميم', items: [
    ['/kit', 'fa-solid fa-swatchbook', 'المكونات المشتركة', null],
  ]},
];

function Layout({ children }) {
  const { route, go, can, sessionExpired, setSessionExpired, tw } = useApp();
  const user = useAuthStore((s) => s.user);
  const logoutRemote = useAuthStore((s) => s.logoutRemote);
  const [cmdOpen, setCmdOpen] = useState(false);
  const [notifyOpen, setNotifyOpen] = useState(false);
  const base = '/' + (route.path.split('/')[1] || '');

  return (
    <div className="app">
      <aside className="side">
        <div className="side-logo"><LogoMark /> <div>ALFARID<small>ADMIN CONSOLE</small></div></div>
        <nav className="side-nav">
          {NAV.map(g => {
            const items = g.items.filter(it => tw.navMode === 'show-locked' || !it[3] || can(it[3]));
            if (!items.length) return null;
            return <div key={g.g}><div className="side-group">{g.g}</div>
              {items.map(it => {
                const locked = it[3] && !can(it[3]);
                return <button key={it[0]} className={'side-item ' + (base === it[0] ? 'active' : '')} onClick={() => go(it[0])}>
                  <i className={it[1]}></i>{it[2]}{locked && <i className="fa-solid fa-lock lock"></i>}
                </button>;
              })}
            </div>;
          })}
        </nav>
        <div className="side-foot">
          <div className="avatar">{user?.name?.slice(0, 2)}</div>
          <div className="who"><b>{user?.name}</b><span>{user?.roles.join('، ')}</span></div>
          <button className="icon-btn" title="تسجيل الخروج" onClick={logoutRemote}><i className="fa-solid fa-arrow-right-from-bracket"></i></button>
        </div>
      </aside>

      <main className="main" onClick={() => notifyOpen && setNotifyOpen(false)}>
        <div className="topbar">
          <span className="branch-chip" style={{ cursor: 'pointer' }} onClick={() => go('/branches')} title="عرض تفاصيل الفروع">
            <i className="fa-solid fa-store muted"></i>كل الفروع <Badge tone="accent" nodot>امتياز صريح</Badge>
          </span>
          <div className="sp"></div>
          <span className="muted" style={{ fontSize: 12, display: 'flex', alignItems: 'center', gap: 6 }}><span className="session-dot"></span>جلسة الإدارة</span>

          <button className="ico-btn" aria-label="بحث سريع" onClick={() => setCmdOpen(true)} title="بحث سريع (Ctrl + K)">
            <i className="fa-solid fa-magnifying-glass"></i>
          </button>
          <span className="cmd-esc" style={{ fontSize: 10, cursor: 'pointer', padding: '2px 6px' }} onClick={() => setCmdOpen(true)} title="البحث السريع">Ctrl + K</span>

          <div style={{ position: 'relative' }}>
            <button className="ico-btn" aria-label="الإشعارات" onClick={(e) => { e.stopPropagation(); setNotifyOpen(o => !o); }} title="الإشعارات والتنبيهات">
              <i className="fa-regular fa-bell"></i>
              <span style={{ position: 'absolute', top: 5, right: 6, width: 7, height: 7, borderRadius: '50%', background: 'var(--accent)' }}></span>
            </button>
            <NotificationsPopover open={notifyOpen} onClose={() => setNotifyOpen(false)} go={go} />
          </div>
        </div>

        {children}
      </main>

      <CommandPalette open={cmdOpen} onClose={() => setCmdOpen(false)} go={go} />

      {sessionExpired && (
        <div className="scrim">
          <div className="modal">
            <div className="modal-b"><div className="mi warn"><i className="fa-solid fa-clock-rotate-left"></i></div>
              <div><h4>انتهت الجلسة</h4><p>لحمايتك تم تسجيل خروجك بعد فترة من عدم النشاط. سجّل الدخول مجددًا وستعود لنفس الصفحة، والمسودة المحلية محفوظة.</p><span className="mono muted">401 Unauthorized</span></div></div>
            <div className="modal-f"><Btn onClick={() => { setSessionExpired(false); go('/login'); }}>تسجيل الدخول</Btn></div>
          </div>
        </div>
      )}
    </div>
  );
}

const TWEAK_DEFAULTS = (typeof window !== 'undefined' && window.ALFARID_TWEAKS) || {
  canViewCost: true,
  canPublish: true,
  canManageRoles: true,
  navMode: 'normal',
  dataState: 'normal',
  publishSim: 'success',
  accent: '#4f7d88',
  band: '#dfe5e9',
};

function App() {
  const user = useAuthStore((s) => s.user);
  const cashierOnly = user?.roles.length === 1 && user.roles[0] === 'cashier';
  const [tw, setTweak] = useTweaks(TWEAK_DEFAULTS);
  const [route, setRoute] = useState(parseHash());
  const [toasts, setToasts] = useState([]);
  const [sessionExpired, setSessionExpired] = useState(false);
  useEffect(() => { const h = () => setRoute(parseHash()); window.addEventListener('hashchange', h); return () => window.removeEventListener('hashchange', h); }, []);
  useEffect(() => { document.documentElement.style.setProperty('--accent', tw.accent); document.documentElement.style.setProperty('--band', tw.band); }, [tw.accent, tw.band]);
  useEffect(() => { window.scrollTo(0, 0); }, [route.path]);
  useEffect(() => {
    if (cashierOnly && route.path !== '/pos') window.location.replace('/pos');
  }, [cashierOnly, route.path]);
  const go = (path, query) => { location.hash = buildHash(path, query); };
  const setQuery = patch => { const q = { ...route.query, ...patch }; location.hash = buildHash(route.path, q); };
  const toast = (text, tone) => { const id = Math.random(); setToasts(t => [...t, { id, text, tone }]); setTimeout(() => setToasts(t => t.filter(x => x.id !== id)), 3200); };
  const can = p => {
    if (!p) return true;
    return !!user?.permissions?.some(permission => permission === '*' || permission === p);
  };
  const ctx = { route, go, setQuery, toast, can, tw, sessionExpired, setSessionExpired, forceState: tw.dataState === 'normal' ? null : tw.dataState };
  const p = route.path.split('/').filter(Boolean);
  let page;
  if (cashierOnly && route.path !== '/pos') return null;
  if (p[0] === 'login' || !p[0]) page = <LoginPage />;
  else {
    let inner;
    if (tw.dataState === 'forbidden' && p[0] !== 'kit') inner = <><PageHeader crumbs={[['الرئيسية', '/dashboard'], ['غير مصرح']]} bold="غير مصرح" /><div className="content"><div className="panel"><NoAccessState perm={'products.view'} go={go} /></div></div></>;
    else if (p[0] === 'pos') inner = <PosPage />;
    else if (NAV.flatMap(g => g.items).find(it => it[0] === '/' + p[0])?.[3] && !can(NAV.flatMap(g => g.items).find(it => it[0] === '/' + p[0])[3])) inner = <NoAccessState go={go} />;
    else if (p[0] === 'dashboard') inner = <DashboardPage />;
    else if (p[0] === 'orders') inner = <OrdersPage />;
    else if (p[0] === 'customers') inner = <CustomersPage />;
    else if (p[0] === 'inventory') inner = <InventoryPage />;
    else if (p[0] === 'purchases') inner = <PurchasesPage />;
    else if (p[0] === 'treasury') inner = <TreasuryPage />;
    else if (p[0] === 'reports') inner = <ReportsPage />;
    else if (p[0] === 'products' && p[1]) inner = <ProductForm id={p[1]} key={p[1]} />;
    else if (p[0] === 'products') inner = <ProductsPage />;
    else if (p[0] === 'categories') inner = <CategoriesPage />;
    else if (p[0] === 'brands') inner = <BrandsPage />;
    else if (p[0] === 'branches' && p[1]) inner = <BranchDetail id={+p[1]} />;
    else if (p[0] === 'branches') inner = <BranchesPage />;
    else if (p[0] === 'users') inner = <UsersPage />;
    else if (p[0] === 'roles') inner = can('roles.manage') ? <RolesPage /> : <><PageHeader crumbs={[['الإدارة'], ['الأدوار']]} bold="الأدوار والصلاحيات" /><div className="content"><div className="panel"><NoAccessState perm="roles.manage" go={go} /></div></div></>;
    else if (p[0] === 'settings') inner = <SettingsPage />;
    else if (p[0] === 'kit') inner = <KitPage />;
    else inner = <DashboardPage />;
    page = <Layout>{inner}</Layout>;
  }
  return (
    <AppCtx.Provider value={ctx}>
      {page}
      <Toasts list={toasts} />
      {import.meta.env.DEV && <TweaksPanel title="Tweaks">
        <TweakSection label="الصلاحيات" />
        <TweakToggle label="products.view_cost" value={tw.canViewCost} onChange={v => setTweak('canViewCost', v)} />
        <TweakToggle label="products.publish" value={tw.canPublish} onChange={v => setTweak('canPublish', v)} />
        <TweakToggle label="roles.manage" value={tw.canManageRoles} onChange={v => setTweak('canManageRoles', v)} />
        <TweakRadio label="عناصر القائمة بدون صلاحية" value={tw.navMode} options={[{ value: 'hide', label: 'إخفاء' }, { value: 'show-locked', label: 'قفل' }]} onChange={v => setTweak('navMode', v)} />
        <TweakSection label="حالة البيانات" />
        <TweakSelect label="فرض حالة" value={tw.dataState} options={[{ value: 'normal', label: 'طبيعي' }, { value: 'loading', label: 'تحميل' }, { value: 'empty', label: 'فراغ' }, { value: 'error', label: 'خطأ + إعادة محاولة' }, { value: 'forbidden', label: '403 بدون صلاحية' }]} onChange={v => setTweak('dataState', v)} />
        <TweakSelect label="محاكاة النشر/الحفظ" value={tw.publishSim} options={[{ value: 'success', label: 'نجاح كامل' }, { value: 'upload-fail', label: 'فشل رفع صورة' }, { value: 'server-422', label: '422 أخطاء حقول + variants.N' }, { value: 'status-block', label: 'منع النشر errors.status' }, { value: 'conflict', label: '409 تعارض تعديل' }]} onChange={v => setTweak('publishSim', v)} />
        <TweakButton label="محاكاة انتهاء الجلسة (401)" onClick={() => setSessionExpired(true)} />
        <TweakSection label="الألوان" />
        <TweakColor label="لون مميز" value={tw.accent} options={['#4f7d88', '#8a5a3c', '#3d4a6b', '#111111']} onChange={v => setTweak('accent', v)} />
        <TweakColor label="شريط العنوان" value={tw.band} options={['#dfe5e9', '#ede3de', '#e8e6df', '#f1f1f1']} onChange={v => setTweak('band', v)} />
      </TweaksPanel>}
    </AppCtx.Provider>
  );
}
Object.assign(window, { App, LoginPage, Layout });// ===== Product form: 4 tabs + sticky bar + draft → upload → publish pipeline =====
function seedProduct(id) {
  if (id === 'new') return {
    p: { ar: '', en: '', cat: null, channel: '', brand: null, slug: '', dar: '', den: '', mat_ar: '', mat_en: '', l: '', w: '', h: '', kg: '', warranty: '', warranty_d: '', origin: '', featured: false, supplier: null, status: 'draft', code: null, mode: null, axes: { colors: [], sizes: [], caps: [] } },
    variants: [], media: { general: [], video: null },
  };
  const base = PRODUCTS.find(x => x.id === +id) || PRODUCTS[0];
  const colors = [COLOR_PRESETS[0], COLOR_PRESETS[1], COLOR_PRESETS[2]];
  const sizes = ['وسط', 'كبير'];
  const variants = []; let n = 1;
  colors.forEach(c => sizes.forEach(s => variants.push(blankVariant({ color_ar: c.ar, color_en: c.en, hex: c.hex, size: s, sku: `LAI-${c.en.slice(0, 3).toUpperCase()}-${s === 'كبير' ? 'L' : 'M'}`, barcode: '62210' + String(48810 + n++), retail: s === 'كبير' ? '2850' : '2450', wholesale: s === 'كبير' ? '2200' : '1900', cost: s === 'كبير' ? '1350' : '1150', compare: '', weight: s === 'كبير' ? '0.9' : '0.7', reorder: '4' }))));
  return {
    p: { ar: base.ar, en: base.en, cat: base.cat, channel: base.channel, brand: base.brand, slug: slugify(base.en), dar: 'حقيبة يد مصنوعة من جلد طبيعي مدبوغ نباتيًا، بحزام كتف قابل للفصل وجيوب داخلية منظمة.', den: 'Vegetable-tanned genuine leather handbag with detachable strap.', mat_ar: 'جلد طبيعي', mat_en: 'Genuine leather', l: '32', w: '12', h: '24', kg: '0.8', warranty: '12', warranty_d: 'ضد عيوب الصناعة والخياطة', origin: 'مصر', featured: base.featured, supplier: null, status: base.status, code: base.code, mode: 'multi', axes: { colors, sizes, caps: [] } },
    variants,
    media: { general: [IMG.bag1, IMG.bag2, IMG.shoulder].map((src, i) => ({ id: 'm' + i, src, name: 'img' + i + '.jpg', state: 'done', p: 100, variant: null })).concat([{ id: 'mv', src: IMG.bag3, name: 'camel.jpg', state: 'done', p: 100, variant: 'Camel' }]), video: 'done' },
  };
}

function ProductForm({ id }) {
  const { go, toast, can, tw, setSessionExpired } = useApp();
  const isNew = id === 'new';
  const seed = useMemo(() => seedProduct(id), [id]);
  const [p, setP] = useState(seed.p);
  const [variants, setVariants] = useState(seed.variants);
  const [media, setMedia] = useState(seed.media);
  const [tab, setTab] = useState('basics');
  const [errs, setErrs] = useState({});            // field errors for basics (client + server 422)
  const [serverV, setServerV] = useState({});      // server variant errors: {idx:{field:msg}}
  const [summary, setSummary] = useState(null);    // { tone, title, items:[{text, tab}] }
  const [pipe, setPipe] = useState(null);          // pipeline modal state
  const [dirty, setDirty] = useState(false);
  const [savedOnce, setSavedOnce] = useState(!isNew);
  const [showV, setShowV] = useState(false);       // show variant client errors after first submit
  const [leave, setLeave] = useState(null);
  const [pubReject, setPubReject] = useState(null);
  const canCost = can('products.view_cost'), canPub = can('products.publish');
  const first = useRef(true);
  useEffect(() => { if (first.current) { first.current = false; return; } setDirty(true); }, [p, variants, media]);
  const set = (k, v) => { setP(x => ({ ...x, [k]: v })); setErrs(e => ({ ...e, [k]: null })); };

  const clientV = useMemo(() => validateVariants(variants, p.channel), [variants, p.channel]);
  const vErrs = useMemo(() => { const m = showV ? { ...clientV } : {}; Object.entries(serverV).forEach(([i, o]) => { m[i] = { ...(m[i] || {}), ...o }; }); return m; }, [clientV, serverV, showV]);

  const cat = p.cat && catById(p.cat);
  const catChainActive = cat ? catPath(cat.id).every(c => c.active) : false;
  const activeVs = variants.filter(v => v.active);
  const pricesOk = activeVs.length > 0 && activeVs.every(v => (p.channel === 'wholesale' || v.retail !== '') && (p.channel === 'retail' || v.wholesale !== ''));
  const imgCount = media.general.length;
  const checks = [
    { ok: !!cat && catChainActive, t: 'القسم وكل الأقسام الأب نشطة', d: cat ? catPath(cat.id).map(c => c.ar + (c.active ? '' : ' (معطل)')).join(' / ') : 'لم يتم اختيار قسم', tab: 'basics' },
    { ok: activeVs.length > 0, t: 'متغير نشط واحد على الأقل', d: `${activeVs.length} نشط من ${variants.length}`, tab: 'variants' },
    { ok: pricesOk, t: 'أسعار المتغيرات النشطة مكتملة حسب قناة البيع', d: p.channel ? 'القناة: ' + CHANNELS[p.channel] : 'اختر قناة البيع أولًا', tab: 'variants' },
    { ok: imgCount > 0, t: 'صورة واحدة على الأقل', d: imgCount ? `${imgCount} صور` : 'لم تُضف صور', tab: 'media' },
    { ok: canPub, t: 'صلاحية النشر', d: canPub ? 'products.publish متاحة' : 'دورك لا يتضمن products.publish', tab: null },
  ];
  const ready = checks.every(c => c.ok);
  const tabErr = {
    basics: ['ar', 'en', 'cat', 'channel', 'slug'].some(k => errs[k]),
    variants: Object.keys(vErrs).length > 0,
    media: media.general.some(f => f.state === 'failed'),
  };

  const validateBasics = () => {
    const e = {};
    if (!p.ar) e.ar = 'الاسم العربي مطلوب.'; if (!p.en) e.en = 'الاسم الإنجليزي مطلوب.';
    if (!p.cat) e.cat = 'اختر القسم.'; if (!p.channel) e.channel = 'اختر قناة البيع.';
    ['l', 'w', 'h', 'kg'].forEach(k => { if (p[k] && isNaN(+p[k])) e[k] = 'رقم فقط.'; });
    return e;
  };

  const run = async (intent) => {
    setSummary(null); setServerV({});
    const e = validateBasics(); setErrs(e); setShowV(true);
    const ve = validateVariants(variants, p.channel);
    const items = [];
    Object.entries(e).forEach(([k, m]) => items.push({ text: m, tab: 'basics' }));
    if (!variants.length) items.push({ text: 'أضف متغيرًا واحدًا على الأقل.', tab: 'variants' });
    Object.entries(ve).forEach(([i, o]) => Object.values(o).forEach(m => items.push({ text: `المتغير ${+i + 1}: ${m}`, tab: 'variants' })));
    if (items.length) { setSummary({ tone: 'danger', title: 'لم يتم الحفظ — راجع الحقول التالية', items: dedupe(items) }); return; }

    const sim = tw.publishSim;
    const steps = [{ k: 'save', t: isNew ? 'إنشاء المسودة والمتغيرات' : 'حفظ البيانات والمتغيرات', s: 'run' }, { k: 'upload', t: 'رفع الصور والفيديو', s: 'wait' }];
    if (intent === 'publish') steps.push({ k: 'publish', t: 'تحديث الحالة إلى «منشور»', s: 'wait' });
    const upd = (k, s, extra) => setPipe(pp => ({ ...pp, steps: pp.steps.map(x => x.k === k ? { ...x, s, ...extra } : x) }));
    setPipe({ intent, steps, done: false });
    await sleep(900);
    if (sim === 'server-422') {
      upd('save', 'fail', { note: '422 — بيانات غير صالحة' });
      const raw = { status: 422, message: 'The given data was invalid.', errors: { slug: ['هذا الرابط مستخدم لمنتج آخر.'], 'variants.2.retail_price': ['سعر التجزئة يجب أن يكون أكبر من صفر.'], 'variants.4.sku': ['SKU مستخدم بالفعل في منتج آخر.'] } };
      const n = apiClient.normalize(raw);
      const fe = {}; const sv = {}; const its = [];
      const map = { retail_price: 'retail', wholesale_price: 'wholesale', cost_price: 'cost', sku: 'sku', barcode: 'barcode' };
      Object.entries(n.fields).forEach(([path, msgs]) => {
        const m = path.match(/^variants\.(\d+)\.(\w+)$/);
        if (m) { const i = +m[1]; sv[i] = { ...(sv[i] || {}), [map[m[2]] || m[2]]: msgs[0] }; its.push({ text: `المتغير ${i + 1}: ${msgs[0]}`, tab: 'variants', path }); }
        else { fe[path] = msgs[0]; its.push({ text: msgs[0], tab: 'basics', path }); }
      });
      setErrs(fe); setServerV(sv);
      await sleep(400); setPipe(null);
      setSummary({ tone: 'danger', title: 'رفض الخادم الحفظ (422) — لم يتم إنشاء أو تعديل أي شيء', items: its });
      return;
    }
    if (sim === 'conflict') {
      upd('save', 'fail', { note: '409 — تعارض' }); await sleep(400); setPipe(null);
      setSummary({ tone: 'warn', title: 'قام مستخدم آخر بتعديل هذا المنتج أثناء عملك (409)', items: [{ text: 'منى عبد الحميد عدّلت الأسعار منذ دقيقتين. حمّل النسخة الأحدث ثم أعد تطبيق تعديلاتك.', tab: null }], action: 'reload' });
      return;
    }
    upd('save', 'done', { note: isNew ? 'PRD-024251 · مسودة' : 'تم الحفظ' }); setSavedOnce(true);
    if (isNew) setP(x => ({ ...x, code: 'PRD-024251' }));
    // upload
    upd('upload', 'run');
    const pend = media.general.filter(f => f.state !== 'done');
    setMedia(m => ({ ...m, general: m.general.map(f => f.state !== 'done' ? { ...f, state: 'uploading', p: 30 } : f), video: m.video === 'pending' ? 'uploading' : m.video }));
    await sleep(1100);
    if (sim === 'upload-fail') {
      setMedia(m => ({ ...m, general: m.general.map((f, i) => f.state === 'uploading' ? (i === m.general.length - 1 || pend.length === 0 ? { ...f, state: 'failed', err: 'انقطع الاتصال' } : { ...f, state: 'done', p: 100 }) : f), video: m.video === 'uploading' ? 'done' : m.video }));
      if (!pend.length) setMedia(m => ({ ...m, general: [...m.general, { id: 'fx', src: IMG.shoulder, name: 'side.jpg', state: 'failed', err: 'انقطع الاتصال', p: 40 }] }));
      upd('upload', 'fail', { note: 'فشل رفع صورة واحدة' });
      if (intent === 'publish') upd('publish', 'skip', { note: 'لم يُنفذ' });
      setPipe(pp => ({ ...pp, done: true, failed: 'upload' })); setDirty(false);
      return;
    }
    setMedia(m => ({ ...m, general: m.general.map(f => ({ ...f, state: 'done', p: 100 })), video: m.video ? 'done' : null }));
    upd('upload', 'done', { note: pend.length ? `${pend.length} ملفات` : 'لا ملفات جديدة' });
    if (intent === 'publish') {
      upd('publish', 'run'); await sleep(800);
      if (sim === 'status-block' || !ready) {
        upd('publish', 'fail', { note: 'errors.status' });
        const raw = { status: 422, errors: { status: ['لا يمكن نشر المنتج: القسم «كلاتش وسهرة» معطل.', 'المتغير 3 نشط بدون سعر جملة.'] } };
        const n = apiClient.normalize(raw);
        setPipe(pp => ({ ...pp, done: true, failed: 'publish', reasons: n.summary.map((t, i) => ({ text: t, tab: i === 0 ? 'basics' : 'variants' })) })); setDirty(false);
        return;
      }
      upd('publish', 'done'); setP(x => ({ ...x, status: 'published' }));
    }
    setPipe(pp => ({ ...pp, done: true })); setDirty(false);
  };
  const dedupe = a => a.filter((x, i) => a.findIndex(y => y.text === x.text) === i);

  const statusTone = P_STATUS[p.status];
  const tryLeave = to => dirty ? setLeave(to) : go(to);
  const onToggleVariantActive = (setter) => setter;

  const TABS = [['basics', 'البيانات الأساسية'], ['variants', 'المتغيرات والأسعار', variants.length], ['media', 'الصور والفيديو', imgCount], ['review', 'المراجعة والنشر']];

  // published product guard: deactivating last active variant or removing a required price
  const guardedSetVariants = next => {
    const nv = typeof next === 'function' ? next(variants) : next;
    if (p.status === 'published') {
      const act = nv.filter(v => v.active);
      const missing = act.some(v => (p.channel !== 'wholesale' && v.retail === '') || (p.channel !== 'retail' && v.wholesale === ''));
      if (act.length === 0) { setPubReject('last'); return; }
      if (missing && !pubReject) { setPubReject('price'); }
    }
    setVariants(nv);
  };

  return <>
    <PageHeader crumbs={[['المنتجات', '/products'], [isNew ? 'منتج جديد' : p.ar]]}
      light={isNew ? 'منتج' : null} bold={isNew ? 'جديد' : p.ar}
      sub={<span style={{ display: 'inline-flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
        <Badge tone={statusTone[1]}>{statusTone[0]}</Badge>
        {p.code ? <span className="mono ltr">{p.code}</span> : <span className="muted">الكود يتولد بعد الحفظ</span>}
        {p.featured && <span><i className="fa-solid fa-star star"></i> مميز</span>}
      </span>}
      decor={media.general[0] ? media.general[0].src : IMG.bag1}
      actions={!isNew && <Btn kind="ghost" icon="fa-solid fa-arrow-up-right-from-square">معاينة في المتجر</Btn>} />
    <div className="content" data-screen-label={'Product form · ' + tab}>
      {summary && <div style={{ marginBottom: 18 }}>
        <Alert tone={summary.tone} title={summary.title} onClose={() => setSummary(null)}>
          <div className="err-summary"><ul>{summary.items.map((it, i) => <li key={i}><i className="fa-solid fa-circle" style={{ fontSize: 5 }}></i><span>{it.text}{it.path && <span className="mono" style={{ opacity: .55, marginInlineStart: 8 }}>{it.path}</span>}</span>{it.tab && <a className="to lnk" onClick={() => setTab(it.tab)}>اذهب إلى {TABS.find(t => t[0] === it.tab)[1]} ←</a>}</li>)}</ul></div>
          {summary.action === 'reload' && <div style={{ marginTop: 10, display: 'flex', gap: 8 }}><Btn size="sm" onClick={() => { setSummary(null); toast('تم تحميل النسخة الأحدث'); }}>تحميل النسخة الأحدث</Btn><Btn size="sm" kind="ghost">مقارنة التغييرات</Btn></div>}
        </Alert>
      </div>}
      {pubReject && <div style={{ marginBottom: 18 }}>
        <Alert tone="warn" title={pubReject === 'last' ? 'لا يمكن تعطيل آخر متغير نشط في منتج منشور' : 'هذا المنتج منشور — كل متغير نشط يحتاج سعرًا مكتملًا'} onClose={() => setPubReject(null)}
          action={canPub ? <Btn size="sm" kind="ghost" onClick={() => { setP(x => ({ ...x, status: 'draft' })); setPubReject(null); toast('سيتحول المنتج لمسودة عند الحفظ'); }}>تحويله لمسودة</Btn> : null}>
          {pubReject === 'last' ? 'المنتج المنشور يجب أن يحتوي على متغير نشط واحد على الأقل حتى يظل ظاهرًا في المتجر.' : 'الخادم سيرفض الحفظ طالما يوجد متغير نشط بدون سعر مطلوب لقناة البيع.'}
          {canPub ? ' يمكنك تحويل المنتج لمسودة أولًا ثم إكمال التعديل.' : ' تحويله لمسودة يتطلب صلاحية products.publish — تواصل مع مدير الكتالوج.'}
        </Alert>
      </div>}

      <div className="tabs" role="tablist">
        {TABS.map(([k, l, n], i) => <button key={k} role="tab" className={'tab ' + (tab === k ? 'on' : '')} onClick={() => setTab(k)}>
          <span className="step">{i + 1}</span>{l}{n != null && n > 0 && <span className="n">{n}</span>}{tabErr[k] && <span className="errdot" title="يوجد أخطاء"></span>}
          {k === 'review' && ready && <i className="fa-solid fa-circle-check okdot"></i>}
        </button>)}
      </div>

      {tab === 'basics' && <BasicsTab p={p} set={set} errs={errs} isNew={isNew} />}
      {tab === 'variants' && <VariantsTab p={p} setP={setP} variants={variants} setVariants={guardedSetVariants} vErrs={vErrs} canCost={canCost} isNew={isNew} />}
      {tab === 'media' && <MediaTab media={media} setMedia={setMedia} variants={variants} isNew={isNew} savedOnce={savedOnce} />}
      {tab === 'review' && <ReviewTab p={p} checks={checks} ready={ready} variants={variants} media={media} setTab={setTab} canPub={canPub} />}
    </div>

    <div className="sticky-bar">
      <Btn kind="text" icon="fa-solid fa-arrow-right" onClick={() => tryLeave('/products')}>المنتجات</Btn>
      {dirty ? <span className="dirty">تغييرات غير محفوظة</span> : savedOnce ? <span className="saved"><i className="fa-solid fa-check"></i>كل التغييرات محفوظة</span> : null}
      <span className="sp"></span>
      {tab !== 'review' && <Btn kind="ghost" onClick={() => setTab(TABS[TABS.findIndex(t => t[0] === tab) + 1][0])}>التالي: {TABS[TABS.findIndex(t => t[0] === tab) + 1][1]}</Btn>}
      <Btn kind="ghost" icon="fa-regular fa-floppy-disk" onClick={() => run('draft')}>{p.status === 'published' ? 'حفظ التغييرات' : 'حفظ كمسودة'}</Btn>
      {canPub ? (p.status !== 'published' && <Btn icon="fa-solid fa-paper-plane" onClick={() => run('publish')} title={ready ? '' : 'راجع قائمة الجاهزية'}>نشر{!ready && <span style={{ opacity: .6, fontSize: 11 }}> · {checks.filter(c => !c.ok).length} ناقص</span>}</Btn>)
        : <span className="muted" style={{ fontSize: 12 }}><i className="fa-solid fa-lock"></i> النشر يتطلب صلاحية</span>}
    </div>

    {pipe && <div className="scrim"><div className="modal" style={{ width: 'min(500px,92vw)' }}>
      <div className="modal-b" style={{ flexDirection: 'column' }}>
        <h4>{pipe.done ? (pipe.failed ? (pipe.failed === 'upload' ? 'تم حفظ المسودة — فشل رفع بعض الملفات' : 'تم الحفظ — لكن النشر مرفوض') : pipe.intent === 'publish' ? 'تم نشر المنتج' : 'تم الحفظ') : 'جاري التنفيذ…'}</h4>
        <div className="pipeline" style={{ marginTop: 6 }}>
          {pipe.steps.map((s, i) => <div key={s.k} className={'pstep ' + (s.s === 'done' ? 'done' : s.s === 'run' ? 'run' : s.s === 'fail' ? 'fail' : '')}>
            <span className="pi">{s.s === 'done' ? <i className="fa-solid fa-check"></i> : s.s === 'fail' ? <i className="fa-solid fa-xmark"></i> : s.s === 'run' ? <span className="spin" style={{ width: 12, height: 12, border: '2px solid var(--ink)', borderInlineEndColor: 'transparent', borderRadius: '50%', animation: 'spin .7s linear infinite' }}></span> : i + 1}</span>
            <div><b style={{ opacity: s.s === 'wait' || s.s === 'skip' ? .45 : 1 }}>{s.t}</b>{s.note && <small>{s.note}</small>}</div>
          </div>)}
        </div>
        {pipe.failed === 'upload' && <Alert tone="warn">المنتج محفوظ كمسودة بالكود <b className="mono">{p.code}</b>. أعد رفع الملفات الفاشلة من تبويب الصور — لن يتم إنشاء منتج جديد.</Alert>}
        {pipe.failed === 'publish' && <Alert tone="danger" title="أسباب رفض النشر"><div className="err-summary"><ul>{pipe.reasons.map((r, i) => <li key={i}><span>{r.text}</span><a className="to lnk" onClick={() => { setPipe(null); setTab(r.tab); }}>إصلاح ←</a></li>)}</ul></div></Alert>}
      </div>
      {pipe.done && <div className="modal-f">
        {pipe.failed === 'upload' ? <><Btn kind="ghost" onClick={() => setPipe(null)}>إغلاق</Btn><Btn onClick={() => { setPipe(null); setTab('media'); }}>إعادة رفع الملفات</Btn></>
          : pipe.failed ? <Btn kind="ghost" onClick={() => setPipe(null)}>إغلاق</Btn>
            : <><Btn kind="ghost" onClick={() => { setPipe(null); if (isNew) go('/products/100'); }}>متابعة التعديل</Btn><Btn onClick={() => { setPipe(null); go('/products'); }}>العودة للمنتجات</Btn></>}
      </div>}
    </div></div>}

    <ConfirmModal open={!!leave} tone="warn" icon="fa-solid fa-pen-to-square" title="مغادرة بدون حفظ؟" text="لديك تعديلات على المنتج لم تُحفظ. إذا غادرت ستفقدها." confirmLabel="مغادرة" onClose={() => setLeave(null)} onConfirm={() => go(leave)} />
  </>;
}

function BasicsTab({ p, set, errs, isNew }) {
  const [sec, setSec] = useState('id');
  return <div className="split">
    <nav className="vnav">
      {[['id', 'الهوية والتصنيف'], ['desc', 'الوصف والخامة'], ['dims', 'الأبعاد والوزن'], ['warranty', 'الضمان والمنشأ']].map(([k, l]) =>
        <button key={k} className={sec === k ? 'on' : ''} onClick={() => { setSec(k); const el = document.getElementById('sec-' + k); el && window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 80, behavior: 'smooth' }); }}>{l}</button>)}
    </nav>
    <div className="grid" style={{ gap: 20 }}>
      <div className="panel" id="sec-id"><div className="panel-h"><h3>الهوية والتصنيف</h3></div><div className="panel-b grid g2">
        <Field label="الاسم العربي" req error={errs.ar}><Input value={p.ar} error={errs.ar} onChange={e => set('ar', e.target.value)} placeholder="حقيبة يد جلد طبيعي «ليلى»" /></Field>
        <Field label="الاسم الإنجليزي" req error={errs.en}><Input ltr value={p.en} error={errs.en} onChange={e => set('en', e.target.value)} placeholder="Laila Leather Handbag" /></Field>
        <Field label="القسم" req error={errs.cat} help="يمكن اختيار أي مستوى. الأقسام المعطلة تمنع النشر.">
          <SearchSelect value={p.cat} error={errs.cat} onChange={v => set('cat', v)} placeholder="اختر القسم" searchPh="ابحث في الأقسام…"
            options={CATS.map(c => { const path = catPath(c.id); const off = path.some(x => !x.active); return { value: c.id, label: path.map(x => x.ar).join(' / '), why: off ? 'معطل' : null }; })} />
        </Field>
        <Field label="البراند" opt><SearchSelect value={p.brand} onChange={v => set('brand', v)} placeholder="بدون براند" clearable options={BRANDS.map(b => ({ value: b.id, label: b.en, sub: b.ar, disabled: !b.active, why: b.active ? null : 'غير نشط' }))} /></Field>
        <Field label="قناة البيع" req error={errs.channel} className="spanall">
          <div className="radio-cards g3" style={{ display: 'grid' }}>
            {[['retail', 'تجزئة', 'سعر التجزئة مطلوب'], ['wholesale', 'جملة', 'سعر الجملة مطلوب'], ['both', 'تجزئة وجملة', 'السعران مطلوبان']].map(o =>
              <div key={o[0]} className={'rcard ' + (p.channel === o[0] ? 'on' : '')} onClick={() => set('channel', o[0])} style={errs.channel ? { borderColor: 'var(--danger)' } : null}><span className="dot"></span><div><b>{o[1]}</b><span>{o[2]}</span></div></div>)}
          </div>
        </Field>
        <Field label="كود المنتج" help="يتولد من الخادم ولا يمكن تعديله."><Input ltr className="mono" value={p.code || ''} readOnly placeholder="يتولد تلقائيًا بعد الحفظ" /></Field>
        <Field label="الرابط (slug)" opt error={errs.slug} help={!p.slug && p.en ? <>سيتولد: <span className="mono">{slugify(p.en)}</span></> : null}><Input ltr className="mono" value={p.slug} error={errs.slug} onChange={e => set('slug', e.target.value)} placeholder={p.en ? slugify(p.en) : 'laila-leather-handbag'} /></Field>
        <Field label="المورد" opt help={<><i className="fa-solid fa-triangle-exclamation" style={{ color: 'var(--warn)' }}></i> الربط الفعلي بجدول الموردين قيد التأكيد — الخادم لا يتحقق من القيمة حاليًا.</>}>
          <SearchSelect value={p.supplier} onChange={v => set('supplier', v)} placeholder="اختر المورد" clearable options={[{ value: 1, label: 'مصنع النيل للجلود' }, { value: 2, label: 'Guangzhou Bags Co.' }, { value: 3, label: 'ورشة الحرفيين — خان الخليلي' }]} />
        </Field>
        <Field label="العرض في المتجر"><div style={{ paddingTop: 8 }}><Toggle checked={p.featured} onChange={v => set('featured', v)} label={<span>منتج مميز <i className="fa-solid fa-star star"></i></span>} /></div></Field>
      </div></div>
      <div className="panel" id="sec-desc"><div className="panel-h"><h3>الوصف والخامة</h3></div><div className="panel-b grid g2">
        <Field label="الوصف العربي" opt><TextArea rows={4} value={p.dar} onChange={e => set('dar', e.target.value)} /></Field>
        <Field label="الوصف الإنجليزي" opt><TextArea ltr rows={4} value={p.den} onChange={e => set('den', e.target.value)} /></Field>
        <Field label="الخامة (عربي)" opt><Input value={p.mat_ar} onChange={e => set('mat_ar', e.target.value)} placeholder="جلد طبيعي" /></Field>
        <Field label="الخامة (إنجليزي)" opt><Input ltr value={p.mat_en} onChange={e => set('mat_en', e.target.value)} placeholder="Genuine leather" /></Field>
      </div></div>
      <div className="panel" id="sec-dims"><div className="panel-h"><h3>الأبعاد والوزن</h3><span className="sp"></span><span className="muted" style={{ fontSize: 12 }}>للشحن وصفحة المنتج</span></div><div className="panel-b grid g4">
        {[['l', 'الطول', 'سم'], ['w', 'العرض', 'سم'], ['h', 'الارتفاع', 'سم'], ['kg', 'الوزن', 'كجم']].map(([k, l, u]) =>
          <Field key={k} label={l} opt error={errs[k]}><div className="inp-wrap"><Input ltr value={p[k]} error={errs[k]} onChange={e => set(k, e.target.value)} inputMode="decimal" placeholder="0" /><span className="adorn" style={{ fontSize: 11, width: 38 }}>{u}</span></div></Field>)}
      </div></div>
      <div className="panel" id="sec-warranty"><div className="panel-h"><h3>الضمان والمنشأ</h3></div><div className="panel-b grid g3">
        <Field label="مدة الضمان" opt><div className="inp-wrap"><Input ltr value={p.warranty} onChange={e => set('warranty', e.target.value)} placeholder="12" /><span className="adorn" style={{ fontSize: 11, width: 40 }}>شهر</span></div></Field>
        <Field label="تفاصيل الضمان" opt><Input value={p.warranty_d} onChange={e => set('warranty_d', e.target.value)} /></Field>
        <Field label="بلد المنشأ" opt><SearchSelect value={p.origin} onChange={v => set('origin', v)} placeholder="اختر الدولة" options={['مصر', 'إيطاليا', 'تركيا', 'الصين', 'الهند', 'فرنسا'].map(c => ({ value: c, label: c }))} /></Field>
      </div></div>
    </div>
  </div>;
}

function ReviewTab({ p, checks, ready, variants, media, setTab, canPub }) {
  const act = variants.filter(v => v.active);
  const prices = act.map(v => +v.retail).filter(Boolean);
  const range = prices.length ? (Math.min(...prices) === Math.max(...prices) ? fmtMoney(prices[0]) : fmtMoney(Math.min(...prices)) + ' – ' + fmtMoney(Math.max(...prices))) : '—';
  const colors = [...new Map(act.filter(v => v.color_en).map(v => [v.color_en, v])).values()];
  return <div style={{ display: 'grid', gridTemplateColumns: '1fr 320px', gap: 24, alignItems: 'start' }}>
    <div className="grid" style={{ gap: 20 }}>
      <div className="panel">
        <div className="panel-h"><div><h3>قائمة الجاهزية للنشر</h3><p>يتم التحقق منها أيضًا على الخادم عند النشر.</p></div><span className="sp"></span>
          {ready ? <Badge tone="ok">جاهز للنشر</Badge> : <Badge tone="warn">{checks.filter(c => !c.ok).length} متطلبات ناقصة</Badge>}</div>
        <div className="ready-list">
          {checks.map((c, i) => <div key={i} className={'ready ' + (c.ok ? 'ok' : 'no')}>
            <span className="ri"><i className={'fa-solid ' + (c.ok ? 'fa-check' : 'fa-xmark')}></i></span>
            <div className="rt"><b>{c.t}</b><small>{c.d}</small></div>
            {!c.ok && c.tab && <Btn kind="ghost" size="sm" onClick={() => setTab(c.tab)}>إصلاح</Btn>}
          </div>)}
        </div>
      </div>
      <div className="panel">
        <div className="panel-h"><h3>ما الذي سيحدث عند النشر؟</h3></div>
        <div className="panel-b"><div className="pipeline">
          {[['حفظ المسودة والمتغيرات', `${variants.length} متغير — حتى 100 في الطلب الواحد`], ['رفع الصور والفيديو', `${media.general.filter(f => f.state !== 'done').length} ملفات بانتظار الرفع · كل ملف بحالته المستقلة`], ['تحديث الحالة إلى «منشور»', canPub ? 'يظهر المنتج فورًا في المتجر' : 'يتطلب products.publish']].map((s, i) =>
            <div key={i} className="pstep"><span className="pi">{i + 1}</span><div><b>{s[0]}</b><small>{s[1]}</small></div></div>)}
        </div>
          <div className="field-help" style={{ marginTop: 14 }}>لو فشل الرفع تبقى المسودة محفوظة، ويمكنك إعادة رفع الملفات بدون إنشاء منتج جديد.</div>
        </div>
      </div>
    </div>
    <div style={{ position: 'sticky', top: 80 }}>
      <div className="sec-title">معاينة في المتجر</div>
      <div className="sf-card">
        {media.general[0] ? <img src={media.general[0].src} alt="" /> : <div style={{ height: 170, display: 'grid', placeItems: 'center' }}><i className="fa-regular fa-image" style={{ fontSize: 32, color: 'var(--ink-5)' }}></i></div>}
        <div className="nm">{p.en || 'Product name'}</div>
        <div className="muted" style={{ fontSize: 12 }}>{p.ar}</div>
        <div className="pr ltr" style={{ textAlign: 'center' }}><b>{range}</b> EGP</div>
        <div style={{ display: 'flex', gap: 6, justifyContent: 'center', marginTop: 10 }}>{colors.map(c => <span key={c.color_en} title={c.color_en} style={{ width: 16, height: 16, borderRadius: '50%', background: c.hex || '#ccc', border: '2px solid #fff', boxShadow: '0 0 0 1px rgba(0,0,0,.1)' }}></span>)}</div>
      </div>
      <dl className="kv" style={{ marginTop: 16, gridTemplateColumns: '110px 1fr', fontSize: 12.5 }}>
        <dt>القسم</dt><dd>{p.cat ? catPath(p.cat).map(c => c.ar).join(' / ') : '—'}</dd>
        <dt>قناة البيع</dt><dd>{CHANNELS[p.channel] || '—'}</dd>
        <dt>المتغيرات</dt><dd>{act.length} نشط / {variants.length}</dd>
        <dt>الوسائط</dt><dd>{media.general.length} صور{media.video ? ' + فيديو' : ''}</dd>
      </dl>
    </div>
  </div>;
}

Object.assign(window, { ProductForm });// ===== Phase 2: Categories tree, Brands, Products list =====
const MAX_DEPTH = 3;
function subtreeHeight(list, id) { const kids = list.filter(c => c.parent === id); return 1 + (kids.length ? Math.max(...kids.map(k => subtreeHeight(list, k.id))) : 0); }
function isDescendant(list, id, maybeChild) { let c = list.find(x => x.id === maybeChild); while (c) { if (c.parent === id) return true; c = list.find(x => x.id === c.parent); } return false; }
function depthOf(list, id) { let d = 0, c = list.find(x => x.id === id); while (c) { d++; c = list.find(x => x.id === c.parent); } return d; }
function totalProducts(list, id) { return list.filter(c => c.parent === id).reduce((a, k) => a + totalProducts(list, k.id), list.find(c => c.id === id).products); }

function CategoriesPage() {
  const { toast, forceState } = useApp();
  const [saved, setSaved] = useState(() => CATS.map(c => ({ ...c })));
  const [list, setList] = useState(() => CATS.map(c => ({ ...c })));
  const [open, setOpen] = useState({ 1: true, 3: true, 7: true, 8: true });
  const [sel, setSel] = useState(null);
  const [form, setForm] = useState(null);
  const [drag, setDrag] = useState(null); const [over, setOver] = useState(null); const [tip, setTip] = useState(null);
  const [del, setDel] = useState(null); const [deact, setDeact] = useState(null); const [q, setQ] = useState('');
  const changed = list.filter(c => { const s = saved.find(x => x.id === c.id); return s.parent !== c.parent || s.order !== c.order; });

  const validDrop = (dragId, targetId, pos) => {
    const t = list.find(c => c.id === targetId);
    const newParent = pos === 'in' ? targetId : t.parent;
    if (dragId === targetId) return 'لا يمكن نقل القسم داخل نفسه';
    if (newParent && (newParent === dragId || isDescendant(list, dragId, newParent))) return 'لا يمكن النقل داخل أحد الأقسام الفرعية';
    const pd = newParent ? depthOf(list, newParent) : 0;
    if (pd + subtreeHeight(list, dragId) > MAX_DEPTH) return 'يتجاوز الحد الأقصى ٣ مستويات';
    return null;
  };
  const doMove = (dragId, targetId, pos) => {
    setList(L => {
      const t = L.find(c => c.id === targetId);
      const newParent = pos === 'in' ? targetId : t.parent;
      let sibs = L.filter(c => c.parent === newParent && c.id !== dragId).sort((a, b) => a.order - b.order);
      let idx = pos === 'in' ? sibs.length : sibs.findIndex(s => s.id === targetId) + (pos === 'after' ? 1 : 0);
      const moving = { ...L.find(c => c.id === dragId), parent: newParent };
      sibs.splice(idx, 0, moving);
      const orderMap = Object.fromEntries(sibs.map((s, i) => [s.id, i + 1]));
      return L.map(c => c.id === dragId ? { ...moving, order: orderMap[c.id] } : orderMap[c.id] ? { ...c, order: orderMap[c.id] } : c);
    });
    if (pos === 'in') setOpen(o => ({ ...o, [targetId]: true }));
  };

  const onDragOver = (e, node) => {
    e.preventDefault();
    const r = e.currentTarget.getBoundingClientRect(); const y = (e.clientY - r.top) / r.height;
    const pos = y < .28 ? 'before' : y > .72 ? 'after' : 'in';
    const bad = validDrop(drag, node.id, pos);
    setOver({ id: node.id, pos, bad }); setTip(bad ? { x: e.clientX + 14, y: e.clientY + 10, t: bad } : null);
  };
  const onDrop = (e, node) => { e.preventDefault(); if (over && !over.bad) doMove(drag, node.id, over.pos); setDrag(null); setOver(null); setTip(null); };

  const render = (parent, lvl) => list.filter(c => c.parent === parent).sort((a, b) => a.order - b.order).map(c => {
    const kids = list.filter(x => x.parent === c.id); const isOpen = open[c.id];
    const match = !q || (c.ar + c.en).toLowerCase().includes(q.toLowerCase());
    const s = saved.find(x => x.id === c.id); const moved = s.parent !== c.parent || s.order !== c.order;
    const oc = over && over.id === c.id ? (over.bad ? ' drop-bad' : over.pos === 'in' ? ' drop-in' : ' drop-' + over.pos) : '';
    return <React.Fragment key={c.id}>
      {(match || kids.length > 0) && <div className={'tnode' + (sel === c.id ? ' sel' : '') + (moved ? ' moved' : '') + oc} style={{ marginInlineStart: (lvl - 1) * 28, opacity: drag === c.id ? .4 : match ? 1 : .5 }}
        draggable onDragStart={e => { setDrag(c.id); e.dataTransfer.effectAllowed = 'move'; }} onDragEnd={() => { setDrag(null); setOver(null); setTip(null); }}
        onDragOver={e => drag && onDragOver(e, c)} onDragLeave={() => setOver(null)} onDrop={e => onDrop(e, c)} onClick={() => setSel(c.id)}>
        <span className="grip" title="اسحب لإعادة الترتيب"><i className="fa-solid fa-grip-vertical"></i></span>
        <button className="tw" onClick={e => { e.stopPropagation(); setOpen(o => ({ ...o, [c.id]: !o[c.id] })); }} style={{ visibility: kids.length ? 'visible' : 'hidden' }} aria-label={isOpen ? 'طي' : 'فتح'}>
          <i className={'fa-solid fa-chevron-' + (isOpen ? 'down' : 'left')}></i></button>
        <div className="thumb" style={{ width: 30, height: 30 }}><i className={'fa-solid ' + (lvl === 1 ? 'fa-folder' : 'fa-folder-tree') + ' muted'} style={{ fontSize: 11 }}></i></div>
        <div style={{ minWidth: 0 }}><span className="nm">{c.ar}</span> <span className="en ltr" style={{ display: 'inline' }}>· {c.en}</span></div>
        <span className="lvl">L{lvl}</span>
        {!c.active && <Badge>معطل</Badge>}
        <span className="cnt">{totalProducts(list, c.id)} منتج</span>
        <div className="tact" onClick={e => e.stopPropagation()}>
          <button className="icon-btn" title={lvl >= MAX_DEPTH ? 'وصلت للحد الأقصى ٣ مستويات' : 'إضافة قسم فرعي'} disabled={lvl >= MAX_DEPTH} onClick={() => setForm({ parent: c.id })}><i className="fa-solid fa-plus"></i></button>
          <button className="icon-btn" title="تعديل" onClick={() => setForm(c)}><i className="fa-regular fa-pen-to-square"></i></button>
          <button className="icon-btn danger" title="حذف" onClick={() => setDel(c)}><i className="fa-regular fa-trash-can"></i></button>
        </div>
      </div>}
      {(isOpen || q) && render(c.id, lvl + 1)}
    </React.Fragment>;
  });
  const selC = sel && list.find(c => c.id === sel);
  return <>
    <PageHeader crumbs={[['الرئيسية', '/products'], ['الكتالوج'], ['الأقسام']]} light="شجرة" bold="الأقسام" sub="حتى ٣ مستويات. اسحب لإعادة الترتيب أو استخدم «نقل إلى» — التغييرات تُحفظ مرة واحدة."
      decor={IMG.bp1} actions={<Btn icon="fa-solid fa-plus" onClick={() => setForm({ parent: null })}>قسم رئيسي</Btn>} />
    <div className="content" data-screen-label="Categories">
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 320px', gap: 20, alignItems: 'start' }}>
        <div className="panel">
          <div className="dt-toolbar">
            <div className="dt-search"><i className="fa-solid fa-magnifying-glass"></i><input className="inp" value={q} onChange={e => setQ(e.target.value)} placeholder="ابحث في الأقسام" /></div>
            <span style={{ flex: 1 }}></span>
            <button className="btn text sm" onClick={() => setOpen(Object.fromEntries(list.map(c => [c.id, true])))}>فتح الكل</button>
            <button className="btn text sm" onClick={() => setOpen({})}>طي الكل</button>
          </div>
          {forceState === 'loading' ? <div className="tree">{[0, 1, 1, 2, 0, 1].map((l, i) => <div key={i} className="tnode" style={{ marginInlineStart: l * 28 }}><div className="sk" style={{ width: 160 + (i % 3) * 40 }}></div></div>)}</div>
            : forceState === 'error' ? <ErrorState onRetry={() => { }} />
              : forceState === 'empty' ? <EmptyState icon="fa-solid fa-sitemap" title="لا توجد أقسام بعد" text="ابدأ بقسم رئيسي مثل «حقائب نسائية» أو «محافظ»، ثم أضف الأقسام الفرعية." action={<Btn icon="fa-solid fa-plus" onClick={() => setForm({ parent: null })}>قسم رئيسي</Btn>} />
                : <div className="tree">{render(null, 1)}</div>}
          {changed.length > 0 && <div style={{ padding: '0 12px 12px' }}><div className="pending-bar">
            <i className="fa-solid fa-arrows-up-down-left-right"></i><span>{changed.length} أقسام تغيّر موقعها أو ترتيبها</span><span className="sp"></span>
            <Btn kind="ghost" size="sm" onClick={() => setList(saved.map(c => ({ ...c })))}>إلغاء التغييرات</Btn>
            <Btn kind="accent" size="sm" onClick={() => { setSaved(list.map(c => ({ ...c }))); toast('تم حفظ ترتيب الأقسام'); }}>حفظ الترتيب</Btn>
          </div></div>}
        </div>
        <div className="panel" style={{ position: 'sticky', top: 80 }}>
          {selC ? <>
            <div className="panel-h"><div><h3>{selC.ar}</h3><p className="ltr" style={{ textAlign: 'right' }}>{selC.en}</p></div></div>
            <div className="panel-b grid" style={{ gap: 14 }}>
              <dl className="kv" style={{ gridTemplateColumns: '100px 1fr' }}>
                <dt>المسار</dt><dd>{catPathL(list, selC.id).map(c => c.ar).join(' / ')}</dd>
                <dt>المستوى</dt><dd>{depthOf(list, selC.id)} من {MAX_DEPTH}</dd>
                <dt>المنتجات</dt><dd>{selC.products} مباشرة · {totalProducts(list, selC.id)} إجمالي</dd>
              </dl>
              <Field label="نقل إلى" help="بديل للسحب — الخيارات غير المسموحة معطلة مع السبب.">
                <SearchSelect value={selC.parent} onChange={v => { const sibs = list.filter(c => c.parent === v && c.id !== selC.id); setList(L => L.map(c => c.id === selC.id ? { ...c, parent: v, order: sibs.length + 1 } : c)); }}
                  options={[{ value: null, label: '— قسم رئيسي —', disabled: subtreeHeight(list, selC.id) > MAX_DEPTH }, ...list.filter(c => c.id !== selC.id).map(c => {
                    const why = isDescendant(list, selC.id, c.id) ? 'قسم فرعي منه' : depthOf(list, c.id) + subtreeHeight(list, selC.id) > MAX_DEPTH ? 'يتجاوز ٣ مستويات' : null;
                    return { value: c.id, label: '\u00A0'.repeat((depthOf(list, c.id) - 1) * 4) + c.ar, disabled: !!why, why };
                  })]} />
              </Field>
              <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                <Toggle checked={selC.active} onChange={v => { if (!v && totalProducts(list, selC.id) > 0) setDeact(selC); else setList(L => L.map(c => c.id === selC.id ? { ...c, active: v } : c)); }} label="القسم نشط" />
              </div>
              <div style={{ display: 'flex', gap: 8 }}><Btn kind="ghost" size="sm" icon="fa-regular fa-pen-to-square" onClick={() => setForm(selC)}>تعديل</Btn><Btn kind="ghost" size="sm" icon="fa-solid fa-plus" disabled={depthOf(list, selC.id) >= MAX_DEPTH} onClick={() => setForm({ parent: selC.id })}>قسم فرعي</Btn></div>
            </div>
          </> : <EmptyState icon="fa-regular fa-hand-pointer" title="اختر قسمًا" text="اعرض تفاصيله، انقله لقسم أب آخر، أو غيّر حالته." />}
        </div>
      </div>
    </div>
    {tip && <div className="drag-tip" style={{ left: tip.x, top: tip.y }}><i className="fa-solid fa-ban"></i> {tip.t}</div>}
    <CategoryForm open={!!form} initial={form} list={list} onClose={() => setForm(null)} />
    <ConfirmModal open={!!del} onClose={() => setDel(null)} title={'حذف قسم «' + (del && del.ar) + '»؟'} text="الحذف نهائي ولا يمكن التراجع عنه." confirmLabel="حذف القسم"
      rejectWith={del && (list.some(c => c.parent === del.id) ? <>القسم يحتوي على <b>{list.filter(c => c.parent === del.id).length}</b> أقسام فرعية. انقلها أو احذفها أولًا.</> : del.products > 0 ? <>يوجد <b>{del.products}</b> منتج مرتبط بهذا القسم. انقل المنتجات لقسم آخر أولًا.</> : null)} />
    <ConfirmModal open={!!deact} tone="warn" icon="fa-solid fa-eye-slash" onClose={() => setDeact(null)} title={'تعطيل «' + (deact && deact.ar) + '»؟'}
      text="المنتجات تحت قسم معطل لن تظهر في المتجر." confirmLabel="تعطيل"
      rejectWith={deact && <>لا يمكن تعطيل القسم لوجود <b>{totalProducts(list, deact.id)}</b> منتجًا منشورًا تحته أو تحت أقسامه الفرعية. حوّلها لمسودة أو انقلها أولًا. <a className="lnk">عرض المنتجات</a></>} />
  </>;
}
const catPathL = (list, id) => { const p = []; let c = list.find(x => x.id === id); while (c) { p.unshift(c); c = list.find(x => x.id === c.parent); } return p; };
const slugify = s => s.toLowerCase().trim().replace(/[^a-z0-9\s-]/g, '').replace(/\s+/g, '-').replace(/-+/g, '-');

function CategoryForm({ open, initial, list, onClose }) {
  const { toast } = useApp();
  const isEdit = initial && initial.id;
  const [f, setF] = useState({}); const [err, setErr] = useState({}); const [saving, setSaving] = useState(false);
  useEffect(() => { if (open) { setF(isEdit ? { ...initial, img: IMG.bag2 } : { ar: '', en: '', parent: initial.parent, slug: '', dar: '', den: '', img: null, active: true, order: 1 }); setErr({}); } }, [open]);
  const set = (k, v) => { setF(x => ({ ...x, [k]: v })); setErr(e => ({ ...e, [k]: null })); };
  const save = async () => {
    const e = {}; if (!f.ar) e.ar = 'الاسم العربي مطلوب.'; if (!f.en) e.en = 'الاسم الإنجليزي مطلوب.';
    if (f.slug && !/^[a-z0-9-]+$/.test(f.slug)) e.slug = 'حروف إنجليزية صغيرة وأرقام وشرطات فقط.';
    setErr(e); if (Object.keys(e).length) return; setSaving(true); await sleep(700); setSaving(false); toast('تم حفظ القسم'); onClose();
  };
  if (!open) return null;
  return (
    <Drawer open={open} onClose={onClose} title={isEdit ? 'تعديل قسم' : 'قسم جديد'} crumbs={<><span>الأقسام</span><span className="sep">/</span><span className="cur">{f.parent ? 'داخل ' + (list.find(c => c.id === f.parent) || {}).ar : 'قسم رئيسي'}</span></>}
      footer={<><Btn loading={saving} onClick={save}>حفظ</Btn><Btn kind="ghost" onClick={onClose}>إلغاء</Btn></>}>
      <div className="grid g2">
        <Field label="الاسم العربي" req error={err.ar}><Input value={f.ar || ''} error={err.ar} onChange={e => set('ar', e.target.value)} placeholder="محافظ رجالي" /></Field>
        <Field label="الاسم الإنجليزي" req error={err.en}><Input ltr value={f.en || ''} error={err.en} onChange={e => set('en', e.target.value)} placeholder="Men Wallets" /></Field>
        <Field label="القسم الأب" opt className="spanall" help="حتى ٣ مستويات. الأقسام في المستوى الثالث لا يمكن أن تكون أبًا.">
          <SearchSelect value={f.parent ?? null} onChange={v => set('parent', v)} options={[{ value: null, label: '— قسم رئيسي —' }, ...list.filter(c => c.id !== f.id).map(c => {
            const d = depthOf(list, c.id); const why = d >= MAX_DEPTH ? 'المستوى الثالث' : f.id && isDescendant(list, f.id, c.id) ? 'قسم فرعي منه' : null;
            return { value: c.id, label: catPathL(list, c.id).map(x => x.ar).join(' / '), disabled: !!why, why };
          })]} />
        </Field>
        <Field label="الرابط (slug)" opt error={err.slug} className="spanall" help={!f.slug && f.en ? <>سيتولد تلقائيًا: <span className="mono ltr" style={{ display: 'inline-block' }}>{slugify(f.en)}</span></> : 'يتولد من الاسم الإنجليزي عند تركه فارغًا.'}>
          <div className="inp-wrap has-pre" dir="ltr"><span className="pre" style={{ left: 12, right: 'auto' }}>/c/</span><Input ltr className="mono" value={f.slug || ''} error={err.slug} onChange={e => set('slug', e.target.value)} placeholder={f.en ? slugify(f.en) : 'men-wallets'} style={{ paddingLeft: 36 }} /></div>
        </Field>
        <Field label="الوصف العربي" opt><TextArea value={f.dar || ''} onChange={e => set('dar', e.target.value)} /></Field>
        <Field label="الوصف الإنجليزي" opt><TextArea ltr value={f.den || ''} onChange={e => set('den', e.target.value)} /></Field>
        <Field label="صورة القسم" opt className="spanall"><SingleImageUpload value={f.img} onChange={v => set('img', v)} /></Field>
        <Field label="الترتيب"><Input ltr type="number" value={f.order || 1} onChange={e => set('order', e.target.value)} /></Field>
        <Field label="الحالة"><div style={{ paddingTop: 8 }}><Toggle checked={f.active} onChange={v => set('active', v)} label={f.active ? 'نشط' : 'معطل'} /></div></Field>
      </div>
    </Drawer>
  );
}

// ---------------- Brands ----------------
function BrandsPage() {
  const { route, setQuery, toast, forceState } = useApp();
  const q = route.query; const [form, setForm] = useState(null); const [del, setDel] = useState(null);
  const rows = useMemo(() => BRANDS.filter(b => (!q.q || (b.ar + b.en).toLowerCase().includes(q.q.toLowerCase())) && (!q.status || (q.status === 'active') === b.active)), [q.q, q.status]);
  return <>
    <PageHeader crumbs={[['الرئيسية', '/products'], ['الكتالوج'], ['البراندات']]} bold="البراندات" decor={IMG.wallet} actions={<Btn icon="fa-solid fa-plus" onClick={() => setForm({})}>إضافة براند</Btn>} />
    <div className="content" data-screen-label="Brands">
      <DataTable rows={rows} query={q} setQuery={setQuery} searchPh="ابحث باسم البراند" forceState={forceState}
        filters={<FilterSelect label="الحالة" value={q.status} onChange={v => setQuery({ status: v, page: 1 })} options={[['active', 'نشط'], ['inactive', 'غير نشط']]} />}
        empty={<EmptyState icon="fa-solid fa-copyright" title="لا توجد براندات" action={<Btn icon="fa-solid fa-plus" onClick={() => setForm({})}>إضافة براند</Btn>} />}
        columns={[
          { k: 'l', label: 'البراند', render: r => <div className="cell-main"><div className="thumb" style={{ background: r.id === 1 ? 'var(--ink)' : 'var(--band)', color: r.id === 1 ? '#fff' : 'var(--ink)', fontFamily: 'var(--display)', fontWeight: 700, fontSize: 13, letterSpacing: 1 }}>{r.logo}</div><div><b>{r.ar}</b><small className="ltr" style={{ display: 'block', textAlign: 'right' }}>{r.en}</small></div></div> },
          { k: 's', label: 'الرابط', render: r => <span className="mono ltr muted">/{r.slug}</span> },
          { k: 'p', label: 'المنتجات', cls: 'num', render: r => r.products },
          { k: 'o', label: 'الترتيب', cls: 'num', render: r => r.order },
          { k: 'st', label: 'الحالة', render: r => <StatusBadge active={r.active} /> },
          { k: 'a', label: '', cls: 'act', render: r => <><button className="icon-btn" onClick={() => setForm(r)}><i className="fa-regular fa-pen-to-square"></i></button><button className="icon-btn danger" onClick={() => setDel(r)}><i className="fa-regular fa-trash-can"></i></button></> },
        ]} />
    </div>
    <Drawer open={!!form} onClose={() => setForm(null)} title={form && form.id ? 'تعديل ' + form.en : 'براند جديد'} crumbs={<><span>البراندات</span><span className="sep">/</span><span className="cur">{form && form.id ? 'تعديل' : 'إضافة'}</span></>}
      footer={<><Btn onClick={() => { toast('تم حفظ البراند'); setForm(null); }}>حفظ</Btn><Btn kind="ghost" onClick={() => setForm(null)}>إلغاء</Btn></>}>
      {form && <div className="grid g2">
        <Field label="الاسم العربي" req><Input defaultValue={form.ar} /></Field>
        <Field label="الاسم الإنجليزي" req><Input ltr defaultValue={form.en} /></Field>
        <Field label="الرابط (slug)" opt className="spanall" help="يتولد من الاسم الإنجليزي عند تركه فارغًا."><Input ltr className="mono" defaultValue={form.slug} placeholder="brand-name" /></Field>
        <Field label="اللوجو" opt className="spanall"><SingleImageUpload value={null} onChange={() => { }} /></Field>
        <Field label="الوصف العربي" opt><TextArea /></Field>
        <Field label="الوصف الإنجليزي" opt><TextArea ltr /></Field>
        <Field label="الترتيب"><Input ltr type="number" defaultValue={form.order || 1} /></Field>
        <Field label="الحالة"><div style={{ paddingTop: 8 }}><Toggle checked={form.active !== false} onChange={() => { }} label="نشط" /></div></Field>
      </div>}
    </Drawer>
    <ConfirmModal open={!!del} onClose={() => setDel(null)} title={'حذف براند «' + (del && del.en) + '»؟'} text="سيتم حذف البراند نهائيًا." confirmLabel="حذف"
      rejectWith={del && del.products > 0 ? <>يوجد <b>{del.products}</b> منتج مرتبط بهذا البراند. لا يمكن حذفه — يمكنك تعطيله بدلًا من ذلك.</> : null} />
  </>;
}

// ---------------- Products list ----------------
function ProductsPage() {
  const { route, setQuery, go, forceState } = useApp();
  const q = route.query;
  const rows = useMemo(() => {
    let r = PRODUCTS.filter(p => {
      if (q.q && !(p.ar + p.en + p.code + 'SKU').toLowerCase().includes(q.q.toLowerCase())) return false;
      if (q.cat) { const cid = +q.cat; if (q.sub === '1') { if (!(p.cat === cid || isDescendant(CATS, cid, p.cat))) return false; } else if (p.cat !== cid) return false; }
      if (q.brand && p.brand !== +q.brand) return false;
      if (q.status && p.status !== q.status) return false;
      if (q.channel && p.channel !== q.channel) return false;
      if (q.featured === '1' && !p.featured) return false;
      return true;
    });
    if (q.sort === 'oldest') r = [...r].sort((a, b) => b.created - a.created);
    else if (q.sort === 'name') r = [...r].sort((a, b) => a.ar.localeCompare(b.ar, 'ar'));
    else r = [...r].sort((a, b) => a.created - b.created);
    return r;
  }, [q.q, q.cat, q.sub, q.brand, q.status, q.channel, q.featured, q.sort]);
  const af = [
    q.cat && { k: 'cat', label: 'القسم: ' + catById(+q.cat).ar + (q.sub === '1' ? ' + الفرعية' : ''), clear: () => setQuery({ cat: '', sub: '', page: 1 }) },
    q.brand && { k: 'brand', label: 'البراند: ' + brandById(+q.brand).en, clear: () => setQuery({ brand: '', page: 1 }) },
    q.status && { k: 'st', label: P_STATUS[q.status][0], clear: () => setQuery({ status: '', page: 1 }) },
    q.channel && { k: 'ch', label: CHANNELS[q.channel], clear: () => setQuery({ channel: '', page: 1 }) },
    q.featured === '1' && { k: 'f', label: 'مميز فقط', clear: () => setQuery({ featured: '', page: 1 }) },
  ].filter(Boolean);
  return <>
    <PageHeader crumbs={[['الرئيسية', '/products'], ['الكتالوج'], ['المنتجات']]} light="كتالوج" bold="المنتجات" sub="الشنط والمحافظ بكل ألوانها ومقاساتها وسعاتها. البحث يشمل الاسم والكود وSKU والباركود."
      decor={IMG.bag1} actions={<Btn icon="fa-solid fa-plus" onClick={() => go('/products/new')}>منتج جديد</Btn>} />
    <div className="content" data-screen-label="Products list">
      <DataTable rows={rows} query={q} setQuery={setQuery} searchPh="الاسم، الكود، SKU أو الباركود" forceState={forceState}
        onRowClick={r => go('/products/' + r.id)}
        filters={<>
          <div style={{ width: 200 }}><SearchSelect value={q.cat ? +q.cat : null} onChange={v => setQuery({ cat: v || '', page: 1 })} placeholder="القسم" clearable
            options={CATS.map(c => ({ value: c.id, label: catPath(c.id).map(x => x.ar).join(' / ') }))} searchPh="ابحث عن قسم…" /></div>
          {q.cat && <label className="fchip" style={{ gap: 8 }}><Check checked={q.sub === '1'} onChange={v => setQuery({ sub: v ? '1' : '', page: 1 })} />تضمين الفرعية</label>}
          <FilterSelect label="البراند" value={q.brand} onChange={v => setQuery({ brand: v, page: 1 })} options={BRANDS.map(b => [b.id, b.en])} />
          <FilterSelect label="الحالة" value={q.status} onChange={v => setQuery({ status: v, page: 1 })} options={Object.entries(P_STATUS).map(([k, v]) => [k, v[0]])} />
          <FilterSelect label="قناة البيع" value={q.channel} onChange={v => setQuery({ channel: v, page: 1 })} options={Object.entries(CHANNELS)} />
          <button className={'fchip ' + (q.featured === '1' ? 'on' : '')} onClick={() => setQuery({ featured: q.featured === '1' ? '' : '1', page: 1 })}><i className="fa-solid fa-star" style={{ fontSize: 11 }}></i>مميز</button>
          <span style={{ width: 1, height: 22, background: 'var(--line-2)', margin: '0 4px' }}></span>
          <label className="fchip"><span className="muted">ترتيب:</span><select value={q.sort || 'newest'} onChange={e => setQuery({ sort: e.target.value === 'newest' ? '' : e.target.value })}><option value="newest">الأحدث</option><option value="oldest">الأقدم</option><option value="name">الاسم</option></select><i className="fa-solid fa-chevron-down car"></i></label>
        </>}
        activeFilters={af} onClearFilters={() => setQuery({ cat: '', sub: '', brand: '', status: '', channel: '', featured: '', page: 1 })}
        empty={af.length || q.q ? <EmptyState icon="fa-solid fa-magnifying-glass" title="لا توجد منتجات مطابقة" text="جرّب كلمة بحث أخرى أو أزل بعض الفلاتر." action={<Btn kind="ghost" onClick={() => setQuery({ q: '', cat: '', sub: '', brand: '', status: '', channel: '', featured: '', page: 1 })}>مسح البحث والفلاتر</Btn>} />
          : <EmptyState icon="fa-solid fa-bag-shopping" title="الكتالوج فارغ" text="أضف أول شنطة أو محفظة. ستُحفظ كمسودة حتى تكتمل الصور والأسعار." action={<Btn icon="fa-solid fa-plus" onClick={() => go('/products/new')}>منتج جديد</Btn>} />}
        columns={[
          { k: 'p', label: 'المنتج', render: r => <div className="cell-main"><div className="thumb">{r.img ? <img src={r.img} alt="" /> : <i className="fa-regular fa-image muted"></i>}</div><div style={{ minWidth: 0 }}><b>{r.ar}</b><small className="mono ltr">{r.code}</small></div></div> },
          { k: 'c', label: 'القسم / البراند', render: r => <div style={{ lineHeight: 1.4 }}><div>{catById(r.cat).ar}</div><small className="muted">{brandById(r.brand).en}</small></div> },
          { k: 's', label: 'الحالة', render: r => <Badge tone={P_STATUS[r.status][1]}>{P_STATUS[r.status][0]}</Badge> },
          { k: 'ch', label: 'قناة البيع', render: r => <span style={{ fontSize: 12.5 }}>{CHANNELS[r.channel]}</span> },
          { k: 'v', label: 'المتغيرات', cls: 'num', render: r => r.variants || <span className="muted">0</span> },
          { k: 'pr', label: 'سعر التجزئة', cls: 'num', render: r => r.price && r.channel !== 'wholesale' ? <span className="ltr" style={{ display: 'inline-block' }}>{r.price[0] === r.price[1] ? fmtMoney(r.price[0]) : fmtMoney(r.price[0]) + ' – ' + fmtMoney(r.price[1])} <small className="muted">EGP</small></span> : <span className="muted">—</span> },
          { k: 'f', label: '', render: r => r.featured ? <i className="fa-solid fa-star star" title="مميز"></i> : null },
          { k: 'a', label: '', cls: 'act', render: r => <span onClick={e => e.stopPropagation()}><button className="icon-btn" onClick={() => go('/products/' + r.id)} title="تعديل"><i className="fa-regular fa-pen-to-square"></i></button><button className="icon-btn" title="المزيد"><i className="fa-solid fa-ellipsis"></i></button></span> },
        ]} />
    </div>
  </>;
}

Object.assign(window, { CategoriesPage, BrandsPage, ProductsPage, slugify });// ===== Phase 1: Branches & locations, Users, Roles, Settings =====
const TYPE_LBL = { branch: 'فرع', warehouse: 'مخزن' };

function BranchForm({ open, onClose, initial }) {
  const { toast } = useApp();
  const [f, setF] = useState(initial || { name: '', code: '', type: '', phone: '', email: '', city: '', address: '', active: true });
  const [err, setErr] = useState({}); const [saving, setSaving] = useState(false); const [dirty, setDirty] = useState(false);
  useEffect(() => { setF(initial || { name: '', code: '', type: '', phone: '', email: '', city: '', address: '', active: true }); setErr({}); setDirty(false); }, [open]);
  const set = (k, v) => { setF(x => ({ ...x, [k]: v })); setDirty(true); setErr(e => ({ ...e, [k]: null })); };
  const save = async () => {
    const e = {};
    if (!f.name) e.name = 'اسم الفرع مطلوب.';
    if (!f.code) e.code = 'الكود مطلوب.';
    else if (BRANCHES.some(b => b.code === f.code && (!initial || b.id !== initial.id))) e.code = 'هذا الكود مستخدم بالفعل لفرع آخر.';
    if (!f.type) e.type = 'اختر نوع الموقع.';
    if (f.email && !/^\S+@\S+\.\S+$/.test(f.email)) e.email = 'صيغة البريد غير صحيحة.';
    setErr(e); if (Object.keys(e).length) return;
    setSaving(true); await sleep(700); setSaving(false); toast(initial ? 'تم تحديث الفرع' : 'تمت إضافة الفرع'); onClose();
  };
  return (
    <Drawer open={open} onClose={onClose} dirty={dirty} title={initial ? 'تعديل ' + initial.name : 'إضافة فرع أو مخزن'} crumbs={<><span>الفروع</span><span className="sep">/</span><span className="cur">{initial ? 'تعديل' : 'جديد'}</span></>}
      footer={<><Btn loading={saving} onClick={save}>{initial ? 'حفظ التغييرات' : 'إضافة'}</Btn><Btn kind="ghost" onClick={onClose}>إلغاء</Btn><span className="sp" style={{ flex: 1 }}></span>{dirty && <span className="dirty">غير محفوظ</span>}</>}>
      <div className="grid g2">
        <Field label="النوع" req error={err.type} className="spanall">
          <div className="radio-cards g2" style={{ display: 'grid' }}>
            {[['branch', 'فرع', 'نقطة بيع للعملاء ومخزون عرض'], ['warehouse', 'مخزن', 'تخزين وتوزيع بدون بيع مباشر']].map(o => (
              <div key={o[0]} className={'rcard ' + (f.type === o[0] ? 'on' : '')} onClick={() => set('type', o[0])} style={err.type ? { borderColor: 'var(--danger)' } : null}>
                <span className="dot"></span><div><b>{o[1]}</b><span>{o[2]}</span></div></div>
            ))}
          </div>
        </Field>
        <Field label="الاسم" req error={err.name}><Input value={f.name} error={err.name} onChange={e => set('name', e.target.value)} placeholder="مثال: فرع المعادي" /></Field>
        <Field label="الكود" req error={err.code} help="حروف إنجليزية وأرقام، يظهر في المستندات."><Input ltr className="mono" value={f.code} error={err.code} onChange={e => set('code', e.target.value.toUpperCase())} placeholder="MAD-04" /></Field>
        <Field label="الهاتف" opt><Input ltr value={f.phone} onChange={e => set('phone', e.target.value)} placeholder="01xxxxxxxxx" /></Field>
        <Field label="البريد الإلكتروني" opt error={err.email}><Input ltr value={f.email} error={err.email} onChange={e => set('email', e.target.value)} placeholder="branch@alfarid.eg" /></Field>
        <Field label="المدينة" opt><Input value={f.city} onChange={e => set('city', e.target.value)} /></Field>
        <Field label="العنوان" opt><Input value={f.address} onChange={e => set('address', e.target.value)} /></Field>
        <div className="spanall"><Toggle checked={f.active} onChange={v => set('active', v)} label={f.active ? 'نشط — يظهر في عمليات البيع والمخزون' : 'غير نشط'} /></div>
      </div>
    </Drawer>
  );
}

function BranchesPage() {
  const { route, setQuery, go, forceState } = useApp();
  const q = route.query; const [form, setForm] = useState(null); const [del, setDel] = useState(null);
  const rows = useMemo(() => BRANCHES.filter(b => (!q.q || (b.name + b.code + b.city).toLowerCase().includes(q.q.toLowerCase())) && (!q.type || b.type === q.type) && (!q.status || String(b.active) === (q.status === 'active' ? 'true' : 'false'))), [q.q, q.type, q.status]);
  const af = [q.type && { k: 'type', label: 'النوع: ' + TYPE_LBL[q.type], clear: () => setQuery({ type: '', page: 1 }) }, q.status && { k: 'st', label: q.status === 'active' ? 'نشط' : 'غير نشط', clear: () => setQuery({ status: '', page: 1 }) }].filter(Boolean);
  return <>
    <PageHeader crumbs={[['الرئيسية', '/products'], ['التشغيل'], ['الفروع والمخازن']]} light="الفروع" bold="والمخازن" sub="كل موقع له كود فريد. مواقع التخزين (رفوف، خانات…) تُدار من داخل تفاصيل كل فرع."
      actions={<Btn icon="fa-solid fa-plus" onClick={() => setForm({})}>إضافة فرع</Btn>} />
    <div className="content" data-screen-label="Branches">
      <DataTable rows={rows} query={q} setQuery={setQuery} searchPh="ابحث بالاسم أو الكود أو المدينة" forceState={forceState}
        onRowClick={r => go('/branches/' + r.id)}
        filters={<><FilterSelect label="النوع" value={q.type} onChange={v => setQuery({ type: v, page: 1 })} options={[['branch', 'فرع'], ['warehouse', 'مخزن']]} />
          <FilterSelect label="الحالة" value={q.status} onChange={v => setQuery({ status: v, page: 1 })} options={[['active', 'نشط'], ['inactive', 'غير نشط']]} /></>}
        activeFilters={af} onClearFilters={() => setQuery({ type: '', status: '', page: 1 })}
        empty={<EmptyState icon="fa-solid fa-store" title="لا توجد فروع بعد" text="أضف أول فرع أو مخزن لتبدأ في توزيع المخزون." action={<Btn icon="fa-solid fa-plus" onClick={() => setForm({})}>إضافة فرع</Btn>} />}
        columns={[
          { k: 'name', label: 'الاسم', render: r => <div className="cell-main"><div className="thumb"><i className={r.type === 'warehouse' ? 'fa-solid fa-warehouse muted' : 'fa-solid fa-store muted'}></i></div><div><b>{r.name}</b><small className="mono ltr">{r.code}</small></div></div> },
          { k: 'type', label: 'النوع', render: r => <Badge tone={r.type === 'warehouse' ? 'accent' : 'line'} nodot>{TYPE_LBL[r.type]}</Badge> },
          { k: 'city', label: 'المدينة', render: r => r.city || <span className="muted">—</span> },
          { k: 'phone', label: 'الهاتف', render: r => r.phone ? <span className="ltr mono">{r.phone}</span> : <span className="muted">—</span> },
          { k: 'loc', label: 'مواقع التخزين', cls: 'num', render: r => r.locations },
          { k: 'active', label: 'الحالة', render: r => <StatusBadge active={r.active} /> },
          { k: 'a', label: '', cls: 'act', render: r => <span onClick={e => e.stopPropagation()}>
            <button className="icon-btn" title="تعديل" onClick={() => setForm(r)}><i className="fa-regular fa-pen-to-square"></i></button>
            <button className="icon-btn danger" title="حذف" onClick={() => setDel(r)}><i className="fa-regular fa-trash-can"></i></button></span> },
        ]} />
    </div>
    <BranchForm open={!!form} initial={form && form.id ? form : null} onClose={() => setForm(null)} />
    <ConfirmModal open={!!del} onClose={() => setDel(null)} title={'حذف «' + (del && del.name) + '»؟'} text="سيتم حذف الفرع نهائيًا. لا يمكن حذف فرع عليه مخزون أو مستخدمون مرتبطون." confirmLabel="حذف الفرع"
      rejectWith={del && del.locations > 0 ? <>لا يمكن حذف الفرع لوجود <b>{del.locations}</b> مواقع تخزين ومخزون مرتبط به. يمكنك تعطيله بدلًا من الحذف.</> : null} />
  </>;
}

function BranchDetail({ id }) {
  const { go, toast, forceState } = useApp();
  const b = branchById(id) || BRANCHES[0];
  const [tab, setTab] = useState('locations'); const [locForm, setLocForm] = useState(null); const [edit, setEdit] = useState(false);
  return <>
    <PageHeader crumbs={[['الفروع والمخازن', '/branches'], [b.name]]} light={TYPE_LBL[b.type]} bold={b.name}
      sub={<><span className="mono ltr" style={{ display: 'inline-block' }}>{b.code}</span> · {b.city || '—'} · <StatusBadge active={b.active} /></>}
      actions={<Btn kind="ghost" icon="fa-regular fa-pen-to-square" onClick={() => setEdit(true)}>تعديل البيانات</Btn>} />
    <div className="content" data-screen-label="Branch detail">
      <div className="tabs">
        <button className={'tab ' + (tab === 'locations' ? 'on' : '')} onClick={() => setTab('locations')}>مواقع التخزين <span className="n">{LOCATIONS.length}</span></button>
        <button className={'tab ' + (tab === 'info' ? 'on' : '')} onClick={() => setTab('info')}>بيانات الفرع</button>
      </div>
      {tab === 'info' ? (
        <div className="panel"><div className="panel-b"><dl className="kv">
          <dt>الاسم</dt><dd>{b.name}</dd><dt>الكود</dt><dd className="mono">{b.code}</dd><dt>النوع</dt><dd>{TYPE_LBL[b.type]}</dd>
          <dt>الهاتف</dt><dd className="ltr" style={{ textAlign: 'right' }}>{b.phone || '—'}</dd><dt>البريد</dt><dd className="ltr" style={{ textAlign: 'right' }}>{b.email || '—'}</dd>
          <dt>العنوان</dt><dd>{[b.address, b.city].filter(Boolean).join('، ') || '—'}</dd></dl></div></div>
      ) : <>
        <div style={{ display: 'flex', alignItems: 'center', marginBottom: 14, gap: 12 }}>
          <Alert tone="info" icon="fa-solid fa-location-dot">كل المواقع أدناه تابعة لـ <b>{b.name}</b> فقط — الكود فريد داخل الفرع.</Alert>
          <Btn icon="fa-solid fa-plus" onClick={() => setLocForm({})}>موقع تخزين</Btn>
        </div>
        <DataTable rows={LOCATIONS} query={{}} setQuery={() => { }} searchPh="ابحث في مواقع هذا الفرع" forceState={forceState}
          filters={<FilterSelect label="النوع" value="" onChange={() => { }} options={Object.entries(LOC_TYPES)} />}
          columns={[
            { k: 'name', label: 'الاسم', render: r => <b style={{ fontWeight: 500 }}>{r.name}</b> },
            { k: 'code', label: 'الكود', render: r => <span className="mono ltr">{r.code}</span> },
            { k: 'type', label: 'النوع', render: r => <Badge tone={r.type === 'damaged' ? 'danger' : r.type === 'receiving' ? 'accent' : 'line'} nodot>{LOC_TYPES[r.type]}</Badge> },
            { k: 'active', label: 'الحالة', render: r => <StatusBadge active={r.active} /> },
            { k: 'a', label: '', cls: 'act', render: r => <><button className="icon-btn" onClick={() => setLocForm(r)}><i className="fa-regular fa-pen-to-square"></i></button><button className="icon-btn danger"><i className="fa-regular fa-trash-can"></i></button></> },
          ]} />
      </>}
    </div>
    <BranchForm open={edit} initial={b} onClose={() => setEdit(false)} />
    <Drawer open={!!locForm} onClose={() => setLocForm(null)} title={locForm && locForm.id ? 'تعديل موقع تخزين' : 'موقع تخزين جديد'}
      crumbs={<><span>{b.name}</span><span className="sep">/</span><span className="cur">مواقع التخزين</span></>}
      footer={<><Btn onClick={() => { toast('تم حفظ موقع التخزين'); setLocForm(null); }}>حفظ</Btn><Btn kind="ghost" onClick={() => setLocForm(null)}>إلغاء</Btn></>}>
      {locForm && <div className="grid g2">
        <Field label="الفرع" className="spanall" help="الموقع يُنشأ داخل هذا الفرع ولا يمكن نقله لفرع آخر."><Input value={b.name + ' — ' + b.code} readOnly /></Field>
        <Field label="الاسم" req><Input defaultValue={locForm.name} placeholder="مثال: رف المحافظ C" /></Field>
        <Field label="الكود" req><Input ltr className="mono" defaultValue={locForm.code} placeholder="C-02" /></Field>
        <Field label="النوع" req className="spanall">
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            {Object.entries(LOC_TYPES).map(([k, v]) => <LocTypePill key={k} k={k} v={v} init={locForm.type} />)}
          </div>
        </Field>
        <div className="spanall"><Toggle checked={locForm.active !== false} onChange={() => { }} label="نشط" /></div>
      </div>}
    </Drawer>
  </>;
}
function LocTypePill({ k, v, init }) { const [on, setOn] = useState(init === k); return <button type="button" className={'fchip ' + (on ? 'on' : '')} onClick={() => setOn(!on)}>{v}</button>; }

// ---------------- Users ----------------
function UsersPage() {
  const { route, setQuery, toast, forceState } = useApp();
  const q = route.query; const [form, setForm] = useState(null);
  const rows = useMemo(() => USERS.filter(u => (!q.q || (u.name + u.email).toLowerCase().includes(q.q.toLowerCase())) && (!q.role || u.roles.includes(+q.role)) && (!q.branch || String(u.branch) === q.branch)), [q.q, q.role, q.branch]);
  return <>
    <PageHeader crumbs={[['الرئيسية', '/products'], ['الإدارة'], ['المستخدمون']]} bold="المستخدمون" sub="ربط كل مستخدم بفرع وأدوار. الوصول لكل الفروع امتياز صريح منفصل."
      actions={<Btn icon="fa-solid fa-user-plus" onClick={() => setForm({})}>إضافة مستخدم</Btn>} />
    <div className="content" data-screen-label="Users">
      <DataTable rows={rows} query={q} setQuery={setQuery} searchPh="ابحث بالاسم أو البريد" forceState={forceState}
        filters={<><FilterSelect label="الدور" value={q.role} onChange={v => setQuery({ role: v, page: 1 })} options={ROLES.map(r => [r.id, r.name])} />
          <FilterSelect label="الفرع" value={q.branch} onChange={v => setQuery({ branch: v, page: 1 })} options={BRANCHES.map(b => [b.id, b.name])} /></>}
        columns={[
          { k: 'n', label: 'المستخدم', render: r => <div className="cell-main"><div className="avatar">{r.name.split(' ').map(s => s[0]).join('')}</div><div><b>{r.name}</b><small className="ltr" style={{ display: 'block', textAlign: 'right' }}>{r.email}</small></div></div> },
          { k: 'b', label: 'الفرع', render: r => r.all ? <Badge tone="accent" nodot>كل الفروع</Badge> : r.branch ? branchById(r.branch).name : <span className="muted">بدون فرع</span> },
          { k: 'r', label: 'الأدوار', render: r => <div className="tags">{r.roles.map(id => <span key={id} className="tag">{ROLES.find(x => x.id === id).name}</span>)}</div> },
          { k: 's', label: 'الحالة', render: r => <StatusBadge active={r.active} /> },
          { k: 'a', label: '', cls: 'act', render: r => <button className="icon-btn" onClick={() => setForm(r)}><i className="fa-regular fa-pen-to-square"></i></button> },
        ]} />
    </div>
    <UserForm open={!!form} initial={form && form.id ? form : null} onClose={() => setForm(null)} />
  </>;
}
function UserForm({ open, initial, onClose }) {
  const { toast } = useApp();
  const blank = { name: '', email: '', password: '', phone: '', branch: null, roles: [], active: true, all: false };
  const [f, setF] = useState(blank); const [err, setErr] = useState({}); const [saving, setSaving] = useState(false);
  useEffect(() => { setF(initial ? { ...blank, ...initial } : blank); setErr({}); }, [open]);
  const set = (k, v) => { setF(x => ({ ...x, [k]: v })); setErr(e => ({ ...e, [k]: null })); };
  const save = async () => {
    const e = {};
    if (!f.name) e.name = 'الاسم مطلوب.';
    if (!f.email) e.email = 'البريد مطلوب.'; else if (USERS.some(u => u.email === f.email && (!initial || u.id !== initial.id))) e.email = 'هذا البريد مسجّل لمستخدم آخر.';
    if (!initial && !f.password) e.password = 'كلمة المرور مطلوبة عند الإنشاء.'; else if (f.password && f.password.length < 8) e.password = 'كلمة المرور يجب ألا تقل عن ٨ أحرف.';
    setErr(e); if (Object.keys(e).length) return;
    setSaving(true); await sleep(700); setSaving(false); toast('تم حفظ المستخدم'); onClose();
  };
  return (
    <Drawer open={open} onClose={onClose} title={initial ? initial.name : 'مستخدم جديد'} crumbs={<><span>المستخدمون</span><span className="sep">/</span><span className="cur">{initial ? 'تعديل' : 'إضافة'}</span></>}
      footer={<><Btn loading={saving} onClick={save}>حفظ</Btn><Btn kind="ghost" onClick={onClose}>إلغاء</Btn></>}>
      <div className="sec-title">بيانات الحساب</div>
      <div className="grid g2" style={{ marginBottom: 26 }}>
        <Field label="الاسم" req error={err.name} className="spanall"><Input value={f.name} error={err.name} onChange={e => set('name', e.target.value)} /></Field>
        <Field label="البريد الإلكتروني" req error={err.email}><Input ltr value={f.email} error={err.email} onChange={e => set('email', e.target.value)} placeholder="user@alfarid.eg" /></Field>
        <Field label="الهاتف" opt><Input ltr value={f.phone} onChange={e => set('phone', e.target.value)} /></Field>
        <Field label={initial ? 'كلمة مرور جديدة' : 'كلمة المرور'} req={!initial} opt={!!initial} error={err.password} help={initial ? 'اتركها فارغة للإبقاء على كلمة المرور الحالية.' : '٨ أحرف على الأقل.'} className="spanall">
          <PasswordInput value={f.password} error={err.password} onChange={e => set('password', e.target.value)} /></Field>
      </div>
      <div className="sec-title">الفرع والأدوار</div>
      <div className="grid" style={{ marginBottom: 20 }}>
        <Field label="الفرع" opt help={f.branch == null && !f.all ? '«بدون فرع» يعني أن المستخدم غير مرتبط بأي فرع — وليس الوصول لكل الفروع.' : null}>
          <SearchSelect value={f.branch} onChange={v => set('branch', v)} placeholder="بدون فرع" clearable
            options={[{ value: null, label: 'بدون فرع', sub: 'غير مرتبط بفرع' }, ...BRANCHES.map(b => ({ value: b.id, label: b.name, sub: b.code, disabled: !b.active, why: !b.active ? 'غير نشط' : null }))]} searchPh="ابحث عن فرع…" />
        </Field>
        <div className="panel" style={{ padding: 14, background: 'var(--soft-2)' }}>
          <Toggle checked={f.all} onChange={v => set('all', v)} label={<span><b style={{ fontWeight: 600 }}>الوصول لكل الفروع</b> <span className="mono muted" style={{ fontSize: 10.5 }}>branches.access_all</span></span>} />
          <div className="field-help" style={{ marginTop: 6, marginInlineStart: 46 }}>امتياز صريح يمنح الاطلاع على بيانات ومخزون جميع الفروع بغض النظر عن الفرع المرتبط.</div>
        </div>
        <Field label="الأدوار" opt><SearchSelect multi value={f.roles} onChange={v => set('roles', v)} placeholder="اختر دورًا أو أكثر" options={ROLES.map(r => ({ value: r.id, label: r.name, sub: r.users + ' مستخدم' }))} /></Field>
        <Toggle checked={f.active} onChange={v => set('active', v)} label="الحساب نشط" />
      </div>
    </Drawer>
  );
}

// ---------------- Roles ----------------
function RolesPage() {
  const { toast } = useApp();
  const [sel, setSel] = useState(2); const [q, setQ] = useState('');
  const [perms, setPerms] = useState(new Set(['products.view', 'products.create', 'products.update', 'products.publish', 'categories.view', 'categories.manage', 'brands.view', 'brands.manage']));
  const [open, setOpen] = useState({ products: true, categories: true });
  const role = ROLES.find(r => r.id === sel);
  const toggle = k => setPerms(p => { const n = new Set(p); n.has(k) ? n.delete(k) : n.add(k); return n; });
  const toggleGroup = (g, on) => setPerms(p => { const n = new Set(p); g.perms.forEach(([k]) => on ? n.add(k) : n.delete(k)); return n; });
  const ql = q.trim().toLowerCase();
  return <>
    <PageHeader crumbs={[['الرئيسية', '/products'], ['الإدارة'], ['الأدوار والصلاحيات']]} light="الأدوار" bold="والصلاحيات" sub="الصلاحيات معروضة بأسماء مفهومة؛ المفتاح التقني يُرسل كما هو للخادم."
      actions={<Btn icon="fa-solid fa-plus">دور جديد</Btn>} />
    <div className="content" data-screen-label="Roles">
      <div className="split" style={{ gridTemplateColumns: '260px 1fr' }}>
        <div className="panel" style={{ padding: 8 }}>
          {ROLES.map(r => <div key={r.id} className={'role-item ' + (r.id === sel ? 'on' : '')} onClick={() => setSel(r.id)}>
            <div className="avatar" style={{ width: 30, height: 30, fontSize: 11 }}><i className="fa-solid fa-shield-halved"></i></div>
            <div style={{ flex: 1 }}><b>{r.name}</b><small>{r.users} مستخدم{r.system ? ' · دور النظام' : ''}</small></div></div>)}
        </div>
        <div className="panel">
          <div className="panel-h">
            <div style={{ flex: 1 }}><Field label="اسم الدور" req><Input defaultValue={role.name} key={role.id} disabled={role.system} /></Field></div>
          </div>
          <div className="panel-b">
            {role.system ? <Alert tone="info">دور «مدير النظام» يملك كل الصلاحيات ولا يمكن تعديله.</Alert> : <>
              <div style={{ display: 'flex', gap: 12, alignItems: 'center', marginBottom: 16 }}>
                <div className="dt-search" style={{ width: 320 }}><i className="fa-solid fa-magnifying-glass"></i><input className="inp" placeholder="ابحث في الصلاحيات (عربي أو المفتاح)" value={q} onChange={e => setQ(e.target.value)} /></div>
                <span className="muted" style={{ fontSize: 12 }}>محدد {perms.size} من {PERM_GROUPS.reduce((a, g) => a + g.perms.length, 0)}</span>
              </div>
              {PERM_GROUPS.map(g => {
                const list = g.perms.filter(([k, l]) => !ql || k.includes(ql) || l.includes(q));
                if (!list.length) return null;
                const n = g.perms.filter(([k]) => perms.has(k)).length;
                const isOpen = open[g.key] || !!ql;
                return <div className="perm-grp" key={g.key}>
                  <div className="perm-grp-h" onClick={() => setOpen(o => ({ ...o, [g.key]: !o[g.key] }))}>
                    <Check checked={n === g.perms.length} indeterminate={n > 0 && n < g.perms.length} onChange={v => toggleGroup(g, v)} />
                    <b>{g.label}</b><span className="cnt">{n}/{g.perms.length}</span>
                    <i className={'fa-solid fa-chevron-' + (isOpen ? 'up' : 'down')} style={{ fontSize: 10, color: 'var(--ink-4)' }}></i>
                  </div>
                  {isOpen && <div className="perm-list">{list.map(([k, l]) => <div className="perm" key={k}><Check checked={perms.has(k)} onChange={() => toggle(k)} label={l} /><span className="k">{k}</span></div>)}</div>}
                </div>;
              })}
            </>}
          </div>
          {!role.system && <div className="drawer-f" style={{ borderTop: '1px solid var(--line)' }}><Btn onClick={() => toast('تم حفظ صلاحيات الدور')}>حفظ الدور</Btn><Btn kind="ghost">إلغاء</Btn><span style={{ flex: 1 }}></span><Btn kind="danger ghost" icon="fa-regular fa-trash-can">حذف الدور</Btn></div>}
        </div>
      </div>
    </div>
  </>;
}

// ---------------- Settings ----------------
function SettingsPage() {
  const { toast } = useApp();
  const [sec, setSec] = useState('company');
  const [num, setNum] = useState({ prefix: 'INV', sep: '-', year: true, pad: 6, next: 1284 });
  const [ret, setRet] = useState('days'); const [days, setDays] = useState(14);
  const [selfApprove, setSelfApprove] = useState(false);
  const [tax, setTax] = useState({ enabled: true, rate: '14', inclusive: true, reg: '512-883-104' });
  const [dirty, setDirty] = useState(false);
  const d = (fn) => (...a) => { fn(...a); setDirty(true); };
  const preview = [num.prefix, num.year ? '2026' : null, String(num.next).padStart(num.pad, '0')].filter(Boolean).join(num.sep);
  const SECS = [['company', 'fa-regular fa-building', 'بيانات الشركة'], ['tax', 'fa-solid fa-percent', 'الضرائب'], ['numbering', 'fa-solid fa-hashtag', 'ترقيم المستندات'], ['returns', 'fa-solid fa-rotate-left', 'سياسة الإرجاع'], ['purchases', 'fa-solid fa-file-signature', 'المشتريات']];
  return <>
    <PageHeader crumbs={[['الرئيسية', '/products'], ['الإدارة'], ['الإعدادات']]} bold="الإعدادات" sub="إعدادات عامة تنطبق على كل الفروع." />
    <div className="content" data-screen-label="Settings">
      <div className="split">
        <nav className="vnav">{SECS.map(s => <button key={s[0]} className={sec === s[0] ? 'on' : ''} onClick={() => setSec(s[0])}><i className={s[1]}></i>{s[2]}</button>)}</nav>
        <div className="panel">
          {sec === 'company' && <><div className="panel-h"><h3>بيانات الشركة</h3></div><div className="panel-b grid g2">
            <Field label="اسم الشركة" req><Input defaultValue="الفريد للحقائب والجلود" onChange={() => setDirty(true)} /></Field>
            <Field label="الاسم التجاري (إنجليزي)"><Input ltr defaultValue="ALFARID" onChange={() => setDirty(true)} /></Field>
            <Field label="رابط اللوجو" className="spanall" help="الحقل حاليًا يقبل رابطًا نصيًا. رفع اللوجو مباشرة يحتاج مسار رفع في الـ API أولًا.">
              <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
                <div className="thumb" style={{ width: 40, height: 40 }}><LogoMark /></div>
                <Input ltr defaultValue="https://cdn.alfarid.eg/brand/logo.png" onChange={() => setDirty(true)} />
              </div></Field>
            <Field label="الهاتف"><Input ltr defaultValue="19XXX" /></Field>
            <Field label="البريد"><Input ltr defaultValue="hello@alfarid.eg" /></Field>
            <Field label="العنوان" className="spanall"><Input defaultValue="القاهرة، مصر" /></Field>
          </div></>}
          {sec === 'tax' && <><div className="panel-h"><h3>الضرائب</h3></div><div className="panel-b grid g2">
            <div className="spanall"><Toggle checked={tax.enabled} onChange={d(v => setTax(t => ({ ...t, enabled: v })))} label="تفعيل ضريبة القيمة المضافة" /></div>
            <Field label="نسبة الضريبة" req help="حتى منزلتين عشريتين."><div className="inp-wrap"><Input ltr value={tax.rate} disabled={!tax.enabled} onChange={d(e => setTax(t => ({ ...t, rate: e.target.value })))} /><span className="adorn">%</span></div></Field>
            <Field label="رقم التسجيل الضريبي"><Input ltr className="mono" value={tax.reg} disabled={!tax.enabled} onChange={d(e => setTax(t => ({ ...t, reg: e.target.value })))} /></Field>
            <Field label="طريقة عرض الأسعار" className="spanall"><div className="seg">
              <button className={tax.inclusive ? 'on' : ''} onClick={d(() => setTax(t => ({ ...t, inclusive: true })))}>الأسعار شاملة الضريبة</button>
              <button className={!tax.inclusive ? 'on' : ''} onClick={d(() => setTax(t => ({ ...t, inclusive: false })))}>الضريبة تُضاف عند البيع</button></div></Field>
          </div></>}
          {sec === 'numbering' && <><div className="panel-h"><h3>ترقيم المستندات</h3><span className="sp"></span><select className="mini-sel"><option>فواتير البيع</option><option>أوامر الشراء</option><option>المرتجعات</option><option>التحويلات</option></select></div><div className="panel-b">
            <div className="num-preview" style={{ marginBottom: 22 }}><div style={{ flex: 1 }}><small>معاينة الرقم التالي</small><div className="big">{preview}</div></div><i className="fa-solid fa-file-invoice" style={{ fontSize: 22, opacity: .5 }}></i></div>
            <div className="grid g4">
              <Field label="البادئة"><Input ltr className="mono" value={num.prefix} onChange={d(e => setNum(n => ({ ...n, prefix: e.target.value.toUpperCase() })))} /></Field>
              <Field label="الفاصل"><select className="inp" value={num.sep} onChange={d(e => setNum(n => ({ ...n, sep: e.target.value })))}><option value="-">شرطة -</option><option value="/">شرطة مائلة /</option><option value="">بدون</option></select></Field>
              <Field label="عدد الخانات"><Input ltr type="number" min="3" max="10" value={num.pad} onChange={d(e => setNum(n => ({ ...n, pad: +e.target.value || 3 })))} /></Field>
              <Field label="الرقم التالي" help="لا يمكن أن يقل عن آخر رقم صادر (1283)."><Input ltr type="number" value={num.next} error={num.next < 1284 ? 1 : null} onChange={d(e => setNum(n => ({ ...n, next: +e.target.value })))} /></Field>
              <div className="spanall"><Toggle checked={num.year} onChange={d(v => setNum(n => ({ ...n, year: v })))} label="تضمين السنة في الرقم" /></div>
            </div>
          </div></>}
          {sec === 'returns' && <><div className="panel-h"><h3>سياسة الإرجاع</h3></div><div className="panel-b">
            <div className="radio-cards g3" style={{ display: 'grid' }}>
              {[['off', 'متوقف', 'لا يُسمح بالإرجاع'], ['unlimited', 'بدون حد زمني', 'الإرجاع مسموح في أي وقت'], ['days', 'خلال عدد أيام', 'من تاريخ الفاتورة']].map(o =>
                <div key={o[0]} className={'rcard ' + (ret === o[0] ? 'on' : '')} onClick={d(() => setRet(o[0]))}><span className="dot"></span><div><b>{o[1]}</b><span>{o[2]}</span></div></div>)}
            </div>
            {ret === 'days' && <div style={{ maxWidth: 260, marginTop: 18 }}><Field label="عدد الأيام" req><div className="inp-wrap"><Input ltr type="number" min="1" value={days} onChange={d(e => setDays(e.target.value))} /><span className="adorn" style={{ width: 40 }}>يوم</span></div></Field></div>}
            <div className="field-help" style={{ marginTop: 16 }}>يُرسل للخادم: <span className="mono ltr" style={{ display: 'inline-block' }}>{ret === 'off' ? '{ returns_enabled: false }' : ret === 'unlimited' ? '{ returns_enabled: true, return_days: null }' : `{ returns_enabled: true, return_days: ${days} }`}</span></div>
          </div></>}
          {sec === 'purchases' && <><div className="panel-h"><h3>المشتريات</h3></div><div className="panel-b">
            <Toggle checked={selfApprove} onChange={d(setSelfApprove)} label="السماح لمنشئ أمر الشراء باعتماده ذاتيًا" />
            <div className="field-help" style={{ marginTop: 8, marginInlineStart: 46 }}>عند الإيقاف، يجب أن يعتمد الأمر مستخدم آخر لديه صلاحية <span className="mono">purchases.approve</span>.</div>
            {selfApprove && <div style={{ marginTop: 16 }}><Alert tone="warn">الاعتماد الذاتي يقلل الرقابة على المشتريات. يُنصح به للفرق الصغيرة فقط.</Alert></div>}
          </div></>}
          <div className="drawer-f" style={{ borderTop: '1px solid var(--line)' }}>
            <Btn disabled={!dirty} onClick={() => { setDirty(false); toast('تم حفظ الإعدادات'); }}>حفظ الإعدادات</Btn>
            {dirty ? <span className="dirty">تغييرات غير محفوظة</span> : <span className="saved"><i className="fa-solid fa-check"></i>محفوظ</span>}
          </div>
        </div>
      </div>
    </div>
  </>;
}

Object.assign(window, { BranchesPage, BranchDetail, UsersPage, RolesPage, SettingsPage });// ===== Shared components reference (Phase 0) =====
function KitPage() {
  const [ss, setSs] = useState(null); const [ms, setMs] = useState([2]);
  const [c1, setC1] = useState(false); const [c2, setC2] = useState(false);
  const [img, setImg] = useState(null);
  const { toast } = useApp();
  return <>
    <PageHeader crumbs={[['الرئيسية', '/products'], ['مرجع التصميم']]} light="المكونات" bold="المشتركة" sub="كل مكون بحالاته — مشترك بين بوابة الإدارة والمورد. المتجر ونقطة البيع لهم تنقل مستقل." />
    <div className="content" data-screen-label="Component kit">
      <section className="kit-sec"><h2>الحقول</h2><p>عنوان واضح، علامة المطلوب، نص مساعد، خطأ تحت الحقل. البريد وSKU والباركود والروابط LTR.</p>
        <div className="kit-grid">
          <div className="kit-cell"><div className="kl">DEFAULT + REQUIRED</div><Field label="اسم المنتج" req help="يظهر في المتجر والفواتير."><Input placeholder="حقيبة يد «ليلى»" /></Field></div>
          <div className="kit-cell"><div className="kl">ERROR</div><Field label="البريد الإلكتروني" req error="هذا البريد مسجّل لمستخدم آخر."><Input ltr error defaultValue="mona.a@alfarid.eg" /></Field></div>
          <div className="kit-cell"><div className="kl">DISABLED</div><Field label="نسبة الضريبة" help="فعّل الضريبة أولًا."><Input ltr disabled defaultValue="14" /></Field></div>
          <div className="kit-cell"><div className="kl">READ-ONLY</div><Field label="كود المنتج" help="يتولد من الخادم."><Input ltr className="mono" readOnly defaultValue="PRD-024180" /></Field></div>
          <div className="kit-cell"><div className="kl">LTR INSIDE RTL</div><Field label="SKU" opt><Input ltr className="mono" defaultValue="LAI-BLK-M" /></Field></div>
          <div className="kit-cell"><div className="kl">PASSWORD</div><Field label="كلمة المرور" req><PasswordInput defaultValue="alfarid2026" /></Field></div>
          <div className="kit-cell"><div className="kl">TOGGLE · CHECK</div><div className="grid" style={{ gap: 12 }}><Toggle checked={c1} onChange={setC1} label="نشط" /><Toggle checked disabled label="معطل (disabled)" /><Check checked={c2} onChange={setC2} label="تضمين الأقسام الفرعية" /><Check indeterminate label="تحديد جزئي" /></div></div>
          <div className="kit-cell"><div className="kl">PRICE · 2 DECIMALS</div><Field label="سعر التجزئة" req error="رقم بحد أقصى منزلتين عشريتين"><div className="inp-wrap"><Input ltr error defaultValue="2450.999" /><span className="adorn" style={{ fontSize: 10.5 }}>EGP</span></div></Field></div>
        </div></section>

      <section className="kit-sec"><h2>Select قابل للبحث</h2><p>للأقسام والبراندات والفروع — تحميل، عدم وجود نتائج (اكتب «xyz»)، خيارات معطلة مع السبب، واختيار متعدد.</p>
        <div className="kit-grid">
          <div className="kit-cell"><div className="kl">SINGLE · DISABLED OPTIONS</div><Field label="الفرع"><SearchSelect value={ss} onChange={setSs} clearable options={BRANCHES.map(b => ({ value: b.id, label: b.name, sub: b.code, disabled: !b.active, why: b.active ? null : 'غير نشط' }))} /></Field></div>
          <div className="kit-cell"><div className="kl">MULTI</div><Field label="الأدوار"><SearchSelect multi value={ms} onChange={setMs} options={ROLES.map(r => ({ value: r.id, label: r.name }))} /></Field></div>
          <div className="kit-cell"><div className="kl">ERROR</div><Field label="القسم" req error="اختر القسم."><SearchSelect error value={null} onChange={() => { }} options={CATS.map(c => ({ value: c.id, label: c.ar }))} /></Field></div>
        </div></section>

      <section className="kit-sec"><h2>الأزرار والشارات</h2><p>نفس لغة المتجر: Oswald مكثف، زوايا 2px، أسود أساسي.</p>
        <div className="kit-cell" style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
          <Btn icon="fa-solid fa-plus">إجراء أساسي</Btn><Btn kind="ghost">ثانوي</Btn><Btn kind="text">نصي</Btn><Btn kind="accent">تأكيد</Btn><Btn kind="danger">حذف</Btn><Btn kind="danger ghost">حذف ثانوي</Btn><Btn loading>جاري الحفظ</Btn><Btn disabled>معطل</Btn>
          <span style={{ width: 20 }}></span>
          <Badge tone="ok">منشور</Badge><Badge tone="warn">مسودة</Badge><Badge>مؤرشف</Badge><Badge tone="danger">خطأ</Badge><Badge tone="accent" nodot>كل الفروع</Badge><Badge tone="ink" nodot>الغلاف</Badge>
        </div></section>

      <section className="kit-sec"><h2>رفع الملفات</h2><p>القيود معروضة قبل الاختيار، معاينة، تقدم، فشل ملف وإعادة محاولة، حذف وترتيب.</p>
        <div className="kit-grid" style={{ gridTemplateColumns: 'repeat(auto-fill,minmax(340px,1fr))' }}>
          <div className="kit-cell"><div className="kl">SINGLE IMAGE (CATEGORY / BRAND)</div><SingleImageUpload value={img} onChange={setImg} /></div>
          <div className="kit-cell"><div className="kl">FILE LIST STATES</div><div className="files">
            <div className="file"><div className="thumb"><img src={IMG.bag1} alt="" /></div><div className="fmeta"><b>laila-front.jpg</b><small>2.1MB</small><div className="prog"><i style={{ width: '62%' }}></i></div></div><span className="muted" style={{ fontSize: 11 }}>62%</span><button className="icon-btn"><i className="fa-solid fa-xmark"></i></button></div>
            <div className="file failed"><div className="thumb"><img src={IMG.bag2} alt="" /></div><div className="fmeta"><b>mira-side.png</b><small style={{ color: 'var(--danger)' }}>يتجاوز 5MB (7.4MB)</small></div><Btn size="sm" kind="ghost">إعادة</Btn><button className="icon-btn danger"><i className="fa-regular fa-trash-can"></i></button></div>
            <div className="file failed"><div className="thumb"><i className="fa-regular fa-file muted"></i></div><div className="fmeta"><b>logo.svg</b><small style={{ color: 'var(--danger)' }}>صيغة SVG غير مدعومة</small></div><button className="icon-btn danger"><i className="fa-regular fa-trash-can"></i></button></div>
            <div className="file"><div className="thumb"><img src={IMG.wallet} alt="" /></div><div className="fmeta"><b>salim-card.webp</b><small>840KB</small></div><Badge tone="ok">مرفوع</Badge><button className="icon-btn"><i className="fa-solid fa-grip-vertical"></i></button></div>
          </div></div>
        </div></section>

      <section className="kit-sec"><h2>التنبيهات والأخطاء</h2><p>النجاح مختصر في toast. أخطاء العمليات تظهر في مكانها، و errors.status في ملخص واضح.</p>
        <div className="grid g2">
          <Alert tone="danger" title="رفض الخادم الحفظ (422)"><div className="err-summary"><ul>
            <li><span>المتغير 3: سعر التجزئة يجب أن يكون أكبر من صفر. <span className="mono" style={{ opacity: .55 }}>variants.2.retail_price</span></span><a className="to lnk">اذهب ←</a></li>
            <li><span>هذا الرابط مستخدم لمنتج آخر. <span className="mono" style={{ opacity: .55 }}>slug</span></span><a className="to lnk">اذهب ←</a></li></ul></div></Alert>
          <div className="grid" style={{ gap: 12 }}>
            <Alert tone="warn" title="تعارض في التعديل (409)">قام مستخدم آخر بتعديل هذا السجل. حمّل النسخة الأحدث.</Alert>
            <Alert tone="info" icon="fa-solid fa-hourglass-half" title="محاولات كثيرة (429)">حاول مرة أخرى بعد 60 ثانية.</Alert>
            <div><Btn kind="ghost" size="sm" onClick={() => toast('تم حفظ التغييرات')}>عرض toast نجاح</Btn></div>
          </div>
        </div></section>

      <section className="kit-sec"><h2>حالات الصفحة</h2><p>تحميل، فراغ، خطأ مع إعادة محاولة، عدم صلاحية. استخدم Tweaks لفرض أي حالة على كل الشاشات.</p>
        <div className="grid g3">
          <div className="panel"><EmptyState icon="fa-solid fa-bag-shopping" title="الكتالوج فارغ" text="أضف أول شنطة أو محفظة." action={<Btn size="sm" icon="fa-solid fa-plus">منتج جديد</Btn>} /></div>
          <div className="panel"><ErrorState onRetry={() => { }} /></div>
          <div className="panel"><NoAccessState perm="roles.manage" /></div>
        </div></section>

      <section className="kit-sec"><h2>التأكيدات</h2><p>رسالة مخصصة لكل عملية، وحالة رفض الحذف من الخادم.</p>
        <KitConfirms />
      </section>
    </div>
  </>;
}
function KitConfirms() {
  const [a, setA] = useState(false); const [b, setB] = useState(false);
  return <div style={{ display: 'flex', gap: 10 }}>
    <Btn kind="danger ghost" icon="fa-regular fa-trash-can" onClick={() => setA(true)}>حذف ناجح</Btn>
    <Btn kind="danger ghost" icon="fa-regular fa-trash-can" onClick={() => setB(true)}>حذف يرفضه الخادم</Btn>
    <ConfirmModal open={a} onClose={() => setA(false)} title="حذف براند «Atlas»؟" text="سيتم حذف البراند نهائيًا." confirmLabel="حذف" />
    <ConfirmModal open={b} onClose={() => setB(false)} title="حذف قسم «محافظ»؟" text="الحذف نهائي ولا يمكن التراجع عنه." confirmLabel="حذف القسم" rejectWith={<>القسم يحتوي على <b>3</b> أقسام فرعية و<b>61</b> منتجًا مرتبطًا.</>} />
  </div>;
}
Object.assign(window, { KitPage });// ===== Mock data + api-client simulation (data/meta/links preserved, 422 normalized) =====
const IMG = {
  bag1: 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?w=600&auto=format&fit=crop&q=80',
  bag2: 'https://images.unsplash.com/photo-1584917865442-de89df76afd3?w=600&auto=format&fit=crop&q=80',
  bag3: 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=600&auto=format&fit=crop&q=80',
  wallet: 'https://images.unsplash.com/photo-1627123424574-724758594e93?w=600&auto=format&fit=crop&q=80',
  bp1: 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=600&auto=format&fit=crop&q=80',
  bp2: 'https://images.unsplash.com/photo-1622560480605-d83c853bc5c3?w=600&auto=format&fit=crop&q=80',
  walletPhoto: 'https://images.unsplash.com/photo-1627123424574-724758594e93?w=600&auto=format&fit=crop&q=80',
  shoulder: 'https://images.unsplash.com/photo-1590874103328-eac38a683ce7?w=600&auto=format&fit=crop&q=80',
};

const BRANCHES = [
  { id: 1, name: 'فرع مصر الجديدة', code: 'HLP-01', type: 'branch', phone: '01001234567', email: 'heliopolis@alfarid.eg', city: 'القاهرة', address: '٤٥ شارع الميرغني', active: true, locations: 6 },
  { id: 2, name: 'فرع الشيخ زايد', code: 'ZYD-02', type: 'branch', phone: '01112345678', email: 'zayed@alfarid.eg', city: 'الجيزة', address: 'أركان بلازا، مبنى ٣', active: true, locations: 4 },
  { id: 3, name: 'المخزن الرئيسي', code: 'WH-MAIN', type: 'warehouse', phone: '0225550101', email: 'warehouse@alfarid.eg', city: 'العبور', address: 'المنطقة الصناعية، بلوك ١٢', active: true, locations: 18 },
  { id: 4, name: 'فرع سموحة', code: 'ALX-03', type: 'branch', phone: '01223456789', email: 'smouha@alfarid.eg', city: 'الإسكندرية', address: 'شارع فوزي معاذ', active: false, locations: 3 },
  { id: 5, name: 'مخزن المرتجعات', code: 'WH-RET', type: 'warehouse', phone: '', email: '', city: 'العبور', address: '', active: true, locations: 5 },
];
const LOC_TYPES = { shelf: 'رف', zone: 'منطقة', bin: 'خانة', damaged: 'تالف', receiving: 'استلام' };
const LOCATIONS = [
  { id: 1, name: 'رف الحقائب اليدوية A', code: 'A-01', type: 'shelf', active: true },
  { id: 2, name: 'رف المحافظ B', code: 'B-01', type: 'shelf', active: true },
  { id: 3, name: 'منطقة العرض الأمامية', code: 'Z-FRONT', type: 'zone', active: true },
  { id: 4, name: 'خانة الإكسسوارات ٣', code: 'BIN-03', type: 'bin', active: true },
  { id: 5, name: 'منطقة الاستلام', code: 'RCV-01', type: 'receiving', active: true },
  { id: 6, name: 'التالف والمرتجع', code: 'DMG-01', type: 'damaged', active: false },
];

const ROLES = [
  { id: 1, name: 'مدير النظام', users: 2, system: true },
  { id: 2, name: 'مدير الكتالوج', users: 3 },
  { id: 3, name: 'مدير فرع', users: 4 },
  { id: 4, name: 'أمين مخزن', users: 6 },
  { id: 5, name: 'مدخل بيانات', users: 2 },
];
const PERM_GROUPS = [
  { key: 'products', label: 'المنتجات', perms: [
    ['products.view', 'عرض المنتجات'], ['products.create', 'إضافة منتج'], ['products.update', 'تعديل منتج'],
    ['products.delete', 'حذف منتج'], ['products.publish', 'نشر وإلغاء نشر'], ['products.view_cost', 'عرض سعر التكلفة'],
  ]},
  { key: 'categories', label: 'الأقسام والبراندات', perms: [
    ['categories.view', 'عرض الأقسام'], ['categories.manage', 'إدارة الأقسام'], ['brands.view', 'عرض البراندات'], ['brands.manage', 'إدارة البراندات'],
  ]},
  { key: 'branches', label: 'الفروع والمخازن', perms: [
    ['branches.view', 'عرض الفروع'], ['branches.manage', 'إدارة الفروع'], ['locations.manage', 'إدارة مواقع التخزين'], ['branches.access_all', 'الوصول لكل الفروع'],
  ]},
  { key: 'users', label: 'المستخدمون والأدوار', perms: [
    ['users.view', 'عرض المستخدمين'], ['users.manage', 'إدارة المستخدمين'], ['roles.manage', 'إدارة الأدوار والصلاحيات'],
  ]},
  { key: 'purchases', label: 'المشتريات', perms: [
    ['purchases.view', 'عرض أوامر الشراء'], ['purchases.create', 'إنشاء أمر شراء'], ['purchases.approve', 'اعتماد أوامر الشراء'],
  ]},
  { key: 'settings', label: 'الإعدادات', perms: [
    ['settings.view', 'عرض الإعدادات'], ['settings.update', 'تعديل الإعدادات'],
  ]},
];

const USERS = [
  { id: 1, name: 'أحمد الفريد', email: 'ahmed@alfarid.eg', phone: '01001110001', branch: null, roles: [1], active: true, all: true },
  { id: 2, name: 'منى عبد الحميد', email: 'mona.a@alfarid.eg', phone: '01001110002', branch: 1, roles: [2], active: true },
  { id: 3, name: 'كريم سامي', email: 'karim.s@alfarid.eg', phone: '01001110003', branch: 3, roles: [4], active: true },
  { id: 4, name: 'سارة يوسف', email: 'sara.y@alfarid.eg', phone: '', branch: 2, roles: [3, 5], active: true },
  { id: 5, name: 'عمر خالد', email: 'omar.k@alfarid.eg', phone: '01001110005', branch: 4, roles: [3], active: false },
  { id: 6, name: 'ليلى منصور', email: 'laila.m@alfarid.eg', phone: '01001110006', branch: null, roles: [5], active: true },
];

let CATS = [
  { id: 1, ar: 'حقائب نسائية', en: 'Women Bags', parent: null, order: 1, active: true, products: 0, slug: 'women-bags' },
  { id: 2, ar: 'حقائب يد', en: 'Handbags', parent: 1, order: 1, active: true, products: 42 },
  { id: 3, ar: 'كروس بودي', en: 'Crossbody', parent: 1, order: 2, active: true, products: 31 },
  { id: 4, ar: 'ميني كروس', en: 'Mini Crossbody', parent: 3, order: 1, active: true, products: 9 },
  { id: 5, ar: 'توت باج', en: 'Totes', parent: 1, order: 3, active: true, products: 18 },
  { id: 6, ar: 'كلاتش وسهرة', en: 'Clutches', parent: 1, order: 4, active: false, products: 0 },
  { id: 7, ar: 'محافظ', en: 'Wallets', parent: null, order: 2, active: true, products: 0 },
  { id: 8, ar: 'محافظ رجالي', en: 'Men Wallets', parent: 7, order: 1, active: true, products: 27 },
  { id: 9, ar: 'محافظ كروت', en: 'Card Holders', parent: 8, order: 1, active: true, products: 12 },
  { id: 10, ar: 'محافظ نسائية', en: 'Women Wallets', parent: 7, order: 2, active: true, products: 22 },
  { id: 11, ar: 'شنط ظهر', en: 'Backpacks', parent: null, order: 3, active: true, products: 0 },
  { id: 12, ar: 'لابتوب', en: 'Laptop Backpacks', parent: 11, order: 1, active: true, products: 14 },
  { id: 13, ar: 'سفر', en: 'Travel', parent: 11, order: 2, active: true, products: 8 },
  { id: 14, ar: 'شنط سفر', en: 'Luggage', parent: null, order: 4, active: true, products: 6 },
];

const BRANDS = [
  { id: 1, ar: 'الفريد', en: 'ALFARID', slug: 'alfarid', logo: 'AF', order: 1, active: true, products: 120 },
  { id: 2, ar: 'سيدرا', en: 'Sidra Leather', slug: 'sidra-leather', logo: 'SL', order: 2, active: true, products: 38 },
  { id: 3, ar: 'نوماد', en: 'Nomad Gear', slug: 'nomad-gear', logo: 'NG', order: 3, active: true, products: 24 },
  { id: 4, ar: 'لورا', en: 'Lora Paris', slug: 'lora-paris', logo: 'LP', order: 4, active: false, products: 0 },
  { id: 5, ar: 'أطلس', en: 'Atlas', slug: 'atlas', logo: 'AT', order: 5, active: true, products: 11 },
];

const P_STATUS = { draft: ['مسودة', 'warn'], published: ['منشور', 'ok'], archived: ['مؤرشف', ''] };
const CHANNELS = { retail: 'تجزئة', wholesale: 'جملة', both: 'تجزئة وجملة' };

const PRODUCTS = (() => {
  const base = [
    ['حقيبة يد جلد طبيعي «ليلى»', 'Laila Leather Handbag', 2, 1, 'published', 'both', 6, [2450, 2850], true, IMG.bag1],
    ['حقيبة كتف «ميرا» كلاسيك', 'Mira Classic Shoulder', 3, 1, 'published', 'retail', 4, [1890, 1890], false, IMG.bag2],
    ['محفظة كروت جلد «سليم»', 'Salim Card Holder', 9, 2, 'published', 'both', 5, [390, 450], false, IMG.wallet],
    ['شنطة ظهر لابتوب «أوربان»', 'Urban Laptop Backpack', 12, 3, 'draft', 'retail', 3, [1650, 1750], false, IMG.bp1],
    ['توت باج «صحارى» كانفاس', 'Sahara Canvas Tote', 5, 1, 'published', 'retail', 8, [990, 1150], true, IMG.bag3],
    ['شنطة ظهر رياضية «سبرنت»', 'Sprint Sport Backpack', 13, 3, 'archived', 'wholesale', 2, null, false, IMG.bp2],
    ['محفظة نسائية طويلة «جود»', 'Jood Long Wallet', 10, 2, 'draft', 'both', 0, null, false, null],
    ['ميني كروس «نور»', 'Nour Mini Crossbody', 4, 4, 'published', 'retail', 6, [1290, 1390], true, IMG.bag2],
    ['حقيبة يد «فريدة» سهرة', 'Farida Evening Bag', 2, 1, 'draft', 'retail', 3, [2100, 2100], false, IMG.bag1],
    ['محفظة رجالي «آدم» بايفولد', 'Adam Bifold Wallet', 8, 1, 'published', 'both', 4, [520, 560], false, IMG.wallet],
  ];
  return base.map((b, i) => ({
    id: 100 + i, ar: b[0], en: b[1], cat: b[2], brand: b[3], status: b[4], channel: b[5], variants: b[6],
    price: b[7], featured: b[8], img: b[9], code: 'PRD-' + String(24180 + i * 7).padStart(6, '0'),
    created: 30 - i,
  }));
})();

const catById = id => CATS.find(c => c.id === id);
const catPath = id => { const p = []; let c = catById(id); while (c) { p.unshift(c); c = catById(c.parent); } return p; };
const catDepth = id => catPath(id).length;
const brandById = id => BRANDS.find(b => b.id === id);
const branchById = id => BRANCHES.find(b => b.id === id);
const fmtMoney = n => n == null ? '—' : Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });

// ---- simulated API client ----
const sleep = ms => new Promise(r => setTimeout(r, ms));
const apiClient = {
  portal: 'admin', // separate session storage per portal: alfarid.session.admin / .supplier / .pos
  async list(rows, { page = 1, per_page = 10, fail = false } = {}) {
    await sleep(550);
    if (fail) { const e = new Error('Network'); e.status = 500; throw e; }
    const total = rows.length, last = Math.max(1, Math.ceil(total / per_page));
    const data = rows.slice((page - 1) * per_page, page * per_page);
    return {
      data,
      meta: { current_page: page, per_page, total, last_page: last, from: total ? (page - 1) * per_page + 1 : 0, to: Math.min(page * per_page, total) },
      links: { first: '?page=1', last: '?page=' + last, prev: page > 1 ? '?page=' + (page - 1) : null, next: page < last ? '?page=' + (page + 1) : null },
    };
  },
  // Normalizes Laravel-style 422 into { status, message, fields: {'variants.2.retail_price': [..]}, summary: [...] }
  normalize(raw) {
    const fields = {}; const summary = [];
    Object.entries(raw.errors || {}).forEach(([k, v]) => { if (k === 'status') summary.push(...v); else fields[k] = v; });
    return { status: raw.status, message: raw.message, fields, summary };
  },
};

// URL state: #/route?page=2&q=...
const parseHash = () => {
  const h = location.hash.replace(/^#/, '') || (location.pathname === '/' ? '/dashboard' : location.pathname + location.search);
  const [path, qs] = h.split('?');
  return { path, query: Object.fromEntries(new URLSearchParams(qs || '')) };
};
const buildHash = (path, query = {}) => {
  const q = new URLSearchParams(Object.entries(query).filter(([, v]) => v !== '' && v != null && v !== false));
  const s = q.toString();
  return '#' + path + (s ? '?' + s : '');
};

Object.assign(window, { IMG, BRANCHES, LOC_TYPES, LOCATIONS, ROLES, PERM_GROUPS, USERS, CATS, BRANDS, PRODUCTS, P_STATUS, CHANNELS,
  catById, catPath, catDepth, brandById, branchById, fmtMoney, sleep, apiClient, parseHash, buildHash });

export default App;
export { App as AdminApp };
