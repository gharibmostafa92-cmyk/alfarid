// Overview — re-architected around "what needs my attention now".
function SalesChart({ data }) {
  const [hover, setHover] = useState(null);
  const max = Math.max(...data.map(d => d.net + d.disc));
  const ticks = [1, .75, .5, .25, 0];
  const nice = Math.ceil(max / 10000) * 10000;
  return <div className="col gap-3" style={{ height: '100%' }}>
    <div className="grid" style={{ gridTemplateColumns: 'auto 1fr', gap: 12, flex: 1, minHeight: 220 }}>
      <div className="col between fs-12 t-3 num" style={{ paddingBottom: 26, textAlign: 'end' }}>{ticks.map(t => <span key={t}>{fmt(nice * t / 1000)} ألف</span>)}</div>
      <div className="grid" style={{ gridTemplateColumns: `repeat(${data.length}, 1fr)`, position: 'relative' }}>
        <div style={{ position: 'absolute', inset: '0 0 26px 0', background: 'repeating-linear-gradient(to top, var(--line) 0 1px, transparent 1px 25%)', pointerEvents: 'none' }} />
        {data.map((d, i) => {
          const last = i === data.length - 1;
          return <div key={i} className="col" style={{ alignItems: 'center', position: 'relative' }} onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)}>
            {hover === i && <div style={{ position: 'absolute', bottom: `calc(${(d.net + d.disc) / nice * 100}% * .86 + 40px)`, zIndex: 3, background: 'var(--bg-ink)', color: 'var(--on-ink)', padding: '8px 10px', borderRadius: 10, fontSize: 12, whiteSpace: 'nowrap', boxShadow: 'var(--sh-pop)' }}>
              <div className="fw-6">{d.d} نوفمبر</div>
              <div className="num">صافي {money(d.net)} ج.م</div>
              <div className="num" style={{ opacity: .7 }}>خصومات {money(d.disc)}</div>
            </div>}
            <div className="col" style={{ flex: 1, width: '100%', alignItems: 'center', justifyContent: 'flex-end', gap: 3, paddingBottom: 8 }}>
              <span className="hatch" style={{ width: 'min(60%, 28px)', height: `${d.disc / nice * 100}%`, borderRadius: 6, opacity: hover === null || hover === i ? 1 : .45, transition: 'opacity .15s' }} />
              <span style={{ width: 'min(60%, 28px)', height: `${d.net / nice * 100}%`, borderRadius: 6, background: last ? 'var(--acc)' : 'var(--bg-ink)', opacity: hover === null || hover === i ? 1 : .45, transition: 'opacity .15s' }} />
            </div>
            <span className={cx('fs-12 num', last ? 't-1 fw-6' : 't-3')} style={{ height: 18 }}>{last ? 'اليوم' : d.d}</span>
          </div>;
        })}
      </div>
    </div>
  </div>;
}

function Overview({ go }) {
  const [period, setPeriod] = useState('today');
  const [rank, setRank] = useState('products');
  const today = { total: 34900, orders: 18, channels: { store: 14200, wholesale: 17400, pos: 3300 } };
  const month = { total: 363900, orders: 412, channels: { store: 128600, wholesale: 201400, pos: 33900 } };
  const s = period === 'today' ? today : month;
  const chTotal = Object.values(s.channels).reduce((a, b) => a + b, 0);
  const chColors = { store: 'var(--acc)', wholesale: 'var(--bg-ink)', pos: 'var(--text-3)' };
  const top = rank === 'products'
    ? [['حقيبة ظهر أوربان ٢٠ لتر', 'BP-URB-20', 128, 112400], ['شنطة لابتوب بيزنس', 'LB-BUS-156', 74, 86200], ['محفظة جلد كلاسيك', 'WL-CLS-01', 196, 54900], ['شنطة مدرسية كيدز برو', 'SB-KDP-01', 61, 37800], ['حقيبة كتف ليا', 'WB-LEA-02', 42, 23500]]
    : [['المخزن الرئيسي (جملة)', null, 96, 201400], ['فرع المعادي', null, 188, 74100], ['فرع مدينة نصر', null, 128, 54500], ['المتجر الإلكتروني', null, 140, 33900]];
  const topMax = Math.max(...top.map(r => r[3]));
  const hour = 11;
  return <div className="page-enter">
    <div className="page-head">
      <div className="grow">
        <h1>صباح الخير، محمد</h1>
        <p>الخميس ٦ نوفمبر · آخر تحديث منذ دقيقتين</p>
      </div>
      <div className="page-actions">
        <Seg value={period} onChange={setPeriod} options={[{ value: 'today', label: 'اليوم' }, { value: 'month', label: 'هذا الشهر' }]} />
        <button className="btn btn-secondary btn-sm" style={{ height: 38 }}><Icon n="refresh" s={15} />تحديث</button>
      </div>
    </div>

    {/* Row 1 — KPIs: one hero, three supporting; all clickable to their source */}
    <div className="grid ov-kpis" style={{ gridTemplateColumns: 'repeat(4, minmax(0,1fr))', gap: 16, marginBottom: 16 }}>
      <KPI hero label={period === 'today' ? 'مبيعات اليوم' : 'مبيعات الشهر'} icon="wallet" value={money(s.total)} unit="ج.م" delta={period === 'today' ? 12 : 8} foot={period === 'today' ? 'مقارنة بنفس اليوم الأسبوع الماضي' : 'مقارنة بالشهر الماضي'} />
      <KPI label="طلبات جديدة" icon="orders" value={fmt(12)} foot={<a className="row gap-1 t-acc fw-5" href="#" onClick={e => { e.preventDefault(); go('orders'); }}>بانتظار التجهيز <Icon n="arrowLeft" s={13} /></a>} onClick={() => go('orders')} />
      <KPI warn label="مخزون منخفض" icon="alert" value={fmt(7)} unit="صنف" foot={<span>تحت حد إعادة الطلب</span>} onClick={() => go('stock', { low: 1 })} />
      <KPI label="طلبات حسابات جملة" icon="dealer" value={fmt(2)} foot={<span>بانتظار الاعتماد</span>} onClick={() => go('dealers')} />
    </div>

    {/* Row 2 — chart gets the width it needs; channel split beside it */}
    <div className="grid ov-row2" style={{ gridTemplateColumns: 'minmax(0, 2fr) minmax(0, 1fr)', gap: 16, marginBottom: 16 }}>
      <Card title="حركة المبيعات" meta="آخر ٨ أيام" actions={<div className="row gap-4 fs-12 t-2">
        <span className="row gap-2"><span style={{ width: 10, height: 10, borderRadius: 3, background: 'var(--bg-ink)' }} />صافي المبيعات</span>
        <span className="row gap-2"><span className="hatch" style={{ width: 10, height: 10, borderRadius: 3 }} />الخصومات</span>
      </div>}>
        <div style={{ padding: '20px 20px 16px', height: 300 }}><SalesChart data={MOCK.sales8} /></div>
      </Card>

      <Card title="قنوات البيع" meta={period === 'today' ? 'اليوم' : 'هذا الشهر'}>
        <div className="card-pad col gap-5">
          <div>
            <div className="row" style={{ height: 10, gap: 3, borderRadius: 99, overflow: 'hidden' }}>
              {Object.entries(s.channels).map(([k, v]) => <span key={k} style={{ width: `${v / chTotal * 100}%`, height: '100%', background: chColors[k], borderRadius: 3 }} />)}
            </div>
          </div>
          <ul className="col gap-4" style={{ listStyle: 'none', margin: 0, padding: 0 }}>
            {Object.entries(s.channels).map(([k, v]) => <li key={k} className="row gap-3">
              <span style={{ width: 10, height: 10, borderRadius: 3, background: chColors[k], flexShrink: 0 }} />
              <span className="grow fs-13">{CHANNEL[k]}</span>
              <span className="fs-12 t-3 num">{fmt(Math.round(v / chTotal * 100))}٪</span>
              <span className="fs-14 fw-6 num" style={{ minWidth: 92, textAlign: 'end' }}>{money(v)}</span>
            </li>)}
          </ul>
          <div className="sunken row between" style={{ padding: '12px 14px' }}>
            <span className="fs-13 t-2">متوسط قيمة الطلب</span>
            <span className="fs-16 fw-6 num">{money(Math.round(s.total / s.orders))} <span className="fs-12 t-3 fw-5">ج.م</span></span>
          </div>
        </div>
      </Card>
    </div>

    {/* Row 3 — ranking + needs attention */}
    <div className="grid ov-row2" style={{ gridTemplateColumns: 'minmax(0, 2fr) minmax(0, 1fr)', gap: 16 }}>
      <Card title={rank === 'products' ? 'الأكثر مبيعًا' : 'أداء الفروع'} meta="هذا الشهر" actions={<Seg value={rank} onChange={setRank} options={[{ value: 'products', label: 'المنتجات' }, { value: 'branches', label: 'الفروع' }]} />}>
        <div className="table-wrap"><table className="tbl">
          <thead><tr><th style={{ width: 36 }}>#</th><th>{rank === 'products' ? 'المنتج' : 'الفرع'}</th><th className="n">{rank === 'products' ? 'الوحدات' : 'العمليات'}</th><th style={{ width: '32%' }}>المبيعات</th></tr></thead>
          <tbody>{top.map(([name, code, q, rev], i) => <tr key={name}>
            <td className="t-3 num">{fmt(i + 1)}</td>
            <td><div className="fw-5">{name}</div>{code && <div className="mono t-3 ltr" style={{ display: 'inline-block' }}>{code}</div>}</td>
            <td className="n">{fmt(q)}</td>
            <td><div className="row gap-3"><div className="grow" style={{ height: 6, borderRadius: 99, background: 'var(--bg-sunken)' }}><div style={{ width: `${rev / topMax * 100}%`, height: '100%', borderRadius: 99, background: i === 0 ? 'var(--acc)' : 'var(--text-2)' }} /></div><span className="num fw-6 fs-13" style={{ minWidth: 72, textAlign: 'end' }}>{money(rev)}</span></div></td>
          </tr>)}</tbody>
        </table></div>
      </Card>

      <Card title="يحتاج انتباهك" meta="٤ عناصر">
        <ul style={{ listStyle: 'none', margin: 0, padding: 6 }}>
          {[
            ['alert', 'warn', '٧ أصناف تحت حد إعادة الطلب', 'أقلها: حقيبة جيم ٣٥ لتر (٤ قطع)', () => go('stock', { low: 1 })],
            ['truck', 'info', 'تحويل TR-0192 في الطريق', 'إلى فرع المعادي · ١٢٠ قطعة', () => go('transfer', { id: 'TR-0192' })],
            ['dealer', 'acc', 'طلبا حسابات جملة جديدة', 'محلات الفاروق، ستايل ستور', () => go('dealers')],
            ['box', 'neutral', 'منتجان مسودة بدون أسعار', 'حقيبة سفر كابينة، هايكنج ٤٠ لتر', () => go('products', { status: 'draft' })],
          ].map(([ic, tone, t, sub, fn], i) => <li key={i}><button onClick={fn} className="row gap-3" style={{ width: '100%', padding: '12px 14px', borderRadius: 12, textAlign: 'start' }} onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-sunken)'} onMouseLeave={e => e.currentTarget.style.background = ''}>
            <span className={cx('kpi-icon', 'b-' + tone)} style={{ flexShrink: 0 }}><Icon n={ic} s={16} /></span>
            <span className="grow" style={{ minWidth: 0 }}><span className="fs-13 fw-6" style={{ display: 'block' }}>{t}</span><span className="fs-12 t-3 truncate" style={{ display: 'block' }}>{sub}</span></span>
            <Icon n="chevLeft" s={16} className="t-3" />
          </button></li>)}
        </ul>
      </Card>
    </div>
  </div>;
}
window.Overview = Overview;
