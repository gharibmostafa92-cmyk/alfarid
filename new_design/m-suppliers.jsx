/* ============ 6. Suppliers · 7. Purchasing ============ */

const PRS = [
  { id: 1, no: 'PR-0318', branch: 'فرع مدينة نصر', status: 'pending', by: 'كريم فتحي', date: '2026-10-27', items: 6 },
  { id: 2, no: 'PR-0317', branch: 'المخزن الرئيسي', status: 'draft', by: 'أحمد ناصر', date: '2026-10-26', items: 14 },
  { id: 3, no: 'PR-0316', branch: 'فرع المعادي', status: 'approved', by: 'سارة حمدي', date: '2026-10-25', items: 4 },
  { id: 4, no: 'PR-0315', branch: 'فرع المنصورة', status: 'converted_to_po', by: 'يوسف عاطف', date: '2026-10-22', items: 9 },
  { id: 5, no: 'PR-0314', branch: 'فرع الإسكندرية - سموحة', status: 'rejected', by: 'منة الله عادل', date: '2026-10-20', items: 3 },
];
const POS = [
  { id: 1, no: 'PO-1207', sup: 1, branch: 'المخزن الرئيسي', status: 'sent', eta: '2026-11-02', total: 148500 },
  { id: 2, no: 'PO-1206', sup: 2, branch: 'فرع مدينة نصر', status: 'revision', eta: '2026-10-30', total: 62400 },
  { id: 3, no: 'PO-1205', sup: 4, branch: 'المخزن الرئيسي', status: 'partial', eta: '2026-10-28', total: 38900 },
  { id: 4, no: 'PO-1204', sup: 6, branch: 'فرع المعادي', status: 'accepted', eta: '2026-10-29', total: 27350 },
  { id: 5, no: 'PO-1203', sup: 1, branch: 'فرع المنصورة', status: 'completed', eta: '2026-10-20', total: 91200 },
  { id: 6, no: 'PO-1202', sup: 3, branch: 'المخزن الرئيسي', status: 'draft', eta: null, total: 14800 },
  { id: 7, no: 'PO-1201', sup: 2, branch: 'فرع المعادي', status: 'cancelled', eta: '2026-10-15', total: 22000 },
];
const INVOICES = [
  { id: 1, sys: 'SINV-0892', supNo: 'SH-2026-4471', sup: 1, po: 'PO-1203', issue: '2026-10-21', due: '2026-11-20', subtotal: 80000, tax: 11200, paid: 50000, status: 'partial', file: true },
  { id: 2, sys: 'SINV-0891', supNo: 'DM/10/882', sup: 2, po: null, issue: '2026-10-18', due: '2026-12-02', subtotal: 54000, tax: 7560, paid: 0, status: 'posted', file: true },
  { id: 3, sys: 'SINV-0890', supNo: 'HC-3310', sup: 4, po: 'PO-1205', issue: '2026-10-28', due: null, subtotal: 18500, tax: 2590, paid: 0, status: 'draft', file: false },
  { id: 4, sys: 'SINV-0889', supNo: 'GF-77120', sup: 6, po: null, issue: '2026-09-30', due: '2026-10-30', subtotal: 24000, tax: 3360, paid: 27360, status: 'paid', file: true },
  { id: 5, sys: 'SINV-0888', supNo: 'NP-0091', sup: 3, po: null, issue: '2026-09-12', due: '2026-09-27', subtotal: 6200, tax: 0, paid: 0, status: 'cancelled', file: false },
];
const SPAYMENTS = [
  { id: 1, no: 'SPAY-0441', sup: 1, inv: 'SINV-0892', amount: 50000, method: 'bank', ref: 'NBE-TRX-882140', date: '2026-10-24', notes: 'دفعة أولى', void: null },
  { id: 2, no: 'SPAY-0440', sup: 6, inv: 'SINV-0889', amount: 27360, method: 'cheque', ref: 'CHQ-004127', date: '2026-10-22', notes: null, void: null },
  { id: 3, no: 'SPAY-0439', sup: 2, inv: null, amount: 15000, method: 'cash', ref: null, date: '2026-10-19', notes: 'دفعة تحت الحساب', void: null },
  { id: 4, no: 'SPAY-0438', sup: 4, inv: null, amount: 8000, method: 'bank', ref: 'MSR-55120', date: '2026-10-11', notes: null, void: { reason: 'تحويل مكرر', by: 'أحمد ناصر', at: '2026-10-12 10:14' } },
];
const SP_METHODS = { cash: 'نقدي', bank: 'تحويل بنكي', cheque: 'شيك' };
const supName = id => SUPPLIERS.find(s => s.id === id)?.company || SUPPLIERS.find(s => s.id === id)?.name;

/* ---------------- Suppliers list ---------------- */
function SuppliersList({ go }) {
  const [q, setQ] = useState('');
  const [form, setForm] = useState(null);
  const rows = SUPPLIERS.filter(s => !q || (s.code + s.name + (s.company || '')).includes(q));
  const totalDue = SUPPLIERS.reduce((a, s) => a + Math.max(0, s.balance), 0);
  return <div className="space-y-5">
    <PageHead title="الموردون" sub="بيانات الموردين وأرصدتهم وحسابات موظفيهم على بوابة الموردين."
      actions={<button className="btn btn-dark" onClick={() => setForm({})}><Icon name="plus" size={14} />مورد جديد</button>} />
    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 stg stg-2">
      <KPI hero label="إجمالي المستحق للموردين" icon="wallet" value={<span className="ltr">{fmtNum(totalDue, 0)}</span>} small foot="EGP · الأرصدة الدائنة" />
      <KPI label="موردون مفعّلون" icon="factory" value={SUPPLIERS.filter(s => s.active).length} foot={<>من أصل <Num>{SUPPLIERS.length}</Num></>} />
      <KPI label="فواتير غير مسددة" icon="receipt" value={INVOICES.filter(i => ['posted', 'partial'].includes(i.status)).length} foot="مُرحّلة أو مدفوعة جزئيًا" />
      <KPI label="أوامر شراء مفتوحة" icon="cart" value={POS.filter(p => ['sent', 'accepted', 'revision', 'partial'].includes(p.status)).length} foot="بانتظار التوريد" />
    </div>
    <Card stg={3}>
      <div className="flex items-center gap-2 mb-4"><SearchPill value={q} onChange={setQ} placeholder="الكود، الاسم، الشركة" /></div>
      <DataTable rows={rows} onRow={s => go({ m: 'suppliers', id: s.id })} cols={[
        { h: 'الكود', r: s => <span className="num font-semibold tx2">{s.code}</span> },
        { h: 'المورد', r: s => <div className="flex items-center gap-2.5"><Avatar name={s.company || s.name} tone={s.id} size={30} /><div className="leading-tight"><div className="font-semibold tx1">{s.name}</div><div className="text-[11.5px] tx3 mt-0.5">{s.company || '—'}</div></div></div> },
        { h: 'التواصل', r: s => <div className="leading-tight"><div className="num ltr text-[12px] tx1">{s.phone}</div><div className="num ltr text-[11px] tx3 mt-0.5">{s.email || '—'}</div></div> },
        { h: 'مدة السداد', r: s => s.terms ? <span><Num className="font-semibold">{s.terms}</Num> <span className="tx3 text-[11.5px]">يوم</span></span> : <span className="tx2 text-[12px]">فوري</span> },
        { h: 'الرصيد الحالي', r: s => <span className={'font-semibold ' + (s.balance < 0 ? 'c-green' : 'tx1')}>{fmtMoney(s.balance)}</span> },
        { h: 'التفعيل', r: s => <ActiveDot on={s.active} /> },
        { h: '', r: s => <RowActions><button className="btn btn-soft btn-xs" onClick={() => go({ m: 'suppliers', id: s.id })}>التفاصيل</button><button className="icon-btn !w-7 !h-7" onClick={() => setForm(s)}><Icon name="edit" size={13} /></button></RowActions> },
      ]} />
    </Card>
    {form && <SupplierForm row={form.id ? form : null} onClose={() => setForm(null)} />}
  </div>;
}

function SupplierForm({ row, onClose }) {
  const toast = useToast();
  return <Modal open onClose={onClose} title={row ? 'تعديل مورد' : 'مورد جديد'} icon="factory" width={640}
    footer={<><button className="btn btn-soft" onClick={onClose}>إلغاء</button><button className="btn btn-dark" onClick={() => { onClose(); toast('تم حفظ المورد'); }}>حفظ</button></>}>
    <div className="space-y-4">
      <div className="grid grid-cols-3 gap-3">
        <Field label="الكود" req hint="فريد · حتى 50 حرفًا"><input className="inp num" dir="ltr" maxLength={50} defaultValue={row?.code} /></Field>
        <Field label="الاسم" req className="col-span-2"><input className="inp" maxLength={255} defaultValue={row?.name} /></Field>
      </div>
      <Field label="اسم الشركة"><input className="inp" defaultValue={row?.company} /></Field>
      <div className="grid grid-cols-2 gap-3">
        <Field label="البريد الإلكتروني"><input className="inp num" dir="ltr" type="email" defaultValue={row?.email} /></Field>
        <Field label="الهاتف"><input className="inp num" dir="ltr" defaultValue={row?.phone} /></Field>
        <Field label="الرقم الضريبي"><input className="inp num" dir="ltr" /></Field>
        <Field label="السجل التجاري"><input className="inp num" dir="ltr" /></Field>
      </div>
      <TextArea label="العنوان" max={500} />
      <div className="grid grid-cols-2 gap-3">
        <Field label="مدة السداد" hint="من 0 إلى 365 يومًا"><div className="relative"><input className="inp num" type="number" min="0" max="365" defaultValue={row?.terms ?? 30} /><span className="absolute top-1/2 -translate-y-1/2 tx3 text-[12px]" style={{ insetInlineEnd: 14 }}>يوم</span></div></Field>
        {row ? <Field label="الرصيد الحالي" hint="للعرض فقط — يتحدث من الفواتير والمدفوعات"><div className="inp !bg-transparent border !border-[var(--border-input)] font-semibold">{fmtMoney(row.balance)}</div></Field>
          : <Field label="الرصيد الافتتاحي" hint="قيمة موجبة (مستحق للمورد) أو سالبة (مستحق لنا)"><input className="inp num" type="number" step="0.01" defaultValue="0" /></Field>}
      </div>
      <ToggleRow label="مفعّل" on={row?.active ?? true} />
    </div>
  </Modal>;
}

function SupplierDetail({ id, go }) {
  const toast = useToast();
  const s = SUPPLIERS.find(x => x.id === id) || SUPPLIERS[0];
  const [tab, setTab] = useState('info');
  const [uform, setUform] = useState(null);
  const [udel, setUdel] = useState(null);
  const [edit, setEdit] = useState(false);
  const users = [
    { id: 1, name: 'محمود الشريف', email: 'mahmoud@sherif-oils.com', phone: '01001112223', active: true },
    { id: 2, name: 'نهى سمير', email: 'noha@sherif-oils.com', phone: null, active: true },
    { id: 3, name: 'حسام عادل', email: 'hossam@sherif-oils.com', phone: '01229988776', active: false },
  ];
  const pos = POS.filter(p => p.sup === s.id), invs = INVOICES.filter(i => i.sup === s.id), pays = SPAYMENTS.filter(p => p.sup === s.id);
  return <div className="space-y-5">
    <PageHead back={{ label: 'الموردون', onClick: () => go({ m: 'suppliers' }) }}
      title={<span className="flex items-center gap-3">{s.company || s.name}<ActiveDot on={s.active} /></span>}
      sub={<span><span className="num">{s.code}</span> · {s.name}</span>}
      actions={<button className="btn btn-soft" onClick={() => setEdit(true)}><Icon name="edit" size={14} />تعديل</button>} />
    <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
      <div className="lg:col-span-4 v-card p-4 stg stg-2 self-start">
        <div className="v-orange rounded-[18px] p-4">
          <div className="text-[12.5px] text-white/90">الرصيد الحالي</div>
          <div className="font-display text-[28px] font-bold num my-1.5 ltr">{fmtNum(s.balance)} <span className="text-[13px] opacity-80">EGP</span></div>
          <div className="text-[11px] text-white/80">{s.balance > 0 ? 'مستحق للمورد' : s.balance < 0 ? 'رصيد مدين لصالحنا' : 'لا يوجد رصيد'}</div>
        </div>
        <div className="grid grid-cols-3 gap-2 mt-3">
          <div className="v-sub p-3 text-center"><div className="text-[10.5px] tx3">أوامر</div><Num className="font-display text-[18px] font-bold">{pos.length}</Num></div>
          <div className="v-sub p-3 text-center"><div className="text-[10.5px] tx3">فواتير</div><Num className="font-display text-[18px] font-bold">{invs.length}</Num></div>
          <div className="v-sub p-3 text-center"><div className="text-[10.5px] tx3">مدفوعات</div><Num className="font-display text-[18px] font-bold">{pays.length}</Num></div>
        </div>
      </div>
      <div className="lg:col-span-8 space-y-4">
        <TabsBar value={tab} onChange={setTab} tabs={[
          { value: 'info', label: 'البيانات الأساسية' }, { value: 'users', label: 'حسابات الموظفين', count: users.length },
          { value: 'pos', label: 'أوامر الشراء', count: pos.length }, { value: 'invs', label: 'الفواتير', count: invs.length }, { value: 'pays', label: 'المدفوعات', count: pays.length }]} />
        {tab === 'info' && <Card stg={3}><div className="grid grid-cols-1 sm:grid-cols-2 gap-x-10 gap-y-1">
          <KV items={[['الكود', <Num>{s.code}</Num>], ['الاسم', s.name], ['اسم الشركة', s.company], ['الهاتف', <Num>{s.phone}</Num>], ['البريد', s.email && <Num>{s.email}</Num>]]} />
          <KV items={[['الرقم الضريبي', <Num>512-884-301</Num>], ['السجل التجاري', <Num>CR-118840</Num>], ['مدة السداد', <><Num>{s.terms}</Num> يوم</>], ['الرصيد الافتتاحي', fmtMoney(25000)], ['العنوان', 'المنطقة الصناعية الثالثة، العاشر من رمضان']]} />
        </div></Card>}
        {tab === 'users' && <Card stg={3}>
          <CardHead title="حسابات موظفي المورد" sub="للدخول إلى بوابة الموردين" right={<button className="btn btn-dark btn-sm" onClick={() => setUform({})}><Icon name="plus" size={13} />حساب جديد</button>} />
          <DataTable rows={users} cols={[
            { h: 'الموظف', r: u => <div className="flex items-center gap-2.5"><Avatar name={u.name} tone={u.id + 2} size={28} /><span className="font-semibold tx1">{u.name}</span></div> },
            { h: 'البريد', r: u => <Num className="tx2 text-[12px]">{u.email}</Num> },
            { h: 'الهاتف', r: u => u.phone ? <Num className="tx2 text-[12px]">{u.phone}</Num> : <span className="tx3">—</span> },
            { h: 'التفعيل', r: u => <ActiveDot on={u.active} /> },
            { h: '', r: u => <RowActions><button className="icon-btn !w-7 !h-7" onClick={() => setUform(u)}><Icon name="edit" size={13} /></button><button className="icon-btn !w-7 !h-7" onClick={() => setUdel(u)}><Icon name="trash" size={13} /></button></RowActions> },
          ]} />
        </Card>}
        {tab === 'pos' && <Card stg={3}><DataTable rows={pos} onRow={() => go({ m: 'purchasing' })} cols={[
          { h: 'رقم الأمر', r: p => <span className="num font-semibold">{p.no}</span> }, { h: 'الفرع', r: p => <span className="tx2">{p.branch}</span> },
          { h: 'الحالة', r: p => <StatusBadge map={PO_STATUS} value={p.status} /> }, { h: 'الإجمالي', r: p => fmtMoney(p.total) }]} empty="لا توجد أوامر شراء لهذا المورد" /></Card>}
        {tab === 'invs' && <Card stg={3}><DataTable rows={invs} cols={[
          { h: 'رقم النظام', r: i => <span className="num font-semibold">{i.sys}</span> }, { h: 'رقم المورد', r: i => <Num className="tx2">{i.supNo}</Num> },
          { h: 'الإجمالي', r: i => fmtMoney(i.subtotal + i.tax) }, { h: 'المتبقي', r: i => fmtMoney(i.subtotal + i.tax - i.paid) }, { h: 'الحالة', r: i => <StatusBadge map={INV_STATUS} value={i.status} /> }]} empty="لا توجد فواتير" /></Card>}
        {tab === 'pays' && <Card stg={3}><DataTable rows={pays} cols={[
          { h: 'رقم الدفعة', r: p => <span className="num font-semibold">{p.no}</span> }, { h: 'الوسيلة', r: p => SP_METHODS[p.method] },
          { h: 'المبلغ', r: p => fmtMoney(p.amount) }, { h: 'التاريخ', r: p => <Num className="tx2">{p.date}</Num> }]} empty="لا توجد مدفوعات" /></Card>}
      </div>
    </div>
    {edit && <SupplierForm row={s} onClose={() => setEdit(false)} />}
    {uform && <Modal open onClose={() => setUform(null)} title={uform.id ? 'تعديل حساب موظف' : 'حساب موظف جديد'} icon="user" width={480}
      footer={<><button className="btn btn-soft" onClick={() => setUform(null)}>إلغاء</button><button className="btn btn-dark" onClick={() => { setUform(null); toast('تم الحفظ'); }}>حفظ</button></>}>
      <div className="space-y-4">
        <Field label="الاسم" req><input className="inp" defaultValue={uform.name} /></Field>
        <Field label="البريد الإلكتروني" req><input className="inp num" dir="ltr" defaultValue={uform.email} /></Field>
        <Field label="الهاتف"><input className="inp num" dir="ltr" defaultValue={uform.phone} /></Field>
        <Field label="كلمة المرور" req={!uform.id} hint={uform.id ? 'اتركها فارغة للإبقاء على كلمة المرور الحالية · 8 أحرف على الأقل' : '8 أحرف على الأقل'}><input className="inp num" type="password" dir="ltr" minLength={8} placeholder="••••••••" /></Field>
        <ToggleRow label="مفعّل" on={uform.active ?? true} />
      </div>
    </Modal>}
    <ConfirmModal open={!!udel} onClose={() => setUdel(null)} danger title="إزالة الحساب" cta="إزالة" text={<>سيفقد <b className="tx1">{udel?.name}</b> إمكانية الدخول إلى بوابة الموردين.</>} onConfirm={() => toast('تمت إزالة الحساب')} />
  </div>;
}

/* ---------------- PURCHASING ---------------- */
function ItemsEditor({ cols, max = 50, init = 1, extra }) {
  const [rows, setRows] = useState(Array.from({ length: init }).map((_, i) => ({ k: i })));
  return <div>
    <div className="flex items-center justify-between mb-2"><span className="lbl">البنود <span className="accent">*</span></span><span className="text-[11px] tx3"><Num>{rows.length}</Num> / <Num>{max}</Num></span></div>
    <div className="v-sub p-2 space-y-2">
      <div className="grid gap-2 px-1 text-[11px] tx3" style={{ gridTemplateColumns: cols.map(c => c.fr).join(' ') + ' 32px' }}>{cols.map((c, i) => <span key={i}>{c.h}</span>)}<span></span></div>
      {rows.map((r, ri) => <div key={r.k} className="grid gap-2 items-center" style={{ gridTemplateColumns: cols.map(c => c.fr).join(' ') + ' 32px' }}>
        {cols.map((c, i) => <Fragment key={i}>{c.el(ri)}</Fragment>)}
        <button className="icon-btn !w-7 !h-7" disabled={rows.length === 1} onClick={() => setRows(rows.filter(x => x.k !== r.k))}><Icon name="trash" size={12} /></button>
      </div>)}
      <button className="btn btn-ghost btn-sm w-full" disabled={rows.length >= max} onClick={() => setRows([...rows, { k: Date.now() }])}><Icon name="plus" size={13} />إضافة بند</button>
    </div>
    {extra}
  </div>;
}
const VSel = ({ d }) => <select className="inp inp-sm !bg-[var(--bg-card)]" defaultValue={d}><option value="">اختر المتغير</option>{VARIANTS.map(v => <option key={v.sku} value={v.sku}>{v.name} · {v.sku}</option>)}</select>;
const NInp = ({ d, step = '1', min = '1', ph }) => <input type="number" step={step} min={min} className="inp inp-sm num !bg-[var(--bg-card)]" defaultValue={d} placeholder={ph} />;

function PurchasingPage({ sub = 'pr' }) {
  const [tab, setTab] = useState(sub);
  useEffect(() => setTab(sub), [sub]);
  return <div className="space-y-5">
    <PageHead title="المشتريات" sub="أربع وحدات مترابطة: من طلب الشراء حتى سداد فاتورة المورد." />
    <div className="flex items-center gap-2 flex-wrap stg stg-2">
      {[['pr', 'طلبات الشراء', 'file'], ['po', 'أوامر الشراء', 'cart'], ['inv', 'فواتير الموردين', 'receipt'], ['pay', 'مدفوعات الموردين', 'cash']].map(([k, l, ic], i) => <Fragment key={k}>
        {i > 0 && <Icon name="chevL" size={14} className="tx3" />}
        <button onClick={() => setTab(k)} className={'v-pill px-4 py-2 flex items-center gap-2 text-[13px] font-medium transition'} style={tab === k ? { background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)', borderColor: 'transparent' } : { color: 'var(--text-secondary)' }}>
          <span className={'w-6 h-6 rounded-full flex items-center justify-center ' + (tab === k ? 'bg-white/15' : 'v-well')}><Icon name={ic} size={12} /></span>{l}</button>
      </Fragment>)}
    </div>
    {tab === 'pr' && <PRModule />}
    {tab === 'po' && <POModule />}
    {tab === 'inv' && <InvModule />}
    {tab === 'pay' && <PayModule />}
  </div>;
}

function PRModule() {
  const toast = useToast();
  const [st, setSt] = useState(''); const [br, setBr] = useState('');
  const [form, setForm] = useState(null); const [view, setView] = useState(null);
  const rows = PRS.filter(p => (!st || p.status === st) && (!br || p.branch === br));
  return <>
    <Card stg={3}>
      <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
        <div className="flex flex-wrap gap-2"><FSelect value={st} onChange={setSt} placeholder="كل الحالات" options={Object.entries(PR_STATUS).map(([v, s]) => ({ value: v, label: s.label }))} /><FSelect value={br} onChange={setBr} placeholder="كل الفروع" options={BRANCHES} /></div>
        <button className="btn btn-dark btn-sm" onClick={() => setForm({})}><Icon name="plus" size={13} />طلب شراء</button>
      </div>
      <DataTable rows={rows} onRow={setView} cols={[
        { h: 'رقم الطلب', r: p => <span className="num font-semibold tx1">{p.no}</span> },
        { h: 'الفرع', r: p => <span className="tx2">{p.branch}</span> },
        { h: 'الحالة', r: p => <StatusBadge map={PR_STATUS} value={p.status} /> },
        { h: 'مقدم الطلب', r: p => <div className="flex items-center gap-2"><Avatar name={p.by} tone={p.id} size={24} /><span className="text-[12px]">{p.by}</span></div> },
        { h: 'البنود', align: 'center', r: p => <Num>{p.items}</Num> },
        { h: 'التاريخ', r: p => <Num className="tx2">{p.date}</Num> },
        { h: '', r: p => <RowActions>
          {p.status === 'draft' && <><button className="btn btn-soft btn-xs" onClick={() => setForm(p)}>تعديل</button><button className="btn btn-dark btn-xs" onClick={() => toast('أُرسل للاعتماد')}>إرسال للاعتماد</button><button className="icon-btn !w-7 !h-7" onClick={() => toast('تم حذف المسودة')}><Icon name="trash" size={12} /></button></>}
          {p.status === 'pending' && <><button className="btn btn-danger btn-xs" onClick={() => toast('تم الرفض')}>رفض</button><button className="btn btn-success btn-xs" onClick={() => toast('تم الاعتماد', 'green')}>اعتماد</button></>}
        </RowActions> },
      ]} />
    </Card>
    {form && <Modal open onClose={() => setForm(null)} title={form.id ? 'تعديل طلب الشراء ' + form.no : 'طلب شراء جديد'} icon="file" width={680}
      footer={<><button className="btn btn-soft" onClick={() => setForm(null)}>إلغاء</button><button className="btn btn-soft" onClick={() => { setForm(null); toast('حُفظ كمسودة'); }}>حفظ كمسودة</button><button className="btn btn-dark" onClick={() => { setForm(null); toast('أُرسل للاعتماد'); }}>حفظ وإرسال للاعتماد</button></>}>
      <div className="space-y-4">
        <Field label="الفرع" req><select className="inp" defaultValue={form.branch}><option value="">اختر الفرع</option>{BRANCHES.map(b => <option key={b}>{b}</option>)}</select></Field>
        <ItemsEditor init={3} cols={[
          { h: 'متغير المنتج', fr: '2.2fr', el: i => <VSel d={VARIANTS[i]?.sku} /> },
          { h: 'الكمية', fr: '.8fr', el: i => <NInp d={[24, 60, 12][i]} /> },
          { h: 'ملاحظات البند (255)', fr: '1.6fr', el: () => <input maxLength={255} className="inp inp-sm !bg-[var(--bg-card)]" placeholder="اختياري" /> }]} />
        <TextArea label="ملاحظات" max={2000} />
      </div>
    </Modal>}
    {view && <Modal open onClose={() => setView(null)} title={<span className="flex items-center gap-2"><span className="num">{view.no}</span><StatusBadge map={PR_STATUS} value={view.status} /></span>} icon="file" width={600}
      footer={view.status === 'pending' ? <><button className="btn btn-danger" onClick={() => { setView(null); toast('تم الرفض'); }}>رفض</button><button className="btn btn-dark" onClick={() => { setView(null); toast('تم الاعتماد', 'green'); }}>اعتماد</button></>
        : view.status === 'draft' ? <><button className="btn btn-soft" onClick={() => toast('تم حذف المسودة')}>حذف</button><button className="btn btn-dark" onClick={() => { setView(null); toast('أُرسل للاعتماد'); }}>إرسال للاعتماد</button></> : null}>
      <div className="space-y-4 pb-2">
        <KV items={[['الفرع', view.branch], ['مقدم الطلب', view.by], ['التاريخ', <Num>{view.date}</Num>]]} />
        <DataTable compact rows={VARIANTS.slice(0, 3).map((v, i) => ({ v, q: [24, 60, 12][i], n: i === 1 ? 'عاجل قبل نهاية الشهر' : null }))} cols={[
          { h: 'المتغير', r: r => <div className="leading-tight"><div className="font-medium tx1">{r.v.name}</div><div className="text-[11px] tx3 num">{r.v.sku}</div></div> }, { h: 'الكمية', r: r => <Num className="font-semibold">{r.q}</Num> }, { h: 'ملاحظات', r: r => r.n || <span className="tx3">—</span> }]} />
      </div>
    </Modal>}
  </>;
}

function POModule() {
  const toast = useToast();
  const [q, setQ] = useState(''); const [st, setSt] = useState(''); const [sp, setSp] = useState(''); const [br, setBr] = useState('');
  const [form, setForm] = useState(false); const [view, setView] = useState(null); const [recv, setRecv] = useState(false);
  const [fromPR, setFromPR] = useState('');
  const rows = POS.filter(p => (!q || p.no.includes(q)) && (!st || p.status === st) && (!sp || String(p.sup) === sp) && (!br || p.branch === br));
  const actionsFor = p => ({
    draft: ['edit', 'approve', 'delete'], accepted: ['receive', 'cancel'], sent: ['cancel'], revision: ['edit', 'send', 'cancel'], partial: ['receive', 'close'], completed: [], cancelled: [],
  }[p.status]);
  const lines = VARIANTS.slice(0, 4).map((v, i) => ({ v, ord: [120, 80, 200, 40][i], rec: view?.status === 'partial' ? [120, 40, 0, 40][i] : 0, cost: v.cost, tax: 14 }));
  return <>
    <Card stg={3}>
      <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
        <div className="flex flex-wrap gap-2"><SearchPill value={q} onChange={setQ} placeholder="رقم الأمر" w="w-32" />
          <FSelect value={st} onChange={setSt} placeholder="الحالة" options={Object.entries(PO_STATUS).map(([v, s]) => ({ value: v, label: s.label }))} />
          <FSelect value={sp} onChange={setSp} placeholder="المورد" options={SUPPLIERS.map(s => ({ value: String(s.id), label: s.company || s.name }))} />
          <FSelect value={br} onChange={setBr} placeholder="الفرع" options={BRANCHES} /></div>
        <button className="btn btn-dark btn-sm" onClick={() => setForm(true)}><Icon name="plus" size={13} />أمر شراء</button>
      </div>
      <DataTable rows={rows} onRow={setView} cols={[
        { h: 'رقم الأمر', r: p => <span className="num font-semibold tx1">{p.no}</span> },
        { h: 'المورد', r: p => <div className="flex items-center gap-2"><Avatar name={supName(p.sup)} tone={p.sup} size={24} /><span className="font-medium">{supName(p.sup)}</span></div> },
        { h: 'الفرع', r: p => <span className="tx2 text-[12px]">{p.branch}</span> },
        { h: 'الحالة', r: p => <StatusBadge map={PO_STATUS} value={p.status} /> },
        { h: 'التوريد المتوقع', r: p => p.eta ? <Num className="tx2">{p.eta}</Num> : <span className="tx3">—</span> },
        { h: 'الإجمالي', r: p => <span className="font-semibold">{fmtMoney(p.total)}</span> },
      ]} />
    </Card>
    {view && <Modal open onClose={() => setView(null)} width={820} icon="cart" title={<span className="flex items-center gap-2"><span className="num">{view.no}</span><StatusBadge map={PO_STATUS} value={view.status} /></span>}
      sub={<>{supName(view.sup)} · {view.branch}</>}
      footer={<>
        {actionsFor(view).includes('delete') && <button className="btn btn-ghost me-auto" onClick={() => { setView(null); toast('تم الحذف'); }}><Icon name="trash" size={13} />حذف</button>}
        {actionsFor(view).includes('cancel') && <button className="btn btn-danger" onClick={() => { setView(null); toast('تم إلغاء الأمر'); }}>إلغاء الأمر</button>}
        {actionsFor(view).includes('edit') && <button className="btn btn-soft" onClick={() => { setView(null); setForm(true); }}>تعديل</button>}
        {actionsFor(view).includes('close') && <button className="btn btn-soft" onClick={() => { setView(null); toast('أُغلق الأمر بالكميات المستلمة'); }}>إغلاق بالمستلم</button>}
        {actionsFor(view).includes('approve') && <button className="btn btn-dark" onClick={() => toast('تم الاعتماد')}>اعتماد</button>}
        {(actionsFor(view).includes('approve') || actionsFor(view).includes('send')) && <button className="btn btn-dark" onClick={() => { setView(null); toast('أُرسل للمورد'); }}><Icon name="send" size={13} />إرسال للمورد</button>}
        {actionsFor(view).includes('receive') && <button className="btn btn-accent" onClick={() => setRecv(true)}><Icon name="box" size={13} />استلام</button>}
      </>}>
      <div className="space-y-4 pb-2">
        {view.status === 'revision' && <Note tone="amber" icon="quote"><b>ملاحظة تعديل من المورد:</b> سعر سمن نباتي 2 كجم ارتفع إلى 221 جنيه، ونقترح تأجيل التوريد إلى 3 نوفمبر.</Note>}
        <div className="grid grid-cols-3 gap-2">
          <div className="v-sub p-3"><div className="text-[11px] tx3">التوريد المتوقع</div><Num className="font-semibold">{view.eta || '—'}</Num></div>
          <div className="v-sub p-3"><div className="text-[11px] tx3">طلب الشراء المرتبط</div><Num className="font-semibold">PR-0315</Num></div>
          <div className="v-sub p-3"><div className="text-[11px] tx3">الإجمالي</div><div className="font-semibold">{fmtMoney(view.total)}</div></div>
        </div>
        <DataTable compact rows={lines} cols={[
          { h: 'المتغير', r: l => <div className="leading-tight"><div className="font-medium tx1">{l.v.name}</div><div className="text-[11px] tx3 num">{l.v.sku}</div></div> },
          { h: 'المطلوب', align: 'center', r: l => <Num>{l.ord}</Num> },
          { h: 'المستلم', r: l => <div className="flex items-center gap-2 min-w-[110px]"><div className="flex-1"><HatchBar pct={l.rec / l.ord * 100} h={9} /></div><Num className="text-[11.5px]">{l.rec}</Num></div> },
          { h: 'تكلفة الوحدة', r: l => fmtMoney(l.cost) }, { h: 'الضريبة', r: l => <Num>{l.tax}%</Num> },
        ]} />
        {view.status === 'partial' && <div><div className="lbl mb-2">التوريدات المرتبطة</div>
          <div className="v-sub p-3 flex items-center justify-between text-[12px]"><span className="flex items-center gap-2"><Icon name="truck" size={14} className="tx2" /><span className="num font-semibold">DLV-3381</span> · استلام جزئي</span><Num className="tx3">2026-10-26</Num></div></div>}
      </div>
    </Modal>}
    <Modal open={recv} onClose={() => setRecv(false)} title={'استلام ' + (view?.no || '')} icon="box" width={620}
      footer={<><button className="btn btn-soft" onClick={() => setRecv(false)}>إلغاء</button><button className="btn btn-accent" onClick={() => { setRecv(false); setView(null); toast('تم تسجيل الاستلام', 'green'); }}>تأكيد الاستلام</button></>}>
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <Field label="موقع التخزين" hint="اختياري"><select className="inp"><option value="">الموقع الافتراضي</option><option>رف A-12</option><option>منطقة الاستلام</option></select></Field>
          <Field label="توريد المورد" hint="اختياري"><select className="inp"><option value="">بدون ربط</option><option>DLV-3392</option></select></Field>
        </div>
        <div className="v-sub p-2">
          <div className="grid grid-cols-12 gap-2 px-2 py-1 text-[11px] tx3"><span className="col-span-6">البند</span><span className="col-span-2 text-center">مطلوب</span><span className="col-span-2 text-center">سابق</span><span className="col-span-2 text-center">الآن</span></div>
          {lines.map((l, i) => <div key={i} className="grid grid-cols-12 gap-2 items-center px-2 py-1.5">
            <span className="col-span-6 text-[12.5px] font-medium clamp1">{l.v.name}</span><Num className="col-span-2 text-center">{l.ord}</Num><Num className="col-span-2 text-center tx3">{l.rec}</Num>
            <input type="number" min="0" max={l.ord - l.rec} defaultValue={l.ord - l.rec} className="inp inp-sm col-span-2 num text-center !bg-[var(--bg-card)]" /></div>)}
        </div>
      </div>
    </Modal>
    {form && <Modal open onClose={() => setForm(false)} title="أمر شراء" icon="cart" width={780}
      footer={<><button className="btn btn-soft" onClick={() => setForm(false)}>إلغاء</button><button className="btn btn-dark" onClick={() => { setForm(false); toast('تم حفظ الأمر كمسودة'); }}>حفظ</button></>}>
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <Field label="المورد" req><select className="inp"><option value="">اختر المورد</option>{SUPPLIERS.filter(s => s.active).map(s => <option key={s.id}>{s.company || s.name}</option>)}</select></Field>
          <Field label="ربط بطلب شراء" hint="اختياري — يملأ الفرع والبنود من الطلب"><select className="inp" value={fromPR} onChange={e => setFromPR(e.target.value)}><option value="">إنشاء مباشر</option>{PRS.filter(p => p.status === 'approved').map(p => <option key={p.id}>{p.no} — {p.branch}</option>)}</select></Field>
          <Field label="الفرع" req={!fromPR}><select className="inp" disabled={!!fromPR}><option value="">{fromPR ? 'من طلب الشراء' : 'اختر الفرع'}</option>{BRANCHES.map(b => <option key={b}>{b}</option>)}</select></Field>
          <Field label="تاريخ التوريد المتوقع"><input type="date" className="inp" /></Field>
        </div>
        <ItemsEditor init={2} cols={[
          { h: 'المتغير', fr: '2.4fr', el: i => <VSel d={VARIANTS[i]?.sku} /> },
          { h: 'الكمية', fr: '.8fr', el: i => <NInp d={[120, 80][i]} /> },
          { h: 'تكلفة الوحدة', fr: '1fr', el: i => <NInp d={VARIANTS[i]?.cost} step="0.01" min="0" /> },
          { h: 'الضريبة %', fr: '.8fr', el: () => <NInp d={14} step="0.01" min="0" /> }]} />
        <div className="flex items-end gap-3">
          <Field label="خصم إجمالي" className="w-48"><input type="number" className="inp num" defaultValue="0" /></Field>
          <div className="flex-1 v-sub px-4 py-2.5 flex items-center justify-between"><span className="tx2 text-[12px]">الإجمالي التقديري</span><span className="font-display font-bold">{fmtMoney(57456)}</span></div>
        </div>
      </div>
    </Modal>}
  </>;
}

function InvModule() {
  const toast = useToast();
  const [form, setForm] = useState(null); const [view, setView] = useState(null);
  return <>
    <Card stg={3}>
      <CardHead title="فواتير الموردين" right={<button className="btn btn-dark btn-sm" onClick={() => setForm({})}><Icon name="plus" size={13} />فاتورة</button>} />
      <DataTable rows={INVOICES} onRow={setView} cols={[
        { h: 'رقم النظام', r: i => <span className="num font-semibold tx1">{i.sys}</span> },
        { h: 'رقم فاتورة المورد', r: i => <Num className="tx2">{i.supNo}</Num> },
        { h: 'المورد', r: i => <span className="font-medium">{supName(i.sup)}</span> },
        { h: 'الإصدار / الاستحقاق', r: i => <div className="leading-tight"><Num className="text-[12px]">{i.issue}</Num><div className="text-[11px] tx3 num">{i.due || '—'}</div></div> },
        { h: 'الإجمالي', r: i => fmtMoney(i.subtotal + i.tax) },
        { h: 'المتبقي', r: i => <span className={'font-semibold ' + (i.subtotal + i.tax - i.paid > 0 && i.status !== 'cancelled' ? 'tx1' : 'tx3')}>{fmtMoney(i.status === 'cancelled' ? 0 : i.subtotal + i.tax - i.paid)}</span> },
        { h: 'الحالة', r: i => <StatusBadge map={INV_STATUS} value={i.status} /> },
        { h: '', r: i => i.file ? <Icon name="file" size={15} className="tx3" /> : null },
      ]} />
    </Card>
    {view && <Modal open onClose={() => setView(null)} width={600} icon="receipt" title={<span className="flex items-center gap-2"><span className="num">{view.sys}</span><StatusBadge map={INV_STATUS} value={view.status} /></span>} sub={supName(view.sup)}
      footer={<>
        {['draft', 'posted'].includes(view.status) && <button className="btn btn-danger me-auto" onClick={() => { setView(null); toast('تم إلغاء الفاتورة'); }}>إلغاء</button>}
        {view.status === 'draft' && <><button className="btn btn-soft" onClick={() => { setForm(view); setView(null); }}>تعديل</button><button className="btn btn-dark" onClick={() => { setView(null); toast('تم ترحيل الفاتورة'); }}>ترحيل</button></>}
        {['posted', 'partial'].includes(view.status) && <button className="btn btn-accent" onClick={() => { setView(null); toast('انتقال لتسجيل دفعة'); }}><Icon name="cash" size={13} />تسجيل دفعة</button>}
      </>}>
      <div className="space-y-4 pb-2">
        <div className="v-orange rounded-[18px] p-4 grid grid-cols-3 gap-3">
          {[['الإجمالي', view.subtotal + view.tax], ['المدفوع', view.paid], ['المتبقي', view.subtotal + view.tax - view.paid]].map(([l, v]) => <div key={l}><div className="text-[11.5px] text-white/85">{l}</div><div className="font-display font-bold text-[18px] num ltr">{fmtNum(v)}</div></div>)}
        </div>
        <div className="mt-1"><HatchBar pct={view.paid / (view.subtotal + view.tax) * 100} /></div>
        <KV items={[['رقم فاتورة المورد', <Num>{view.supNo}</Num>], ['أمر الشراء', view.po && <Num>{view.po}</Num>], ['تاريخ الإصدار', <Num>{view.issue}</Num>], ['تاريخ الاستحقاق', view.due && <Num>{view.due}</Num>], ['المجموع الفرعي', fmtMoney(view.subtotal)], ['الضريبة', fmtMoney(view.tax)]]} />
        {view.file && <div className="v-sub p-3 flex items-center justify-between"><span className="flex items-center gap-2.5 text-[12.5px]"><span className="w-9 h-9 rounded-lg bg-[var(--bg-card)] flex items-center justify-center accent"><Icon name="file" size={16} /></span><span><span className="num font-medium tx1">{view.supNo}.pdf</span><div className="text-[11px] tx3 num">1.2 MB</div></span></span><button className="btn btn-soft btn-xs"><Icon name="download" size={12} />تنزيل</button></div>}
      </div>
    </Modal>}
    {form && <Modal open onClose={() => setForm(null)} title={form.id ? 'تعديل فاتورة ' + form.sys : 'فاتورة مورد جديدة'} icon="receipt" width={600}
      footer={<><button className="btn btn-soft" onClick={() => setForm(null)}>إلغاء</button><button className="btn btn-dark" onClick={() => { setForm(null); toast('تم حفظ الفاتورة كمسودة'); }}>حفظ</button></>}>
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <Field label="المورد" req hint={form.id ? 'لا يمكن تغيير المورد بعد الإنشاء' : null}><select className="inp" disabled={!!form.id} defaultValue={form.sup}><option value="">اختر المورد</option>{SUPPLIERS.map(s => <option key={s.id} value={s.id}>{s.company || s.name}</option>)}</select></Field>
          <Field label="رقم فاتورة المورد" req><input className="inp num" dir="ltr" defaultValue={form.supNo} /></Field>
          <Field label="أمر شراء مرتبط"><select className="inp" defaultValue={form.po || ''}><option value="">بدون</option>{POS.map(p => <option key={p.id}>{p.no}</option>)}</select></Field>
          <div></div>
          <Field label="تاريخ الإصدار" req><input type="date" className="inp" defaultValue={form.issue} /></Field>
          <Field label="تاريخ الاستحقاق"><input type="date" className="inp" defaultValue={form.due || ''} /></Field>
          <Field label="المجموع الفرعي" req><input type="number" step="0.01" className="inp num" defaultValue={form.subtotal} /></Field>
          <Field label="الضريبة"><input type="number" step="0.01" className="inp num" defaultValue={form.tax} /></Field>
        </div>
        <Field label="المرفق"><Dropzone accept=".pdf,.jpg,.png,.webp" max="10MB" /></Field>
        <Note icon="info">الفاتورة تعتمد على مبالغ إجمالية فقط — بدون جدول أصناف.</Note>
      </div>
    </Modal>}
  </>;
}

function PayModule() {
  const toast = useToast();
  const [form, setForm] = useState(null); const [view, setView] = useState(null); const [voidM, setVoidM] = useState(false);
  const [method, setMethod] = useState('bank');
  return <>
    <Card stg={3}>
      <CardHead title="مدفوعات الموردين" right={<button className="btn btn-dark btn-sm" onClick={() => { setMethod('bank'); setForm({}); }}><Icon name="plus" size={13} />دفعة</button>} />
      <DataTable rows={SPAYMENTS} onRow={setView} cols={[
        { h: 'رقم الدفعة', r: p => <span className={'num font-semibold ' + (p.void ? 'tx3 line-through' : 'tx1')}>{p.no}</span> },
        { h: 'المورد', r: p => <span className="font-medium">{supName(p.sup)}</span> },
        { h: 'الفاتورة', r: p => p.inv ? <Num className="tx2">{p.inv}</Num> : <span className="tx3 text-[11.5px]">غير مرتبطة</span> },
        { h: 'الوسيلة', r: p => <span className="flex items-center gap-1.5 text-[12px]"><Icon name={{ cash: 'cash', bank: 'bank', cheque: 'file' }[p.method]} size={13} className="tx3" />{SP_METHODS[p.method]}</span> },
        { h: 'المرجع', r: p => p.ref ? <Num className="text-[11.5px] tx2">{p.ref}</Num> : <span className="tx3">—</span> },
        { h: 'المبلغ', r: p => <span className={'font-semibold ' + (p.void ? 'tx3 line-through' : '')}>{fmtMoney(p.amount)}</span> },
        { h: 'التاريخ', r: p => <Num className="tx2">{p.date}</Num> },
        { h: 'الحالة', r: p => p.void ? <Badge tone="red">ملغاة</Badge> : <Badge tone="green">سارية</Badge> },
      ]} />
    </Card>
    {view && <Modal open onClose={() => setView(null)} width={540} icon="cash" title={<span className="num">{view.no}</span>} sub={supName(view.sup)}
      footer={!view.void && <><button className="btn btn-danger me-auto" onClick={() => setVoidM(true)}>إلغاء الدفعة</button>{!view.inv && <button className="btn btn-soft" onClick={() => toast('تم ربط الدفعة بالفاتورة')}><Icon name="link" size={13} />ربط بفاتورة</button>}<button className="btn btn-dark" onClick={() => { setMethod(view.method); setForm(view); setView(null); }}>تعديل</button></>}>
      <div className="space-y-4 pb-2">
        {view.void && <div className="v-sub p-4 t-red !bg-[var(--red-bg)] rounded-[14px]"><div className="font-semibold text-[13px] mb-1.5 flex items-center gap-2"><Icon name="ban" size={14} />دفعة ملغاة</div>
          <KV items={[['السبب', view.void.reason], ['بواسطة', view.void.by], ['الوقت', <Num>{view.void.at}</Num>]]} /></div>}
        <div className="v-sub p-4 flex items-center justify-between"><span className="tx2">المبلغ</span><span className="font-display font-bold text-[20px]">{fmtMoney(view.amount)}</span></div>
        <KV items={[['الفاتورة', view.inv && <Num>{view.inv}</Num>], ['الوسيلة', SP_METHODS[view.method]], ['المرجع', view.ref && <Num>{view.ref}</Num>], ['تاريخ الدفع', <Num>{view.date}</Num>], ['ملاحظات', view.notes]]} />
      </div>
    </Modal>}
    <Modal open={voidM} onClose={() => setVoidM(false)} title="إلغاء الدفعة" icon="ban" tone="red" width={440}
      footer={<><button className="btn btn-soft" onClick={() => setVoidM(false)}>تراجع</button><button className="btn btn-danger" onClick={() => { setVoidM(false); setView(null); toast('تم إلغاء الدفعة'); }}>إلغاء الدفعة</button></>}>
      <div className="pb-2"><TextArea label="سبب الإلغاء" req /></div>
    </Modal>
    {form && <Modal open onClose={() => setForm(null)} title={form.id ? 'تعديل دفعة ' + form.no : 'دفعة جديدة لمورد'} icon="cash" width={560}
      footer={<><button className="btn btn-soft" onClick={() => setForm(null)}>إلغاء</button><button className="btn btn-dark" onClick={() => { setForm(null); toast('تم الحفظ'); }}>حفظ</button></>}>
      <div className="space-y-4">
        {form.id && <Note icon="lock">لا يمكن تعديل المبلغ أو المورد أو التاريخ — يمكن تعديل الوسيلة والمرجع والملاحظات فقط.</Note>}
        <div className="grid grid-cols-2 gap-3">
          <Field label="المورد" req><select className="inp" disabled={!!form.id} defaultValue={form.sup}><option value="">اختر المورد</option>{SUPPLIERS.map(s => <option key={s.id} value={s.id}>{s.company || s.name}</option>)}</select></Field>
          <Field label="فاتورة مرتبطة" hint="اختيارية"><select className="inp" defaultValue={form.inv || ''}><option value="">بدون</option>{INVOICES.filter(i => ['posted', 'partial'].includes(i.status)).map(i => <option key={i.id}>{i.sys}</option>)}</select></Field>
          <Field label="المبلغ" req hint={form.id ? null : 'أكبر من صفر'}><input type="number" min="0.01" step="0.01" className="inp num" disabled={!!form.id} defaultValue={form.amount} /></Field>
          <Field label="تاريخ الدفع" req><input type="date" className="inp" disabled={!!form.id} defaultValue={form.date || '2026-10-27'} /></Field>
        </div>
        <Field label="الوسيلة" req><Seg value={method} onChange={setMethod} options={Object.entries(SP_METHODS).map(([v, l]) => ({ value: v, label: l }))} /></Field>
        <Field label="المرجع" req={method !== 'cash'} hint={method === 'cash' ? 'اختياري للدفع النقدي' : method === 'cheque' ? 'رقم الشيك' : 'رقم التحويل البنكي'}><input className="inp num" dir="ltr" defaultValue={form.ref} /></Field>
        <TextArea label="ملاحظات" />
      </div>
    </Modal>}
  </>;
}

Object.assign(window, { SuppliersList, SupplierDetail, PurchasingPage, INVOICES, SPAYMENTS, POS });
