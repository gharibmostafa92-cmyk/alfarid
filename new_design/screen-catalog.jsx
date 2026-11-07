// Catalog: Products list, Product editor, Categories tree.
function Products({ go, params }) {
  const toast = useToast();
  const [status, setStatus] = useState(params.status || '');
  const [q, setQ] = useState('');
  const [channel, setChannel] = useState('');
  const [featured, setFeatured] = useState(false);
  const [sort, setSort] = useState('newest');
  const [sel, setSel] = useState([]);
  const [page, setPage] = useState(1);
  const all = MOCK.products;
  const counts = { '': all.length, published: all.filter(p => p.status === 'published').length, draft: all.filter(p => p.status === 'draft').length, archived: all.filter(p => p.status === 'archived').length };
  let rows = all.filter(p => (!status || p.status === status) && (!channel || p.channel === channel) && (!featured || p.featured) && (!q || p.ar.includes(q) || p.code.toLowerCase().includes(q.toLowerCase())));
  if (sort === 'name') rows = [...rows].sort((a, b) => a.en.localeCompare(b.en));
  if (sort === 'oldest') rows = [...rows].reverse();
  const filters = [q && ['بحث', q, () => setQ('')], channel && ['القناة', CHANNEL[channel], () => setChannel('')], featured && ['مميز', 'نعم', () => setFeatured(false)]].filter(Boolean);
  const allSel = rows.length > 0 && rows.every(r => sel.includes(r.id));
  return <div className="page-enter">
    <PageHead title="المنتجات" sub="المنتج يُنشأ كمسودة، ثم تُضاف الصور والأسعار، ثم يُنشر."
      actions={<><button className="btn btn-secondary"><Icon n="download" s={16} />تصدير</button><button className="btn btn-primary" onClick={() => go('product', { id: 'new' })}><Icon n="plus" s={16} />منتج جديد</button></>} />

    <Card>
      {/* Status as tabs (it's a filter, not a KPI) */}
      <div className="tabs" role="tablist" style={{ padding: '0 8px' }}>
        {[['', 'الكل'], ['published', 'منشور'], ['draft', 'مسودة'], ['archived', 'مؤرشف']].map(([k, l]) => <button key={k} role="tab" aria-selected={status === k} onClick={() => { setStatus(k); setPage(1); }}>{l}<span className="tab-count">{fmt(counts[k])}</span></button>)}
      </div>
      {sel.length > 0 ? <div className="toolbar" style={{ background: 'var(--acc-tint)' }}>
        <Check on={allSel ? true : 'mixed'} onChange={() => setSel([])} label="إلغاء التحديد" />
        <span className="fs-13 fw-6">تم تحديد {fmt(sel.length)}</span>
        <span style={{ width: 1, height: 20, background: 'var(--line-strong)', margin: '0 4px' }} />
        <button className="btn btn-secondary btn-sm" onClick={() => { toast(`تم نشر ${fmt(sel.length)} منتج`); setSel([]); }}><Icon n="globe" s={14} />نشر</button>
        <button className="btn btn-secondary btn-sm" onClick={() => { toast('تمت الأرشفة'); setSel([]); }}><Icon n="folder" s={14} />أرشفة</button>
        <button className="btn btn-danger btn-sm ms-auto"><Icon n="trash" s={14} />حذف</button>
      </div> : <div className="toolbar">
        <Search value={q} onChange={setQ} placeholder="الاسم أو الكود أو SKU أو الباركود" />
        <FilterSelect label="القسم" value="" onChange={() => {}} options={MOCK.categories.map(c => ({ value: String(c.id), label: c.ar }))} />
        <FilterSelect label="القناة" value={channel} onChange={setChannel} options={[{ value: 'retail', label: 'تجزئة' }, { value: 'wholesale', label: 'جملة' }, { value: 'both', label: 'تجزئة وجملة' }]} />
        <button className={cx('chip', featured && 'on')} onClick={() => setFeatured(f => !f)}><Icon n="star" s={13} />مميز</button>
        <div className="ms-auto row gap-2">
          <span className="fs-12 t-3 hide-mobile">ترتيب</span>
          <select className="input" style={{ width: 130, height: 34, fontSize: 12 }} value={sort} onChange={e => setSort(e.target.value)}><option value="newest">الأحدث</option><option value="oldest">الأقدم</option><option value="name">الاسم</option></select>
        </div>
      </div>}
      {filters.length > 0 && sel.length === 0 && <div className="active-filters">
        <span className="t-2"><b className="num t-1">{fmt(rows.length)}</b> نتيجة</span>
        {filters.map(([k, v, clr]) => <span key={k} className="filter-tag">{k}: <b className="fw-6">{v}</b><button onClick={clr} aria-label="إزالة"><Icon n="x" s={11} w={2.4} /></button></span>)}
        <button className="t-acc fw-6" onClick={() => { setQ(''); setChannel(''); setFeatured(false); }}>مسح الكل</button>
      </div>}

      {rows.length === 0 ? <Empty icon="search" title="لا توجد منتجات مطابقة" body="البحث يشمل الاسم والكود وSKU والباركود. جرّب كلمات أقل أو امسح الفلاتر." action={<button className="btn btn-secondary" onClick={() => { setQ(''); setChannel(''); setFeatured(false); setStatus(''); }}>مسح كل الفلاتر</button>} /> :
      <div className="table-wrap"><table className="tbl">
        <thead><tr>
          <th style={{ width: 44 }}><Check on={allSel ? true : sel.length ? 'mixed' : false} onChange={v => setSel(v ? rows.map(r => r.id) : [])} label="تحديد الكل" /></th>
          <th>المنتج</th><th className="hide-mobile">القسم</th><th>الحالة</th><th className="hide-mobile">القناة</th><th className="n">المخزون</th><th className="n">سعر التجزئة</th><th style={{ width: 48 }} />
        </tr></thead>
        <tbody>{rows.map(r => <tr key={r.id} className={cx('clickable', sel.includes(r.id) && 'selected')} onClick={() => go('product', { id: r.id })}>
          <td onClick={e => e.stopPropagation()}><Check on={sel.includes(r.id)} onChange={v => setSel(s => v ? [...s, r.id] : s.filter(x => x !== r.id))} label={'تحديد ' + r.ar} /></td>
          <td><div className="row gap-3" style={{ minWidth: 240 }}>
            <span className="thumb" style={{ background: r.img }} />
            <div style={{ minWidth: 0 }}><div className="fw-6 row gap-2">{r.ar}{r.featured && <Icon n="star" s={13} className="t-acc" style={{ fill: 'currentColor' }} />}</div><div className="mono t-3 ltr" style={{ display: 'inline-block' }}>{r.code} · {fmt(r.variants)} متغيرات</div></div>
          </div></td>
          <td className="t-2 hide-mobile">{r.cat}</td>
          <td><Status kind="product" s={r.status} /></td>
          <td className="t-2 hide-mobile">{CHANNEL[r.channel]}</td>
          <td className="n">{r.stock === 0 ? <span className="t-3">—</span> : r.stock < 40 ? <span className="t-warn fw-6">{fmt(r.stock)}</span> : fmt(r.stock)}</td>
          <td className="n">{r.channel === 'wholesale' ? <span className="t-3 fs-12">جملة فقط</span> : r.min == null ? <span className="badge b-warn">ناقص</span> : <span className="fw-6">{r.min === r.max ? money(r.min) : `${money(r.min)}–${money(r.max)}`} <span className="t-3 fs-12 fw-5">ج.م</span></span>}</td>
          <td onClick={e => e.stopPropagation()}><div className="row-act"><Menu trigger={({ toggle }) => <button className="icon-btn sm" onClick={toggle} aria-label="إجراءات"><Icon n="dots" s={16} /></button>}
            items={[{ label: 'تعديل', icon: 'edit', onClick: () => go('product', { id: r.id }) }, { label: 'نسخ', icon: 'copy' }, { label: 'عرض في المتجر', icon: 'globe' }, '-', { label: 'حذف', icon: 'trash', danger: true }]} /></div></td>
        </tr>)}</tbody>
      </table></div>}
      {rows.length > 0 && <Pager page={page} pages={4} total={38} from={1} to={rows.length} onPage={setPage} />}
    </Card>
  </div>;
}

function ProductEditor({ go, params }) {
  const toast = useToast();
  const isNew = params.id === 'new';
  const base = MOCK.products.find(p => String(p.id) === String(params.id)) || MOCK.products[0];
  const [tab, setTab] = useState('basic');
  const [p, setP] = useState(isNew ? { ar: '', en: '', code: '', channel: 'both', cat: '', desc: '' } : { ar: base.ar, en: base.en, code: base.code, channel: base.channel, cat: base.cat, desc: 'حقيبة عملية للاستخدام اليومي بخامة مقاومة للمياه وجيب مبطن للابتوب حتى ١٥٫٦ بوصة.' });
  const [dirty, setDirty] = useState(false);
  const set = (k, v) => { setP(x => ({ ...x, [k]: v })); setDirty(true); };
  const variants = [['أسود', '#18151c', 'BP-URB-20-BLK', 850, 640, 142], ['كحلي', '#1e2a4a', 'BP-URB-20-NVY', 850, 640, 54], ['رمادي', '#8b8f99', 'BP-URB-20-GRY', 850, 640, 18], ['زيتي', '#556b2f', 'BP-URB-20-OLV', 950, 700, 0]];
  const ready = [
    ['القسم وكل الأقسام الأب نشطة', !!p.cat, 'basic'],
    ['متغير نشط واحد على الأقل', !isNew, 'variants'],
    ['أسعار المتغيرات النشطة مكتملة', !isNew, 'variants'],
    ['صورة واحدة على الأقل', !isNew, 'media'],
    ['صلاحية نشر المنتجات', true, null],
  ];
  const ok = ready.filter(r => r[1]).length;
  const tabs = [['basic', 'البيانات الأساسية'], ['variants', 'المتغيرات والأسعار'], ['media', 'الصور'], ['review', 'المراجعة والنشر']];
  return <div className="page-enter" style={{ paddingBottom: 72 }}>
    <PageHead back={{ label: 'المنتجات', onClick: () => go('products') }} title={isNew ? 'منتج جديد' : p.ar}
      sub={!isNew && <span className="row gap-2 wrap"><Status kind="product" s={base.status} /><span className="mono t-3 ltr">{base.code}</span><span className="t-3">· آخر تعديل منذ ٣ أيام</span></span>}
      actions={!isNew && <Menu trigger={({ toggle }) => <button className="btn btn-secondary" onClick={toggle}><Icon n="dots" s={16} />المزيد</button>} items={[{ label: 'نسخ المنتج', icon: 'copy' }, { label: 'عرض في المتجر', icon: 'globe' }, { label: 'سجل التغييرات', icon: 'history' }, '-', { label: 'أرشفة', icon: 'folder' }, { label: 'حذف', icon: 'trash', danger: true }]} />} />

    <div className="grid pe-grid" style={{ gridTemplateColumns: 'minmax(0, 1fr) 320px', gap: 20, alignItems: 'start' }}>
      <Card>
        <div className="tabs" role="tablist" style={{ padding: '0 8px' }}>
          {tabs.map(([k, l], i) => <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)}>
            <span className="num" style={{ width: 20, height: 20, borderRadius: 99, display: 'inline-grid', placeItems: 'center', fontSize: 11, background: tab === k ? 'var(--acc)' : 'var(--neutral-bg)', color: tab === k ? '#fff' : 'var(--text-2)' }}>{fmt(i + 1)}</span>{l}
          </button>)}
        </div>
        <div className="card-pad">
          {tab === 'basic' && <div className="col gap-5">
            <div className="grid fg-2" style={{ gridTemplateColumns: '1fr 1fr', gap: 16 }}>
              <Field label="الاسم بالعربية" req><input className="input" value={p.ar} onChange={e => set('ar', e.target.value)} placeholder="مثال: حقيبة ظهر أوربان" /></Field>
              <Field label="الاسم بالإنجليزية" req><input className="input ltr" style={{ textAlign: 'left' }} value={p.en} onChange={e => set('en', e.target.value)} placeholder="Urban Backpack" /></Field>
              <Field label="القسم" req hint="الأقسام غير النشطة تمنع النشر"><select className="input" value={p.cat} onChange={e => set('cat', e.target.value)}><option value="">اختر القسم</option>{MOCK.products.map(x => x.cat).filter((v, i, a) => a.indexOf(v) === i).map(c => <option key={c}>{c}</option>)}</select></Field>
              <Field label="كود المنتج" hint="يُولَّد تلقائيًا إن تُرك فارغًا"><input className="input ltr mono" style={{ textAlign: 'left' }} value={p.code} onChange={e => set('code', e.target.value)} placeholder="BP-XXX-00" /></Field>
            </div>
            <Field label="قناة البيع" req>
              <div className="grid fg-3" style={{ gridTemplateColumns: 'repeat(3, 1fr)', gap: 10 }}>
                {[['retail', 'تجزئة', 'المتجر ونقاط البيع'], ['wholesale', 'جملة', 'التجار والموزعون فقط'], ['both', 'تجزئة وجملة', 'كل القنوات']].map(([k, l, d]) => <button type="button" key={k} onClick={() => set('channel', k)} className="col gap-1" style={{ padding: 14, borderRadius: 12, textAlign: 'start', border: `1.5px solid ${p.channel === k ? 'var(--acc)' : 'var(--line-strong)'}`, background: p.channel === k ? 'var(--acc-tint)' : 'var(--bg-card)' }}>
                  <span className="row between"><span className="fs-13 fw-6">{l}</span><span style={{ width: 16, height: 16, borderRadius: 99, border: `1.5px solid ${p.channel === k ? 'var(--acc)' : 'var(--line-strong)'}`, display: 'grid', placeItems: 'center' }}>{p.channel === k && <span style={{ width: 8, height: 8, borderRadius: 99, background: 'var(--acc)' }} />}</span></span>
                  <span className="fs-12 t-3">{d}</span>
                </button>)}
              </div>
            </Field>
            <Field label="الوصف"><textarea className="input" rows={4} value={p.desc} onChange={e => set('desc', e.target.value)} /></Field>
            <details className="sunken" style={{ padding: '12px 14px' }}><summary className="fs-13 fw-6" style={{ cursor: 'pointer' }}>الأبعاد والوزن والضمان <span className="t-3 fw-5">(اختياري)</span></summary>
              <div className="grid fg-4" style={{ gridTemplateColumns: 'repeat(4, 1fr)', gap: 12, marginTop: 14 }}>
                {[['الطول', 'سم'], ['العرض', 'سم'], ['الارتفاع', 'سم'], ['الوزن', 'كجم']].map(([l, u]) => <Field key={l} label={l}><div className="input-group"><input className="input num" inputMode="decimal" style={{ paddingInlineStart: 12 }} /><span className="suffix">{u}</span></div></Field>)}
              </div>
            </details>
          </div>}
          {tab === 'variants' && <div className="col gap-4">
            <div className="row between wrap gap-3"><div><div className="fs-14 fw-6">المحاور</div><div className="fs-12 t-3">اللون · ٤ قيم</div></div><button className="btn btn-secondary btn-sm"><Icon n="plus" s={14} />إضافة محور</button></div>
            <div className="card" style={{ overflow: 'hidden' }}><div className="table-wrap"><table className="tbl">
              <thead><tr><th>المتغير</th><th>SKU</th><th className="n">سعر التجزئة</th><th className="n">سعر الجملة</th><th className="n">المخزون</th><th>نشط</th></tr></thead>
              <tbody>{variants.map(([n, c, sku, r, w, s], i) => <tr key={sku}>
                <td><span className="row gap-2"><span style={{ width: 16, height: 16, borderRadius: 99, background: c, boxShadow: 'inset 0 0 0 1px rgba(0,0,0,.1)' }} />{n}</span></td>
                <td className="mono t-2 ltr">{sku}</td>
                <td className="n"><input className="input num" defaultValue={r} style={{ width: 96, height: 32, textAlign: 'end' }} /></td>
                <td className="n"><input className="input num" defaultValue={w} style={{ width: 96, height: 32, textAlign: 'end' }} /></td>
                <td className="n">{s === 0 ? <span className="t-3">—</span> : fmt(s)}</td>
                <td><Toggle on={i < 3} onChange={() => setDirty(true)} label="نشط" /></td>
              </tr>)}</tbody>
            </table></div></div>
          </div>}
          {tab === 'media' && <div className="col gap-4">
            <div style={{ border: '1.5px dashed var(--line-strong)', borderRadius: 16, padding: 32, textAlign: 'center' }} className="col gap-2" >
              <span className="empty-ic" style={{ margin: '0 auto 4px' }}><Icon n="image" s={24} /></span>
              <div className="fs-14 fw-6">اسحب الصور هنا أو <span className="t-acc">اختر من جهازك</span></div>
              <div className="fs-12 t-3">JPG أو PNG أو WebP · حتى ٥ ميجابايت · الصورة الأولى هي الرئيسية</div>
            </div>
            <div className="grid" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(120px, 1fr))', gap: 12 }}>
              {['#2a2530,#5c5363', '#1e2a4a,#4a5a82', '#8b8f99,#c5c8ce'].map((g, i) => <div key={i} style={{ aspectRatio: '1', borderRadius: 14, background: `linear-gradient(135deg, ${g})`, position: 'relative' }}>{i === 0 && <span className="badge b-ink" style={{ position: 'absolute', top: 8, insetInlineStart: 8, height: 22, fontSize: 11 }}>رئيسية</span>}</div>)}
            </div>
          </div>}
          {tab === 'review' && <div className="col gap-3">
            {ready.map(([l, okk, t]) => <div key={l} className="row gap-3 sunken" style={{ padding: '12px 14px' }}>
              <span style={{ width: 22, height: 22, borderRadius: 99, display: 'grid', placeItems: 'center', background: okk ? 'var(--ok-bg)' : 'var(--bad-bg)', color: okk ? 'var(--ok)' : 'var(--bad)' }}><Icon n={okk ? 'check' : 'x'} s={12} w={3} /></span>
              <span className="grow fs-13">{l}</span>
              {!okk && t && <button className="btn btn-ghost btn-sm" onClick={() => setTab(t)}>إصلاح <Icon n="arrowLeft" s={13} /></button>}
            </div>)}
          </div>}
        </div>
      </Card>

      {/* Sticky side summary — readiness always visible, not hidden in a last tab */}
      <div className="col gap-4 pe-side" style={{ position: 'sticky', top: 84 }}>
        <Card pad>
          <div className="row between" style={{ marginBottom: 12 }}><span className="fs-14 fw-6">جاهزية النشر</span><span className="num fs-13 fw-6">{fmt(ok)}/{fmt(ready.length)}</span></div>
          <div className="row gap-1" style={{ marginBottom: 14 }}>{ready.map((r, i) => <span key={i} className="grow" style={{ height: 6, borderRadius: 99, background: r[1] ? 'var(--ok)' : 'var(--bg-active)' }} />)}</div>
          <ul className="col gap-2" style={{ listStyle: 'none', margin: 0, padding: 0 }}>
            {ready.map(([l, okk, t]) => <li key={l} className="row gap-2 fs-12"><Icon n={okk ? 'check' : 'info'} s={14} w={2.4} className={okk ? 't-ok' : 't-3'} /><span className={okk ? 't-2' : 't-1 fw-5'}>{l}</span></li>)}
          </ul>
        </Card>
        {!isNew && <Card pad>
          <div className="fs-14 fw-6" style={{ marginBottom: 12 }}>ملخص</div>
          <dl className="col gap-3 fs-13" style={{ margin: 0 }}>
            {[['المتغيرات', fmt(base.variants)], ['إجمالي المخزون', fmt(base.stock) + ' قطعة'], ['نطاق السعر', base.min ? `${money(base.min)}–${money(base.max)} ج.م` : '—'], ['القناة', CHANNEL[base.channel]]].map(([k, v]) => <div key={k} className="row between"><dt className="t-2">{k}</dt><dd className="fw-6 num" style={{ margin: 0 }}>{v}</dd></div>)}
          </dl>
        </Card>}
      </div>
    </div>

    {/* Sticky save bar — appears only when dirty or new */}
    {(dirty || isNew) && <div style={{ position: 'fixed', bottom: 20, insetInline: 0, zIndex: 45, display: 'flex', justifyContent: 'center', pointerEvents: 'none' }} className="save-bar">
      <div className="card row gap-3" style={{ padding: '8px 8px 8px 8px', paddingInlineStart: 18, boxShadow: 'var(--sh-pop)', pointerEvents: 'auto', borderRadius: 16 }}>
        <span className="row gap-2 fs-13"><span style={{ width: 8, height: 8, borderRadius: 99, background: 'var(--warn)' }} />تغييرات غير محفوظة</span>
        <button className="btn btn-ghost btn-sm" onClick={() => setDirty(false)}>تجاهل</button>
        <button className="btn btn-secondary btn-sm" onClick={() => { setDirty(false); toast('تم حفظ المسودة'); }}>حفظ كمسودة</button>
        <button className="btn btn-accent btn-sm" disabled={ok < ready.length} title={ok < ready.length ? 'أكمل متطلبات النشر أولًا' : ''} onClick={() => { setDirty(false); toast('تم النشر'); }}>حفظ ونشر</button>
      </div>
    </div>}
  </div>;
}

function Categories({ go }) {
  const toast = useToast();
  const [open, setOpen] = useState({ 1: true, 2: true, 22: true });
  const [sel, setSel] = useState(2);
  const [q, setQ] = useState('');
  const find = (nodes, id, path = []) => { for (const n of nodes) { if (n.id === id) return { n, path }; const r = find(n.children || [], id, [...path, n]); if (r) return r; } };
  const selected = find(MOCK.categories, sel);
  const total = (function c(ns) { return ns.reduce((a, n) => a + 1 + c(n.children || []), 0); })(MOCK.categories);
  const match = n => !q || n.ar.includes(q) || n.en.toLowerCase().includes(q.toLowerCase()) || (n.children || []).some(match);
  const render = (n, d) => {
    if (!match(n)) return null;
    const has = (n.children || []).length > 0, isOpen = open[n.id] || !!q;
    return <div key={n.id}>
      <div className="row gap-2 cat-row" onClick={() => setSel(n.id)} style={{ height: 44, paddingInlineStart: 8 + d * 24, paddingInlineEnd: 8, borderRadius: 10, cursor: 'pointer', background: sel === n.id ? 'var(--acc-tint)' : '', boxShadow: sel === n.id ? 'inset 0 0 0 1px var(--acc-soft)' : '' }}>
        <span className="t-3 drag" style={{ cursor: 'grab' }}><Icon n="drag" s={14} /></span>
        <button className="icon-btn sm" style={{ width: 24, height: 24, visibility: has ? 'visible' : 'hidden' }} onClick={e => { e.stopPropagation(); setOpen(o => ({ ...o, [n.id]: !isOpen })); }} aria-label={isOpen ? 'طي' : 'فتح'}><Icon n="chevLeft" s={14} style={{ transform: isOpen ? 'rotate(-90deg)' : '', transition: 'transform .15s' }} /></button>
        {d === 0 ? <span className="thumb" style={{ width: 28, height: 28, borderRadius: 8, background: n.img }} /> : <span className="t-3"><Icon n={has ? 'folder' : 'tag'} s={16} /></span>}
        <span className={cx('grow fs-13 truncate', d === 0 ? 'fw-6' : 'fw-5', n.status === 'inactive' && 't-3')}>{n.ar}</span>
        {n.status === 'inactive' && <span className="badge b-neutral" style={{ height: 20, fontSize: 11 }}>غير نشط</span>}
        <span className="fs-12 t-3 num" style={{ minWidth: 40, textAlign: 'end' }}>{fmt(n.count)}</span>
      </div>
      {has && isOpen && n.children.map(c => render(c, d + 1))}
    </div>;
  };
  return <div className="page-enter">
    <PageHead title="الأقسام" sub="اسحب لإعادة الترتيب أو النقل. الحد الأقصى ٣ مستويات."
      actions={<button className="btn btn-primary"><Icon n="plus" s={16} />قسم رئيسي</button>} />
    <div className="grid cat-grid" style={{ gridTemplateColumns: 'minmax(0, 1.25fr) minmax(0, 1fr)', gap: 20, alignItems: 'start' }}>
      <Card>
        <div className="toolbar"><Search value={q} onChange={setQ} placeholder="ابحث بالاسم العربي أو الإنجليزي" /><span className="ms-auto fs-12 t-3">{fmt(total)} قسم</span>
          <button className="btn btn-ghost btn-sm" onClick={() => { const all = {}; (function w(ns) { ns.forEach(n => { all[n.id] = true; w(n.children || []); }); })(MOCK.categories); setOpen(all); }}>فتح الكل</button></div>
        <div style={{ padding: 8 }}>{MOCK.categories.map(n => render(n, 0))}</div>
      </Card>
      <div style={{ position: 'sticky', top: 84 }}>
        {selected ? <Card>
          <div className="card-pad row gap-4" style={{ borderBottom: '1px solid var(--line)' }}>
            <span className="thumb lg" style={{ background: selected.n.img || selected.path[0]?.img || 'var(--bg-sunken)' }} />
            <div className="grow">
              <div className="fs-12 t-3">{selected.path.length ? selected.path.map(p => p.ar).join(' › ') : 'قسم رئيسي'}</div>
              <div className="fs-20 display fw-5">{selected.n.ar}</div>
              <div className="ltr fs-13 t-2" style={{ textAlign: 'right' }}>{selected.n.en}</div>
            </div>
            <Status kind="category" s={selected.n.status} />
          </div>
          <div className="grid" style={{ gridTemplateColumns: 'repeat(3, 1fr)', borderBottom: '1px solid var(--line)' }}>
            {[['المنتجات', selected.n.count], ['أقسام فرعية', (selected.n.children || []).length], ['المستوى', selected.path.length + 1]].map(([k, v], i) => <div key={k} style={{ padding: '14px 20px', borderInlineStart: i ? '1px solid var(--line)' : 0 }}><div className="fs-12 t-3">{k}</div><div className="fs-20 num fw-5">{fmt(v)}</div></div>)}
          </div>
          <div className="card-pad col gap-3">
            <div className="row between sunken" style={{ padding: '12px 14px' }}><div><div className="fs-13 fw-6">ظاهر في المتجر</div><div className="fs-12 t-3">إخفاء القسم يخفي كل أقسامه الفرعية</div></div><Toggle on={selected.n.status === 'active'} onChange={() => toast('تم تحديث حالة القسم')} label="ظاهر" /></div>
            <div className="row gap-2 wrap">
              <button className="btn btn-primary grow"><Icon n="edit" s={15} />تعديل</button>
              <button className="btn btn-secondary grow" disabled={selected.path.length >= 2}><Icon n="plus" s={15} />قسم فرعي</button>
              <button className="btn btn-secondary" onClick={() => go('products')}>عرض المنتجات</button>
              <button className="icon-btn" style={{ color: 'var(--bad)' }} aria-label="حذف"><Icon n="trash" s={16} /></button>
            </div>
          </div>
        </Card> : <Card><Empty icon="tree" title="اختر قسمًا" body="اختر قسمًا من الشجرة لعرض تفاصيله وتعديله." /></Card>}
      </div>
    </div>
  </div>;
}
Object.assign(window, { Products, ProductEditor, Categories });
