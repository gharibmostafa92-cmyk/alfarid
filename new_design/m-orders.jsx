/* ============ 1. Orders  ·  2. Returns ============ */

function PayCell({ o }) {
  return <div className="leading-tight">
    <div className="text-[12.5px] tx1 font-medium">{PAY_METHODS[o.pay]}</div>
    <div className={'text-[11px] mt-0.5 font-semibold c-' + PAY_STATUS[o.payStatus].tone}>{PAY_STATUS[o.payStatus].label}</div>
  </div>;
}
function OrderFlags({ o }) {
  return <div className="flex flex-col gap-1 items-start">
    {o.followUp && <span className="badge t-amber !py-[2px] !text-[10.5px]"><Icon name="flag" size={10} sw={2.4} />يحتاج متابعة</span>}
    {o.needRefund && <span className="badge t-red !py-[2px] !text-[10.5px]"><Icon name="returns" size={10} sw={2.4} />يتطلب استرداد مبلغ</span>}
  </div>;
}

/* ---------- Orders list ---------- */
function OrdersList({ go }) {
  const [q, setQ] = useState('');
  const [st, setSt] = useState('');
  const [br, setBr] = useState('');
  const [pm, setPm] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [fu, setFu] = useState(false);
  const [rf, setRf] = useState(false);

  const rows = ORDERS.filter(o =>
    (!q || (o.no + o.customer + o.phone).includes(q)) && (!st || o.status === st) && (!br || o.branch === br) &&
    (!pm || o.pay === pm) && (!fu || o.followUp) && (!rf || o.needRefund) && (!from || o.date >= from) && (!to || o.date <= to));
  const anyFilter = q || st || br || pm || from || to || fu || rf;
  const reset = () => { setQ(''); setSt(''); setBr(''); setPm(''); setFrom(''); setTo(''); setFu(false); setRf(false); };

  const cols = [
    { h: 'رقم الطلب', r: o => <span className="num font-semibold tx2">{o.no}</span> },
    { h: 'العميل', r: o => <div className="flex items-center gap-2.5"><Avatar name={o.customer} tone={o.id} size={28} />
      <div className="leading-tight"><div className="font-semibold tx1 flex items-center gap-1.5">{o.customer}{o.guest && <span className="badge t-gray !py-0 !px-1.5 !text-[10px]" >زائر</span>}</div>
        <div className="text-[11px] tx3 num ltr mt-0.5">{o.phone}</div></div></div> },
    { h: 'القناة', r: o => <span className="v-sub px-2.5 py-1 rounded-full text-[11px] tx2 whitespace-nowrap">{o.channel}</span> },
    { h: 'الحالة', r: o => <StatusBadge map={ORDER_STATUS} value={o.status} /> },
    { h: 'الدفع', r: o => <PayCell o={o} /> },
    { h: 'الفرع المخصص', r: o => o.branch ? <span className="tx2 whitespace-nowrap">{o.branch}</span> : <span className="tx3 text-[11.5px]">غير مخصص</span> },
    { h: 'الإجمالي', r: o => <span className="font-semibold tx1 whitespace-nowrap">{fmtMoney(o.total)}</span> },
    { h: 'تاريخ الإنشاء', r: o => <div className="leading-tight tx2"><Num>{o.date}</Num><div className="text-[11px] tx3 num">{o.time}</div></div> },
    { h: 'تنبيهات', r: o => <OrderFlags o={o} /> },
    { h: '', r: o => <RowActions><button className="btn btn-soft btn-xs" onClick={() => go({ m: 'orders', id: o.id })}>عرض التفاصيل</button></RowActions> },
  ];

  return <div className="space-y-5">
    <PageHead title="الطلبات" sub="متابعة طلبات كل القنوات، وتحديث حالتها ودفعها وتخصيص مخزونها." />
    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 stg stg-2">
      <KPI hero label="طلبات قيد المراجعة" icon="clock" value={ORDERS.filter(o => o.status === 'pending').length} foot="تحتاج تأكيدًا أو إلغاء" />
      <KPI label="يحتاج متابعة" icon="flag" value={ORDERS.filter(o => o.followUp).length} foot="معلَّمة للمتابعة" />
      <KPI label="يتطلب استرداد مبلغ" icon="returns" value={ORDERS.filter(o => o.needRefund).length} foot="ملغاة أو مرتجعة ومدفوعة" />
      <KPI label="بدون فرع مخصص" icon="store" value={ORDERS.filter(o => !o.branch).length} foot="بانتظار تخصيص المخزون" />
    </div>
    <Card stg={3}>
      <div className="flex flex-wrap items-center gap-2 mb-4">
        <SearchPill value={q} onChange={setQ} placeholder="رقم الطلب، العميل، الهاتف" w="w-48" />
        <FSelect value={st} onChange={setSt} placeholder="كل الحالات" options={Object.entries(ORDER_STATUS).map(([v, s]) => ({ value: v, label: s.label }))} />
        <FSelect value={br} onChange={setBr} placeholder="كل الفروع" options={BRANCHES} />
        <FSelect value={pm} onChange={setPm} placeholder="طريقة الدفع" options={Object.entries(PAY_METHODS).map(([v, l]) => ({ value: v, label: l }))} />
        <FDate label="من" value={from} onChange={setFrom} />
        <FDate label="إلى" value={to} onChange={setTo} />
        <FChip on={fu} onClick={() => setFu(!fu)} icon="flag">يحتاج متابعة</FChip>
        <FChip on={rf} onClick={() => setRf(!rf)} icon="returns">يتطلب استرداد</FChip>
        {anyFilter && <button className="btn btn-ghost btn-xs" onClick={reset}><Icon name="x" size={12} />مسح الفلاتر</button>}
      </div>
      <DataTable cols={cols} rows={rows} onRow={o => go({ m: 'orders', id: o.id })} />
      <Pager total={rows.length ? 248 : 0} />
    </Card>
  </div>;
}

/* ---------- Order detail ---------- */
function OrderDetail({ id, go }) {
  const toast = useToast();
  const base = ORDERS.find(o => o.id === id) || ORDERS[0];
  const [o, setO] = useState(base);
  const [modal, setModal] = useState(null);
  const [newSt, setNewSt] = useState('');
  const [newPay, setNewPay] = useState(o.payStatus);
  const [allocBranch, setAllocBranch] = useState('');
  const [carrier, setCarrier] = useState('');
  const [awb, setAwb] = useState('');

  const items = [
    { v: VARIANTS[0], qty: 2, disc: 20, tax: 0, branch: o.branch },
    { v: VARIANTS[3], qty: 3, disc: 0, tax: 0, branch: o.branch },
    { v: VARIANTS[6], qty: 1, disc: 15, tax: 0, branch: o.branch ? 'المخزن الرئيسي' : null },
  ];
  const lineTotal = it => it.v.price * it.qty - it.disc + it.tax;
  const subtotal = items.reduce((s, it) => s + it.v.price * it.qty, 0);
  const disc = items.reduce((s, it) => s + it.disc, 0) + 50;
  const ship = 55, codFee = o.pay === 'cod' ? 15 : 0, tax = 0;
  const total = subtotal - disc + ship + codFee + tax;
  const next = ORDER_NEXT[o.status];
  const canShip = next.includes('shipped');
  const terminal = next.length === 0;

  const history = [
    { from: 'confirmed', to: o.status, note: 'تم تجهيز الطلب وتسليمه لشركة الشحن', by: 'أحمد ناصر · مدير العمليات', at: '2026-10-27 · 11:20' },
    { from: 'pending', to: 'confirmed', note: 'تم التأكيد هاتفيًا مع العميل', by: 'منة الله عادل', at: '2026-10-27 · 09:48' },
    { from: null, to: 'pending', note: 'تم إنشاء الطلب من ' + o.channel, by: 'النظام', at: '2026-10-27 · ' + o.time },
  ];

  return <div className="space-y-5">
    <PageHead back={{ label: 'كل الطلبات', onClick: () => go({ m: 'orders' }) }}
      title={<span className="flex items-center gap-3 flex-wrap"><span className="num">{o.no}</span><StatusBadge map={ORDER_STATUS} value={o.status} /></span>}
      sub={<span>أُنشئ <Num>{o.date} · {o.time}</Num> عبر {o.channel}</span>}
      actions={<>
        <button className="btn btn-soft" disabled={terminal} onClick={() => { setNewSt(''); setModal('status'); }}><Icon name="refresh" size={14} />تغيير الحالة</button>
        <button className="btn btn-soft" onClick={() => setModal('pay')}><Icon name="card" size={14} />تحديث الدفع</button>
        {o.branch
          ? <button className="btn btn-soft" onClick={() => setModal('dealloc')}><Icon name="layers" size={14} />فك التخصيص</button>
          : <button className="btn btn-soft" onClick={() => setModal('alloc')}><Icon name="layers" size={14} />تخصيص المخزون</button>}
        <button className="btn btn-dark" disabled={!canShip} onClick={() => setModal('ship')}><Icon name="truck" size={14} />إنشاء شحنة</button>
      </>} />

    {(o.followUp || o.needRefund) && <div className="flex flex-wrap gap-2 stg stg-2">
      {o.followUp && <Note tone="amber" icon="flag">هذا الطلب معلَّم <b>«يحتاج متابعة»</b>.</Note>}
      {o.needRefund && <Note tone="red" icon="returns">هذا الطلب <b>«يتطلب استرداد مبلغ»</b> — أنشئ مرتجعًا أو حدّث حالة الدفع إلى مسترد.</Note>}
    </div>}

    <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
      <div className="lg:col-span-8 space-y-5">
        <Card stg={2}>
          <CardHead title="المنتجات" sub="بيانات المنتج كما كانت وقت الطلب" right={<span className="text-[11.5px] tx3"><Num>{items.length}</Num> بنود</span>} />
          <DataTable compact cols={[
            { h: 'المنتج', r: it => <div className="flex items-center gap-2.5"><span className="w-9 h-9 rounded-[10px] v-sub flex items-center justify-center tx3"><Icon name="box" size={15} /></span>
              <div className="leading-tight"><div className="font-semibold tx1">{it.v.name}</div><div className="text-[11px] tx3 num ltr mt-0.5">{it.v.sku}</div></div></div> },
            { h: 'الكمية', align: 'center', r: it => <Num className="font-semibold">{it.qty}</Num> },
            { h: 'سعر الوحدة', r: it => fmtMoney(it.v.price) },
            { h: 'الخصم', r: it => it.disc ? <span className="c-red">{fmtMoney(-it.disc)}</span> : <span className="tx3">—</span> },
            { h: 'الضريبة', r: it => <span className="tx2">{fmtMoney(it.tax)}</span> },
            { h: 'إجمالي البند', r: it => <span className="font-semibold">{fmtMoney(lineTotal(it))}</span> },
            { h: 'فرع البند', r: it => it.branch ? <span className="text-[11.5px] tx2">{it.branch}</span> : <span className="text-[11.5px] tx3">غير مخصص</span> },
          ]} rows={items} />
        </Card>

        <Card stg={3}>
          <CardHead title="سجل التغييرات" sub="كل تغيير في حالة الطلب ومن قام به" />
          <Timeline items={history.map(h => ({
            icon: h.to === 'pending' ? 'plus' : 'refresh',
            body: <div>
              <div className="flex items-center gap-2 flex-wrap">
                {h.from && <><StatusBadge map={ORDER_STATUS} value={h.from} /><Icon name="arrowL" size={13} className="tx3" /></>}
                <StatusBadge map={ORDER_STATUS} value={h.to} />
              </div>
              {h.note && <div className="text-[12.5px] tx1 mt-2">{h.note}</div>}
              <div className="text-[11px] tx3 mt-1 flex items-center gap-2"><span>{h.by}</span><span>·</span><Num>{h.at}</Num></div>
            </div>
          }))} />
        </Card>
      </div>

      <div className="lg:col-span-4 space-y-5">
        <div className="v-card p-4 stg stg-2">
          <div className="v-orange rounded-[18px] p-4">
            <div className="flex items-center justify-between text-[12.5px] text-white/90"><span>إجمالي الطلب</span><span className="px-2 py-0.5 rounded-full text-[10.5px] font-semibold bg-white/20">{PAY_METHODS[o.pay]}</span></div>
            <div className="font-display text-[30px] font-bold num mt-2 mb-1 ltr">{fmtNum(total)} <span className="text-[14px] opacity-80">EGP</span></div>
            <div className="text-[11.5px] text-white/85">حالة الدفع: <b>{PAY_STATUS[o.payStatus].label}</b></div>
          </div>
          <div className="px-1 pt-4">
            <KV items={[
              ['المجموع الفرعي', fmtMoney(subtotal)],
              ['الخصم', <span className="c-red">{fmtMoney(-disc)}</span>],
              ['كود الكوبون', <span className="badge t-orange num !text-[11px]" style={{ letterSpacing: '.04em' }}><Icon name="ticket" size={11} />WELCOME50</span>],
              ['الشحن', fmtMoney(ship)],
              ['رسوم الدفع عند الاستلام', fmtMoney(codFee)],
              ['الضريبة', fmtMoney(tax)],
            ]} />
            <div className="flex items-center justify-between v-bt mt-3 pt-3"><span className="font-semibold tx1">الإجمالي</span><span className="font-display font-bold text-[16px]">{fmtMoney(total)}</span></div>
          </div>
        </div>

        <Card stg={3}>
          <CardHead title="العميل" className="mb-3" />
          <div className="flex items-center gap-3 mb-4"><Avatar name={o.customer} tone={o.id} size={40} />
            <div><div className="font-semibold tx1">{o.customer}</div><div className="text-[11.5px] tx3 num ltr">{o.phone}</div></div></div>
          <KV items={[['نوع العميل', <Badge tone={o.guest ? 'gray' : o.ctype === 'جملة' ? 'violet' : 'blue'} dot={false}>{o.ctype}</Badge>],
            o.guest && ['بيانات الزائر', <span className="text-[12px]">بدون حساب · <span className="num">guest_8841</span></span>]]} />
        </Card>

        <Card stg={4}>
          <CardHead title="التوصيل" className="mb-3" />
          <KV items={[['المدينة', o.city], ['العنوان', <span className="text-[12px] leading-relaxed">١٤ ش مصطفى النحاس، الدور الثالث، شقة ٧</span>]]} />
          <div className="v-sub px-3.5 py-2.5 mt-3 text-[12px] tx2 flex gap-2"><Icon name="info" size={14} style={{ marginTop: 2 }} />الاتصال قبل الوصول بنصف ساعة، التسليم بعد الخامسة مساءً.</div>
        </Card>

        <Card stg={5}>
          <CardHead title="التنفيذ" className="mb-3" />
          <KV items={[['حالة الطلب', <StatusBadge map={ORDER_STATUS} value={o.status} />], ['الفرع المخصص', o.branch || <span className="tx3">غير مخصص</span>]]} />
          {!terminal && <div className="mt-4"><div className="lbl mb-2">الانتقالات المتاحة</div>
            <div className="flex flex-wrap gap-1.5">{next.map(n => <StatusBadge key={n} map={ORDER_STATUS} value={n} />)}</div></div>}
        </Card>
      </div>
    </div>

    {/* status */}
    <Modal open={modal === 'status'} onClose={() => setModal(null)} title="تغيير حالة الطلب" icon="refresh"
      sub="تظهر فقط الحالات المسموح الانتقال إليها من الحالة الحالية."
      footer={<><button className="btn btn-soft" onClick={() => setModal(null)}>إلغاء</button><button className="btn btn-dark" disabled={!newSt} onClick={() => { setO({ ...o, status: newSt }); setModal(null); toast('تم تحديث حالة الطلب'); }}>حفظ الحالة</button></>}>
      <div className="space-y-4">
        <div className="v-sub p-3 flex items-center gap-2 text-[12px]"><span className="tx2">الحالة الحالية</span><StatusBadge map={ORDER_STATUS} value={o.status} /></div>
        <Field label="الحالة الجديدة" req>
          <div className="grid grid-cols-2 gap-2">{next.map(n => (
            <button key={n} type="button" onClick={() => setNewSt(n)} className="v-sub p-3 flex items-center justify-between text-[12.5px] font-medium transition"
              style={newSt === n ? { outline: '2px solid var(--accent)', background: 'var(--bg-card)' } : {}}>
              <StatusBadge map={ORDER_STATUS} value={n} />{newSt === n && <Icon name="check" size={15} className="accent" />}</button>))}</div>
        </Field>
        {newSt === 'shipped' && <Note tone="amber" icon="alert">قد ترفض العملية إن لم توجد شحنة أو لم يُخصص المخزون — يُفضّل استخدام «إنشاء شحنة».</Note>}
        <TextArea label="ملاحظات" max={500} placeholder="اختياري" />
      </div>
    </Modal>

    {/* payment */}
    <Modal open={modal === 'pay'} onClose={() => setModal(null)} title="تحديث حالة الدفع" icon="card"
      footer={<><button className="btn btn-soft" onClick={() => setModal(null)}>إلغاء</button><button className="btn btn-dark" onClick={() => { setO({ ...o, payStatus: newPay }); setModal(null); toast('تم تحديث حالة الدفع'); }}>حفظ</button></>}>
      <div className="space-y-4">
        <Field label="حالة الدفع" req>
          <div className="grid grid-cols-2 gap-2">{Object.entries(PAY_STATUS).map(([k, s]) => (
            <button key={k} type="button" onClick={() => setNewPay(k)} className="v-sub p-3 flex items-center justify-between transition"
              style={newPay === k ? { outline: '2px solid var(--accent)', background: 'var(--bg-card)' } : {}}>
              <span className="flex items-center gap-2 text-[12.5px] font-medium tx1"><span className={'w-2 h-2 rounded-full t-' + s.tone} style={{ background: 'currentColor' }}></span>{s.label}<span className="text-[10.5px] tx3 num">{k}</span></span>
              {newPay === k && <Icon name="check" size={15} className="accent" />}</button>))}</div>
        </Field>
        <Note icon="info">يُسجَّل تغيير الحالة فقط — لا يدعم النظام إدخال مبلغ دفعة جزئية.</Note>
        <TextArea label="ملاحظات" max={500} placeholder="اختياري" />
      </div>
    </Modal>

    {/* allocate */}
    <Modal open={modal === 'alloc'} onClose={() => setModal(null)} title="تخصيص المخزون" icon="layers" width={460}
      footer={<><button className="btn btn-soft" onClick={() => setModal(null)}>إلغاء</button><button className="btn btn-dark" onClick={() => { setO({ ...o, branch: allocBranch || 'فرع مدينة نصر' }); setModal(null); toast(allocBranch ? 'تم التخصيص من ' + allocBranch : 'تم طلب التخصيص التلقائي'); }}>{allocBranch ? 'تخصيص' : 'تخصيص تلقائي'}</button></>}>
      <div className="space-y-4 pb-2">
        <Field label="الفرع" hint="اترك الحقل فارغًا ليختار النظام الفرع المناسب تلقائيًا.">
          <select className="inp" value={allocBranch} onChange={e => setAllocBranch(e.target.value)}>
            <option value="">— تخصيص تلقائي —</option>{BRANCHES.map(b => <option key={b}>{b}</option>)}</select>
        </Field>
      </div>
    </Modal>
    <ConfirmModal open={modal === 'dealloc'} onClose={() => setModal(null)} danger title="فك تخصيص المخزون" cta="فك التخصيص"
      text={<>سيتم تحرير الكميات المحجوزة لهذا الطلب في <b className="tx1">{o.branch}</b> وإعادتها للمخزون المتاح.</>}
      onConfirm={() => { setO({ ...o, branch: null }); toast('تم فك التخصيص'); }} />

    {/* shipment */}
    <Modal open={modal === 'ship'} onClose={() => setModal(null)} title="إنشاء شحنة" icon="truck" width={480}
      footer={<><button className="btn btn-soft" onClick={() => setModal(null)}>إلغاء</button><button className="btn btn-dark" disabled={!carrier} onClick={() => { setO({ ...o, status: 'shipped' }); setModal(null); toast('تم إنشاء الشحنة'); }}>إنشاء الشحنة</button></>}>
      <div className="space-y-4 pb-2">
        <Field label="شركة الشحن" req>
          <select className="inp" value={carrier} onChange={e => setCarrier(e.target.value)}><option value="">اختر الشركة</option>
            {SHIP_COMPANIES.filter(c => c.active).map(c => <option key={c.id} value={c.code}>{c.name}</option>)}</select>
        </Field>
        <Field label="رقم البوليصة (AWB)" count={`${awb.length} / 100`} hint="اختياري — يمكن تركه إن كانت الشركة تُصدره تلقائيًا.">
          <input className="inp num" dir="ltr" maxLength={100} value={awb} onChange={e => setAwb(e.target.value)} placeholder="e.g. 7731 4402 9918" />
        </Field>
        {!o.branch && <Note tone="amber" icon="alert">لم يتم تخصيص مخزون لهذا الطلب بعد — قد يرفض النظام إنشاء الشحنة.</Note>}
      </div>
    </Modal>
  </div>;
}

/* ================= RETURNS ================= */
function ReturnsList({ go }) {
  const [st, setSt] = useState('');
  const [q, setQ] = useState('');
  const [create, setCreate] = useState(false);
  const rows = RETURNS.filter(r => (!st || r.status === st) && (!q || (r.no + r.order + r.customer).includes(q)));
  return <div className="space-y-5">
    <PageHead title="المرتجعات" sub="الموافقة والاستلام ورد المبلغ تتم كخطوات منفصلة لكل مرتجع."
      actions={<button className="btn btn-dark" onClick={() => setCreate(true)}><Icon name="plus" size={14} />إنشاء مرتجع</button>} />
    <Card stg={2} pad="p-4">
      <div className="flex gap-2 overflow-x-auto">
        <button onClick={() => setSt('')} className={'v-sub px-4 py-3 rounded-[14px] text-start min-w-[120px] transition ' + (!st ? 'ring-2' : '')} style={!st ? { '--tw-ring-color': 'var(--accent)' } : {}}>
          <div className="text-[11.5px] tx2">الكل</div><div className="font-display text-[22px] font-bold num tx1">{RETURNS.length}</div></button>
        {Object.entries(RETURN_STATUS).map(([k, s]) => <button key={k} onClick={() => setSt(k)} className={'v-sub px-4 py-3 rounded-[14px] text-start min-w-[120px] transition ' + (st === k ? 'ring-2' : '')} style={st === k ? { '--tw-ring-color': 'var(--accent)' } : {}}>
          <div className={'text-[11.5px] font-medium c-' + s.tone}>{s.label}</div><div className="font-display text-[22px] font-bold num tx1">{RETURNS.filter(r => r.status === k).length}</div></button>)}
      </div>
    </Card>
    <Card stg={3}>
      <div className="flex flex-wrap items-center gap-2 mb-4"><SearchPill value={q} onChange={setQ} placeholder="رقم المرتجع أو الطلب" /></div>
      <DataTable onRow={r => go({ m: 'returns', id: r.id })} rows={rows} cols={[
        { h: 'رقم المرتجع', r: r => <span className="num font-semibold tx1">{r.no}</span> },
        { h: 'الطلب الأصلي', r: r => <span className="num tx2 underline decoration-dotted underline-offset-4">{r.order}</span> },
        { h: 'العميل', r: r => <div className="flex items-center gap-2"><Avatar name={r.customer} tone={r.id} size={26} /><span className="font-medium tx1">{r.customer}</span></div> },
        { h: 'الفرع', r: r => r.branch ? <span className="tx2">{r.branch}</span> : <span className="tx3 text-[11.5px]">يحدد عند الموافقة</span> },
        { h: 'الحالة', r: r => <StatusBadge map={RETURN_STATUS} value={r.status} /> },
        { h: 'طريقة الرد', r: r => r.method ? <span className="tx2">{REFUND_METHODS[r.method]}</span> : <span className="tx3">—</span> },
        { h: 'إجمالي الاسترداد', r: r => <span className="font-semibold">{fmtMoney(r.total)}</span> },
        { h: 'تاريخ الإنشاء', r: r => <Num className="tx2">{r.date}</Num> },
      ]} />
      <Pager total={rows.length} />
    </Card>
    <CreateReturnModal open={create} onClose={() => setCreate(false)} />
  </div>;
}

function CreateReturnModal({ open, onClose }) {
  const toast = useToast();
  const [order, setOrder] = useState('');
  const [lines, setLines] = useState([{ item: '', qty: 1, reason: '' }]);
  const set = (i, k, v) => setLines(lines.map((l, j) => j === i ? { ...l, [k]: v } : l));
  return <Modal open={open} onClose={onClose} title="إنشاء مرتجع" icon="returns" width={680} sub="يُنشأ بحالة «قيد المراجعة»؛ يُحسب مبلغ الاسترداد من النظام."
    footer={<><button className="btn btn-soft" onClick={onClose}>إلغاء</button><button className="btn btn-dark" disabled={!order} onClick={() => { onClose(); toast('تم إنشاء المرتجع RET-2042'); }}>إنشاء المرتجع</button></>}>
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3">
        <Field label="الطلب الأصلي" req><select className="inp" value={order} onChange={e => setOrder(e.target.value)}><option value="">ابحث برقم الطلب</option>{ORDERS.filter(o => ['delivered', 'shipped'].includes(o.status)).map(o => <option key={o.id} value={o.no}>{o.no} — {o.customer}</option>)}</select></Field>
        <Field label="طريقة الاسترداد" hint="اختيارية — يمكن تحديدها عند رد المبلغ."><select className="inp"><option value="">لم تُحدد</option>{Object.entries(REFUND_METHODS).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></Field>
      </div>
      <TextArea label="سبب الإرجاع" req max={2000} placeholder="اشرح سبب الإرجاع" />
      <div>
        <div className="flex items-center justify-between mb-2"><span className="lbl">البنود <span className="accent">*</span></span><span className="text-[11px] tx3">من بند واحد حتى <Num>50</Num> بندًا · <Num>{lines.length}</Num>/50</span></div>
        <div className="v-sub p-2 space-y-2">
          <div className="grid grid-cols-12 gap-2 px-2 pt-1 text-[11px] tx3"><span className="col-span-5">بند الطلب</span><span className="col-span-2">الكمية</span><span className="col-span-4">سبب البند</span></div>
          {lines.map((l, i) => <div key={i} className="grid grid-cols-12 gap-2 items-center">
            <select className="inp inp-sm col-span-5 !bg-[var(--bg-card)]" disabled={!order} value={l.item} onChange={e => set(i, 'item', e.target.value)}><option value="">{order ? 'اختر بندًا' : 'اختر الطلب أولًا'}</option>{VARIANTS.slice(0, 3).map(v => <option key={v.id}>{v.name} · {v.sku}</option>)}</select>
            <input type="number" min="1" className="inp inp-sm col-span-2 num !bg-[var(--bg-card)]" value={l.qty} onChange={e => set(i, 'qty', e.target.value)} />
            <input className="inp inp-sm col-span-4 !bg-[var(--bg-card)]" placeholder="اختياري" value={l.reason} onChange={e => set(i, 'reason', e.target.value)} />
            <button className="icon-btn !w-7 !h-7 col-span-1 justify-self-center" disabled={lines.length === 1} onClick={() => setLines(lines.filter((_, j) => j !== i))}><Icon name="trash" size={13} /></button>
          </div>)}
          <button className="btn btn-ghost btn-sm w-full" disabled={lines.length >= 50} onClick={() => setLines([...lines, { item: '', qty: 1, reason: '' }])}><Icon name="plus" size={13} />إضافة بند</button>
        </div>
      </div>
    </div>
  </Modal>;
}

function ReturnDetail({ id, go }) {
  const toast = useToast();
  const base = RETURNS.find(r => r.id === id) || RETURNS[0];
  const [r, setR] = useState(base);
  const [modal, setModal] = useState(null);
  const [approveBranch, setApproveBranch] = useState(r.branch || '');
  const [rejectMode, setRejectMode] = useState('rejected');
  const lines = [
    { v: VARIANTS[0], req: 2, appr: 2, good: 1, dmg: 1, price: 385, reason: 'العبوة وصلت مكسورة', files: 2 },
    { v: VARIANTS[8], req: 1, appr: r.status === 'pending' ? null : 0, good: null, dmg: null, price: 145, reason: 'غير مطابق للطلب', files: 0 },
  ];
  const steps = [
    { k: 'pending', l: 'طلب الإرجاع' }, { k: 'approved', l: 'الموافقة' }, { k: 'received', l: 'الاستلام والفحص' }, { k: 'refunded', l: 'رد المبلغ' },
  ];
  const order = ['pending', 'approved', 'received', 'refunded'];
  const idx = order.indexOf(r.status);
  const closed = ['rejected', 'cancelled'].includes(r.status);

  return <div className="space-y-5">
    <PageHead back={{ label: 'كل المرتجعات', onClick: () => go({ m: 'returns' }) }}
      title={<span className="flex items-center gap-3"><span className="num">{r.no}</span><StatusBadge map={RETURN_STATUS} value={r.status} /></span>}
      sub={<span>عن الطلب <span className="num font-semibold tx1">{r.order}</span> · {r.customer}</span>}
      actions={<>
        {r.status === 'pending' && <><button className="btn btn-danger" onClick={() => setModal('reject')}>رفض / إلغاء</button><button className="btn btn-dark" onClick={() => setModal('approve')}><Icon name="check" size={14} />الموافقة</button></>}
        {r.status === 'approved' && <><button className="btn btn-soft" onClick={() => { setRejectMode('cancelled'); setModal('reject'); }}>إلغاء</button><button className="btn btn-dark" onClick={() => setModal('receive')}><Icon name="box" size={14} />الاستلام والفحص</button></>}
        {r.status === 'received' && <button className="btn btn-accent" onClick={() => setModal('refund')}><Icon name="wallet" size={14} />رد المبلغ</button>}
      </>} />

    <Card stg={2} pad="p-5">
      {closed ? <Note tone={r.status === 'rejected' ? 'red' : 'gray'} icon="ban">هذا المرتجع {RETURN_STATUS[r.status].label} — لا توجد خطوات إضافية.</Note> :
        <div className="grid grid-cols-4 gap-2">{steps.map((s, i) => {
          const done = i <= idx, cur = i === idx + 1;
          return <div key={s.k} className="flex flex-col gap-2">
            <div className="h-2 rounded-full overflow-hidden" style={{ background: done ? 'var(--accent)' : 'transparent' }}>{!done && <div className="h-full hatch-track"></div>}</div>
            <div className="flex items-center gap-2"><span className={'w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold num ' + (done ? 'btn-accent' : cur ? 'btn-dark' : 'v-well')}>{done ? <Icon name="check" size={10} sw={3} /> : i + 1}</span>
              <span className={'text-[12.5px] font-medium ' + (done || cur ? 'tx1' : 'tx3')}>{s.l}</span></div>
          </div>;
        })}</div>}
    </Card>

    <div className="grid grid-cols-1 lg:grid-cols-12 gap-5">
      <Card stg={3} className="lg:col-span-8">
        <CardHead title="بنود المرتجع" />
        <DataTable compact rows={lines} cols={[
          { h: 'المنتج', r: l => <div className="leading-tight"><div className="font-semibold tx1">{l.v.name}</div><div className="text-[11px] tx3 num ltr">{l.v.sku}</div></div> },
          { h: 'مطلوب', align: 'center', r: l => <Num>{l.req}</Num> },
          { h: 'موافق عليه', align: 'center', r: l => l.appr == null ? <span className="tx3">—</span> : <Num className="font-semibold">{l.appr}</Num> },
          { h: 'سليم', align: 'center', r: l => l.good == null ? <span className="tx3">—</span> : <Num className="c-green font-semibold">{l.good}</Num> },
          { h: 'تالف', align: 'center', r: l => l.dmg == null ? <span className="tx3">—</span> : <Num className="c-red font-semibold">{l.dmg}</Num> },
          { h: 'سعر الاسترداد', r: l => fmtMoney(l.price) },
          { h: 'السبب والمرفقات', r: l => <div className="leading-tight"><div className="text-[12px] tx1">{l.reason}</div>{l.files > 0 && <div className="flex gap-1 mt-1.5">{Array.from({ length: l.files }).map((_, i) => <span key={i} className="w-7 h-7 rounded-lg v-sub flex items-center justify-center tx3"><Icon name="image" size={12} /></span>)}</div>}</div> },
        ]} />
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-5">
          <div className="v-sub p-4"><div className="lbl mb-1.5">سبب الإرجاع</div><div className="text-[12.5px] tx1 leading-relaxed">وصلت عبوة زيت الزيتون مكسورة جزئيًا، والبن المستلم نوع مختلف عن المطلوب.</div></div>
          <div className="v-sub p-4"><div className="lbl mb-1.5">ملاحظات الإدارة</div><div className="text-[12.5px] tx1 leading-relaxed">{r.status === 'pending' ? <span className="tx3">لم تُضف بعد</span> : 'تمت الموافقة على بند الزيت فقط بعد مراجعة الصور.'}</div></div>
        </div>
      </Card>
      <div className="lg:col-span-4 space-y-5">
        <div className="v-card p-4 stg stg-3">
          <div className="v-orange rounded-[18px] p-4">
            <div className="text-[12.5px] text-white/90">إجمالي الاسترداد</div>
            <div className="font-display text-[28px] font-bold num my-1.5 ltr">{fmtNum(r.total)} <span className="text-[13px] opacity-80">EGP</span></div>
            <div className="text-[11px] text-white/80">محسوب من النظام حسب الكميات المعتمدة</div>
          </div>
          <div className="p-2 pt-4"><KV items={[['الفرع', r.branch], ['طريقة الاسترداد', r.method && REFUND_METHODS[r.method]], ['مرجع الاسترداد', r.status === 'refunded' ? <span className="num">TRX-55102</span> : null]]} /></div>
        </div>
        <Card stg={4}>
          <CardHead title="السجل" className="mb-4" />
          <Timeline items={[
            r.status === 'refunded' && { icon: 'wallet', body: <div><div className="text-[12.5px] font-medium tx1">تم رد المبلغ</div><div className="text-[11px] tx3">سارة حمدي · <Num>2026-10-24 14:10</Num></div></div> },
            idx >= 2 && { icon: 'box', body: <div><div className="text-[12.5px] font-medium tx1">تم الاستلام والفحص</div><div className="text-[11px] tx3">كريم فتحي · <Num>2026-10-24 10:32</Num></div></div> },
            idx >= 1 && { icon: 'check', body: <div><div className="text-[12.5px] font-medium tx1">تمت الموافقة</div><div className="text-[11px] tx3">أحمد ناصر · <Num>2026-10-23 16:05</Num></div></div> },
            { icon: 'plus', body: <div><div className="text-[12.5px] font-medium tx1">تم إنشاء المرتجع</div><div className="text-[11px] tx3">{r.customer} · <Num>{r.date} 09:12</Num></div></div> },
          ].filter(Boolean)} />
        </Card>
      </div>
    </div>

    <Modal open={modal === 'approve'} onClose={() => setModal(null)} title="الموافقة على المرتجع" icon="check" width={620}
      footer={<><button className="btn btn-soft" onClick={() => setModal(null)}>إلغاء</button><button className="btn btn-dark" disabled={!approveBranch} onClick={() => { setR({ ...r, status: 'approved', branch: approveBranch }); setModal(null); toast('تمت الموافقة'); }}>موافقة</button></>}>
      <div className="space-y-4">
        <Field label="الفرع المستلم" req><select className="inp" value={approveBranch} onChange={e => setApproveBranch(e.target.value)}><option value="">اختر الفرع</option>{BRANCHES.map(b => <option key={b}>{b}</option>)}</select></Field>
        <div><div className="lbl mb-2">الكمية الموافق عليها لكل بند</div>
          <div className="v-sub p-2 space-y-1.5">{lines.map((l, i) => <div key={i} className="flex items-center gap-3 px-2 py-1.5">
            <div className="flex-1 min-w-0"><div className="text-[12.5px] font-medium tx1 clamp1">{l.v.name}</div><div className="text-[11px] tx3">مطلوب <Num>{l.req}</Num></div></div>
            <input type="number" min="0" max={l.req} defaultValue={l.req} className="inp inp-sm w-20 num text-center !bg-[var(--bg-card)]" /></div>)}</div></div>
        <TextArea label="ملاحظات الإدارة" placeholder="اختياري" />
      </div>
    </Modal>
    <Modal open={modal === 'receive'} onClose={() => setModal(null)} title="الاستلام والفحص" icon="box" width={620} sub="أدخل الكمية السليمة والتالفة المستلمة لكل بند."
      footer={<><button className="btn btn-soft" onClick={() => setModal(null)}>إلغاء</button><button className="btn btn-dark" onClick={() => { setR({ ...r, status: 'received' }); setModal(null); toast('تم تسجيل الاستلام'); }}>تأكيد الاستلام</button></>}>
      <div className="v-sub p-2">
        <div className="grid grid-cols-12 gap-2 px-2 py-1.5 text-[11px] tx3"><span className="col-span-6">البند</span><span className="col-span-2 text-center">معتمد</span><span className="col-span-2 text-center">سليم</span><span className="col-span-2 text-center">تالف</span></div>
        {lines.map((l, i) => <div key={i} className="grid grid-cols-12 gap-2 items-center px-2 py-1.5">
          <span className="col-span-6 text-[12.5px] font-medium tx1 clamp1">{l.v.name}</span>
          <Num className="col-span-2 text-center font-semibold">{l.appr ?? l.req}</Num>
          <input type="number" min="0" defaultValue={l.appr ?? l.req} className="inp inp-sm col-span-2 num text-center !bg-[var(--bg-card)]" />
          <input type="number" min="0" defaultValue={0} className="inp inp-sm col-span-2 num text-center !bg-[var(--bg-card)]" />
        </div>)}
      </div>
    </Modal>
    <Modal open={modal === 'refund'} onClose={() => setModal(null)} title="رد المبلغ" icon="wallet" width={460}
      footer={<><button className="btn btn-soft" onClick={() => setModal(null)}>إلغاء</button><button className="btn btn-accent" onClick={() => { setR({ ...r, status: 'refunded' }); setModal(null); toast('تم رد المبلغ', 'green'); }}>تأكيد الرد</button></>}>
      <div className="space-y-4 pb-2">
        <div className="v-sub p-4 flex items-center justify-between"><span className="tx2 text-[12.5px]">المبلغ المحسوب</span><span className="font-display font-bold text-[18px]">{fmtMoney(r.total)}</span></div>
        <Field label="طريقة الاسترداد" req><select className="inp" defaultValue={r.method || ''}>{Object.entries(REFUND_METHODS).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></Field>
        <Field label="المرجع" hint="اختياري — رقم العملية أو التحويل."><input className="inp num" dir="ltr" /></Field>
      </div>
    </Modal>
    <Modal open={modal === 'reject'} onClose={() => setModal(null)} title="رفض أو إلغاء المرتجع" icon="ban" tone="red" width={460}
      footer={<><button className="btn btn-soft" onClick={() => setModal(null)}>تراجع</button><button className="btn btn-danger" onClick={() => { setR({ ...r, status: rejectMode }); setModal(null); toast('تم التحديث'); }}>تأكيد</button></>}>
      <div className="space-y-4 pb-2">
        <Field label="الحالة"><Seg value={rejectMode} onChange={setRejectMode} options={[{ value: 'rejected', label: 'مرفوض' }, { value: 'cancelled', label: 'ملغي' }]} /></Field>
        <TextArea label="ملاحظات الإدارة" placeholder="سبب الرفض أو الإلغاء" />
      </div>
    </Modal>
  </div>;
}

Object.assign(window, { OrdersList, OrderDetail, ReturnsList, ReturnDetail });
