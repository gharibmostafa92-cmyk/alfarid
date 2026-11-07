// Operations: Orders, Stock, Transfers, Transfer detail, Production, Dealers, POS count.
function Orders({ go }) {
  const [st, setSt] = useState('');
  const [q, setQ] = useState('');
  const [ch, setCh] = useState('');
  const [open, setOpen] = useState(null);
  const rows = MOCK.orders.filter(o => (!st || o.status === st) && (!ch || o.channel === ch) && (!q || o.id.toLowerCase().includes(q.toLowerCase()) || o.customer.includes(q)));
  const c = k => MOCK.orders.filter(o => !k || o.status === k).length;
  return <div className="page-enter">
    <PageHead title="الطلبات" sub="كل طلبات المتجر والجملة ونقاط البيع في مكان واحد." actions={<><button className="btn btn-secondary"><Icon n="download" s={16} />تصدير</button><button className="btn btn-primary"><Icon n="plus" s={16} />طلب يدوي</button></>} />
    <Card>
      <div className="tabs" style={{ padding: '0 8px' }}>{[['', 'الكل'], ['new', 'جديد'], ['processing', 'قيد التجهيز'], ['shipped', 'تم الشحن'], ['completed', 'مكتمل'], ['cancelled', 'ملغي']].map(([k, l]) => <button key={k} aria-selected={st === k} onClick={() => setSt(k)}>{l}<span className="tab-count">{fmt(c(k))}</span></button>)}</div>
      <div className="toolbar"><Search value={q} onChange={setQ} placeholder="رقم الطلب أو اسم العميل" />
        <FilterSelect label="القناة" value={ch} onChange={setCh} options={[{ value: 'store', label: 'المتجر' }, { value: 'wholesale', label: 'الجملة' }, { value: 'pos', label: 'نقطة البيع' }]} />
        <button className="chip"><Icon n="calendar" s={13} />التاريخ</button></div>
      {rows.length === 0 ? <Empty icon="orders" title="لا توجد طلبات مطابقة" body="غيّر الفلاتر أو امسحها." /> : <div className="table-wrap"><table className="tbl">
        <thead><tr><th>الطلب</th><th>العميل</th><th className="hide-mobile">القناة</th><th>الحالة</th><th className="hide-mobile">الدفع</th><th className="n">الإجمالي</th><th className="hide-mobile">التاريخ</th></tr></thead>
        <tbody>{rows.map(o => <tr key={o.id} className="clickable" onClick={() => setOpen(o)}>
          <td className="mono fw-6 ltr">{o.id}</td>
          <td><div className="row gap-2"><span className="avatar" style={{ width: 28, height: 28, fontSize: 11 }}>{o.customer.slice(0, 1)}</span><span className="fw-5">{o.customer}</span></div></td>
          <td className="t-2 hide-mobile">{CHANNEL[o.channel]}</td>
          <td><Status kind="order" s={o.status} /></td>
          <td className={cx('fs-12 fw-5 hide-mobile', STATUS.pay[o.pay][1])}>{STATUS.pay[o.pay][0]}</td>
          <td className="n fw-6">{money(o.total)} <span className="t-3 fs-12 fw-5">ج.م</span></td>
          <td className="t-2 fs-12 hide-mobile">{o.date}</td>
        </tr>)}</tbody>
      </table></div>}
      <Pager page={1} pages={18} total={412} from={1} to={rows.length} onPage={() => {}} />
    </Card>
    <Drawer open={!!open} onClose={() => setOpen(null)} title={open?.id} sub={open && `${open.customer} · ${open.date}`}
      footer={<><button className="btn btn-primary grow">تحديث الحالة</button><button className="btn btn-secondary"><Icon n="printer" s={16} />فاتورة</button></>}>
      {open && <div className="col gap-5">
        <Stepper steps={['جديد', 'تجهيز', 'شحن', 'مكتمل']} current={{ new: 0, processing: 1, shipped: 2, completed: 4, cancelled: 0 }[open.status]} />
        <div className="grid" style={{ gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          {[['القناة', CHANNEL[open.channel]], ['الدفع', STATUS.pay[open.pay][0]], ['عدد القطع', fmt(open.items)], ['الإجمالي', money(open.total) + ' ج.م']].map(([k, v]) => <div key={k} className="sunken" style={{ padding: '12px 14px' }}><div className="fs-12 t-3">{k}</div><div className="fs-14 fw-6 num">{v}</div></div>)}
        </div>
        <div><div className="fs-14 fw-6" style={{ marginBottom: 10 }}>الأصناف</div>
          {MOCK.products.slice(0, 2).map(p => <div key={p.id} className="row gap-3" style={{ padding: '10px 0', borderBottom: '1px solid var(--line)' }}><span className="thumb" style={{ background: p.img }} /><div className="grow"><div className="fs-13 fw-5">{p.ar}</div><div className="mono t-3 ltr" style={{ display: 'inline-block' }}>{p.code}-BLK × ١</div></div><span className="num fw-6">{money(p.min || 600)}</span></div>)}
        </div>
      </div>}
    </Drawer>
  </div>;
}

function InvTabs({ route, go }) {
  return null; // sub-navigation now lives in the sidebar — no duplicate button strip.
}

function Stock({ go, params }) {
  const [low, setLow] = useState(!!params.low);
  const [branch, setBranch] = useState('');
  const [q, setQ] = useState('');
  const rows = MOCK.stock.filter(s => (!low || s.onHand - s.reserved - s.damaged < s.reorder) && (!branch || s.branch === branch) && (!q || s.name.includes(q) || s.sku.toLowerCase().includes(q.toLowerCase())));
  const avail = s => s.onHand - s.reserved - s.damaged;
  const lowCount = MOCK.stock.filter(s => avail(s) < s.reorder).length;
  const sum = k => MOCK.stock.reduce((a, s) => a + (k === 'avail' ? avail(s) : s[k]), 0);
  return <div className="page-enter">
    <PageHead title="أرصدة المخزون" sub="الكميات الحالية لكل SKU في كل فرع وموقع. لتغيير أي كمية استخدم تسوية أو تحويل."
      actions={<><button className="btn btn-secondary" onClick={() => go('transfers')}><Icon n="swap" s={16} />تحويل</button><button className="btn btn-primary"><Icon n="sliders" s={16} />تسوية جديدة</button></>} />
    <div className="grid ov-kpis" style={{ gridTemplateColumns: 'repeat(4, minmax(0,1fr))', gap: 16, marginBottom: 16 }}>
      <KPI label="المتاح للبيع" icon="box" value={fmt(sum('avail'))} unit="قطعة" foot="عبر كل الفروع" />
      <KPI label="المحجوز" icon="lock" value={fmt(sum('reserved'))} unit="قطعة" foot="لطلبات مفتوحة" />
      <KPI label="قيد النقل" icon="truck" value={fmt(sum('transit'))} unit="قطعة" foot="في تحويلات مشحونة" onClick={() => go('transfers')} />
      <KPI warn label="تحت حد إعادة الطلب" icon="alert" value={fmt(lowCount)} unit="صنف" foot={low ? 'الفلتر مفعّل — اضغط للإلغاء' : 'اضغط لعرضها فقط'} onClick={() => setLow(l => !l)} active={low} />
    </div>
    <Card>
      <div className="toolbar">
        <Search value={q} onChange={setQ} placeholder="اسم الصنف أو SKU" />
        <FilterSelect label="الفرع" value={branch} onChange={setBranch} options={['المخزن الرئيسي', 'فرع المعادي', 'فرع مدينة نصر'].map(b => ({ value: b, label: b }))} />
        <button className="chip" disabled={!branch} style={!branch ? { opacity: .5 } : null}><Icon n="plus" s={13} />الموقع</button>
        <label className="row gap-2 ms-auto fs-13 fw-5" style={{ cursor: 'pointer' }}><Toggle on={low} onChange={setLow} label="المنخفض فقط" />المنخفض فقط</label>
      </div>
      {rows.length === 0 ? <Empty icon="filter" title="لا توجد أرصدة تطابق الفلاتر" body="غيّر الفلاتر أو امسحها لعرض كل الأرصدة." /> :
      <div className="table-wrap"><table className="tbl">
        <thead><tr><th>الصنف</th><th className="hide-mobile">الفرع / الموقع</th><th className="n hide-mobile">الموجود</th><th className="n hide-mobile">محجوز</th><th className="n hide-mobile">تالف</th><th className="n hide-mobile">قيد النقل</th><th className="n">المتاح</th><th style={{ width: 150 }} className="hide-mobile">مقابل حد الطلب</th><th style={{ width: 48 }} /></tr></thead>
        <tbody>{rows.map(s => { const a = avail(s), isLow = a < s.reorder, pct = Math.min(100, a / (s.reorder * 2) * 100); return <tr key={s.sku}>
          <td><div className="fw-5">{s.name}</div><div className="mono t-3 ltr" style={{ display: 'inline-block' }}>{s.sku}</div></td>
          <td className="hide-mobile"><div className="fs-13">{s.branch}</div><div className="fs-12 t-3 mono">{s.loc || 'بدون موقع'}</div></td>
          <td className="n hide-mobile">{fmt(s.onHand)}</td>
          <td className="n t-2 hide-mobile">{s.reserved ? fmt(s.reserved) : '—'}</td>
          <td className={cx('n hide-mobile', s.damaged ? 't-bad' : 't-3')}>{s.damaged ? fmt(s.damaged) : '—'}</td>
          <td className={cx('n hide-mobile', s.transit ? 't-1' : 't-3')}>{s.transit ? fmt(s.transit) : '—'}</td>
          <td className="n"><span className={cx('fw-7 fs-14', isLow && 't-warn')}>{fmt(a)}</span></td>
          <td className="hide-mobile"><div className="row gap-2"><div className="grow" style={{ height: 6, borderRadius: 99, background: 'var(--bg-sunken)', position: 'relative' }}><div style={{ width: `${pct}%`, height: '100%', borderRadius: 99, background: isLow ? 'var(--warn)' : 'var(--ok)' }} /><span style={{ position: 'absolute', insetInlineStart: '50%', top: -3, bottom: -3, width: 2, background: 'var(--text-3)', borderRadius: 2 }} /></div><span className="fs-12 t-3 num">{fmt(s.reorder)}</span></div></td>
          <td><div className="row-act"><Menu trigger={({ toggle }) => <button className="icon-btn sm" onClick={toggle} aria-label="إجراءات"><Icon n="dots" s={16} /></button>} items={[{ label: 'تسوية', icon: 'sliders' }, { label: 'تحويل', icon: 'swap', onClick: () => go('transfers') }, { label: 'سجل الحركات', icon: 'history', onClick: () => go('moves') }]} /></div></td>
        </tr>; })}</tbody>
      </table></div>}
      <Pager page={1} pages={6} total={84} from={1} to={rows.length} onPage={() => {}} />
    </Card>
  </div>;
}

function Transfers({ go }) {
  const [st, setSt] = useState('');
  const [q, setQ] = useState('');
  const rows = MOCK.transfers.filter(t => (!st || t.status === st) && (!q || t.id.includes(q.toUpperCase()) || t.from.includes(q) || t.to.includes(q)));
  const c = k => MOCK.transfers.filter(t => !k || t.status === k).length;
  return <div className="page-enter">
    <PageHead title="تحويلات المخزون" sub="نقل البضاعة بين الفروع والمخازن: مسودة ← اعتماد ← شحن ← استلام." actions={<button className="btn btn-primary"><Icon n="plus" s={16} />تحويل جديد</button>} />
    <Card>
      <div className="tabs" style={{ padding: '0 8px' }}>{[['', 'الكل'], ['draft', 'مسودة'], ['approved', 'معتمد'], ['shipped', 'مشحون'], ['received', 'مستلم'], ['cancelled', 'ملغي']].map(([k, l]) => <button key={k} aria-selected={st === k} onClick={() => setSt(k)}>{l}<span className="tab-count">{fmt(c(k))}</span></button>)}</div>
      <div className="toolbar"><Search value={q} onChange={setQ} placeholder="رقم التحويل أو الفرع" /><FilterSelect label="من" value="" onChange={() => {}} options={[{ value: 'm', label: 'المخزن الرئيسي' }]} /><FilterSelect label="إلى" value="" onChange={() => {}} options={[{ value: 'a', label: 'فرع المعادي' }]} /></div>
      <div className="table-wrap"><table className="tbl">
        <thead><tr><th>التحويل</th><th>المسار</th><th className="n hide-mobile">الأصناف</th><th className="n">الكمية</th><th>الحالة</th><th className="hide-mobile">بواسطة</th><th className="hide-mobile">التاريخ</th></tr></thead>
        <tbody>{rows.map(t => <tr key={t.id} className="clickable" onClick={() => go('transfer', { id: t.id })}>
          <td className="mono fw-6 ltr">{t.id}</td>
          <td><span className="row gap-2 fs-13"><span>{t.from}</span><Icon n="arrowLeft" s={14} className="t-3" /><span className="fw-5">{t.to}</span></span></td>
          <td className="n hide-mobile">{fmt(t.lines)}</td><td className="n fw-6">{fmt(t.qty)}</td>
          <td><Status kind="transfer" s={t.status} /></td>
          <td className="t-2 hide-mobile">{t.by}</td><td className="t-2 fs-12 hide-mobile">{t.date}</td>
        </tr>)}</tbody>
      </table></div>
    </Card>
  </div>;
}

function TransferDetail({ go, params }) {
  const toast = useToast();
  const t = MOCK.transfers.find(x => x.id === params.id) || MOCK.transfers[0];
  const [status, setStatus] = useState(t.status);
  const flow = ['draft', 'approved', 'shipped', 'received'];
  const idx = status === 'cancelled' ? -1 : flow.indexOf(status);
  const next = { draft: ['اعتماد التحويل', 'approved'], approved: ['تأكيد الشحن', 'shipped'], shipped: ['تأكيد الاستلام', 'received'] }[status];
  const lines = MOCK.stock.slice(0, Math.min(6, t.lines));
  return <div className="page-enter">
    <PageHead back={{ label: 'التحويلات', onClick: () => go('transfers') }} title={<span className="row gap-3 wrap"><span className="ltr">{t.id}</span><Status kind="transfer" s={status} /></span>}
      sub={`${t.from} ← ${t.to} · أنشأه ${t.by} في ${t.date}`}
      actions={<>{status !== 'received' && status !== 'cancelled' && <button className="btn btn-ghost" style={{ color: 'var(--bad)' }} onClick={() => setStatus('cancelled')}>إلغاء التحويل</button>}<button className="btn btn-secondary"><Icon n="printer" s={16} />طباعة إذن</button>{next && <button className="btn btn-primary" onClick={() => { setStatus(next[1]); toast('تم: ' + next[0]); }}>{next[0]}</button>}</>} />
    <div className="grid pe-grid" style={{ gridTemplateColumns: 'minmax(0,1fr) 320px', gap: 20, alignItems: 'start' }}>
      <div className="col gap-4">
        <Card pad><Stepper steps={['مسودة', 'معتمد', 'مشحون', 'مستلم']} current={idx === 3 ? 4 : idx} /></Card>
        <Card title="الأصناف" meta={`${fmt(lines.length)} أصناف · ${fmt(t.qty)} قطعة`}>
          <div className="table-wrap"><table className="tbl">
            <thead><tr><th>الصنف</th><th className="hide-mobile">من موقع</th><th className="n">المطلوب</th><th className="n">المشحون</th><th className="n">المستلم</th></tr></thead>
            <tbody>{lines.map((s, i) => { const req = Math.round(t.qty / lines.length); return <tr key={s.sku}>
              <td><div className="fw-5">{s.name}</div><div className="mono t-3 ltr" style={{ display: 'inline-block' }}>{s.sku}</div></td>
              <td className="mono t-2 hide-mobile">{s.loc || '—'}</td>
              <td className="n">{fmt(req)}</td>
              <td className="n">{idx >= 2 ? fmt(req) : <span className="t-3">—</span>}</td>
              <td className="n">{idx >= 3 ? <span className={i === 1 ? 't-bad fw-6' : ''}>{fmt(i === 1 ? req - 2 : req)}</span> : status === 'shipped' ? <input className="input num" defaultValue={req} style={{ width: 80, height: 32, textAlign: 'end' }} /> : <span className="t-3">—</span>}</td>
            </tr>; })}</tbody>
          </table></div>
        </Card>
      </div>
      <div className="col gap-4 pe-side">
        <Card title="السجل">
          <ol style={{ listStyle: 'none', margin: 0, padding: '14px 20px' }} className="col gap-4">
            {[['أُنشئ التحويل', t.by, t.date, 0], ['اعتُمد', 'هبة سعيد', t.date, 1], ['شُحن', 'محمد علي', t.date, 2], ['استُلم', 'أمين مخزن المعادي', '—', 3]].filter(e => e[3] <= Math.max(idx, 0)).map(([a, by, d], i) => <li key={i} className="row gap-3" style={{ alignItems: 'flex-start' }}>
              <span style={{ width: 8, height: 8, borderRadius: 99, background: i === Math.max(idx, 0) ? 'var(--acc)' : 'var(--text-3)', marginTop: 7, flexShrink: 0 }} />
              <div><div className="fs-13 fw-6">{a}</div><div className="fs-12 t-3">{by} · {d}</div></div>
            </li>)}
          </ol>
        </Card>
        <Card pad><div className="fs-12 t-3" style={{ marginBottom: 6 }}>ملاحظات</div><p className="fs-13 t-2">تحويل أسبوعي لتغذية رفوف موسم المدارس.</p></Card>
      </div>
    </div>
  </div>;
}

function Production({ go }) {
  const toast = useToast();
  const [lines, setLines] = useState([{ sku: 'BP-URB-20-BLK', name: 'حقيبة ظهر أوربان — أسود', qty: 200, cost: 340 }, { sku: 'BP-URB-20-NVY', name: 'حقيبة ظهر أوربان — كحلي', qty: 150, cost: 340 }]);
  const total = lines.reduce((a, l) => a + l.qty * l.cost, 0), qty = lines.reduce((a, l) => a + Number(l.qty), 0);
  const upd = (i, k, v) => setLines(ls => ls.map((l, j) => j === i ? { ...l, [k]: Number(v) || 0 } : l));
  return <div className="page-enter">
    <PageHead title="استلام الإنتاج" sub="إدخال المنتجات التامة من المصنع للمخزن مع تسجيل تكلفة تصنيع الوحدة." />
    <div className="grid pe-grid" style={{ gridTemplateColumns: 'minmax(0,1fr) 320px', gap: 20, alignItems: 'start', marginBottom: 20 }}>
      <Card title="دفعة إنتاج جديدة" meta="كل دفعة لها رقم فريد">
        <div className="card-pad col gap-5">
          <div className="grid fg-3" style={{ gridTemplateColumns: 'repeat(3, 1fr)', gap: 16 }}>
            <Field label="رقم الدفعة" req><input className="input ltr mono" style={{ textAlign: 'left' }} defaultValue="PRD-2611-08" /></Field>
            <Field label="مخزن الاستلام" req><select className="input"><option>المخزن الرئيسي</option><option>فرع المعادي</option></select></Field>
            <Field label="الموقع" hint="اختياري"><select className="input"><option>بدون موقع محدد</option><option>A-03</option></select></Field>
          </div>
          <div className="card" style={{ overflow: 'hidden' }}>
            <div className="table-wrap"><table className="tbl">
              <thead><tr><th>الصنف</th><th className="n">الكمية</th><th className="n">تكلفة الوحدة</th><th className="n">الإجمالي</th><th style={{ width: 44 }} /></tr></thead>
              <tbody>{lines.map((l, i) => <tr key={l.sku}>
                <td><div className="fw-5">{l.name}</div><div className="mono t-3 ltr" style={{ display: 'inline-block' }}>{l.sku}</div></td>
                <td className="n"><input className="input num" value={l.qty} onChange={e => upd(i, 'qty', e.target.value)} style={{ width: 84, height: 32, textAlign: 'end' }} inputMode="numeric" /></td>
                <td className="n"><input className="input num" value={l.cost} onChange={e => upd(i, 'cost', e.target.value)} style={{ width: 96, height: 32, textAlign: 'end' }} inputMode="decimal" /></td>
                <td className="n fw-6">{money(l.qty * l.cost)}</td>
                <td><button className="icon-btn sm" aria-label="حذف السطر" onClick={() => setLines(ls => ls.filter((_, j) => j !== i))}><Icon n="trash" s={15} /></button></td>
              </tr>)}</tbody>
            </table></div>
            <div className="row gap-2" style={{ padding: 10, borderTop: '1px solid var(--line)' }}>
              <div className="grow"><Search value="" onChange={() => {}} placeholder="أضف صنفًا بالاسم أو SKU أو امسح الباركود" /></div>
              <button className="btn btn-secondary"><Icon n="plus" s={15} />إضافة</button>
            </div>
          </div>
          <Field label="ملاحظات"><textarea className="input" rows={2} placeholder="اختياري" /></Field>
        </div>
      </Card>
      <div className="pe-side" style={{ position: 'sticky', top: 84 }}>
        <Card pad>
          <div className="fs-14 fw-6" style={{ marginBottom: 14 }}>ملخص الدفعة</div>
          <dl className="col gap-3 fs-13" style={{ margin: 0 }}>
            {[['الأصناف', fmt(lines.length)], ['إجمالي القطع', fmt(qty)], ['متوسط تكلفة القطعة', qty ? money(Math.round(total / qty)) + ' ج.م' : '—']].map(([k, v]) => <div key={k} className="row between"><dt className="t-2">{k}</dt><dd className="fw-6 num" style={{ margin: 0 }}>{v}</dd></div>)}
          </dl>
          <div className="row between" style={{ borderTop: '1px solid var(--line)', marginTop: 16, paddingTop: 16 }}><span className="fs-13 t-2">إجمالي التكلفة</span><span className="fs-20 num fw-6">{money(total)} <span className="fs-12 t-3 fw-5">ج.م</span></span></div>
          <button className="btn btn-accent btn-lg" style={{ width: '100%', marginTop: 16 }} disabled={!lines.length} onClick={() => toast('تم استلام الإنتاج وإضافته للمخزون')}>استلام وإضافة للمخزون</button>
          <p className="fs-12 t-3" style={{ marginTop: 10, textAlign: 'center' }}>سيتم تحديث متوسط تكلفة المنتج تلقائيًا</p>
        </Card>
      </div>
    </div>
    <Card title="الدفعات السابقة">
      <div className="table-wrap"><table className="tbl">
        <thead><tr><th>الدفعة</th><th>المخزن</th><th className="n">الأصناف</th><th className="n">القطع</th><th className="n">التكلفة</th><th className="hide-mobile">بواسطة</th><th>التاريخ</th></tr></thead>
        <tbody>{MOCK.receipts.map(r => <tr key={r.id} className="clickable"><td className="mono fw-6 ltr">{r.batch}</td><td>{r.branch}</td><td className="n">{fmt(r.lines)}</td><td className="n">{fmt(r.qty)}</td><td className="n fw-6">{money(r.cost)}</td><td className="t-2 hide-mobile">{r.by}</td><td className="t-2 fs-12">{r.date}</td></tr>)}</tbody>
      </table></div>
    </Card>
  </div>;
}

function Dealers({ go }) {
  const toast = useToast();
  const [st, setSt] = useState('');
  const [q, setQ] = useState('');
  const [open, setOpen] = useState(null);
  const [tab, setTab] = useState('details');
  const rows = MOCK.dealers.filter(d => (!st || d.status === st) && (!q || d.name.includes(q) || d.company.includes(q) || d.phone.includes(q)));
  const c = k => MOCK.dealers.filter(d => !k || d.status === k).length;
  const TYPES = { wholesale: 'تاجر جملة', distributor: 'موزع' };
  return <div className="page-enter">
    <PageHead title="التجار والموزعون" sub="عملاء الجملة: بياناتهم، اعتماد حساباتهم، وحدود الائتمان." actions={<button className="btn btn-primary" onClick={() => { setOpen({ new: true, name: '', company: '', phone: '', city: '', type: 'wholesale', status: 'pending', balance: 0, limit: 0 }); setTab('details'); }}><Icon n="plus" s={16} />إضافة تاجر</button>} />
    {c('pending') > 0 && <div className="banner b-warn" style={{ marginBottom: 16, alignItems: 'center' }}><Icon n="info" s={18} /><span className="grow"><b>{fmt(c('pending'))} طلبات حسابات</b> بانتظار الاعتماد.</span><button className="btn btn-sm btn-secondary" onClick={() => setSt('pending')}>مراجعة</button></div>}
    <Card>
      <div className="tabs" style={{ padding: '0 8px' }}>{[['', 'الكل'], ['active', 'نشط'], ['pending', 'بانتظار الاعتماد'], ['suspended', 'موقوف']].map(([k, l]) => <button key={k} aria-selected={st === k} onClick={() => setSt(k)}>{l}<span className="tab-count">{fmt(c(k))}</span></button>)}</div>
      <div className="toolbar"><Search value={q} onChange={setQ} placeholder="اسم التاجر أو الشركة أو التليفون" /><FilterSelect label="نوع الحساب" value="" onChange={() => {}} options={Object.entries(TYPES).map(([value, label]) => ({ value, label }))} /><FilterSelect label="المدينة" value="" onChange={() => {}} options={[{ value: 'c', label: 'القاهرة' }]} /></div>
      <div className="table-wrap"><table className="tbl">
        <thead><tr><th>التاجر</th><th className="hide-mobile">النوع</th><th className="hide-mobile">المدينة</th><th>الحالة</th><th style={{ width: 200 }}>الرصيد / حد الائتمان</th><th className="n hide-mobile">الطلبات</th><th style={{ width: 48 }} /></tr></thead>
        <tbody>{rows.map(d => { const pct = d.limit ? Math.min(100, d.balance / d.limit * 100) : 0, over = d.limit && d.balance > d.limit; return <tr key={d.id} className="clickable" onClick={() => { setOpen(d); setTab('details'); }}>
          <td><div className="row gap-3"><span className="avatar">{d.name.slice(0, 1)}</span><div><div className="fw-6">{d.name}</div><div className="fs-12 t-3">{d.company}</div></div></div></td>
          <td className="t-2 hide-mobile">{TYPES[d.type]}</td>
          <td className="t-2 hide-mobile">{d.city}</td>
          <td><Status kind="dealer" s={d.status} /></td>
          <td>{d.limit ? <div className="col gap-1"><div className="row between fs-12"><span className={cx('num fw-6', over && 't-bad')}>{money(d.balance)}</span><span className="num t-3">/ {money(d.limit)}</span></div><div style={{ height: 5, borderRadius: 99, background: 'var(--bg-sunken)' }}><div style={{ width: `${pct}%`, height: '100%', borderRadius: 99, background: over ? 'var(--bad)' : pct > 75 ? 'var(--warn)' : 'var(--text-2)' }} /></div></div> : <span className="t-3 fs-12">لم يُحدد</span>}</td>
          <td className="n hide-mobile">{fmt(d.orders)}</td>
          <td onClick={e => e.stopPropagation()}>{d.status === 'pending' ? <button className="btn btn-sm btn-secondary" onClick={() => toast('تم اعتماد ' + d.name)}>اعتماد</button> : <div className="row-act"><button className="icon-btn sm" aria-label="إجراءات"><Icon n="dots" s={16} /></button></div>}</td>
        </tr>; })}</tbody>
      </table></div>
    </Card>
    <Drawer open={!!open} onClose={() => setOpen(null)} title={open?.new ? 'تاجر جديد' : open?.name} sub={open && !open.new && open.company}
      footer={<><button className="btn btn-primary grow" onClick={() => { setOpen(null); toast('تم حفظ حساب التاجر'); }}>حفظ</button><button className="btn btn-secondary" onClick={() => setOpen(null)}>إلغاء</button></>}>
      {open && <div className="col gap-5">
        {!open.new && <div className="tabs" style={{ margin: '-8px -20px 0', padding: '0 12px' }}><button aria-selected={tab === 'details'} onClick={() => setTab('details')}>البيانات</button><button aria-selected={tab === 'wallet'} onClick={() => setTab('wallet')}>المحفظة والرصيد</button></div>}
        {tab === 'details' ? <>
          <div className="grid fg-2" style={{ gridTemplateColumns: '1fr 1fr', gap: 14 }}>
            <Field label="اسم التاجر" req><input className="input" defaultValue={open.name} /></Field>
            <Field label="رقم التليفون" req><input className="input ltr num" style={{ textAlign: 'left' }} defaultValue={open.phone} inputMode="tel" /></Field>
            <Field label="اسم الشركة" style={{ gridColumn: '1 / -1' }}><input className="input" defaultValue={open.company} /></Field>
            <Field label="الرقم الضريبي"><input className="input ltr num" style={{ textAlign: 'left' }} /></Field>
            <Field label="السجل التجاري"><input className="input ltr num" style={{ textAlign: 'left' }} /></Field>
          </div>
          <div className="sunken col gap-3" style={{ padding: 14 }}>
            <div className="fs-13 fw-6">الائتمان</div>
            <Field label="حد الائتمان" hint="يتطلب صلاحية اعتماد التجار"><div className="input-group"><input className="input num" defaultValue={open.limit} style={{ paddingInlineStart: 12 }} /><span className="suffix">ج.م</span></div></Field>
          </div>
        </> : <div className="col gap-4">
          <div className="grid" style={{ gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <div className="card kpi" style={{ minHeight: 0 }}><div className="kpi-label">الرصيد المستحق</div><div className="kpi-value" style={{ fontSize: 22 }}>{money(open.balance)}<small>ج.م</small></div></div>
            <div className="card kpi" style={{ minHeight: 0 }}><div className="kpi-label">المتاح من الحد</div><div className="kpi-value" style={{ fontSize: 22 }}>{money(Math.max(0, open.limit - open.balance))}<small>ج.م</small></div></div>
          </div>
          <div className="row gap-2"><button className="btn btn-secondary grow"><Icon n="plus" s={15} />تسجيل دفعة</button><button className="btn btn-secondary grow"><Icon n="sliders" s={15} />تسوية رصيد</button></div>
          <div>{[['دفعة نقدية', -12000, '٢ نوفمبر'], ['طلب ORD-24816', 28400, 'اليوم'], ['طلب ORD-24790', 12000, '٢٨ أكتوبر']].map(([l, v, d]) => <div key={l} className="row between" style={{ padding: '12px 0', borderBottom: '1px solid var(--line)' }}><div><div className="fs-13 fw-5">{l}</div><div className="fs-12 t-3">{d}</div></div><span className={cx('num fw-6', v < 0 ? 't-ok' : '')}>{v < 0 ? '−' : '+'}{money(Math.abs(v))}</span></div>)}</div>
        </div>}
      </div>}
    </Drawer>
  </div>;
}

function PosCount({ go }) {
  const toast = useToast();
  const [session, setSession] = useState('14');
  const [barcode, setBarcode] = useState('');
  const [words, setWords] = useState('');
  const [listening, setListening] = useState(false);
  const [log, setLog] = useState([{ sku: 'WL-CLS-01-BRN', qty: 22, t: '١١:٤٠' }, { sku: 'BP-URB-20-BLK', qty: 8, t: '١١:٣٨' }]);
  const can = session && barcode && words;
  const submit = e => { e.preventDefault(); if (!can) return; setLog(l => [{ sku: barcode.toUpperCase(), qty: Number(words.replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))) || 0, t: 'الآن' }, ...l]); setBarcode(''); setWords(''); toast('تم تسجيل المسحة'); };
  return <div className="page-enter" style={{ maxWidth: 880, margin: '0 auto' }}>
    <PageHead title="عدّ الجرد" sub="امسح الباركود، ثم قل العدد أو اكتبه، وراجع قبل التسجيل." actions={<span className="badge b-ok" style={{ height: 30, padding: '0 12px' }}><span className="dot" />جلسة #{fmt(Number(session) || 0)} مفتوحة</span>} />
    <div className="grid pe-grid" style={{ gridTemplateColumns: 'minmax(0,1fr) 300px', gap: 20, alignItems: 'start' }}>
      <Card>
        <form onSubmit={submit} className="card-pad col gap-5">
          <Field label="رقم جلسة الجرد" req><input className="input num" value={session} onChange={e => setSession(e.target.value)} inputMode="numeric" style={{ height: 48, fontSize: 16 }} /></Field>
          <Field label="الباركود" req hint="الماسح الضوئي يكتب هنا تلقائيًا"><div className="input-group"><Icon n="barcode" s={20} /><input className="input mono ltr" autoFocus value={barcode} onChange={e => setBarcode(e.target.value)} style={{ height: 56, fontSize: 18, textAlign: 'left', paddingInlineStart: 42 }} placeholder="امسح أو اكتب" /></div></Field>
          <Field label="العدد" req hint="مثال: «اتنين وعشرين» أو ٢٢">
            <div className="row gap-2">
              <input className="input num grow" value={words} onChange={e => setWords(e.target.value)} style={{ height: 56, fontSize: 20 }} placeholder="٠" />
              <button type="button" disabled={!barcode} onClick={() => { setListening(true); setTimeout(() => { setWords('٢٢'); setListening(false); }, 1400); }} className={cx('btn btn-lg', listening ? 'btn-accent' : 'btn-secondary')} style={{ height: 56, minWidth: 140 }}>
                <Icon n="mic" s={20} />{listening ? 'أستمع…' : 'قل العدد'}
              </button>
            </div>
          </Field>
          <button type="submit" className="btn btn-primary btn-lg" style={{ height: 56, fontSize: 15 }} disabled={!can || listening}><Icon n="check" s={18} />تسجيل المسحة</button>
        </form>
      </Card>
      <Card title="آخر المسحات" meta={fmt(log.length)}>
        <ul style={{ listStyle: 'none', margin: 0, padding: '4px 0' }}>{log.map((l, i) => <li key={i} className="row gap-3" style={{ padding: '10px 20px', borderBottom: '1px solid var(--line)' }}><span className="mono ltr grow truncate fs-12">{l.sku}</span><span className="num fw-7 fs-16">{fmt(l.qty)}</span><span className="fs-12 t-3" style={{ minWidth: 40, textAlign: 'end' }}>{l.t}</span></li>)}</ul>
      </Card>
    </div>
  </div>;
}

Object.assign(window, { Orders, Stock, Transfers, TransferDetail, Production, Dealers, PosCount });
