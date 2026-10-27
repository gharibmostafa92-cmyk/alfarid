/* ============ 8. Finance · 9. Shifts · 10. Wholesale ============ */

const EXPENSES = [
  { id: 1, no: 'EXP-2214', branch: 'فرع مدينة نصر', tr: 2, cat: 'نظافة', amount: 350, date: '2026-10-27', by: 'محمود سعيد', shift: 'SH-5521', notes: 'أدوات تنظيف' },
  { id: 2, no: 'EXP-2213', branch: 'المخزن الرئيسي', tr: 1, cat: 'صيانة', amount: 4800, date: '2026-10-26', by: 'أحمد ناصر', shift: null, notes: 'صيانة ثلاجة العرض' },
  { id: 3, no: 'EXP-2212', branch: 'فرع المعادي', tr: 4, cat: 'نقل وتحميل', amount: 600, date: '2026-10-26', by: 'كريم فتحي', shift: 'SH-5518', notes: null },
  { id: 4, no: 'EXP-2211', branch: 'المخزن الرئيسي', tr: 5, cat: 'إيجار', amount: 45000, date: '2026-10-25', by: 'أحمد ناصر', shift: null, notes: 'إيجار نوفمبر' },
  { id: 5, no: 'EXP-2210', branch: 'فرع مدينة نصر', tr: 3, cat: 'ضيافة', amount: 180, date: '2026-10-24', by: 'سارة حمدي', shift: 'SH-5512', notes: null },
];
const TRANSFERS = [
  { id: 1, from: 2, to: 1, amount: 15000, status: 'pending', by: 'محمود سعيد', appr: null, date: '2026-10-27', notes: 'توريد نقدية نهاية اليوم' },
  { id: 2, from: 1, to: 5, amount: 250000, status: 'pending', by: 'أحمد ناصر', appr: null, date: '2026-10-27', notes: 'إيداع بنكي' },
  { id: 3, from: 4, to: 1, amount: 10500, status: 'approved', by: 'كريم فتحي', appr: 'أحمد ناصر', date: '2026-10-26', notes: null },
  { id: 4, from: 5, to: 6, amount: 120000, status: 'approved', by: 'أحمد ناصر', appr: 'هشام رضا', date: '2026-10-24', notes: null },
  { id: 5, from: 3, to: 1, amount: 8000, status: 'rejected', by: 'سارة حمدي', appr: 'أحمد ناصر', date: '2026-10-23', notes: 'المبلغ غير مطابق للعد' },
];
const SHIFTS = [
  { id: 1, no: 'SH-5524', cashier: 'محمود سعيد', branch: 'فرع مدينة نصر', tr: 2, open: '2026-10-27 08:00', close: null, status: 'open', exp: null, act: null },
  { id: 2, no: 'SH-5521', cashier: 'منة الله عادل', branch: 'فرع مدينة نصر', tr: 3, open: '2026-10-26 16:00', close: '2026-10-27 00:10', status: 'closed', exp: 21840, act: 21640 },
  { id: 3, no: 'SH-5518', cashier: 'كريم فتحي', branch: 'فرع المعادي', tr: 4, open: '2026-10-26 08:00', close: '2026-10-26 16:05', status: 'closed', exp: 14260.5, act: 14310.5 },
  { id: 4, no: 'SH-5515', cashier: 'سارة حمدي', branch: 'فرع مدينة نصر', tr: 2, open: '2026-10-25 16:00', close: '2026-10-26 00:02', status: 'approved', exp: 18900, act: 18900 },
  { id: 5, no: 'SH-5512', cashier: 'يوسف عاطف', branch: 'فرع المنصورة', tr: 2, open: '2026-10-25 08:00', close: '2026-10-25 16:00', status: 'approved', exp: 9420, act: 9300 },
];
const trName = id => TREASURIES.find(t => t.id === id)?.name;

/* ---------------- FINANCE ---------------- */
function FinancePage({ sub = 'tr' }) {
  const [tab, setTab] = useState(sub);
  useEffect(() => setTab(sub), [sub]);
  return <div className="space-y-5">
    <PageHead title="المالية" sub="الخزائن والمصروفات وتحويلات النقدية بين الخزائن." />
    <TabsBar value={tab} onChange={setTab} tabs={[{ value: 'tr', label: 'الخزائن', icon: 'vault', count: TREASURIES.length }, { value: 'exp', label: 'المصروفات', icon: 'receipt' }, { value: 'trf', label: 'تحويلات الخزائن', icon: 'swap', count: TRANSFERS.filter(t => t.status === 'pending').length }]} />
    {tab === 'tr' && <TreasuriesTab />}
    {tab === 'exp' && <ExpensesTab />}
    {tab === 'trf' && <TransfersTab />}
  </div>;
}

function TreasuriesTab() {
  const toast = useToast();
  const [br, setBr] = useState(''); const [ty, setTy] = useState(''); const [act, setAct] = useState('');
  const [form, setForm] = useState(null); const [del, setDel] = useState(null);
  const rows = TREASURIES.filter(t => (!br || t.branch === br) && (!ty || t.type === ty) && (!act || String(t.active) === act));
  const sum = k => TREASURIES.filter(t => t.type === k).reduce((a, t) => a + t.balance, 0);
  return <>
    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 stg stg-2">
      <KPI hero label="الحسابات البنكية" icon="bank" value={<span className="ltr">{fmtNum(sum('bank'), 0)}</span>} small foot="EGP" />
      <KPI label="الخزائن الرئيسية" icon="vault" value={<span className="ltr">{fmtNum(sum('main'), 0)}</span>} small foot="EGP" />
      <KPI label="أدراج الكاشير" icon="cash" value={<span className="ltr">{fmtNum(sum('drawer'), 0)}</span>} small foot="EGP" />
    </div>
    <Card stg={3}>
      <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
        <div className="flex flex-wrap gap-2"><FSelect value={br} onChange={setBr} placeholder="الفرع" options={BRANCHES} />
          <FSelect value={ty} onChange={setTy} placeholder="النوع" options={Object.entries(TREASURY_TYPES).map(([v, l]) => ({ value: v, label: l }))} />
          <FSelect value={act} onChange={setAct} placeholder="التفعيل" options={[{ value: 'true', label: 'مفعّلة' }, { value: 'false', label: 'معطّلة' }]} /></div>
        <button className="btn btn-dark btn-sm" onClick={() => setForm({})}><Icon name="plus" size={13} />خزينة</button>
      </div>
      <DataTable rows={rows} cols={[
        { h: 'الخزينة', r: t => <div className="flex items-center gap-2.5"><span className="w-9 h-9 rounded-[11px] v-sub flex items-center justify-center tx2"><Icon name={{ main: 'vault', drawer: 'cash', bank: 'bank' }[t.type]} size={15} /></span><span className="font-semibold tx1">{t.name}</span></div> },
        { h: 'الكود', r: t => <span className="num text-[11.5px] font-semibold v-sub px-2 py-1 rounded-md tx2">{t.code}</span> },
        { h: 'النوع', r: t => <span className="tx2">{TREASURY_TYPES[t.type]}</span> },
        { h: 'الفرع', r: t => t.branch ? <span className="tx2 text-[12px]">{t.branch}</span> : <span className="tx3 text-[11.5px]">مركزي</span> },
        { h: 'الرصيد الحالي', r: t => <span className="font-semibold tx1">{fmtMoney(t.balance)}</span> },
        { h: 'التفعيل', r: t => <ActiveDot on={t.active} /> },
        { h: '', r: t => <RowActions><button className="icon-btn !w-7 !h-7" onClick={() => setForm(t)}><Icon name="edit" size={13} /></button><button className="icon-btn !w-7 !h-7" onClick={() => setDel(t)}><Icon name="trash" size={13} /></button></RowActions> },
      ]} />
    </Card>
    {form && <Modal open onClose={() => setForm(null)} title={form.id ? 'تعديل خزينة' : 'خزينة جديدة'} icon="vault" width={520}
      footer={<><button className="btn btn-soft" onClick={() => setForm(null)}>إلغاء</button><button className="btn btn-dark" onClick={() => { setForm(null); toast('تم الحفظ'); }}>حفظ</button></>}>
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3"><Field label="الاسم" req><input className="inp" defaultValue={form.name} /></Field><Field label="الكود" req hint="فريد"><input className="inp num" dir="ltr" defaultValue={form.code} /></Field></div>
        <Field label="النوع" req><Seg value={form.type || 'drawer'} onChange={v => setForm({ ...form, type: v })} options={Object.entries(TREASURY_TYPES).map(([v, l]) => ({ value: v, label: l }))} /></Field>
        <Field label="الفرع" hint="اختياري — حسب نطاق صلاحيتك"><select className="inp" defaultValue={form.branch || ''}><option value="">مركزي (بدون فرع)</option>{BRANCHES.map(b => <option key={b}>{b}</option>)}</select></Field>
        {!form.id && <Note icon="info">تبدأ الخزينة برصيد صفر — لا يوجد رصيد افتتاحي.</Note>}
        <ToggleRow label="مفعّلة" on={form.active ?? true} />
      </div>
    </Modal>}
    <ConfirmModal open={!!del} onClose={() => setDel(null)} danger title={'حذف «' + (del?.name || '') + '»'} cta="حذف"
      text="يُمنع الحذف إن كان للخزينة رصيد أو حركات أو ورديات مرتبطة."
      onConfirm={() => del.balance > 0 ? toast('لا يمكن الحذف: الخزينة لها رصيد وحركات', 'red') : toast('تم الحذف')} />
  </>;
}

function ExpensesTab() {
  const toast = useToast();
  const [br, setBr] = useState(''); const [tr, setTr] = useState(''); const [sh, setSh] = useState(''); const [cat, setCat] = useState(''); const [from, setFrom] = useState(''); const [to, setTo] = useState('');
  const [form, setForm] = useState(null);
  const rows = EXPENSES.filter(e => (!br || e.branch === br) && (!tr || String(e.tr) === tr) && (!sh || e.shift === sh) && (!cat || e.cat === cat) && (!from || e.date >= from) && (!to || e.date <= to));
  return <>
    <Card stg={3}>
      <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
        <div className="flex flex-wrap gap-2">
          <FSelect value={br} onChange={setBr} placeholder="الفرع" options={BRANCHES} />
          <FSelect value={tr} onChange={setTr} placeholder="الخزينة" options={TREASURIES.map(t => ({ value: String(t.id), label: t.name }))} />
          <FSelect value={sh} onChange={setSh} placeholder="الوردية" options={SHIFTS.map(s => s.no)} />
          <FSelect value={cat} onChange={setCat} placeholder="التصنيف" options={[...new Set(EXPENSES.map(e => e.cat))]} />
          <FDate label="من" value={from} onChange={setFrom} /><FDate label="إلى" value={to} onChange={setTo} />
        </div>
        <button className="btn btn-dark btn-sm" onClick={() => setForm({})}><Icon name="plus" size={13} />مصروف</button>
      </div>
      <DataTable rows={rows} onRow={setForm} cols={[
        { h: 'رقم المصروف', r: e => <span className="num font-semibold tx1">{e.no}</span> },
        { h: 'الفرع', r: e => <span className="tx2 text-[12px]">{e.branch}</span> },
        { h: 'الخزينة', r: e => <span className="text-[12px]">{trName(e.tr)}</span> },
        { h: 'التصنيف', r: e => <span className="v-sub px-2.5 py-1 rounded-full text-[11px] tx2">{e.cat}</span> },
        { h: 'المبلغ', r: e => <span className="font-semibold">{fmtMoney(e.amount)}</span> },
        { h: 'التاريخ', r: e => <Num className="tx2">{e.date}</Num> },
        { h: 'المنشئ', r: e => <span className="text-[12px] tx2">{e.by}</span> },
        { h: 'الوردية', r: e => e.shift ? <Num className="text-[11.5px] tx2">{e.shift}</Num> : <span className="tx3">—</span> },
      ]} />
      <div className="v-bt mt-2 pt-3 flex justify-end gap-2 text-[12.5px]"><span className="tx2">إجمالي النتائج</span><span className="font-bold">{fmtMoney(rows.reduce((a, e) => a + e.amount, 0))}</span></div>
    </Card>
    {form && <Modal open onClose={() => setForm(null)} title={form.id ? 'تعديل ' + form.no : 'مصروف جديد'} icon="receipt" width={540}
      footer={<><button className="btn btn-soft" onClick={() => setForm(null)}>إلغاء</button><button className="btn btn-dark" onClick={() => { setForm(null); toast('تم الحفظ'); }}>حفظ</button></>}>
      <div className="space-y-4">
        {form.id && <Note icon="lock">يمكن تعديل التصنيف والملاحظات فقط.</Note>}
        <div className="grid grid-cols-2 gap-3">
          <Field label="الفرع" req><select className="inp" disabled={!!form.id} defaultValue={form.branch}>{BRANCHES.map(b => <option key={b}>{b}</option>)}</select></Field>
          <Field label="الخزينة" req><select className="inp" disabled={!!form.id} defaultValue={form.tr}>{TREASURIES.filter(t => t.active).map(t => <option key={t.id} value={t.id}>{t.name}</option>)}</select></Field>
          <Field label="التصنيف" req><input className="inp" list="exp-cats" defaultValue={form.cat} placeholder="مثال: صيانة" /><datalist id="exp-cats">{[...new Set(EXPENSES.map(e => e.cat))].map(c => <option key={c} value={c} />)}</datalist></Field>
          <Field label="المبلغ" req><input type="number" step="0.01" className="inp num" disabled={!!form.id} defaultValue={form.amount} /></Field>
          <Field label="التاريخ" req><input type="date" className="inp" disabled={!!form.id} defaultValue={form.date || '2026-10-27'} /></Field>
        </div>
        <TextArea label="ملاحظات" max={500} value={undefined} />
      </div>
    </Modal>}
  </>;
}

function TransfersTab() {
  const toast = useToast();
  const [f, setF] = useState(''); const [t, setT] = useState(''); const [st, setSt] = useState('');
  const [form, setForm] = useState(false); const [view, setView] = useState(null);
  const [src, setSrc] = useState(''); const [dst, setDst] = useState('');
  const rows = TRANSFERS.filter(x => (!f || String(x.from) === f) && (!t || String(x.to) === t) && (!st || x.status === st));
  const trOpts = TREASURIES.map(x => ({ value: String(x.id), label: x.name }));
  return <>
    <Card stg={3}>
      <div className="flex flex-wrap items-center justify-between gap-2 mb-4">
        <div className="flex flex-wrap gap-2"><FSelect value={f} onChange={setF} placeholder="الخزينة المصدر" options={trOpts} /><FSelect value={t} onChange={setT} placeholder="الوجهة" options={trOpts} />
          <FSelect value={st} onChange={setSt} placeholder="الحالة" options={Object.entries(TRANSFER_STATUS).map(([v, s]) => ({ value: v, label: s.label }))} /></div>
        <button className="btn btn-dark btn-sm" onClick={() => { setSrc(''); setDst(''); setForm(true); }}><Icon name="plus" size={13} />طلب تحويل</button>
      </div>
      <DataTable rows={rows} onRow={setView} cols={[
        { h: 'من خزينة → إلى خزينة', r: x => <div className="flex items-center gap-2 text-[12.5px]"><span className="font-medium tx1">{trName(x.from)}</span><span className="w-6 h-6 rounded-full v-well flex items-center justify-center"><Icon name="arrowL" size={12} /></span><span className="font-medium tx1">{trName(x.to)}</span></div> },
        { h: 'المبلغ', r: x => <span className="font-semibold">{fmtMoney(x.amount)}</span> },
        { h: 'الحالة', r: x => <StatusBadge map={TRANSFER_STATUS} value={x.status} /> },
        { h: 'المنشئ', r: x => <span className="text-[12px] tx2">{x.by}</span> },
        { h: 'المعتمد', r: x => x.appr ? <span className="text-[12px] tx2">{x.appr}</span> : <span className="tx3">—</span> },
        { h: '', r: x => x.status === 'pending' && <RowActions><button className="btn btn-danger btn-xs" onClick={() => toast('تم رفض التحويل')}>رفض</button><button className="btn btn-success btn-xs" onClick={() => toast('تم اعتماد التحويل', 'green')}>اعتماد</button></RowActions> },
      ]} />
    </Card>
    {view && <Modal open onClose={() => setView(null)} title="طلب تحويل" icon="swap" width={520}
      footer={view.status === 'pending' && <><button className="btn btn-danger" onClick={() => { setView(null); toast('تم الرفض'); }}>رفض</button><button className="btn btn-dark" onClick={() => { setView(null); toast('تم الاعتماد', 'green'); }}>اعتماد</button></>}>
      <div className="space-y-4 pb-2">
        <div className="grid grid-cols-[1fr_auto_1fr] items-center gap-3">
          <div className="v-sub p-3 text-center"><div className="text-[11px] tx3 mb-1">من</div><div className="font-semibold text-[12.5px]">{trName(view.from)}</div></div>
          <span className="w-9 h-9 rounded-full btn-accent flex items-center justify-center"><Icon name="arrowL" size={15} /></span>
          <div className="v-sub p-3 text-center"><div className="text-[11px] tx3 mb-1">إلى</div><div className="font-semibold text-[12.5px]">{trName(view.to)}</div></div>
        </div>
        <div className="text-center"><div className="font-display text-[28px] font-bold">{fmtMoney(view.amount)}</div><div className="mt-1"><StatusBadge map={TRANSFER_STATUS} value={view.status} /></div></div>
        <KV items={[['المنشئ', view.by], ['المعتمد', view.appr], ['التاريخ', <Num>{view.date}</Num>], ['ملاحظات', view.notes]]} />
      </div>
    </Modal>}
    <Modal open={form} onClose={() => setForm(false)} title="طلب تحويل بين خزائن" icon="swap" width={520} sub="يُنشأ بحالة «بانتظار الاعتماد» ولا يمكن تعديله بعد ذلك."
      footer={<><button className="btn btn-soft" onClick={() => setForm(false)}>إلغاء</button><button className="btn btn-dark" disabled={!src || !dst || src === dst} onClick={() => { setForm(false); toast('أُرسل طلب التحويل'); }}>إنشاء الطلب</button></>}>
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <Field label="الخزينة المصدر" req><select className="inp" value={src} onChange={e => setSrc(e.target.value)}><option value="">اختر</option>{TREASURIES.filter(x => x.active).map(x => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
          <Field label="الخزينة الوجهة" req err={src && src === dst ? 'يجب أن تختلف عن المصدر' : null}><select className={'inp ' + (src && src === dst ? 'err' : '')} value={dst} onChange={e => setDst(e.target.value)}><option value="">اختر</option>{TREASURIES.filter(x => x.active).map(x => <option key={x.id} value={x.id} disabled={String(x.id) === src}>{x.name}</option>)}</select></Field>
        </div>
        {src && <div className="text-[11.5px] tx2">الرصيد المتاح في المصدر: <b className="tx1">{fmtMoney(TREASURIES.find(x => String(x.id) === src).balance)}</b></div>}
        <Field label="المبلغ" req><input type="number" step="0.01" min="0.01" className="inp num" /></Field>
        <TextArea label="ملاحظات" />
      </div>
    </Modal>
  </>;
}

/* ---------------- SHIFTS ---------------- */
function DiffCell({ s }) {
  if (s.exp == null) return <span className="tx3">—</span>;
  const d = s.act - s.exp;
  if (d === 0) return <Badge tone="green" dot={false}>مطابق</Badge>;
  return <span className={'font-semibold ' + (d < 0 ? 'c-red' : 'c-amber')}>{d < 0 ? 'عجز ' : 'زيادة '}<span className="num ltr">{fmtNum(Math.abs(d))}</span></span>;
}
function ShiftsPage() {
  const toast = useToast();
  const [br, setBr] = useState(''); const [ca, setCa] = useState(''); const [st, setSt] = useState(''); const [from, setFrom] = useState(''); const [to, setTo] = useState('');
  const [view, setView] = useState(null);
  const rows = SHIFTS.filter(s => (!br || s.branch === br) && (!ca || s.cashier === ca) && (!st || s.status === st) && (!from || s.open.slice(0, 10) >= from) && (!to || s.open.slice(0, 10) <= to));
  return <div className="space-y-5">
    <PageHead title="مراجعة الورديات" sub="مطابقة النقدية المتوقعة مع الفعلية واعتماد الورديات المغلقة." />
    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 stg stg-2">
      <KPI hero label="بانتظار الاعتماد" icon="clock" value={SHIFTS.filter(s => s.status === 'closed').length} foot="ورديات مغلقة" />
      <KPI label="مفتوحة الآن" icon="cash" value={SHIFTS.filter(s => s.status === 'open').length} foot="لا يمكن اعتمادها قبل الإغلاق" />
      <KPI label="ورديات بها فروق" icon="alert" value={SHIFTS.filter(s => s.exp != null && s.act !== s.exp).length} foot="عجز أو زيادة" />
    </div>
    <Card stg={3}>
      <div className="flex flex-wrap gap-2 mb-4">
        <FSelect value={br} onChange={setBr} placeholder="الفرع" options={BRANCHES} /><FSelect value={ca} onChange={setCa} placeholder="الكاشير" options={CASHIERS} />
        <FSelect value={st} onChange={setSt} placeholder="الحالة" options={Object.entries(SHIFT_STATUS).map(([v, s]) => ({ value: v, label: s.label }))} />
        <FDate label="من" value={from} onChange={setFrom} /><FDate label="إلى" value={to} onChange={setTo} />
      </div>
      <DataTable rows={rows} onRow={setView} cols={[
        { h: 'رقم الوردية', r: s => <span className="num font-semibold tx1">{s.no}</span> },
        { h: 'الكاشير / الفرع', r: s => <div className="flex items-center gap-2"><Avatar name={s.cashier} tone={s.id} size={28} /><div className="leading-tight"><div className="font-medium tx1">{s.cashier}</div><div className="text-[11px] tx3">{s.branch}</div></div></div> },
        { h: 'الخزينة', r: s => <span className="text-[12px] tx2">{trName(s.tr)}</span> },
        { h: 'الفتح / الإغلاق', r: s => <div className="leading-tight num text-[11.5px]"><div className="tx1">{s.open}</div><div className="tx3">{s.close || '—'}</div></div> },
        { h: 'الحالة', r: s => <StatusBadge map={SHIFT_STATUS} value={s.status} /> },
        { h: 'المتوقعة', r: s => s.exp != null ? fmtMoney(s.exp) : <span className="tx3">—</span> },
        { h: 'الفعلية', r: s => s.act != null ? fmtMoney(s.act) : <span className="tx3">—</span> },
        { h: 'الفرق', r: s => <DiffCell s={s} /> },
      ]} />
    </Card>
    {view && <ShiftModal s={view} onClose={() => setView(null)} onApprove={() => { setView(null); toast('تم اعتماد الوردية', 'green'); }} />}
  </div>;
}
function ShiftModal({ s, onClose, onApprove }) {
  const opening = 2000, cashSales = s.exp ? s.exp + 380 + 530 - opening + 0 : 8420, cardSales = 11240, cashRet = 380, cashExp = 530;
  const exp = s.exp ?? opening + cashSales - cashRet - cashExp;
  const d = s.act != null ? s.act - exp : null;
  return <Modal open onClose={onClose} width={680} icon="clock" title={<span className="flex items-center gap-2"><span className="num">{s.no}</span><StatusBadge map={SHIFT_STATUS} value={s.status} /></span>}
    sub={<>{s.cashier} · {s.branch} · {trName(s.tr)}</>}
    footer={s.status === 'closed' ? <><button className="btn btn-soft" onClick={onClose}>إغلاق</button><button className="btn btn-dark" onClick={onApprove}><Icon name="check" size={14} />اعتماد الوردية</button></> : null}>
    <div className="space-y-4 pb-2">
      {s.status === 'open' && <Note tone="blue" icon="info">الوردية ما زالت مفتوحة — الأرقام حتى اللحظة، والاعتماد متاح بعد إغلاقها من نقطة البيع.</Note>}
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div className="v-sub p-4 space-y-2.5 text-[12.5px]">
          {[['عهدة بداية الوردية', opening, ''], ['+ المبيعات النقدية', cashSales, 'c-green'], ['− المرتجعات النقدية', -cashRet, 'c-red'], ['− المصروفات النقدية', -cashExp, 'c-red']].map(([l, v, c]) =>
            <div key={l} className="flex items-center justify-between"><span className="tx2">{l}</span><span className={'font-semibold ' + c}>{fmtMoney(v)}</span></div>)}
          <div className="flex items-center justify-between v-bt pt-2.5"><span className="font-semibold tx1">النقدية المتوقعة</span><span className="font-display font-bold">{fmtMoney(exp)}</span></div>
          <div className="flex items-center justify-between text-[11.5px] tx3"><span>مبيعات البطاقات (خارج الدرج)</span><span>{fmtMoney(cardSales)}</span></div>
        </div>
        <div className={'rounded-[18px] p-4 flex flex-col justify-between ' + (d == null ? 'v-sub' : d === 0 ? 't-green' : d < 0 ? 't-red' : 't-amber')}>
          <div><div className="text-[12px] font-medium opacity-90">النقدية الفعلية</div><div className="font-display text-[26px] font-bold num ltr mt-1">{s.act != null ? fmtNum(s.act) : '—'}</div></div>
          <div className="mt-4"><div className="text-[12px] font-medium opacity-90">الفرق</div>
            <div className="font-display text-[20px] font-bold mt-0.5">{d == null ? '—' : d === 0 ? 'مطابق' : <>{d < 0 ? 'عجز' : 'زيادة'} <span className="num ltr">{fmtNum(Math.abs(d))}</span></>}</div></div>
        </div>
      </div>
      <div><div className="lbl mb-2">مصروفات الوردية</div>
        <DataTable compact rows={EXPENSES.filter(e => e.shift).slice(0, 2)} cols={[{ h: 'رقم', r: e => <Num>{e.no}</Num> }, { h: 'التصنيف', r: e => e.cat }, { h: 'المبلغ', r: e => fmtMoney(e.amount) }]} /></div>
      <div className="grid grid-cols-2 gap-3">
        <div className="v-sub p-3"><div className="lbl mb-1">ملاحظات</div><div className="text-[12.5px] tx1">{s.status === 'open' ? '—' : 'فرق بسبب فكة غير متوفرة لعميلين.'}</div></div>
        <div className="v-sub p-3"><div className="lbl mb-1">الاعتماد</div><div className="text-[12.5px] tx1">{s.status === 'approved' ? <>أحمد ناصر · <Num>2026-10-26 09:12</Num></> : <span className="tx3">لم تُعتمد بعد</span>}</div></div>
      </div>
    </div>
  </Modal>;
}

/* ---------------- WHOLESALE ---------------- */
const QUOTES = [
  { id: 1, no: 'QT-0611', cust: 'محمد الصاوي', company: 'شركة النور للتجارة', status: 'requested', total: null, valid: null, date: '2026-10-27' },
  { id: 2, no: 'QT-0610', cust: 'أشرف حمدي', company: 'سوبر ماركت الأمل', status: 'priced', total: 86420, valid: '2026-11-05', date: '2026-10-25' },
  { id: 3, no: 'QT-0609', cust: 'عادل رمضان', company: 'هايبر الفتح', status: 'accepted', total: 142800, valid: '2026-10-31', date: '2026-10-22' },
  { id: 4, no: 'QT-0608', cust: 'ياسر فهمي', company: 'مؤسسة الرحمة للتوزيع', status: 'converted', total: 211500, valid: '2026-10-28', date: '2026-10-18', order: 'ORD-10401' },
  { id: 5, no: 'QT-0607', cust: 'سامح نبيل', company: 'ماركت الصفا', status: 'expired', total: 18900, valid: '2026-10-15', date: '2026-10-08' },
  { id: 6, no: 'QT-0606', cust: 'أشرف حمدي', company: 'سوبر ماركت الأمل', status: 'rejected', total: 44200, valid: '2026-10-20', date: '2026-10-06' },
];
const NOTICES = [
  { id: 1, no: 'PN-0284', cust: 'محمد الصاوي', company: 'شركة النور للتجارة', order: 'ORD-10475', amount: 24800, method: 'bank', bank: 'البنك الأهلي المصري', ref: 'FT26300118842', date: '2026-10-27', file: true, status: 'pending' },
  { id: 2, no: 'PN-0283', cust: 'ياسر فهمي', company: 'مؤسسة الرحمة للتوزيع', order: null, amount: 100000, method: 'cheque', bank: 'بنك مصر', ref: 'CHQ-883104', date: '2026-10-26', file: true, status: 'pending' },
  { id: 3, no: 'PN-0282', cust: 'عادل رمضان', company: 'هايبر الفتح', order: 'ORD-10452', amount: 71400, method: 'deposit', bank: 'CIB', ref: 'DEP-55021', date: '2026-10-24', file: false, status: 'approved', tr: 5, review: 'تمت المطابقة مع كشف الحساب' },
  { id: 4, no: 'PN-0281', cust: 'سامح نبيل', company: 'ماركت الصفا', order: null, amount: 9000, method: 'bank', bank: 'QNB', ref: 'QNB-1192', date: '2026-10-21', file: true, status: 'rejected', review: 'المبلغ غير ظاهر في كشف الحساب' },
];
const NOTICE_METHODS = { bank: 'تحويل بنكي', cheque: 'شيك', deposit: 'إيداع' };

function WholesalePage({ sub = 'quotes' }) {
  const [tab, setTab] = useState(sub);
  useEffect(() => setTab(sub), [sub]);
  return <div className="space-y-5">
    <PageHead title="الجملة" sub="تسعير عروض أسعار التجار ومراجعة إشعارات السداد الواردة من بوابة الجملة." />
    <TabsBar value={tab} onChange={setTab} tabs={[{ value: 'quotes', label: 'عروض الأسعار', icon: 'quote', count: QUOTES.filter(q => q.status === 'requested').length }, { value: 'notices', label: 'إشعارات السداد', icon: 'bank', count: NOTICES.filter(n => n.status === 'pending').length }]} />
    {tab === 'quotes' ? <QuotesTab /> : <NoticesTab />}
  </div>;
}

function QuotesTab() {
  const toast = useToast();
  const [st, setSt] = useState(''); const [cu, setCu] = useState('');
  const [view, setView] = useState(null); const [price, setPrice] = useState(false); const [expire, setExpire] = useState(false);
  const rows = QUOTES.filter(q => (!st || q.status === st) && (!cu || q.company === cu));
  const lines = VARIANTS.slice(0, 5).map((v, i) => ({ v, qty: [200, 150, 500, 120, 300][i], price: view?.total ? (v.price * 0.82) : null, note: i === 0 ? 'نحتاج تاريخ صلاحية بعيد' : null }));
  const sub = lines.reduce((a, l) => a + (l.price || 0) * l.qty, 0);
  return <>
    <Card stg={3}>
      <div className="flex flex-wrap gap-2 mb-4"><FSelect value={st} onChange={setSt} placeholder="الحالة" options={Object.entries(QUOTE_STATUS).map(([v, s]) => ({ value: v, label: s.label }))} /><FSelect value={cu} onChange={setCu} placeholder="العميل" options={WHOLESALE} /></div>
      <DataTable rows={rows} onRow={setView} cols={[
        { h: 'رقم العرض', r: q => <span className="num font-semibold tx1">{q.no}</span> },
        { h: 'العميل / الشركة', r: q => <div className="flex items-center gap-2"><Avatar name={q.company} tone={q.id} size={28} /><div className="leading-tight"><div className="font-medium tx1">{q.company}</div><div className="text-[11px] tx3">{q.cust}</div></div></div> },
        { h: 'الحالة', r: q => <StatusBadge map={QUOTE_STATUS} value={q.status} /> },
        { h: 'الإجمالي', r: q => q.total ? <span className="font-semibold">{fmtMoney(q.total)}</span> : <span className="tx3 text-[11.5px]">لم يُسعّر</span> },
        { h: 'الصلاحية', r: q => q.valid ? <Num className="tx2">{q.valid}</Num> : <span className="tx3">—</span> },
        { h: 'التاريخ', r: q => <Num className="tx2">{q.date}</Num> },
        { h: '', r: q => q.status === 'requested' && <RowActions><button className="btn btn-accent btn-xs" onClick={() => { setView(q); setPrice(true); }}>تسعير</button></RowActions> },
      ]} />
    </Card>
    {view && !price && <Modal open onClose={() => setView(null)} width={760} icon="quote" title={<span className="flex items-center gap-2"><span className="num">{view.no}</span><StatusBadge map={QUOTE_STATUS} value={view.status} /></span>} sub={<>{view.company} · {view.cust}</>}
      footer={<>
        {['priced', 'requested'].includes(view.status) && <button className="btn btn-ghost me-auto" onClick={() => setExpire(true)}><Icon name="clock" size={13} />إنهاء الصلاحية</button>}
        {['requested', 'priced'].includes(view.status) && <button className="btn btn-dark" onClick={() => setPrice(true)}>{view.status === 'priced' ? 'إعادة التسعير' : 'تسعير العرض'}</button>}
      </>}>
      <div className="space-y-4 pb-2">
        {view.status === 'priced' && <Note tone="blue" icon="info">بانتظار رد التاجر — القبول أو الرفض يتم من بوابة الجملة.</Note>}
        {view.order && <div className="v-sub p-3 flex items-center justify-between text-[12.5px]"><span className="flex items-center gap-2"><Icon name="link" size={14} className="accent" />تحول إلى طلب</span><span className="num font-semibold accent underline underline-offset-4">{view.order}</span></div>}
        <div className="v-sub p-3.5"><div className="lbl mb-1">ملاحظات العميل</div><div className="text-[12.5px] tx1">برجاء التوريد على دفعتين، والتسليم في المخزن الرئيسي بالعبور.</div></div>
        <DataTable compact rows={lines} cols={[
          { h: 'البند', r: l => <div className="leading-tight"><div className="font-medium tx1">{l.v.name}</div><div className="text-[11px] tx3 num">{l.v.sku}</div>{l.note && <div className="text-[11px] tx2 mt-0.5">«{l.note}»</div>}</div> },
          { h: 'الكمية', align: 'center', r: l => <Num className="font-semibold">{l.qty}</Num> },
          { h: 'السعر المعروض', r: l => l.price ? fmtMoney(l.price) : <span className="tx3">—</span> },
          { h: 'الإجمالي', r: l => l.price ? <span className="font-semibold">{fmtMoney(l.price * l.qty)}</span> : <span className="tx3">—</span> },
        ]} />
        {view.total && <div className="grid grid-cols-2 gap-4">
          <div className="v-sub p-3.5"><div className="lbl mb-1">ملاحظات الإدارة</div><div className="text-[12.5px] tx1">الأسعار تشمل التوصيل داخل القاهرة الكبرى.</div><div className="text-[11px] tx3 mt-2">صالح حتى <Num>{view.valid}</Num></div></div>
          <div className="px-1"><KV items={[['المجموع الفرعي', fmtMoney(sub)], ['الخصم', <span className="c-red">{fmtMoney(-1500)}</span>], ['الضريبة', fmtMoney(0)], ['الإجمالي', <b>{fmtMoney(sub - 1500)}</b>]]} /></div>
        </div>}
      </div>
    </Modal>}
    {view && price && <Modal open onClose={() => { setPrice(false); setView(null); }} width={720} icon="tag" title={'تسعير ' + view.no} sub={view.company}
      footer={<><button className="btn btn-soft" onClick={() => setPrice(false)}>رجوع</button><button className="btn btn-accent" onClick={() => { setPrice(false); setView(null); toast('أُرسل العرض المسعّر للتاجر'); }}>حفظ وإرسال للتاجر</button></>}>
      <div className="space-y-4">
        <div className="v-sub p-2">
          <div className="grid grid-cols-12 gap-2 px-2 py-1 text-[11px] tx3"><span className="col-span-6">البند</span><span className="col-span-2 text-center">الكمية</span><span className="col-span-4">سعر الوحدة المعروض</span></div>
          {lines.map((l, i) => <div key={i} className="grid grid-cols-12 gap-2 items-center px-2 py-1.5">
            <div className="col-span-6 leading-tight"><div className="text-[12.5px] font-medium clamp1">{l.v.name}</div><div className="text-[10.5px] tx3">قطاعي {fmtNum(l.v.price)}</div></div>
            <Num className="col-span-2 text-center font-semibold">{l.qty}</Num>
            <input type="number" step="0.01" className="inp inp-sm col-span-4 num !bg-[var(--bg-card)]" defaultValue={(l.v.price * 0.82).toFixed(2)} /></div>)}
        </div>
        <div className="grid grid-cols-3 gap-3">
          <Field label="خصم إجمالي"><input type="number" className="inp num" defaultValue="0" /></Field>
          <Field label="ضريبة إجمالية"><input type="number" className="inp num" defaultValue="0" /></Field>
          <Field label="صالح حتى" req hint="لا يسبق اليوم"><input type="date" className="inp" min="2026-10-27" defaultValue="2026-11-10" /></Field>
        </div>
        <TextArea label="ملاحظات الإدارة" max={2000} />
      </div>
    </Modal>}
    <ConfirmModal open={expire} onClose={() => setExpire(false)} danger title="إنهاء صلاحية العرض" cta="إنهاء الصلاحية" text="سيتحول العرض إلى «منتهي» ولن يتمكن التاجر من قبوله." onConfirm={() => { setView(null); toast('انتهت صلاحية العرض'); }} />
  </>;
}

function NoticesTab() {
  const toast = useToast();
  const [st, setSt] = useState('pending'); const [cu, setCu] = useState('');
  const [sel, setSel] = useState(NOTICES[0]); const [mode, setMode] = useState(null);
  const rows = NOTICES.filter(n => (!st || n.status === st) && (!cu || n.company === cu));
  return <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
    <Card stg={3} className="lg:col-span-5" pad="p-4">
      <div className="flex flex-wrap gap-2 mb-3"><FSelect value={st} onChange={setSt} placeholder="كل الحالات" options={Object.entries(NOTICE_STATUS).map(([v, s]) => ({ value: v, label: s.label }))} /><FSelect value={cu} onChange={setCu} placeholder="العميل" options={WHOLESALE} /></div>
      <div className="space-y-2">{rows.length === 0 && <Empty icon="bank" title="لا توجد إشعارات" />}
        {rows.map(n => <button key={n.id} onClick={() => setSel(n)} className="w-full text-start v-sub p-3.5 transition flex items-start gap-3" style={sel?.id === n.id ? { outline: '2px solid var(--accent)', background: 'var(--bg-card)' } : {}}>
          <Avatar name={n.company} tone={n.id} size={34} />
          <div className="flex-1 min-w-0"><div className="flex items-center justify-between gap-2"><span className="font-semibold text-[12.5px] tx1 clamp1">{n.company}</span><StatusBadge map={NOTICE_STATUS} value={n.status} /></div>
            <div className="flex items-center justify-between mt-1.5"><span className="text-[11px] tx3"><span className="num">{n.no}</span> · {NOTICE_METHODS[n.method]}</span><span className="font-semibold text-[12.5px]">{fmtMoney(n.amount, 0)}</span></div></div>
        </button>)}</div>
    </Card>
    {sel && <Card stg={4} className="lg:col-span-7">
      <div className="flex items-start justify-between gap-3 mb-4">
        <div><div className="flex items-center gap-2"><span className="font-display text-[18px] font-semibold num">{sel.no}</span><StatusBadge map={NOTICE_STATUS} value={sel.status} /></div><div className="text-[12px] tx2 mt-1">{sel.company} · {sel.cust}</div></div>
        {sel.status === 'pending' && <div className="flex gap-2"><button className="btn btn-danger btn-sm" onClick={() => setMode('reject')}>رفض</button><button className="btn btn-dark btn-sm" onClick={() => setMode('approve')}><Icon name="check" size={13} />موافقة</button></div>}
      </div>
      <div className="v-orange rounded-[18px] p-4 flex items-center justify-between mb-4">
        <div><div className="text-[12px] text-white/85">المبلغ</div><div className="font-display text-[26px] font-bold num ltr">{fmtNum(sel.amount)} <span className="text-[13px] opacity-80">EGP</span></div></div>
        <span className="px-3 py-1 rounded-full bg-white/20 text-[12px] font-semibold">{NOTICE_METHODS[sel.method]}</span>
      </div>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-8">
        <KV items={[['البنك', sel.bank], ['المرجع', <Num>{sel.ref}</Num>], ['تاريخ التحويل', <Num>{sel.date}</Num>]]} />
        <KV items={[['الطلب المرتبط', sel.order ? <Num className="accent">{sel.order}</Num> : null], ['الخزينة', sel.tr ? trName(sel.tr) : null], ['ملاحظات المراجعة', sel.review]]} />
      </div>
      <div className="mt-4">{sel.file ? <div className="v-sub p-3 flex items-center gap-3">
        <div className="w-16 h-20 rounded-[10px] bg-[var(--bg-card)] flex items-center justify-center tx3 border" style={{ borderColor: 'var(--border-subtle)' }}><Icon name="image" size={20} /></div>
        <div className="flex-1"><div className="text-[12.5px] font-medium tx1">إيصال التحويل</div><div className="text-[11px] tx3 num">receipt_{sel.no}.jpg · 640 KB</div></div>
        <button className="btn btn-soft btn-xs"><Icon name="eye" size={12} />معاينة</button><button className="btn btn-soft btn-xs"><Icon name="download" size={12} />تنزيل</button>
      </div> : <div className="v-sub p-3 text-[12px] tx3 text-center">لم يُرفق إيصال</div>}</div>
    </Card>}
    <Modal open={mode === 'approve'} onClose={() => setMode(null)} title="الموافقة على إشعار السداد" icon="check" tone="green" width={480}
      footer={<><button className="btn btn-soft" onClick={() => setMode(null)}>إلغاء</button><button className="btn btn-dark" onClick={() => { setMode(null); toast('تمت الموافقة', 'green'); }}>موافقة</button></>}>
      <div className="space-y-4 pb-2">
        <Field label="الخزينة" hint="اختياري — الخزينة التي استقبلت المبلغ"><select className="inp"><option value="">بدون</option>{TREASURIES.filter(t => t.type === 'bank').map(t => <option key={t.id}>{t.name}</option>)}</select></Field>
        <TextArea label="ملاحظات الإدارة" />
      </div>
    </Modal>
    <Modal open={mode === 'reject'} onClose={() => setMode(null)} title="رفض إشعار السداد" icon="ban" tone="red" width={460}
      footer={<><button className="btn btn-soft" onClick={() => setMode(null)}>إلغاء</button><button className="btn btn-danger" onClick={() => { setMode(null); toast('تم الرفض'); }}>رفض</button></>}>
      <div className="pb-2"><TextArea label="ملاحظات الإدارة" req placeholder="وضّح سبب الرفض للتاجر" /></div>
    </Modal>
  </div>;
}

Object.assign(window, { FinancePage, ShiftsPage, WholesalePage, SHIFTS });
