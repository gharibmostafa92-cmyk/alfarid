// Screen 4 — POS Sales Terminal (catalog + cart)
function ScanBar({ customer, onAdd, inputRef }) {
  const [q, setQ] = useState('');
  const [hl, setHl] = useState(0);
  const [open, setOpen] = useState(false);
  const results = useMemo(() => {
    const s = q.trim().toLowerCase();
    if (!s) return [];
    return PRODUCTS.filter(p => p.name.includes(s) || p.sku.toLowerCase().includes(s) || p.barcode.includes(s)).slice(0, 7);
  }, [q]);
  useEffect(() => setHl(0), [q]);
  const pick = p => { onAdd(p); setQ(''); setOpen(false); };
  const onKey = e => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setHl(h => Math.min(h + 1, results.length - 1)); }
    if (e.key === 'ArrowUp') { e.preventDefault(); setHl(h => Math.max(h - 1, 0)); }
    if (e.key === 'Enter') {
      const exact = PRODUCTS.find(p => p.barcode === q.trim() || p.sku.toLowerCase() === q.trim().toLowerCase());
      if (exact) pick(exact); else if (results[hl]) pick(results[hl]);
    }
    if (e.key === 'Escape') { setQ(''); setOpen(false); }
  };
  return <div className="scanbar">
    <span className="scan-ic"><Icon n="barcode" s={18} w={2} /></span>
    <input ref={inputRef} value={q} onChange={e => { setQ(e.target.value); setOpen(true); }} onFocus={() => setOpen(true)} onBlur={() => setTimeout(() => setOpen(false), 150)} onKeyDown={onKey}
      placeholder="امسح الباركود أو ابحث بالاسم / الكود (SKU)…" aria-label="بحث الأصناف" />
    <span className="row gap-1 fs-12 t-3"><Kbd>F2</Kbd></span>
    {open && q && <div className="scan-results">
      {results.length === 0 ? <div className="empty" style={{ padding: 24 }}><h4 className="fs-14">لا يوجد صنف مطابق</h4><p>جرّب الباركود الكامل أو جزء من الاسم</p></div> :
        results.map((p, i) => <button key={p.id} className={cx('scan-row', i === hl && 'hl')} onMouseDown={() => pick(p)} onMouseEnter={() => setHl(i)}>
          <PImg p={p} size="xs" />
          <div className="grow"><div className="fs-13 fw-5 truncate">{p.name}</div><div className="fs-12 t-3 ltr" style={{ textAlign: 'right' }}><span className="mono">{p.sku}</span> · <span className="mono">{p.barcode}</span></div></div>
          <span className={cx('badge', p.stock === 0 ? 'b-bad' : p.stock <= p.reorder ? 'b-warn' : 'b-neutral')}>{p.stock === 0 ? 'نفد' : <>رصيد <b className="num">{fmt(p.stock)}</b></>}</span>
          <span className="price fs-14">{egp(customer?.tier === 'wholesale' ? p.wholesale : p.price)}</span>
        </button>)}
      <div className="row between fs-12 t-3" style={{ padding: '8px 10px 4px', borderTop: '1px solid var(--line)', marginTop: 4 }}>
        <span className="row gap-1"><Kbd>↑</Kbd><Kbd>↓</Kbd> تنقّل <Kbd>Enter</Kbd> إضافة</span>
        <Api path={`/pos/products/lookup?q=…${customer ? '&customer_id=' + customer.id : ''}`} />
      </div>
    </div>}
  </div>;
}

function Catalog({ cart, customer, onAdd, scanRef }) {
  const [cat, setCat] = useState('all');
  const list = PRODUCTS.filter(p => cat === 'all' || p.cat === cat);
  const qtyOf = id => cart.find(l => l.id === id)?.qty || 0;
  return <div className="catalog">
    <ScanBar customer={customer} onAdd={onAdd} inputRef={scanRef} />
    <div className="cats">
      {CATS.map(c => <button key={c.id} className="cat" aria-pressed={cat === c.id} onClick={() => setCat(c.id)}>{c.name}<span className="count">{fmt(c.id === 'all' ? PRODUCTS.length : PRODUCTS.filter(p => p.cat === c.id).length)}</span></button>)}
    </div>
    <div className="grid-scroll">
      <div className="pgrid">
        {list.map(p => {
          const q = qtyOf(p.id); const price = customer?.tier === 'wholesale' ? p.wholesale : p.price;
          return <button key={p.id} className={cx('pcard', p.stock === 0 && 'out')} onClick={() => p.stock > 0 && onAdd(p)} disabled={p.stock === 0}>
            {q > 0 && <span className="qty-tag">{fmt(q)}</span>}
            {p.stock === 0 ? <span className="stock-tag badge b-bad" style={{ height: 22 }}>نفد</span> : p.stock <= p.reorder ? <span className="stock-tag badge b-warn" style={{ height: 22 }}>باقي {fmt(p.stock)}</span> : null}
            <PImg p={p} />
            <div style={{ padding: '0 4px' }}><div className="pcard-name">{p.name}</div><div className="mono t-3" style={{ fontSize: 11 }}>{p.sku}</div></div>
            <div className="pcard-foot">
              <span className="price fs-16">{egp(price)}<small>ج</small></span>
              {customer?.tier === 'wholesale' && <span className="badge b-info" style={{ height: 20, fontSize: 11 }}>جملة</span>}
            </div>
          </button>;
        })}
      </div>
    </div>
  </div>;
}

function CustomerBar({ customer, setCustomer, toast }) {
  const [q, setQ] = useState('');
  const [open, setOpen] = useState(false);
  const [quick, setQuick] = useState(false);
  const [nw, setNw] = useState({ name: '', phone: '', tier: 'retail' });
  const ref = useRef();
  useOutside(ref, () => setOpen(false));
  const res = CUSTOMERS.filter(c => !q || c.phone.includes(q) || c.name.includes(q));
  if (customer) return <div className="cust"><div className="cust-chip">
    <span className="avatar" style={{ background: 'var(--acc-soft)', color: 'var(--acc)' }}>{customer.name.slice(0, 1)}</span>
    <div className="grow"><div className="fs-13 fw-6 row gap-2">{customer.name}{customer.tier === 'wholesale' ? <span className="badge b-info" style={{ height: 20, fontSize: 11 }}>أسعار جملة</span> : <span className="badge b-neutral" style={{ height: 20, fontSize: 11 }}>تجزئة</span>}</div>
      <div className="fs-12 t-3 row gap-1"><span className="num ltr">{customer.phone}</span><span className="dot-sep" /><Icon n="star" s={12} />{fmt(customer.points)} نقطة</div></div>
    <button className="icon-btn sm" onClick={() => setCustomer(null)} aria-label="إزالة العميل"><Icon n="x" s={15} /></button>
  </div></div>;
  return <div className="cust" ref={ref}>
    <div className="row gap-2">
      <div className="input-group grow"><Icon n="phone" s={15} /><input className="input" style={{ height: 40 }} value={q} onChange={e => { setQ(e.target.value); setOpen(true); }} onFocus={() => setOpen(true)} placeholder="عميل: رقم الهاتف أو الاسم" /></div>
      <button className="btn btn-secondary" style={{ height: 40, padding: '0 12px' }} onClick={() => setQuick(true)} title="تسجيل عميل سريع"><Icon n="userPlus" s={16} />جديد</button>
    </div>
    {open && <div className="menu" style={{ top: 'calc(100% - 6px)', insetInline: 14 }}>
      {res.map(c => <button key={c.id} onClick={() => { setCustomer(c); setOpen(false); setQ(''); }}>
        <span className="avatar" style={{ width: 26, height: 26, fontSize: 11 }}>{c.name.slice(0, 1)}</span>
        <span className="grow">{c.name}</span><span className="num ltr t-3 fs-12">{c.phone}</span>
      </button>)}
      {res.length === 0 && <button onClick={() => { setNw({ ...nw, phone: q }); setQuick(true); setOpen(false); }}><Icon n="userPlus" s={16} />تسجيل «{q}» كعميل جديد</button>}
    </div>}
    <Modal open={quick} onClose={() => setQuick(false)} title="تسجيل عميل سريع" width={420}
      footer={<><button className="btn btn-secondary" onClick={() => setQuick(false)}>إلغاء</button><button className="btn btn-primary" disabled={!nw.name || nw.phone.length < 10} onClick={() => { const c = { id: 99, name: nw.name, phone: nw.phone, tier: nw.tier, points: 0 }; setCustomer(c); setQuick(false); toast('تم تسجيل العميل وربطه بالفاتورة'); }}>حفظ وربط بالفاتورة</button></>}>
      <div className="col gap-4">
        <Field label="الاسم" req><input className="input" autoFocus value={nw.name} onChange={e => setNw({ ...nw, name: e.target.value })} placeholder="اسم العميل" /></Field>
        <Field label="رقم الهاتف" req><input className="input ltr num" style={{ textAlign: 'right' }} value={nw.phone} onChange={e => setNw({ ...nw, phone: toAsciiNum(e.target.value) })} placeholder="01xxxxxxxxx" /></Field>
        <Field label="نوع التسعير"><Seg value={nw.tier} onChange={v => setNw({ ...nw, tier: v })} options={[{ value: 'retail', label: 'تجزئة + نقاط ولاء' }, { value: 'wholesale', label: 'أسعار جملة' }]} /></Field>
      </div>
    </Modal>
  </div>;
}

function CartLine({ l, onQty, onRemove, onPrice, canOverride, flash }) {
  const p = pById(l.id);
  const [edit, setEdit] = useState(false);
  const [val, setVal] = useState(String(l.price));
  const overridden = l.price !== l.listPrice;
  return <div className={cx('line', flash && 'flash')}>
    <PImg p={p} size="sm" />
    <div style={{ minWidth: 0 }}>
      <div className="line-name truncate">{p.name}</div>
      <div className="line-sub">
        {edit ? <input className="price-input" autoFocus value={val} onChange={e => setVal(toAsciiNum(e.target.value))} onBlur={() => { onPrice(Number(val || l.listPrice)); setEdit(false); }} onKeyDown={e => { if (e.key === 'Enter') e.target.blur(); if (e.key === 'Escape') { setVal(String(l.price)); setEdit(false); } }} />
          : <span className={cx('num', canOverride && 'price-edit')} onClick={() => canOverride && (setVal(String(l.price)), setEdit(true))} title={canOverride ? 'تعديل السعر (pos.override_price)' : undefined}>{egp(l.price)} ج</span>}
        {overridden && !edit && <><s className="num">{egp(l.listPrice)}</s><span className="badge b-warn" style={{ height: 18, fontSize: 10.5, padding: '0 6px' }}>سعر معدّل</span></>}
        {l.qty > p.stock && <span className="badge b-bad" style={{ height: 18, fontSize: 10.5, padding: '0 6px' }}>الرصيد {fmt(p.stock)}</span>}
      </div>
    </div>
    <div className="col" style={{ alignItems: 'flex-end', gap: 6 }}>
      <div className="row gap-1">
        <div className="stepper-q">
          <button onClick={() => onQty(l.qty + 1)} aria-label="زيادة"><Icon n="plus" s={14} /></button>
          <span>{fmt(l.qty)}</span>
          <button onClick={() => l.qty > 1 ? onQty(l.qty - 1) : onRemove()} aria-label="نقص"><Icon n="minus" s={14} /></button>
        </div>
        <button className="icon-btn sm" onClick={onRemove} aria-label="حذف" style={{ color: 'var(--text-3)' }}><Icon n="trash" s={15} /></button>
      </div>
      <span className="price fs-14">{egp(l.price * l.qty)}</span>
    </div>
  </div>;
}

function Cart({ cart, setCart, customer, setCustomer, discount, setDiscount, onHold, onHeld, heldCount, onPay, onClear, lastAdded, toast }) {
  const canOverride = CASHIER.perms.includes('pos.override_price');
  const subtotal = cart.reduce((s, l) => s + l.listPrice * l.qty, 0);
  const lineAdj = cart.reduce((s, l) => s + (l.listPrice - l.price) * l.qty, 0);
  const total = Math.max(0, subtotal - lineAdj - discount);
  const items = cart.reduce((s, l) => s + l.qty, 0);
  const [discOpen, setDiscOpen] = useState(false);
  const [discVal, setDiscVal] = useState('');
  return <aside className="cart">
    <CustomerBar customer={customer} setCustomer={setCustomer} toast={toast} />
    <div className="cart-head">
      <div className="row gap-2"><h3 className="fs-16">الفاتورة الحالية</h3>{items > 0 && <span className="badge b-neutral num">{fmt(items)} قطعة</span>}</div>
      {cart.length > 0 && <button className="btn btn-ghost btn-sm" onClick={onClear}><Icon n="trash" s={14} />مسح</button>}
    </div>
    <div className="cart-list">
      {cart.length === 0 ? <div className="empty" style={{ paddingTop: 56 }}>
        <div className="empty-ic"><Icon n="barcode" s={24} /></div>
        <h4>الفاتورة فارغة</h4>
        <p>امسح باركود صنف أو اضغط على أي صنف من الكتالوج لإضافته.</p>
        <div className="row gap-2 fs-12 t-3" style={{ marginTop: 8 }}><Kbd>F2</Kbd> بحث <Kbd>F6</Kbd> المعلقة <Kbd>F8</Kbd> مرتجع</div>
      </div> : cart.map(l => <CartLine key={l.id} l={l} canOverride={canOverride} flash={lastAdded?.id === l.id ? lastAdded.k : null}
        onQty={q => setCart(c => c.map(x => x.id === l.id ? { ...x, qty: q } : x))}
        onPrice={pr => setCart(c => c.map(x => x.id === l.id ? { ...x, price: pr } : x))}
        onRemove={() => setCart(c => c.filter(x => x.id !== l.id))} />)}
    </div>
    <div className="totals">
      <div className="r"><span>المجموع الفرعي</span><b>{egp(subtotal)}</b></div>
      {lineAdj !== 0 && <div className="r"><span>تعديلات الأسعار</span><b className="t-warn">−{egp(lineAdj)}</b></div>}
      <div className="r">
        <span className="row gap-2">الخصم {discOpen ? <span className="row gap-1"><input className="price-input" autoFocus value={discVal} onChange={e => setDiscVal(toAsciiNum(e.target.value))} onKeyDown={e => { if (e.key === 'Enter') { setDiscount(Math.min(Number(discVal || 0), subtotal)); setDiscOpen(false); } }} onBlur={() => { setDiscount(Math.min(Number(discVal || 0), subtotal)); setDiscOpen(false); }} placeholder="0" /></span> : <button className="t-acc fs-12 fw-6" onClick={() => { setDiscVal(discount ? String(discount) : ''); setDiscOpen(true); }} disabled={!cart.length}>{discount ? 'تعديل' : '+ إضافة'}</button>}</span>
        <b className={discount ? 't-ok' : ''}>{discount ? '−' + egp(discount) : '٠'}</b>
      </div>
      <div className="grand"><span className="fs-13 t-2">الإجمالي</span><span className="v">{egp(total)}<small>ج.م</small></span></div>
    </div>
    <div className="actions">
      <button className="btn btn-secondary" disabled={!cart.length} onClick={onHold}><Icon n="pause" s={15} />تعليق</button>
      <button className="btn btn-secondary" onClick={onHeld}><Icon n="inbox" s={16} />المعلقة{heldCount > 0 && <span className="badge-count">{fmt(heldCount)}</span>}<Kbd>F6</Kbd></button>
      <button className="btn btn-danger" disabled={!cart.length} onClick={onClear} aria-label="إلغاء ومسح الفاتورة" title="إلغاء ومسح الفاتورة"><Icon n="x" s={18} /></button>
    </div>
    <button className="pay-btn" disabled={!cart.length} onClick={onPay}>
      <span className="row gap-2">إتمام الدفع <span className="kbd" style={{ background: 'rgba(255,255,255,.15)', borderColor: 'rgba(255,255,255,.35)', color: '#fff' }}>Space</span></span>
      <span className="amt num">{egp(total)} ج</span>
    </button>
  </aside>;
}

Object.assign(window, { Catalog, Cart });
