/* ============ 11. Reports · 13. Notifications ============ */

function BarsChart({ data, h = 210, keyA, keyB, labelA, labelB, fmt = v => v }) {
  const max = Math.max(...data.map(d => (d[keyA] || 0) + (keyB ? d[keyB] || 0 : 0))) * 1.1 || 1;
  const ticks = [1, .8, .6, .4, .2, 0].map(t => max * t);
  return <div>
    {labelA && <div className="flex items-center gap-4 text-[11px] mb-3 justify-end">
      <span className="flex items-center gap-1.5 tx2 font-medium"><span className="w-2.5 h-2.5 rounded-sm hatch-orange inline-block"></span>{labelA}</span>
      {labelB && <span className="flex items-center gap-1.5 tx2 font-medium"><span className="w-2.5 h-2.5 rounded-sm inline-block" style={{ background: 'var(--bar-loss)' }}></span>{labelB}</span>}
    </div>}
    <div className="flex gap-2.5" style={{ height: h }}>
      <div className="flex flex-col justify-between text-[10px] tx3 pb-5 shrink-0 num text-end w-9">{ticks.map((t, i) => <span key={i}>{t >= 1000 ? (t / 1000).toFixed(0) + 'k' : t.toFixed(0)}</span>)}</div>
      <div className="relative flex-1 pb-5">
        <div className="absolute inset-x-0 top-0 bottom-5 flex flex-col justify-between pointer-events-none">{ticks.map((_, i) => <div key={i} className="v-bb w-full" style={{ borderBottomStyle: i === ticks.length - 1 ? 'solid' : 'dashed' }}></div>)}</div>
        <div className="relative z-10 h-full flex items-end justify-between gap-1.5 px-1" dir="ltr">
          {data.map((d, i) => <div key={i} className="group relative flex-1 h-full flex flex-col items-center justify-end">
            <div className="opacity-0 group-hover:opacity-100 pointer-events-none transition absolute -top-2 -translate-y-full z-20 px-2 py-1 rounded-md text-[10px] font-semibold whitespace-nowrap shadow-md num" style={{ background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' }}>{fmt(d[keyA])}{keyB && ' · ' + fmt(d[keyB])}</div>
            <div className="w-full max-w-[28px] flex flex-col justify-end gap-[3px]" style={{ height: 'calc(100% - 0px)' }}>
              <div className="w-full rounded-[6px] hatch-orange transition-all duration-500" style={{ height: (d[keyA] / max * 100) + '%' }}></div>
              {keyB && <div className="w-full rounded-[6px] transition-all duration-500" style={{ height: (d[keyB] / max * 100) + '%', background: 'var(--bar-loss)' }}></div>}
            </div>
            <span className="absolute -bottom-0 text-[10px] tx3 font-medium num translate-y-0" style={{ bottom: -2 }}>{d.l}</span>
          </div>)}
        </div>
      </div>
    </div>
  </div>;
}

function ReportsPage() {
  const [tab, setTab] = useState('sales');
  return <div className="space-y-5">
    <PageHead title="التقارير" sub="أقصى فترة لأي تقرير 366 يومًا." />
    <TabsBar value={tab} onChange={setTab} tabs={[{ value: 'sales', label: 'المبيعات', icon: 'chart' }, { value: 'inv', label: 'المخزون', icon: 'box' }, { value: 'profit', label: 'الأرباح', icon: 'percent' }, { value: 'sup', label: 'الموردون', icon: 'factory' }, { value: 'shifts', label: 'الورديات', icon: 'clock' }]} />
    {tab === 'sales' && <SalesReport />}
    {tab === 'inv' && <InventoryReport />}
    {tab === 'profit' && <ProfitReport />}
    {tab === 'sup' && <SupplierReport />}
    {tab === 'shifts' && <ShiftReport />}
  </div>;
}
function DateRange({ from, to, setFrom, setTo }) {
  const days = from && to ? Math.round((new Date(to) - new Date(from)) / 864e5) : 0;
  return <><FDate label="من" value={from} onChange={setFrom} /><FDate label="إلى" value={to} onChange={setTo} />
    {days > 366 && <span className="badge t-red"><Icon name="alert" size={11} />الفترة تتجاوز 366 يومًا</span>}</>;
}

function SalesReport() {
  const [from, setFrom] = useState('2026-05-01'); const [to, setTo] = useState('2026-10-27');
  const [grp, setGrp] = useState('month'); const [by, setBy] = useState('');
  const months = ['May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct'];
  const data = months.map((l, i) => { const g = [1.82, 2.04, 1.91, 2.36, 2.58, 2.71][i] * 1e6; return { l, gross: g, disc: g * .045, tax: g * .02, ship: g * .018, orders: [4120, 4480, 4310, 5020, 5390, 5610][i], net: g * .955 }; });
  const days = Array.from({ length: 14 }).map((_, i) => ({ l: String(14 + i), gross: 70000 + Math.sin(i) * 18000 + i * 2200, net: 0 }));
  const tot = k => data.reduce((a, d) => a + d[k], 0);
  return <>
    <Card stg={3} pad="p-4"><div className="flex flex-wrap items-center gap-2">
      <DateRange {...{ from, to, setFrom, setTo }} />
      <Seg value={grp} onChange={setGrp} options={[{ value: 'day', label: 'يومي' }, { value: 'month', label: 'شهري' }]} />
      <FSelect value={by} onChange={setBy} placeholder="بدون تقسيم" options={[{ value: 'branch', label: 'حسب الفرع' }, { value: 'channel', label: 'حسب القناة' }, { value: 'cashier', label: 'حسب الكاشير' }, { value: 'pay', label: 'حسب طريقة الدفع' }]} />
    </div></Card>
    <div className="grid grid-cols-2 lg:grid-cols-6 gap-3 stg stg-3">
      <div className="col-span-2"><KPI hero label="صافي المبيعات" icon="chart" value={<span className="ltr">{fmtNum(tot('net'), 0)}</span>} foot="EGP" /></div>
      <KPI label="عدد الطلبات" value={fmtInt(tot('orders'))} small />
      <KPI label="إجمالي المبيعات" value={<span className="ltr">{(tot('gross') / 1e6).toFixed(2)}M</span>} small />
      <KPI label="الخصومات" value={<span className="ltr">{(tot('disc') / 1e3).toFixed(0)}k</span>} small />
      <KPI label="الضرائب + الشحن" value={<span className="ltr">{((tot('tax') + tot('ship')) / 1e3).toFixed(0)}k</span>} small />
    </div>
    <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
      <Card stg={4} className="lg:col-span-7"><CardHead title={grp === 'month' ? 'المبيعات الشهرية' : 'المبيعات اليومية'} sub="إجمالي المبيعات مقابل صافي المبيعات" />
        <div className="v-sub p-4">{grp === 'month' ? <BarsChart data={data.map(d => ({ ...d, a: d.net, b: d.disc + d.tax }))} keyA="net" labelA="صافي" fmt={v => fmtNum(v, 0)} /> : <BarsChart data={days} keyA="gross" labelA="إجمالي" fmt={v => fmtNum(v, 0)} />}</div>
      </Card>
      <Card stg={4} className="lg:col-span-5"><CardHead title={by ? { branch: 'حسب الفرع', channel: 'حسب القناة', cashier: 'حسب الكاشير', pay: 'حسب طريقة الدفع' }[by] : 'التقسيم'} sub={by ? null : 'اختر تقسيمًا من الفلاتر'} />
        {by ? <div className="space-y-3">{(by === 'branch' ? BRANCHES : by === 'channel' ? CHANNELS : by === 'cashier' ? CASHIERS : Object.values(PAY_METHODS)).map((n, i, arr) => { const pct = [34, 24, 18, 14, 10][i] || 6; return <div key={n}>
          <div className="flex items-center justify-between text-[12px] mb-1.5"><span className="tx1 font-medium">{n}</span><span className="tx2"><Num>{pct}%</Num> · {fmtMoney(tot('net') * pct / 100, 0)}</span></div><HatchBar pct={pct * 2.5} h={10} /></div>; })}</div>
          : <Empty icon="filter" title="بدون تقسيم" text="قسّم حسب الفرع أو القناة أو الكاشير أو طريقة الدفع." />}
      </Card>
    </div>
    <Card stg={5}><CardHead title="جدول حسب الفترة" right={<span className="text-[11px] tx3 flex items-center gap-1"><Icon name="info" size={12} />تصدير التقارير غير متاح حاليًا</span>} />
      <DataTable rows={data} cols={[{ h: 'الفترة', r: d => <Num className="font-semibold">{d.l} 2026</Num> }, { h: 'الطلبات', r: d => <Num>{fmtInt(d.orders)}</Num> }, { h: 'إجمالي المبيعات', r: d => fmtMoney(d.gross, 0) }, { h: 'الخصومات', r: d => <span className="c-red">{fmtMoney(-d.disc, 0)}</span> }, { h: 'الضرائب', r: d => fmtMoney(d.tax, 0) }, { h: 'الشحن', r: d => fmtMoney(d.ship, 0) }, { h: 'صافي المبيعات', r: d => <b>{fmtMoney(d.net, 0)}</b> }]} />
    </Card>
  </>;
}

function InventoryReport() {
  const [days, setDays] = useState(90);
  const br = BRANCHES.map((b, i) => ({ b, variants: [412, 388, 301, 264, 1240][i], qty: [8420, 7310, 5980, 4410, 48200][i], value: [1.24, 1.08, .86, .62, 7.9][i] * 1e6 }));
  const totV = br.reduce((a, x) => a + x.value, 0);
  return <>
    <Card stg={3} pad="p-4"><div className="flex flex-wrap items-center gap-3"><span className="text-[12px] tx2">اعتبار الصنف راكدًا بعد</span>
      <div className="flex items-center gap-2"><input type="number" min="1" className="pill-inp w-20 num text-center" value={days} onChange={e => setDays(e.target.value)} /><span className="text-[12px] tx2">يومًا بدون حركة</span></div>
      {days != 90 && <button className="btn btn-ghost btn-xs" onClick={() => setDays(90)}>الافتراضي 90</button>}</div></Card>
    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 stg stg-3">
      <KPI hero label="القيمة الإجمالية" icon="box" value={<span className="ltr">{(totV / 1e6).toFixed(2)}M</span>} foot="EGP بسعر التكلفة" />
      <KPI label="عدد المتغيرات" value={fmtInt(br.reduce((a, x) => a + x.variants, 0))} />
      <KPI label="إجمالي الكميات" value={fmtInt(br.reduce((a, x) => a + x.qty, 0))} />
      <KPI label="أصناف راكدة" value={days >= 90 ? 37 : 64} foot={<>بدون حركة منذ <Num>{days}</Num> يومًا</>} />
    </div>
    <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
      <Card stg={4} className="lg:col-span-6"><CardHead title="قيمة المخزون حسب الفرع" />
        <div className="space-y-3.5">{br.map(x => <div key={x.b}><div className="flex items-center justify-between text-[12px] mb-1.5"><span className="font-medium tx1">{x.b}</span><span>{fmtMoney(x.value, 0)}</span></div><HatchBar pct={x.value / totV * 100} h={12} />
          <div className="flex gap-4 text-[11px] tx3 mt-1"><span><Num>{x.variants}</Num> متغير</span><span><Num>{fmtInt(x.qty)}</Num> وحدة</span></div></div>)}</div></Card>
      <Card stg={4} className="lg:col-span-6"><CardHead title="الأصناف الراكدة" sub={<>لا حركة منذ <Num>{days}</Num> يومًا أو أكثر</>} />
        <DataTable compact rows={VARIANTS.slice(4, 9).map((v, i) => ({ v, qty: [140, 62, 18, 240, 35][i], last: [212, 168, 131, 104, 96][i], br: BRANCHES[i] }))} cols={[
          { h: 'الصنف', r: r => <div className="leading-tight"><div className="font-medium tx1">{r.v.name}</div><div className="text-[11px] tx3 num">{r.v.sku}</div></div> },
          { h: 'الفرع', r: r => <span className="text-[11.5px] tx2">{r.br}</span> }, { h: 'الكمية', r: r => <Num>{r.qty}</Num> },
          { h: 'آخر حركة', r: r => <span className="c-amber font-semibold"><Num>{r.last}</Num> يوم</span> }]} /></Card>
    </div>
  </>;
}

function ProfitReport() {
  const [from, setFrom] = useState('2026-10-01'); const [to, setTo] = useState('2026-10-27');
  const rev = 2589000, cogs = 1942000, gross = rev - cogs, exp = 318400, net = gross - exp;
  const steps = [['صافي إيراد المبيعات', rev, 'a'], ['تكلفة البضاعة', -cogs, 'b'], ['مجمل الربح', gross, 'a'], ['المصروفات', -exp, 'b'], ['صافي الربح', net, 'hero']];
  return <>
    <Card stg={3} pad="p-4"><div className="flex flex-wrap items-center gap-2"><DateRange {...{ from, to, setFrom, setTo }} /></div></Card>
    <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
      <Card stg={4} className="lg:col-span-7"><CardHead title="من الإيراد إلى صافي الربح" />
        <div className="space-y-3">{steps.map(([l, v, k]) => <div key={l} className={'rounded-[16px] p-4 ' + (k === 'hero' ? 'v-orange' : 'v-sub')}>
          <div className="flex items-center justify-between mb-2"><span className={'text-[13px] font-medium ' + (k === 'hero' ? 'text-white' : 'tx1')}>{l}</span><span className={'font-display font-bold text-[16px] num ltr ' + (k === 'hero' ? 'text-white' : v < 0 ? 'c-red' : 'tx1')}>{fmtNum(v, 0)}</span></div>
          <div className="h-2.5 rounded-full overflow-hidden" style={{ background: k === 'hero' ? 'rgba(255,255,255,.2)' : 'transparent' }}>
            <div className={'h-full rounded-full ' + (k === 'hero' ? 'bg-white' : k === 'a' ? 'hatch-orange' : '')} style={{ width: Math.abs(v) / rev * 100 + '%', background: k === 'b' ? 'var(--bar-loss)' : undefined }}></div></div>
        </div>)}</div></Card>
      <Card stg={4} className="lg:col-span-5"><CardHead title="الهوامش" />
        <div className="grid grid-cols-2 gap-3">
          <KPI hero label="هامش مجمل الربح" value={<Num>{(gross / rev * 100).toFixed(1)}%</Num>} />
          <KPI label="هامش صافي الربح" value={<Num>{(net / rev * 100).toFixed(1)}%</Num>} />
          <KPI label="تكلفة البضاعة" value={<Num>{(cogs / rev * 100).toFixed(1)}%</Num>} foot="من الإيراد" />
          <KPI label="المصروفات" value={<Num>{(exp / rev * 100).toFixed(1)}%</Num>} foot="من الإيراد" />
        </div></Card>
    </div>
  </>;
}

function SupplierReport() {
  const aging = [['غير مستحق', 168400], ['1–30', 92600], ['31–60', 61340], ['61–90', 38900], ['+90', 14250]];
  const tot = aging.reduce((a, x) => a + x[1], 0);
  return <>
    <Note icon="lock" className="stg stg-3">يعرض البيانات الحالية بدون فلتر فترة، ويتطلب صلاحية وصول مركزي.</Note>
    <Card stg={3}><CardHead title="أعمار مديونية الموردين" sub="إجمالي المستحق موزّعًا على فترات التأخير" right={<span className="font-display font-bold text-[18px]">{fmtMoney(tot, 0)}</span>} />
      <div className="flex h-10 rounded-full overflow-hidden gap-1 p-1 v-sub">{aging.map(([l, v], i) => <div key={l} className="h-full rounded-full flex items-center justify-center text-[10.5px] font-semibold" style={{ width: v / tot * 100 + '%', background: ['#22c55e33', 'var(--accent-light)', '#e0491f99', '#e0491f', 'var(--bar-loss)'][i], color: i >= 2 ? '#fff' : 'var(--text-primary)' }}>{v / tot > .08 && <Num>{(v / tot * 100).toFixed(0)}%</Num>}</div>)}</div>
      <div className="grid grid-cols-5 gap-2 mt-4">{aging.map(([l, v], i) => <div key={l} className="v-sub p-3"><div className="text-[11px] tx2">{i === 0 ? l : <>متأخر <Num>{l}</Num> يوم</>}</div><div className="font-semibold text-[13px] mt-1">{fmtMoney(v, 0)}</div></div>)}</div>
    </Card>
    <Card stg={4}><DataTable rows={SUPPLIERS.filter(s => s.active)} cols={[
      { h: 'المورد', r: s => <div className="flex items-center gap-2"><Avatar name={s.company || s.name} tone={s.id} size={26} /><span className="font-medium tx1">{s.company || s.name}</span></div> },
      { h: 'الرصيد', r: s => <b className={s.balance < 0 ? 'c-green' : ''}>{fmtMoney(s.balance, 0)}</b> },
      { h: 'إجمالي المشتريات', r: s => fmtMoney(s.balance * 3.4 + 120000, 0) },
      { h: 'غير مستحق', r: s => fmtMoney(Math.max(0, s.balance * .5), 0) },
      { h: '1–30', r: s => fmtMoney(Math.max(0, s.balance * .3), 0) },
      { h: '31–60', r: s => fmtMoney(Math.max(0, s.balance * .12), 0) },
      { h: '61–90', r: s => fmtMoney(Math.max(0, s.balance * .05), 0) },
      { h: '+90', r: s => <span className={s.balance * .03 > 1000 ? 'c-red font-semibold' : ''}>{fmtMoney(Math.max(0, s.balance * .03), 0)}</span> },
    ]} /></Card>
  </>;
}

function ShiftReport() {
  const [from, setFrom] = useState('2026-10-20'); const [to, setTo] = useState('2026-10-27'); const [diffOnly, setDiffOnly] = useState(false);
  const rows = SHIFTS.filter(s => s.exp != null && (!diffOnly || s.act !== s.exp));
  return <>
    <Card stg={3} pad="p-4"><div className="flex flex-wrap items-center gap-2"><DateRange {...{ from, to, setFrom, setTo }} /><FChip on={diffOnly} onClick={() => setDiffOnly(!diffOnly)} icon="alert">الورديات ذات الفروق فقط</FChip></div></Card>
    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 stg stg-3">
      <KPI hero label="المبيعات النقدية" value={<span className="ltr">82.4k</span>} foot="EGP" />
      <KPI label="مبيعات البطاقات" value={<span className="ltr">56.1k</span>} foot="EGP" />
      <KPI label="المرتجعات والمصروفات" value={<span className="ltr">4.9k</span>} foot="EGP نقدًا" />
      <KPI label="صافي الفروق" value={<span className="ltr c-red">−270</span>} foot="عجز إجمالي" />
    </div>
    <Card stg={4}><DataTable rows={rows} cols={[
      { h: 'الوردية', r: s => <Num className="font-semibold">{s.no}</Num> }, { h: 'الكاشير', r: s => s.cashier },
      { h: 'نقدي', r: () => fmtMoney(16480, 0) }, { h: 'بطاقات', r: () => fmtMoney(11240, 0) }, { h: 'مرتجعات ومصروفات', r: () => fmtMoney(910, 0) },
      { h: 'المتوقعة', r: s => fmtMoney(s.exp) }, { h: 'الفعلية', r: s => fmtMoney(s.act) }, { h: 'الفرق', r: s => <DiffCellR s={s} /> }]} /></Card>
  </>;
}
function DiffCellR({ s }) { const d = s.act - s.exp; return d === 0 ? <Badge tone="green" dot={false}>مطابق</Badge> : <span className={'font-semibold ' + (d < 0 ? 'c-red' : 'c-amber')}>{d < 0 ? 'عجز ' : 'زيادة '}<Num>{fmtNum(Math.abs(d))}</Num></span>; }

/* ---------------- NOTIFICATIONS ---------------- */
const NOTIFS = [
  { id: 'n1', type: 'order.created', data: { order_no: 'ORD-10482', total: 1284.5, channel: 'المتجر الإلكتروني', target: { m: 'orders', id: 10482 } }, read_at: null, created_at: 'منذ 4 دقائق' },
  { id: 'n2', type: 'return.requested', data: { return_no: 'RET-2041', order_no: 'ORD-10433', target: { m: 'returns', id: 1 } }, read_at: null, created_at: 'منذ 22 دقيقة' },
  { id: 'n3', type: 'payment_notice.submitted', data: { notice_no: 'PN-0284', company: 'شركة النور للتجارة', amount: 24800, target: { m: 'wholesale', sub: 'notices' } }, read_at: null, created_at: 'منذ ساعة' },
  { id: 'n4', type: 'shift.closed', data: { shift_no: 'SH-5521', cashier: 'منة الله عادل', difference: -200, target: { m: 'shifts' } }, read_at: null, created_at: 'منذ 9 ساعات' },
  { id: 'n5', type: 'purchase_order.revision_requested', data: { po_no: 'PO-1206', supplier: 'مطاحن الدلتا', target: { m: 'purchasing', sub: 'po' } }, read_at: '2026-10-26', created_at: 'أمس 18:40' },
  { id: 'n6', type: 'quotation.requested', data: { quote_no: 'QT-0611', company: 'شركة النور للتجارة', target: { m: 'wholesale', sub: 'quotes' } }, read_at: '2026-10-26', created_at: 'أمس 14:12' },
  { id: 'n7', type: 'stock.low', data: { sku: 'OIL-EV-1000', branch: 'فرع المعادي', qty: 4 }, read_at: '2026-10-25', created_at: '25 أكتوبر' },
  { id: 'n8', type: 'system.message', data: { message: 'تمت جدولة صيانة الخادم يوم الجمعة 2:00 ص' }, read_at: '2026-10-24', created_at: '24 أكتوبر' },
];
function notifView(n) {
  const d = n.data;
  switch (n.type) {
    case 'order.created': return { icon: 'orders', tone: 'orange', text: <>طلب جديد <b className="num">{d.order_no}</b> بقيمة {fmtMoney(d.total, 0)} من {d.channel}</> };
    case 'return.requested': return { icon: 'returns', tone: 'amber', text: <>طلب إرجاع <b className="num">{d.return_no}</b> على الطلب <span className="num">{d.order_no}</span></> };
    case 'payment_notice.submitted': return { icon: 'bank', tone: 'blue', text: <>إشعار سداد <b className="num">{d.notice_no}</b> من {d.company} بمبلغ {fmtMoney(d.amount, 0)}</> };
    case 'shift.closed': return { icon: 'clock', tone: d.difference ? 'red' : 'green', text: <>أُغلقت الوردية <b className="num">{d.shift_no}</b> — {d.cashier}{d.difference ? <> · عجز <Num>{Math.abs(d.difference)}</Num></> : ''}</> };
    case 'purchase_order.revision_requested': return { icon: 'cart', tone: 'violet', text: <>{d.supplier} طلب تعديل أمر الشراء <b className="num">{d.po_no}</b></> };
    case 'quotation.requested': return { icon: 'quote', tone: 'orange', text: <>عرض أسعار <b className="num">{d.quote_no}</b> بانتظار التسعير — {d.company}</> };
    case 'stock.low': return { icon: 'box', tone: 'amber', text: <>مخزون منخفض: <span className="num">{d.sku}</span> في {d.branch} (<Num>{d.qty}</Num> متبقي)</> };
    default: return { icon: 'bell', tone: 'gray', text: d.message || <span className="tx3 num">{n.type}</span> };
  }
}
function NotifItem({ n, onRead, onOpen, compact }) {
  const v = notifView(n); const unread = !n.read_at;
  return <div className={'flex items-start gap-3 rounded-[16px] transition ' + (compact ? 'p-3' : 'p-4') + (unread ? ' v-sub' : '')} style={!unread ? { background: 'transparent' } : {}}>
    <span className={'w-9 h-9 rounded-full flex items-center justify-center shrink-0 t-' + v.tone}><Icon name={v.icon} size={15} /></span>
    <div className="flex-1 min-w-0">
      <div className={'text-[12.5px] leading-relaxed pretty ' + (unread ? 'tx1 font-medium' : 'tx2')}>{v.text}</div>
      <div className="flex items-center gap-3 mt-1.5 flex-wrap">
        <span className="text-[11px] tx3">{n.created_at}</span>
        {n.data.target && <button className="text-[11.5px] font-semibold accent flex items-center gap-1 hover:underline" onClick={() => onOpen(n)}>فتح السجل<Icon name="chevL" size={11} sw={2.4} /></button>}
        {unread && <button className="text-[11.5px] font-medium tx2 hover:tx1 flex items-center gap-1" onClick={() => onRead(n.id)}><Icon name="check" size={11} sw={2.4} />تعليم كمقروء</button>}
      </div>
    </div>
    {unread && <span className="w-2 h-2 rounded-full mt-2 shrink-0" style={{ background: 'var(--accent)' }}></span>}
  </div>;
}
function NotifDrawer({ open, onClose, items, onRead, go }) {
  if (!open) return null;
  const unread = items.filter(n => !n.read_at).length;
  return ReactDOM.createPortal(<>
    <div className="drawer-bd" onClick={onClose}></div>
    <div className="drawer" dir="rtl">
      <div className="flex items-center justify-between p-5 pb-3"><div><h3 className="font-display text-[18px] font-semibold tx1">الإشعارات</h3><div className="text-[11.5px] tx3 mt-0.5"><Num>{unread}</Num> غير مقروء</div></div><button className="icon-btn" onClick={onClose}><Icon name="x" size={14} /></button></div>
      <div className="flex-1 overflow-y-auto px-3 space-y-1">{items.slice(0, 6).map(n => <NotifItem compact key={n.id} n={n} onRead={onRead} onOpen={x => { onRead(x.id); onClose(); go(x.data.target); }} />)}</div>
      <div className="p-4 v-bt"><button className="btn btn-dark w-full" onClick={() => { onClose(); go({ m: 'notifications' }); }}>عرض كل الإشعارات</button></div>
    </div>
  </>, document.body);
}
function NotificationsPage({ items, onRead, go }) {
  const [page, setPage] = useState(1);
  const unread = items.filter(n => !n.read_at).length;
  return <div className="space-y-5">
    <PageHead title="الإشعارات" sub={<><Num>{unread}</Num> إشعارات غير مقروءة · <Num>20</Num> إشعارًا لكل صفحة</>} />
    <Card stg={2} pad="p-3">
      <div className="space-y-1">{items.map(n => <NotifItem key={n.id} n={n} onRead={onRead} onOpen={x => { onRead(x.id); go(x.data.target); }} />)}</div>
      <div className="px-2"><Pager total={46} page={page} onPage={setPage} /></div>
    </Card>
  </div>;
}

Object.assign(window, { ReportsPage, NOTIFS, NotifDrawer, NotificationsPage });
