/* ============ 3. Shipping · 4. Pricing · 5. Promos/Coupons · 12. Brands ============ */

const SHIP_COMPANIES = [
  { id: 1, name: 'بوسطة', code: 'BOSTA', url: 'https://bosta.co/tracking-shipments?shipment-number={awb}', active: true, api: true },
  { id: 2, name: 'أرامكس', code: 'ARAMEX', url: 'https://www.aramex.com/track/results?ShipmentNumber={awb}', active: true, api: true },
  { id: 3, name: 'مرسول إكسبريس', code: 'MRSOOL', url: 'https://track.mrsool.eg/{awb}', active: true, api: false },
  { id: 4, name: 'مندوب داخلي', code: 'INHOUSE', url: '', active: true, api: false },
  { id: 5, name: 'J&T Express', code: 'JNT', url: 'https://www.jtexpress.eg/track?awb={awb}', active: false, api: false },
];
const SHIP_ZONES = [
  { id: 1, name: 'القاهرة الكبرى', cities: ['القاهرة', 'الجيزة'], active: true },
  { id: 2, name: 'الإسكندرية والساحل', cities: ['الإسكندرية'], active: true },
  { id: 3, name: 'الدلتا', cities: ['المنصورة', 'طنطا', 'الزقازيق', 'دمياط'], active: true },
  { id: 4, name: 'القناة', cities: ['بورسعيد', 'الإسماعيلية', 'السويس'], active: true },
  { id: 5, name: 'الصعيد', cities: ['أسيوط', 'سوهاج', 'الأقصر', 'أسوان', 'المنيا', 'بني سويف', 'الفيوم'], active: true },
  { id: 6, name: 'البحر الأحمر', cities: ['الغردقة'], active: false },
];
const SHIP_RATES = [
  { id: 1, co: 'BOSTA', zone: 1, base: 45, free: 1500, cod: 10, min: 1, max: 2 },
  { id: 2, co: 'BOSTA', zone: 3, base: 60, free: 2000, cod: 12, min: 2, max: 3 },
  { id: 3, co: 'ARAMEX', zone: 2, base: 65, free: null, cod: 15, min: 2, max: 4 },
  { id: 4, co: 'ARAMEX', zone: 5, base: 85, free: 0, cod: 15, min: 3, max: 6 },
  { id: 5, co: 'INHOUSE', zone: 1, base: 30, free: 800, cod: 0, min: 0, max: 1 },
  { id: 6, co: 'MRSOOL', zone: 4, base: 70, free: null, cod: 10, min: 2, max: 4 },
];

function TabsBar({ tabs, value, onChange }) {
  return <div className="v-pill p-1.5 inline-flex items-center gap-1 stg stg-2 flex-wrap">
    {tabs.map(t => <button key={t.value} onClick={() => onChange(t.value)} className="px-4 py-2 rounded-full text-[13px] font-medium transition flex items-center gap-2"
      style={value === t.value ? { background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' } : { color: 'var(--text-secondary)' }}>
      {t.icon && <Icon name={t.icon} size={14} />}{t.label}{t.count != null && <span className="num text-[11px] opacity-70">{t.count}</span>}</button>)}
  </div>;
}

/* ---------------- SHIPPING ---------------- */
function ShippingPage() {
  const [tab, setTab] = useState('companies');
  const [edit, setEdit] = useState(null);
  return <div className="space-y-5">
    <PageHead title="الشحن" sub="شركات الشحن، ومناطق التغطية، وأسعار كل شركة لكل منطقة."
      actions={<button className="btn btn-dark" onClick={() => setEdit({ kind: tab, row: null })}><Icon name="plus" size={14} />{{ companies: 'شركة شحن', zones: 'منطقة شحن', rates: 'سعر شحن' }[tab]}</button>} />
    <TabsBar value={tab} onChange={setTab} tabs={[
      { value: 'companies', label: 'شركات الشحن', icon: 'truck', count: SHIP_COMPANIES.length },
      { value: 'zones', label: 'مناطق الشحن', icon: 'globe', count: SHIP_ZONES.length },
      { value: 'rates', label: 'أسعار الشحن', icon: 'tag', count: SHIP_RATES.length }]} />
    {tab === 'companies' && <Card stg={3}><DataTable rows={SHIP_COMPANIES} onRow={r => setEdit({ kind: 'companies', row: r })} cols={[
      { h: 'الشركة', r: c => <div className="flex items-center gap-2.5"><span className="w-9 h-9 rounded-[11px] v-sub flex items-center justify-center tx2"><Icon name="truck" size={15} /></span><span className="font-semibold tx1">{c.name}</span></div> },
      { h: 'الكود', r: c => <span className="num text-[11.5px] font-semibold v-sub px-2 py-1 rounded-md tx2">{c.code}</span> },
      { h: 'قالب رابط التتبع', r: c => c.url ? <span className="num text-[11.5px] tx2 ltr clamp1 max-w-[340px]" dir="ltr">{c.url.split('{awb}')[0]}<b className="accent">{'{awb}'}</b>{c.url.split('{awb}')[1]}</span> : <span className="tx3 text-[11.5px]">بدون تتبع</span> },
      { h: 'تكامل API', r: c => c.api ? <Badge tone="blue">متصل</Badge> : <span className="tx3 text-[11.5px]">—</span> },
      { h: 'التفعيل', r: c => <ActiveDot on={c.active} /> },
      { h: '', r: c => <RowActions><button className="icon-btn !w-7 !h-7" onClick={() => setEdit({ kind: 'companies', row: c })}><Icon name="edit" size={13} /></button></RowActions> },
    ]} /></Card>}
    {tab === 'zones' && <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
      {SHIP_ZONES.map((z, i) => <div key={z.id} className={'v-card p-5 stg stg-' + (2 + i % 4) + ' cursor-pointer hover:shadow-sm transition'} onClick={() => setEdit({ kind: 'zones', row: z })}>
        <div className="flex items-center justify-between mb-3"><span className="font-display text-[16px] font-semibold tx1">{z.name}</span><ActiveDot on={z.active} /></div>
        <div className="text-[11.5px] tx3 mb-2"><Num>{z.cities.length}</Num> مدن</div>
        <div className="flex flex-wrap gap-1.5">{z.cities.map(c => <span key={c} className="v-sub px-2.5 py-1 rounded-full text-[11.5px] tx2">{c}</span>)}</div>
      </div>)}
    </div>}
    {tab === 'rates' && <Card stg={3}><DataTable rows={SHIP_RATES} onRow={r => setEdit({ kind: 'rates', row: r })} cols={[
      { h: 'الشركة', r: r => <span className="font-semibold tx1">{SHIP_COMPANIES.find(c => c.code === r.co).name}</span> },
      { h: 'المنطقة', r: r => <span className="tx2">{SHIP_ZONES.find(z => z.id === r.zone).name}</span> },
      { h: 'التكلفة الأساسية', r: r => fmtMoney(r.base) },
      { h: 'حد الشحن المجاني', r: r => r.free == null ? <span className="tx3 text-[11.5px]">لا يوجد شحن مجاني</span> : r.free === 0 ? <Badge tone="green">مجاني دائمًا</Badge> : <span>أكثر من {fmtMoney(r.free, 0)}</span> },
      { h: 'رسوم الاستلام', r: r => fmtMoney(r.cod) },
      { h: 'مدة التوصيل', r: r => <span className="tx1"><Num>{r.min}–{r.max}</Num> <span className="tx3 text-[11.5px]">يوم</span></span> },
      { h: '', r: r => <RowActions><button className="icon-btn !w-7 !h-7" onClick={() => setEdit({ kind: 'rates', row: r })}><Icon name="edit" size={13} /></button></RowActions> },
    ]} /></Card>}
    {edit?.kind === 'companies' && <CompanyModal row={edit.row} onClose={() => setEdit(null)} />}
    {edit?.kind === 'zones' && <ZoneModal row={edit.row} onClose={() => setEdit(null)} />}
    {edit?.kind === 'rates' && <RateModal row={edit.row} onClose={() => setEdit(null)} />}
  </div>;
}

function CompanyModal({ row, onClose }) {
  const toast = useToast();
  const [url, setUrl] = useState(row?.url || '');
  const [creds, setCreds] = useState(row?.api ? [{ k: 'api_key', v: '••••••••••••' }, { k: 'account_id', v: '88412' }] : []);
  const urlErr = url && (!url.startsWith('https://') ? 'يجب أن يبدأ الرابط بـ https://' : !url.includes('{awb}') ? 'يجب أن يحتوي الرابط على {awb} مكان رقم البوليصة' : null);
  return <Modal open onClose={onClose} title={row ? 'تعديل شركة شحن' : 'شركة شحن جديدة'} icon="truck" width={600}
    footer={<><button className="btn btn-soft" onClick={onClose}>إلغاء</button><button className="btn btn-dark" disabled={!!urlErr} onClick={() => { onClose(); toast('تم الحفظ'); }}>حفظ</button></>}>
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3">
        <Field label="الاسم" req><input className="inp" defaultValue={row?.name} /></Field>
        <Field label="الكود" req hint="فريد على مستوى النظام"><input className="inp num uppercase" dir="ltr" defaultValue={row?.code} /></Field>
      </div>
      <Field label="قالب رابط التتبع" err={urlErr} hint={<>مثال: <span className="num ltr">https://carrier.com/track/{'{awb}'}</span></>}>
        <input className={'inp num ' + (urlErr ? 'err' : '')} dir="ltr" value={url} onChange={e => setUrl(e.target.value)} placeholder="https://…/{awb}" />
      </Field>
      {url && !urlErr && <div className="v-sub px-3.5 py-2.5 text-[11.5px] tx2 flex items-center gap-2"><Icon name="eye" size={13} />معاينة: <span className="num ltr tx1 clamp1" dir="ltr">{url.replace('{awb}', '7731440299')}</span></div>}
      <div>
        <div className="flex items-center justify-between mb-2"><span className="lbl">بيانات اتصال API <span className="tx3 font-normal">(اختياري)</span></span>
          <button className="btn btn-ghost btn-xs" onClick={() => setCreds([...creds, { k: '', v: '' }])}><Icon name="plus" size={12} />حقل</button></div>
        <div className="v-sub p-2 space-y-2">
          {creds.length === 0 && <div className="text-[11.5px] tx3 text-center py-3">لا توجد بيانات اتصال</div>}
          {creds.map((c, i) => <div key={i} className="grid grid-cols-12 gap-2">
            <input className="inp inp-sm col-span-5 num !bg-[var(--bg-card)]" dir="ltr" placeholder="key" defaultValue={c.k} />
            <input className="inp inp-sm col-span-6 num !bg-[var(--bg-card)]" dir="ltr" placeholder="value" defaultValue={c.v} />
            <button className="icon-btn !w-7 !h-7 col-span-1 justify-self-center" onClick={() => setCreds(creds.filter((_, j) => j !== i))}><Icon name="trash" size={12} /></button>
          </div>)}
        </div>
        <div className="text-[11px] tx3 mt-1.5 pretty">كائن مرن (مفتاح/قيمة) — أسماء الحقول مثل API Key أو Secret تختلف حسب شركة التكامل.</div>
      </div>
      <ToggleRow label="مفعّلة" sub="تظهر في قائمة الشركات عند إنشاء شحنة" on={row?.active ?? true} />
    </div>
  </Modal>;
}

function ZoneModal({ row, onClose }) {
  const toast = useToast();
  const [sel, setSel] = useState(row?.cities || []);
  const [q, setQ] = useState('');
  const tg = c => setSel(sel.includes(c) ? sel.filter(x => x !== c) : [...sel, c]);
  return <Modal open onClose={onClose} title={row ? 'تعديل منطقة شحن' : 'منطقة شحن جديدة'} icon="globe" width={560}
    footer={<><button className="btn btn-soft" onClick={onClose}>إلغاء</button><button className="btn btn-dark" onClick={() => { onClose(); toast('تم الحفظ'); }}>حفظ</button></>}>
    <div className="space-y-4">
      <Field label="اسم المنطقة" req><input className="inp" defaultValue={row?.name} /></Field>
      <div>
        <div className="flex items-center justify-between mb-2"><span className="lbl">المدن <span className="accent">*</span></span><span className="text-[11px] tx3"><Num>{sel.length}</Num> مختارة</span></div>
        {sel.length > 0 && <div className="flex flex-wrap gap-1.5 mb-2">{sel.map(c => <span key={c} className="chip-x">{c}<button onClick={() => tg(c)} className="w-4 h-4 rounded-full v-well flex items-center justify-center"><Icon name="x" size={9} sw={3} /></button></span>)}</div>}
        <div className="v-sub p-2">
          <div className="px-1 pb-2"><input className="inp inp-sm !bg-[var(--bg-card)]" placeholder="ابحث عن مدينة" value={q} onChange={e => setQ(e.target.value)} /></div>
          <div className="grid grid-cols-3 gap-1 max-h-[180px] overflow-y-auto">{CITIES.filter(c => c.includes(q)).map(c => <label key={c} className="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-[var(--bg-card)] cursor-pointer text-[12.5px] tx1">
            <input type="checkbox" checked={sel.includes(c)} onChange={() => tg(c)} />{c}</label>)}</div>
        </div>
      </div>
      <ToggleRow label="مفعّلة" on={row?.active ?? true} />
    </div>
  </Modal>;
}

function RateModal({ row, onClose }) {
  const toast = useToast();
  const [min, setMin] = useState(row?.min ?? 1);
  const [max, setMax] = useState(row?.max ?? 3);
  const [freeMode, setFreeMode] = useState(row ? (row.free == null ? 'none' : 'value') : 'none');
  const [free, setFree] = useState(row?.free ?? '');
  const err = Number(max) < Number(min) ? 'أقصى أيام التوصيل لا يقل عن الحد الأدنى' : null;
  return <Modal open onClose={onClose} title={row ? 'تعديل سعر شحن' : 'سعر شحن جديد'} icon="tag" width={560}
    footer={<><button className="btn btn-soft" onClick={onClose}>إلغاء</button><button className="btn btn-dark" disabled={!!err} onClick={() => { onClose(); toast('تم الحفظ'); }}>حفظ</button></>}>
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3">
        <Field label="شركة الشحن" req><select className="inp" defaultValue={row?.co}>{SHIP_COMPANIES.map(c => <option key={c.code} value={c.code}>{c.name}</option>)}</select></Field>
        <Field label="المنطقة" req><select className="inp" defaultValue={row?.zone}>{SHIP_ZONES.map(z => <option key={z.id} value={z.id}>{z.name}</option>)}</select></Field>
        <Field label="التكلفة الأساسية" req><input className="inp num" type="number" defaultValue={row?.base} /></Field>
        <Field label="رسوم الدفع عند الاستلام"><input className="inp num" type="number" defaultValue={row?.cod ?? 0} /></Field>
      </div>
      <div>
        <div className="lbl mb-2">حد الشحن المجاني</div>
        <Seg value={freeMode} onChange={setFreeMode} options={[{ value: 'none', label: 'بدون شحن مجاني' }, { value: 'value', label: 'مجاني فوق مبلغ' }]} />
        {freeMode === 'value' && <div className="mt-2.5"><input className="inp num" type="number" min="0" value={free} onChange={e => setFree(e.target.value)} placeholder="0.00" /></div>}
        <div className="text-[11px] tx3 mt-1.5 pretty">{freeMode === 'none' ? 'الحقل فارغ (null): الشحن مدفوع دائمًا مهما كانت قيمة الطلب.' : Number(free) === 0 && free !== '' ? 'القيمة صفر: الشحن مجاني لكل الطلبات.' : 'يصبح الشحن مجانيًا عندما تتجاوز قيمة الطلب هذا المبلغ.'}</div>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <Field label="أقل عدد أيام"><input className="inp num" type="number" min="0" value={min} onChange={e => setMin(e.target.value)} /></Field>
        <Field label="أقصى عدد أيام" err={err}><input className={'inp num ' + (err ? 'err' : '')} type="number" min="0" value={max} onChange={e => setMax(e.target.value)} /></Field>
      </div>
    </div>
  </Modal>;
}

/* ---------------- PRICING ---------------- */
const PL_TYPES = { retail: 'قطاعي', wholesale: 'جملة', distributor: 'موزع', group: 'مجموعة عملاء', customer: 'عميل محدد' };
const CUSTOMER_GROUPS = [
  { id: 1, name: 'عملاء VIP', code: 'VIP', desc: 'عملاء التجزئة الأعلى إنفاقًا', disc: 7.5, active: true },
  { id: 2, name: 'تجار الجملة — فئة أ', code: 'WHL-A', desc: 'حجم شراء شهري يتجاوز 250 ألف', disc: 12, active: true },
  { id: 3, name: 'تجار الجملة — فئة ب', code: 'WHL-B', desc: null, disc: 8, active: true },
  { id: 4, name: 'الموظفون', code: 'STAFF', desc: 'خصم موظفي الشركة', disc: 15, active: true },
  { id: 5, name: 'المطاعم والكافيهات', code: 'HORECA', desc: 'قطاع الضيافة', disc: 10, active: false },
];
const PRICE_LISTS = [
  { id: 1, name: 'قائمة القطاعي الأساسية', type: 'retail', target: null, branch: null, from: '2026-01-01', to: null, prio: 0, active: true, items: 1240 },
  { id: 2, name: 'أسعار الجملة 2026', type: 'wholesale', target: null, branch: null, from: '2026-01-01', to: '2026-12-31', prio: 100, active: true, items: 860 },
  { id: 3, name: 'موزعي الدلتا', type: 'distributor', target: null, branch: 'فرع المنصورة', from: '2026-07-01', to: null, prio: 150, active: true, items: 412 },
  { id: 4, name: 'خصومات VIP', type: 'group', target: 'عملاء VIP', branch: null, from: '2026-10-01', to: '2026-12-31', prio: 200, active: true, items: 95 },
  { id: 5, name: 'تعاقد سوبر ماركت الأمل', type: 'customer', target: 'سوبر ماركت الأمل', branch: null, from: '2026-09-15', to: '2027-03-15', prio: 500, active: true, items: 64 },
  { id: 6, name: 'عروض فرع سموحة', type: 'retail', target: null, branch: 'فرع الإسكندرية - سموحة', from: '2026-06-01', to: '2026-08-31', prio: 50, active: false, items: 38 },
];

function PricingPage({ go }) {
  const [tab, setTab] = useState('lists');
  const [q, setQ] = useState(''); const [ty, setTy] = useState(''); const [br, setBr] = useState(''); const [act, setAct] = useState('');
  const [grp, setGrp] = useState(''); const [cus, setCus] = useState('');
  const [form, setForm] = useState(null);
  const [gform, setGform] = useState(null);
  const [imp, setImp] = useState(false);
  const toast = useToast();
  const rows = PRICE_LISTS.filter(p => (!q || p.name.includes(q)) && (!ty || p.type === ty) && (!br || p.branch === br) && (!act || String(p.active) === act) && (!grp || p.target === grp) && (!cus || p.target === cus));
  return <div className="space-y-5">
    <PageHead title="التسعير" sub="قوائم الأسعار حسب النوع والفرع والعميل، ومجموعات العملاء."
      actions={tab === 'lists' ? <>
        <button className="btn btn-soft" onClick={() => setImp(true)}><Icon name="upload" size={14} />استيراد CSV</button>
        <button className="btn btn-soft" onClick={() => toast('جارٍ تصدير CSV…')}><Icon name="download" size={14} />تصدير CSV</button>
        <button className="btn btn-dark" onClick={() => setForm({})}><Icon name="plus" size={14} />قائمة أسعار</button></>
        : <button className="btn btn-dark" onClick={() => setGform({})}><Icon name="plus" size={14} />مجموعة عملاء</button>} />
    <TabsBar value={tab} onChange={setTab} tabs={[{ value: 'lists', label: 'قوائم الأسعار', icon: 'tag', count: PRICE_LISTS.length }, { value: 'groups', label: 'مجموعات العملاء', icon: 'users', count: CUSTOMER_GROUPS.length }]} />
    {tab === 'lists' && <Card stg={3}>
      <div className="flex flex-wrap items-center gap-2 mb-4">
        <SearchPill value={q} onChange={setQ} placeholder="اسم القائمة" />
        <FSelect value={ty} onChange={setTy} placeholder="النوع" options={Object.entries(PL_TYPES).map(([v, l]) => ({ value: v, label: l }))} />
        <FSelect value={cus} onChange={setCus} placeholder="العميل" options={WHOLESALE} />
        <FSelect value={grp} onChange={setGrp} placeholder="المجموعة" options={CUSTOMER_GROUPS.map(g => g.name)} />
        <FSelect value={br} onChange={setBr} placeholder="الفرع" options={BRANCHES} />
        <FSelect value={act} onChange={setAct} placeholder="التفعيل" options={[{ value: 'true', label: 'مفعّلة' }, { value: 'false', label: 'معطّلة' }]} />
      </div>
      <DataTable rows={rows} onRow={p => go({ m: 'pricing', id: p.id })} cols={[
        { h: 'الاسم', r: p => <span className="font-semibold tx1">{p.name}</span> },
        { h: 'النوع', r: p => <Badge dot={false} tone={{ retail: 'gray', wholesale: 'violet', distributor: 'blue', group: 'orange', customer: 'green' }[p.type]}>{PL_TYPES[p.type]}</Badge> },
        { h: 'العميل / المجموعة', r: p => p.target ? <span className="tx1 text-[12px]">{p.target}</span> : <span className="tx3">—</span> },
        { h: 'الفرع', r: p => p.branch ? <span className="tx2 text-[12px]">{p.branch}</span> : <span className="tx3 text-[11.5px]">كل الفروع</span> },
        { h: 'العملة', r: () => <span className="num text-[11.5px] font-semibold tx2">EGP</span> },
        { h: 'السريان', r: p => <span className="num text-[11.5px] tx2 ltr">{p.from} → {p.to || '∞'}</span> },
        { h: 'الأولوية', align: 'center', r: p => <Num className="font-semibold">{p.prio}</Num> },
        { h: 'البنود', align: 'center', r: p => <Num className="tx2">{fmtInt(p.items)}</Num> },
        { h: 'التفعيل', r: p => <ActiveDot on={p.active} /> },
      ]} />
    </Card>}
    {tab === 'groups' && <Card stg={3}><DataTable rows={CUSTOMER_GROUPS} onRow={g => setGform(g)} cols={[
      { h: 'المجموعة', r: g => <div className="leading-tight"><div className="font-semibold tx1">{g.name}</div>{g.desc && <div className="text-[11.5px] tx3 mt-0.5">{g.desc}</div>}</div> },
      { h: 'الكود', r: g => <span className="num text-[11.5px] font-semibold v-sub px-2 py-1 rounded-md tx2">{g.code}</span> },
      { h: 'نسبة الخصم', r: g => <div className="flex items-center gap-2.5 min-w-[140px]"><div className="flex-1"><HatchBar pct={g.disc * 4} h={10} /></div><Num className="font-semibold">{g.disc}%</Num></div> },
      { h: 'التفعيل', r: g => <ActiveDot on={g.active} /> },
      { h: '', r: g => <RowActions><button className="icon-btn !w-7 !h-7" onClick={() => setGform(g)}><Icon name="edit" size={13} /></button></RowActions> },
    ]} /></Card>}
    {form && <PriceListForm row={form.id ? form : null} onClose={() => setForm(null)} />}
    {gform && <Modal open onClose={() => setGform(null)} title={gform.id ? 'تعديل مجموعة عملاء' : 'مجموعة عملاء جديدة'} icon="users"
      footer={<><button className="btn btn-soft" onClick={() => setGform(null)}>إلغاء</button><button className="btn btn-dark" onClick={() => { setGform(null); toast('تم الحفظ'); }}>حفظ</button></>}>
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3"><Field label="الاسم" req><input className="inp" defaultValue={gform.name} /></Field>
          <Field label="الكود" req hint="فريد"><input className="inp num" dir="ltr" defaultValue={gform.code} /></Field></div>
        <TextArea label="الوصف" value={gform.desc || undefined} />
        <Field label="نسبة الخصم" hint="من 0 إلى 100"><div className="relative"><input className="inp num" type="number" min="0" max="100" step="0.01" defaultValue={gform.disc ?? 0} /><span className="absolute top-1/2 -translate-y-1/2 tx3 text-[12px]" style={{ insetInlineEnd: 14 }}>%</span></div></Field>
        <ToggleRow label="مفعّلة" on={gform.active ?? true} />
      </div>
    </Modal>}
    <Modal open={imp} onClose={() => setImp(false)} title="استيراد قوائم الأسعار" icon="upload" width={540}
      footer={<><button className="btn btn-soft" onClick={() => setImp(false)}>إغلاق</button><button className="btn btn-dark" onClick={() => toast('تم الاستيراد مع 2 أخطاء')}>رفع واستيراد</button></>}>
      <div className="space-y-4">
        <Dropzone accept=".csv" max="10MB" label="اسحب ملف CSV هنا" />
        <div><div className="lbl mb-2">نتيجة آخر استيراد</div>
          <div className="grid grid-cols-3 gap-2 mb-2">
            <div className="v-sub p-3"><div className="text-[11px] tx2">صفوف ناجحة</div><div className="font-display text-[20px] font-bold num c-green">418</div></div>
            <div className="v-sub p-3"><div className="text-[11px] tx2">تم تحديثها</div><div className="font-display text-[20px] font-bold num tx1">36</div></div>
            <div className="v-sub p-3"><div className="text-[11px] tx2">أخطاء</div><div className="font-display text-[20px] font-bold num c-red">2</div></div>
          </div>
          <div className="v-sub p-1"><table className="tbl compact"><tbody>
            <tr><td className="num tx3 !w-14">#57</td><td className="text-[12px] c-red">SKU «TEA-GR-100» غير موجود</td></tr>
            <tr><td className="num tx3">#212</td><td className="text-[12px] c-red">min_qty يجب أن يكون أكبر من صفر</td></tr>
          </tbody></table></div>
        </div>
      </div>
    </Modal>
  </div>;
}

function PriceListForm({ row, onClose }) {
  const toast = useToast();
  const [type, setType] = useState(row?.type || 'retail');
  const [prio, setPrio] = useState(row?.prio ?? 0);
  const prErr = (prio < -10000 || prio > 10000) ? 'الأولوية من ‎-10000 إلى 10000' : null;
  return <Modal open onClose={onClose} title={row ? 'تعديل قائمة أسعار' : 'قائمة أسعار جديدة'} icon="tag" width={620}
    footer={<><button className="btn btn-soft" onClick={onClose}>إلغاء</button><button className="btn btn-dark" disabled={!!prErr} onClick={() => { onClose(); toast('تم الحفظ'); }}>حفظ</button></>}>
    <div className="space-y-4">
      <Field label="الاسم" req><input className="inp" defaultValue={row?.name} /></Field>
      <Field label="النوع" req><div className="flex flex-wrap gap-1.5">{Object.entries(PL_TYPES).map(([k, l]) => <button key={k} type="button" onClick={() => setType(k)} className={'pill-inp font-medium ' + (type === k ? 'on' : '')}>{l}</button>)}</div></Field>
      {type === 'customer' && <Field label="العميل" req><select className="inp"><option value="">اختر العميل</option>{WHOLESALE.map(w => <option key={w}>{w}</option>)}</select></Field>}
      {type === 'group' && <Field label="مجموعة العملاء" req><select className="inp"><option value="">اختر المجموعة</option>{CUSTOMER_GROUPS.map(g => <option key={g.id}>{g.name}</option>)}</select></Field>}
      <div className="grid grid-cols-2 gap-3">
        <Field label="الفرع" hint="اتركه فارغًا لكل الفروع"><select className="inp" defaultValue={row?.branch || ''}><option value="">كل الفروع</option>{BRANCHES.map(b => <option key={b}>{b}</option>)}</select></Field>
        <Field label="العملة"><input className="inp num" value="EGP" readOnly dir="ltr" /></Field>
        <Field label="بداية السريان"><input type="date" className="inp" defaultValue={row?.from} /></Field>
        <Field label="نهاية السريان"><input type="date" className="inp" defaultValue={row?.to || ''} /></Field>
      </div>
      <Field label="الأولوية" err={prErr} hint="الأعلى يُطبَّق أولًا عند تعارض القوائم · من ‎-10000 إلى 10000"><input type="number" className={'inp num ' + (prErr ? 'err' : '')} value={prio} onChange={e => setPrio(Number(e.target.value))} /></Field>
      <ToggleRow label="مفعّلة" on={row?.active ?? true} />
    </div>
  </Modal>;
}

function PriceListDetail({ id, go }) {
  const toast = useToast();
  const p = PRICE_LISTS.find(x => x.id === id) || PRICE_LISTS[1];
  const [items, setItems] = useState(VARIANTS.slice(0, 7).map((v, i) => ({ id: i, v, min: i % 3 === 0 ? 12 : 1, price: (v.price * 0.88).toFixed(4), from: i === 2 ? '2026-11-01' : '', to: i === 2 ? '2026-11-30' : '' })));
  const [editRow, setEditRow] = useState(null);
  const [form, setForm] = useState(false);
  const upd = (id, k, v) => setItems(items.map(it => it.id === id ? { ...it, [k]: v } : it));
  return <div className="space-y-5">
    <PageHead back={{ label: 'قوائم الأسعار', onClick: () => go({ m: 'pricing' }) }}
      title={p.name} sub={<span className="flex flex-wrap items-center gap-2"><Badge dot={false} tone="violet">{PL_TYPES[p.type]}</Badge>{p.target && <span>{p.target} ·</span>}<span>{p.branch || 'كل الفروع'}</span>·<span className="num ltr">{p.from} → {p.to || '∞'}</span>·<span>أولوية <Num>{p.prio}</Num></span></span>}
      actions={<><button className="btn btn-soft" onClick={() => setForm(true)}><Icon name="edit" size={14} />تعديل القائمة</button><button className="btn btn-soft" onClick={() => toast('جارٍ تصدير CSV…')}><Icon name="download" size={14} />تصدير CSV</button></>} />
    <Card stg={2}>
      <CardHead title="بنود القائمة" sub="الكمية والسعر يدعمان حتى أربع خانات عشرية" right={<button className="btn btn-dark btn-sm" onClick={() => { const nid = Date.now(); setItems([{ id: nid, v: null, min: 1, price: '', from: '', to: '' }, ...items]); setEditRow(nid); }}><Icon name="plus" size={13} />إضافة بند</button>} />
      <DataTable compact rows={items} cols={[
        { h: 'متغير المنتج', r: it => editRow === it.id && !it.v ? <select className="inp inp-sm min-w-[220px]" onChange={e => upd(it.id, 'v', VARIANTS.find(v => v.sku === e.target.value))}><option>اختر المتغير</option>{VARIANTS.map(v => <option key={v.sku} value={v.sku}>{v.name}</option>)}</select>
          : <div className="leading-tight"><div className="font-semibold tx1">{it.v?.name}</div><div className="text-[11px] tx3 num ltr">{it.v?.sku}</div></div> },
        { h: 'الحد الأدنى للكمية', r: it => editRow === it.id ? <input type="number" step="0.0001" min="0.0001" className="inp inp-sm w-24 num" value={it.min} onChange={e => upd(it.id, 'min', e.target.value)} /> : <Num>{it.min}</Num> },
        { h: 'السعر', r: it => editRow === it.id ? <input type="number" step="0.0001" className="inp inp-sm w-28 num" value={it.price} onChange={e => upd(it.id, 'price', e.target.value)} /> : <span className="font-semibold">{fmtMoney(it.price, 4)}</span> },
        { h: 'سريان البند', r: it => editRow === it.id ? <div className="flex gap-1"><input type="date" className="inp inp-sm" value={it.from} onChange={e => upd(it.id, 'from', e.target.value)} /><input type="date" className="inp inp-sm" value={it.to} onChange={e => upd(it.id, 'to', e.target.value)} /></div>
          : it.from ? <span className="num text-[11.5px] tx2 ltr">{it.from} → {it.to}</span> : <span className="tx3 text-[11.5px]">حسب القائمة</span> },
        { h: '', r: it => <RowActions>{editRow === it.id
          ? <button className="btn btn-dark btn-xs" onClick={() => { setEditRow(null); toast('تم حفظ البند'); }}><Icon name="check" size={12} />حفظ</button>
          : <><button className="icon-btn !w-7 !h-7" onClick={() => setEditRow(it.id)}><Icon name="edit" size={13} /></button><button className="icon-btn !w-7 !h-7" onClick={() => setItems(items.filter(x => x.id !== it.id))}><Icon name="trash" size={13} /></button></>}</RowActions> },
      ]} />
      <Pager total={p.items} />
    </Card>
    {form && <PriceListForm row={p} onClose={() => setForm(false)} />}
  </div>;
}

/* ---------------- PROMOTIONS & COUPONS ---------------- */
const PROMO_TYPES = { percent: 'خصم نسبة', amount: 'خصم مبلغ', bxgy: 'اشترِ X واحصل على Y', free_ship: 'شحن مجاني' };
const SCOPES = { all: 'الكل', categories: 'أقسام', brands: 'براندات', variants: 'متغيرات منتجات' };
const PROMOS = [
  { id: 1, name: 'تخفيضات نوفمبر', type: 'percent', value: 15, min: 500, scope: 'categories', targets: ['البقالة', 'المعلبات'], from: '2026-11-01', to: '2026-11-30', active: true },
  { id: 2, name: 'خصم 50 جنيه على الزيوت', type: 'amount', value: 50, min: 400, scope: 'brands', targets: ['الشريف'], from: '2026-10-15', to: '2026-11-15', active: true },
  { id: 3, name: 'شحن مجاني للطلبات الكبيرة', type: 'free_ship', value: null, min: 1200, scope: 'all', targets: [], from: '2026-10-01', to: null, active: true },
  { id: 4, name: 'اشترِ 2 شاي واحصل على 1', type: 'bxgy', value: null, min: null, scope: 'variants', targets: ['TEA-BL-250'], from: '2026-09-01', to: '2026-09-30', active: false },
];
const COUPONS = [
  { id: 1, code: 'WELCOME50', type: 'amount', value: 50, maxDisc: null, min: 300, total: 5000, perCust: 1, used: 1842, from: '2026-01-01', to: null, active: true },
  { id: 2, code: 'RAMADAN20', type: 'percent', value: 20, maxDisc: 150, min: 500, total: 2000, perCust: 2, used: 2000, from: '2026-02-18', to: '2026-03-20', active: false },
  { id: 3, code: 'FREESHIP', type: 'free_ship', value: null, maxDisc: null, min: 600, total: null, perCust: 3, used: 412, from: '2026-10-01', to: '2026-12-31', active: true },
  { id: 4, code: 'VIP10', type: 'percent', value: 10, maxDisc: 300, min: 0, total: null, perCust: null, used: 96, from: '2026-10-10', to: null, active: true },
];
const COUPON_TYPES = { percent: 'نسبة', amount: 'مبلغ ثابت', free_ship: 'شحن مجاني' };

function PromosPage() {
  const [tab, setTab] = useState('promos');
  const [pf, setPf] = useState(null);
  const [cf, setCf] = useState(null);
  const promoVal = p => p.type === 'percent' ? <Num className="font-semibold">{p.value}%</Num> : p.type === 'amount' ? <span className="font-semibold">{fmtMoney(p.value, 0)}</span> : <span className="tx3">—</span>;
  return <div className="space-y-5">
    <PageHead title="العروض والكوبونات" sub="العروض تُطبق على نطاق محدد، والكوبونات بأكواد يُدخلها العميل."
      actions={<button className="btn btn-dark" onClick={() => tab === 'promos' ? setPf({}) : setCf({})}><Icon name="plus" size={14} />{tab === 'promos' ? 'عرض جديد' : 'كوبون جديد'}</button>} />
    <TabsBar value={tab} onChange={setTab} tabs={[{ value: 'promos', label: 'العروض', icon: 'gift', count: PROMOS.length }, { value: 'coupons', label: 'الكوبونات', icon: 'ticket', count: COUPONS.length }]} />
    {tab === 'promos' && <Card stg={3}><DataTable rows={PROMOS} onRow={setPf} cols={[
      { h: 'الاسم', r: p => <span className="font-semibold tx1">{p.name}</span> },
      { h: 'النوع', r: p => <Badge dot={false} tone={{ percent: 'orange', amount: 'blue', bxgy: 'violet', free_ship: 'green' }[p.type]}>{PROMO_TYPES[p.type]}</Badge> },
      { h: 'القيمة', r: promoVal },
      { h: 'نطاق التطبيق', r: p => <div className="flex items-center gap-1.5 flex-wrap"><span className="tx1 text-[12px] font-medium">{SCOPES[p.scope]}</span>{p.targets.slice(0, 2).map(t => <span key={t} className="v-sub px-2 py-0.5 rounded-full text-[11px] tx2">{t}</span>)}</div> },
      { h: 'مدة السريان', r: p => <span className="num text-[11.5px] tx2 ltr">{p.from} → {p.to || '∞'}</span> },
      { h: 'التفعيل', r: p => <ActiveDot on={p.active} /> },
    ]} /></Card>}
    {tab === 'coupons' && <div className="grid grid-cols-1 md:grid-cols-2 gap-4">{COUPONS.map((c, i) => {
      const pct = c.total ? Math.min(100, c.used / c.total * 100) : null;
      return <div key={c.id} className={'v-card p-5 stg stg-' + (2 + i) + ' cursor-pointer hover:shadow-sm transition'} onClick={() => setCf(c)}>
        <div className="flex items-start justify-between gap-3">
          <div><div className="font-display text-[22px] font-bold num tracking-[.06em] tx1 ltr">{c.code}</div>
            <div className="flex items-center gap-2 mt-1.5"><Badge dot={false} tone="orange">{COUPON_TYPES[c.type]}</Badge>{c.value && <span className="font-semibold text-[13px]">{c.type === 'percent' ? <Num>{c.value}%</Num> : fmtMoney(c.value, 0)}</span>}{c.maxDisc && <span className="text-[11.5px] tx3">حد أقصى {fmtMoney(c.maxDisc, 0)}</span>}</div></div>
          <ActiveDot on={c.active} />
        </div>
        <div className="mt-4"><div className="flex items-center justify-between text-[12px] mb-1.5"><span><b className="num tx1">{fmtInt(c.used)}</b> <span className="tx3">مرة استخدام</span></span><span className="tx2">{c.total ? <>من <Num>{fmtInt(c.total)}</Num></> : 'غير محدود'}</span></div>
          {pct != null ? <HatchBar pct={pct} h={12} /> : <div className="h-3 rounded-full hatch-track"></div>}</div>
        <div className="grid grid-cols-3 gap-2 mt-4">
          <div className="v-sub px-3 py-2"><div className="text-[10.5px] tx3">الحد الأدنى</div><div className="text-[12px] font-semibold">{fmtMoney(c.min, 0)}</div></div>
          <div className="v-sub px-3 py-2"><div className="text-[10.5px] tx3">لكل عميل</div><div className="text-[12px] font-semibold">{c.perCust ? <Num>{c.perCust}</Num> : '∞'}</div></div>
          <div className="v-sub px-3 py-2"><div className="text-[10.5px] tx3">السريان</div><div className="text-[11px] font-semibold num ltr">{c.to ? c.to.slice(5) : '∞'}</div></div>
        </div>
      </div>;
    })}</div>}
    {pf && <PromoForm row={pf.id ? pf : null} onClose={() => setPf(null)} />}
    {cf && <CouponForm row={cf.id ? cf : null} onClose={() => setCf(null)} />}
  </div>;
}

function PromoForm({ row, onClose }) {
  const toast = useToast();
  const [type, setType] = useState(row?.type || 'percent');
  const [scope, setScope] = useState(row?.scope || 'all');
  const opts = { categories: ['البقالة', 'المعلبات', 'المشروبات', 'الألبان', 'المنظفات'], brands: ['الشريف', 'الضحى', 'العروسة', 'بن الحرمين', 'جهينة'], variants: VARIANTS.map(v => v.sku) };
  const [targets, setTargets] = useState(row?.targets || []);
  return <Modal open onClose={onClose} title={row ? 'تعديل عرض' : 'عرض جديد'} icon="gift" width={620}
    footer={<><button className="btn btn-soft" onClick={onClose}>إلغاء</button><button className="btn btn-dark" onClick={() => { onClose(); toast('تم الحفظ'); }}>حفظ</button></>}>
    <div className="space-y-4">
      <Field label="الاسم" req><input className="inp" defaultValue={row?.name} /></Field>
      <Field label="النوع" req><div className="grid grid-cols-4 gap-2">{Object.entries(PROMO_TYPES).map(([k, l]) => <button key={k} type="button" onClick={() => setType(k)} className="v-sub p-3 text-[12px] font-medium tx1 text-center transition leading-snug"
        style={type === k ? { outline: '2px solid var(--accent)', background: 'var(--bg-card)' } : {}}><Icon name={{ percent: 'percent', amount: 'cash', bxgy: 'gift', free_ship: 'truck' }[k]} size={16} className="mx-auto mb-1.5 accent" />{l}</button>)}</div></Field>
      {type === 'bxgy' && <Note tone="amber" icon="info">لا يوفر النظام حاليًا حقولًا لتحديد X وY — يُحفظ النوع فقط ويُطبق حسب منطق النظام.</Note>}
      <div className="grid grid-cols-2 gap-3">
        {(type === 'percent' || type === 'amount') && <Field label="القيمة" req><div className="relative"><input className="inp num" type="number" defaultValue={row?.value} /><span className="absolute top-1/2 -translate-y-1/2 tx3 text-[12px]" style={{ insetInlineEnd: 14 }}>{type === 'percent' ? '%' : 'EGP'}</span></div></Field>}
        <Field label="الحد الأدنى لقيمة الطلب"><input className="inp num" type="number" defaultValue={row?.min} placeholder="اختياري" /></Field>
      </div>
      <Field label="نطاق التطبيق"><Seg value={scope} onChange={s => { setScope(s); setTargets([]); }} options={Object.entries(SCOPES).map(([v, l]) => ({ value: v, label: l }))} /></Field>
      {scope !== 'all' && <Field label={'العناصر المستهدفة — ' + SCOPES[scope]} req>
        <div className="v-sub p-2.5 flex flex-wrap gap-1.5">{opts[scope].map(o => { const on = targets.includes(o); return <button key={o} type="button" onClick={() => setTargets(on ? targets.filter(x => x !== o) : [...targets, o])} className={'pill-inp !py-1 !text-[11.5px] ' + (on ? 'on' : '!bg-[var(--bg-card)]') + (scope === 'variants' ? ' num' : '')}>{on && '✓ '}{o}</button>; })}</div>
      </Field>}
      <div className="grid grid-cols-2 gap-3"><Field label="بداية السريان"><input type="date" className="inp" defaultValue={row?.from} /></Field><Field label="نهاية السريان"><input type="date" className="inp" defaultValue={row?.to || ''} /></Field></div>
      <ToggleRow label="مفعّل" on={row?.active ?? true} />
    </div>
  </Modal>;
}

function CouponForm({ row, onClose }) {
  const toast = useToast();
  const [code, setCode] = useState(row?.code || '');
  const [type, setType] = useState(row?.type || 'percent');
  return <Modal open onClose={onClose} title={row ? 'تعديل كوبون' : 'كوبون جديد'} icon="ticket" width={600}
    footer={<><button className="btn btn-soft" onClick={onClose}>إلغاء</button><button className="btn btn-dark" disabled={!code} onClick={() => { onClose(); toast('تم الحفظ'); }}>حفظ</button></>}>
    <div className="space-y-4">
      <Field label="الكود" req count={`${code.length} / 50`} hint="فريد · يُحوَّل تلقائيًا لحروف كبيرة">
        <input className="inp num font-bold tracking-[.08em]" dir="ltr" maxLength={50} value={code} onChange={e => setCode(e.target.value.toUpperCase().replace(/\s/g, ''))} placeholder="SUMMER25" />
      </Field>
      <Field label="النوع" req><Seg value={type} onChange={setType} options={Object.entries(COUPON_TYPES).map(([v, l]) => ({ value: v, label: l }))} /></Field>
      <div className="grid grid-cols-2 gap-3">
        {type !== 'free_ship' && <Field label="قيمة الخصم" req><div className="relative"><input className="inp num" type="number" defaultValue={row?.value} /><span className="absolute top-1/2 -translate-y-1/2 tx3 text-[12px]" style={{ insetInlineEnd: 14 }}>{type === 'percent' ? '%' : 'EGP'}</span></div></Field>}
        {type === 'percent' && <Field label="أقصى مبلغ خصم"><input className="inp num" type="number" defaultValue={row?.maxDisc} placeholder="بدون حد" /></Field>}
        <Field label="الحد الأدنى للطلب"><input className="inp num" type="number" defaultValue={row?.min} /></Field>
        <Field label="الحد الإجمالي للاستخدام"><input className="inp num" type="number" defaultValue={row?.total} placeholder="غير محدود" /></Field>
        <Field label="حد الاستخدام لكل عميل"><input className="inp num" type="number" defaultValue={row?.perCust} placeholder="غير محدود" /></Field>
      </div>
      <div className="grid grid-cols-2 gap-3"><Field label="بداية السريان"><input type="date" className="inp" defaultValue={row?.from} /></Field><Field label="نهاية السريان"><input type="date" className="inp" defaultValue={row?.to || ''} /></Field></div>
      {row && <div className="v-sub px-4 py-3 flex items-center justify-between"><span className="text-[12.5px] tx2">عدد مرات الاستخدام</span><Num className="font-display font-bold text-[16px]">{fmtInt(row.used)}</Num></div>}
      <ToggleRow label="مفعّل" on={row?.active ?? true} />
    </div>
  </Modal>;
}

/* ---------------- BRANDS ---------------- */
const BRANDS = [
  { id: 1, ar: 'الشريف', en: 'El Sherif', slug: 'el-sherif', sort: 1, active: true, logo: '#e0491f', products: 48 },
  { id: 2, ar: 'الضحى', en: 'Al Doha', slug: 'al-doha', sort: 2, active: true, logo: '#18151c', products: 31 },
  { id: 3, ar: 'العروسة', en: 'El Arousa', slug: 'el-arousa', sort: 3, active: true, logo: '#0ea5a4', products: 22 },
  { id: 4, ar: 'بن الحرمين', en: 'Haramain Coffee', slug: 'haramain-coffee', sort: 4, active: true, logo: '#92400e', products: 14 },
  { id: 5, ar: 'جهينة', en: 'Juhayna', slug: 'juhayna', sort: 5, active: true, logo: '#2f5bd3', products: 57 },
  { id: 6, ar: 'ريف مصر', en: 'Reef Masr', slug: null, sort: 6, active: false, logo: null, products: 0 },
];
function BrandsPage() {
  const toast = useToast();
  const [form, setForm] = useState(null);
  const [del, setDel] = useState(null);
  const [blocked, setBlocked] = useState(null);
  return <div className="space-y-5">
    <PageHead title="البراندات" sub="العلامات التجارية المعروضة في المتجر وترتيب ظهورها."
      actions={<button className="btn btn-dark" onClick={() => setForm({})}><Icon name="plus" size={14} />براند جديد</button>} />
    {blocked && <Note tone="red" icon="ban" className="stg stg-2">لا يمكن حذف «{blocked.ar}» لأنه مرتبط بـ <Num>{blocked.products}</Num> منتجًا. انقل المنتجات لبراند آخر أو عطّل البراند بدلًا من حذفه.</Note>}
    <Card stg={2}><DataTable rows={BRANDS} onRow={setForm} cols={[
      { h: 'الشعار', w: 64, r: b => b.logo ? <span className="w-10 h-10 rounded-[12px] flex items-center justify-center text-white font-display font-bold" style={{ background: b.logo }}>{b.en[0]}</span> : <span className="w-10 h-10 rounded-[12px] v-sub flex items-center justify-center tx3"><Icon name="image" size={15} /></span> },
      { h: 'الاسم العربي', r: b => <span className="font-semibold tx1">{b.ar}</span> },
      { h: 'الاسم الإنجليزي', r: b => <span className="num tx2 ltr">{b.en}</span> },
      { h: 'الرابط المختصر', r: b => b.slug ? <span className="num text-[11.5px] v-sub px-2 py-1 rounded-md tx2 ltr">/{b.slug}</span> : <span className="tx3 text-[11.5px]">يُولّد تلقائيًا</span> },
      { h: 'الترتيب', align: 'center', r: b => <Num className="font-semibold">{b.sort}</Num> },
      { h: 'التفعيل', r: b => <ActiveDot on={b.active} /> },
      { h: '', r: b => <RowActions><button className="icon-btn !w-7 !h-7" onClick={() => setForm(b)}><Icon name="edit" size={13} /></button><button className="icon-btn !w-7 !h-7" onClick={() => setDel(b)}><Icon name="trash" size={13} /></button></RowActions> },
    ]} /></Card>
    {form && <Modal open onClose={() => setForm(null)} title={form.id ? 'تعديل براند' : 'براند جديد'} icon="star" width={620}
      footer={<><button className="btn btn-soft" onClick={() => setForm(null)}>إلغاء</button><button className="btn btn-dark" onClick={() => { setForm(null); toast('تم الحفظ'); }}>حفظ</button></>}>
      <div className="space-y-4">
        <div className="flex items-start gap-4">
          <div className="shrink-0 text-center">
            {form.logo ? <div className="relative"><span className="w-[88px] h-[88px] rounded-[20px] flex items-center justify-center text-white font-display font-bold text-[30px]" style={{ background: form.logo }}>{form.en[0]}</span>
              <button className="btn btn-danger btn-xs mt-2 w-full" onClick={() => setForm({ ...form, logo: null })}><Icon name="trash" size={11} />إزالة الشعار</button></div>
              : <div className="w-[88px] h-[88px] rounded-[20px] drop !p-0 flex flex-col items-center justify-center gap-1"><Icon name="upload" size={18} className="tx3" /><span className="text-[10.5px] tx3">رفع شعار</span></div>}
            <div className="text-[10px] tx3 mt-1.5 leading-tight num">JPG · PNG · WebP<br />حتى 4MB</div>
          </div>
          <div className="flex-1 grid grid-cols-1 gap-3">
            <Field label="الاسم العربي" req hint="حتى 150 حرفًا"><input className="inp" maxLength={150} defaultValue={form.ar} /></Field>
            <Field label="الاسم الإنجليزي" req hint="حتى 150 حرفًا"><input className="inp num" dir="ltr" maxLength={150} defaultValue={form.en} /></Field>
          </div>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Field label="slug" hint="اختياري"><input className="inp num" dir="ltr" defaultValue={form.slug} placeholder="auto-generated" /></Field>
          <Field label="ترتيب العرض"><input className="inp num" type="number" defaultValue={form.sort} /></Field>
        </div>
        <div className="grid grid-cols-2 gap-3"><TextArea label="الوصف العربي" /><Field label="الوصف الإنجليزي"><textarea className="inp num" dir="ltr"></textarea></Field></div>
        <ToggleRow label="مفعّل" on={form.active ?? true} />
      </div>
    </Modal>}
    <ConfirmModal open={!!del} onClose={() => setDel(null)} danger title={'حذف «' + (del?.ar || '') + '»'} cta="حذف"
      text="سيُحذف البراند نهائيًا. سيرفض النظام الحذف إن كان مرتبطًا بمنتجات."
      onConfirm={() => { if (del.products > 0) { setBlocked(del); toast('رفض النظام الحذف', 'red'); } else toast('تم الحذف'); }} />
  </div>;
}

Object.assign(window, { SHIP_COMPANIES, TabsBar, ShippingPage, PricingPage, PriceListDetail, PromosPage, BrandsPage, CUSTOMER_GROUPS });
