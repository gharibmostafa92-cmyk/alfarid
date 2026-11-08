// Screens 8, 9, 10 — Branch stock, Transfers, Stocktake
function StockPage() {
  const [q, setQ] = useState('');
  const [low, setLow] = useState(false);
  const [cat, setCat] = useState('all');
  const list = PRODUCTS.filter(p => (!q || p.name.includes(q) || p.sku.toLowerCase().includes(q.toLowerCase()) || p.barcode.includes(q)) && (!low || p.stock <= p.reorder) && (cat === 'all' || p.cat === cat));
  const lowCount = PRODUCTS.filter(p => p.stock <= p.reorder).length;
  const outCount = PRODUCTS.filter(p => p.stock === 0).length;
  return <div className="page" data-screen-label="08 Branch Stock"><div className="page-in page-enter">
    <div className="page-title">
      <div><h1>مخزون الفرع</h1><p>أرصدة {BRANCH.name} لحظيًا — بدون مغادرة نقطة البيع.</p></div>
      <Api path={`/pos/stock?q=${q || '…'}&low_stock=${low}&per_page=20`} />
    </div>
    <div className="grid" style={{ gridTemplateColumns: 'repeat(3, 1fr)', gap: 12, marginBottom: 16 }}>
      <KPI label="أصناف بالفرع" value={fmt(PRODUCTS.length)} icon="box" foot={<>إجمالي <b className="num t-1">{fmt(PRODUCTS.reduce((s, p) => s + p.stock, 0))}</b> قطعة</>} />
      <KPI label="منخفضة عن حد الطلب" value={fmt(lowCount)} icon="alert" warn onClick={() => setLow(!low)} active={low} foot="اضغط للفلترة" />
      <KPI label="نفدت من الفرع" value={fmt(outCount)} icon="x" foot="اطلب تحويل من فرع آخر" />
    </div>
    <div className="card" style={{ overflow: 'hidden' }}>
      <div className="toolbar">
        <div className="input-group" style={{ width: 360 }}><Icon n="barcode" s={16} /><input className="input" autoFocus value={q} onChange={e => setQ(e.target.value)} placeholder="SKU أو الاسم أو الباركود" /></div>
        <button className={cx('chip', low && 'on')} onClick={() => setLow(!low)}><Icon n="alert" s={13} />الأصناف المنخفضة<span className="count">{fmt(lowCount)}</span></button>
        <FilterSelect label="الفئة" value={cat === 'all' ? '' : cat} options={CATS.slice(1).map(c => ({ value: c.id, label: c.name }))} onChange={v => setCat(v || 'all')} />
        <span className="ms-auto fs-12 t-3">{fmt(list.length)} صنف</span>
      </div>
      <div className="table-wrap"><table className="tbl">
        <thead><tr><th>الصنف</th><th>الكود</th><th>الباركود</th><th className="n">السعر</th><th>المستوى</th><th className="n">الرصيد المتاح</th><th className="n">حد إعادة الطلب</th><th></th></tr></thead>
        <tbody>{list.map(p => {
          const st = p.stock === 0 ? 'out' : p.stock <= p.reorder ? 'low' : 'ok';
          const pct = Math.min(100, p.stock / (p.reorder * 3) * 100);
          return <tr key={p.id}>
            <td><div className="row gap-3"><PImg p={p} size="sm" /><span className="fw-5">{p.name}</span></div></td>
            <td><span className="mono">{p.sku}</span></td>
            <td><span className="mono t-3">{p.barcode}</span></td>
            <td className="n">{egp(p.price)}</td>
            <td><div className="lvl"><span style={{ width: pct + '%', background: st === 'ok' ? 'var(--ok)' : st === 'low' ? 'var(--warn)' : 'var(--bad)' }} /><i style={{ insetInlineStart: (100 / 3) + '%' }} /></div></td>
            <td className="n"><span className={cx('fs-16 fw-6', st === 'out' ? 't-bad' : st === 'low' ? 't-warn' : '')}>{fmt(p.stock)}</span></td>
            <td className="n t-2">{fmt(p.reorder)}</td>
            <td>{st === 'out' ? <span className="badge b-bad"><span className="dot" />نفد</span> : st === 'low' ? <span className="badge b-warn"><span className="dot" />منخفض</span> : <span className="badge b-ok"><span className="dot" />متوفر</span>}</td>
          </tr>;
        })}</tbody>
      </table></div>
      {list.length === 0 && <Empty icon="search" title="لا توجد نتائج" body="جرّب كلمة بحث مختلفة أو أزل فلتر المنخفضة." />}
    </div>
  </div></div>;
}

function TransfersPage({ toast }) {
  const [tab, setTab] = useState('receive');
  return <div className="page" data-screen-label="09 Transfers"><div className="page-in page-enter">
    <div className="page-title">
      <div><h1>التحويلات بين الفروع</h1><p>اطلب بضاعة من المخزن أو فرع آخر، واستلم الشحنات الواردة لـ{BRANCH.name}.</p></div>
    </div>
    <div className="tabs" style={{ marginBottom: 18 }}>
      <button aria-selected={tab === 'receive'} onClick={() => setTab('receive')}><Icon n="truck" s={16} />استلام شحنة واردة<span className="tab-count">{fmt(TRANSFERS_IN.length)}</span></button>
      <button aria-selected={tab === 'request'} onClick={() => setTab('request')}><Icon n="plus" s={16} />طلب تحويل وارد للفرع</button>
    </div>
    {tab === 'receive' ? <ReceiveTab toast={toast} /> : <RequestTab toast={toast} />}
  </div></div>;
}

function ReceiveTab({ toast }) {
  const [list, setList] = useState(TRANSFERS_IN);
  const [cur, setCur] = useState(TRANSFERS_IN[0].id);
  const t = list.find(x => x.id === cur);
  const [rec, setRec] = useState({});
  useEffect(() => { if (t) setRec(Object.fromEntries(t.items.map(i => [i.id, String(i.qty)]))); }, [cur]);
  if (!t) return <div className="card"><Empty icon="truck" title="لا توجد شحنات واردة" body="كل التحويلات المشحونة للفرع تم استلامها." /></div>;
  const shipped = t.items.reduce((s, i) => s + i.qty, 0);
  const got = t.items.reduce((s, i) => s + Number(rec[i.id] || 0), 0);
  const diffs = t.items.filter(i => Number(rec[i.id] || 0) !== i.qty).length;
  return <div className="grid" style={{ gridTemplateColumns: '320px minmax(0, 1fr)', gap: 16, alignItems: 'start' }}>
    <div className="tr-list">
      {list.map(x => <button key={x.id} className="tr-item" aria-pressed={x.id === cur} onClick={() => setCur(x.id)}>
        <div className="row between"><b className="mono" style={{ fontSize: 12.5 }}>{x.number}</b><Status kind="transfer" s="shipped" /></div>
        <div className="route"><Icon n={x.from.includes('المخزن') ? 'warehouse' : 'store'} s={15} className="t-3" /><span className="truncate" style={{ maxWidth: 110 }}>{x.from}</span><span className="ln" /><Icon n="store" s={15} className="t-acc" /><span>فرعنا</span></div>
        <div className="row between fs-12 t-3"><span>{x.shipped}</span><span className="num">{fmt(x.items.reduce((s, i) => s + i.qty, 0))} قطعة · {fmt(x.items.length)} أصناف</span></div>
      </button>)}
    </div>
    <div className="card" style={{ overflow: 'hidden' }}>
      <div className="card-head">
        <div className="col"><h3>{t.number}</h3><span className="fs-12 t-3">من {t.from} · السائق {t.driver}</span></div>
        <button className="btn btn-secondary btn-sm" onClick={() => setRec(Object.fromEntries(t.items.map(i => [i.id, String(i.qty)])))}><Icon n="check" s={14} />الكل مطابق</button>
      </div>
      <table className="tbl">
        <thead><tr><th>الصنف</th><th className="n">المشحون</th><th style={{ textAlign: 'center' }}>المستلم فعليًا</th><th className="n">الفرق</th><th className="n">رصيد الفرع بعد</th></tr></thead>
        <tbody>{t.items.map(i => {
          const p = pById(i.id); const r = Number(rec[i.id] || 0); const d = r - i.qty;
          return <tr key={i.id} className={d !== 0 ? 'selected' : ''}>
            <td><div className="row gap-3"><PImg p={p} size="xs" /><div><div className="fw-5">{p.name}</div><div className="mono t-3" style={{ fontSize: 11 }}>{p.sku}</div></div></div></td>
            <td className="n fw-6">{fmt(i.qty)}</td>
            <td style={{ textAlign: 'center' }}><input className={cx('qty-input', d !== 0 && 'bad')} value={rec[i.id] ?? ''} onChange={e => setRec({ ...rec, [i.id]: toAsciiNum(e.target.value) })} /></td>
            <td className="n">{d === 0 ? <span className="t-ok"><Icon n="check" s={15} w={2.4} /></span> : <span className="badge b-bad num">{d > 0 ? '+' : '−'}{fmt(Math.abs(d))}</span>}</td>
            <td className="n"><span className="t-3 num">{fmt(p.stock)}</span> <Icon n="arrowLeft" s={12} className="t-3" /> <b className="num">{fmt(p.stock + r)}</b></td>
          </tr>;
        })}</tbody>
      </table>
      <div className="row gap-3" style={{ padding: '14px 16px', borderTop: '1px solid var(--line)', background: 'var(--bg-sunken)' }}>
        <div className="grow col gap-1">
          <div className="fs-13"><b className="num">{fmt(got)}</b> من <b className="num">{fmt(shipped)}</b> قطعة {diffs > 0 && <span className="badge b-bad" style={{ marginInlineStart: 6 }}>{fmt(diffs)} صنف به فرق</span>}</div>
          <div className="row gap-2"><Api m="POST" path={`/pos/transfers/${t.id}/receive`} /><span className="perm"><Icon n="shield" s={11} />transfers.receive</span></div>
        </div>
        <button className="btn btn-accent btn-lg" onClick={() => { toast(`تم استلام ${t.number} ورفع رصيد الفرع`); const rest = list.filter(x => x.id !== t.id); setList(rest); setCur(rest[0]?.id); }}><Icon n="check" s={16} w={2.4} />تأكيد الاستلام ورفع الرصيد</button>
      </div>
    </div>
  </div>;
}

function RequestTab({ toast }) {
  const [src, setSrc] = useState(1);
  const [lines, setLines] = useState([{ id: 106, qty: 8 }, { id: 112, qty: 10 }]);
  const [q, setQ] = useState('');
  const [note, setNote] = useState('');
  const res = q ? PRODUCTS.filter(p => p.name.includes(q) || p.sku.toLowerCase().includes(q.toLowerCase())).slice(0, 5) : [];
  const suggestions = PRODUCTS.filter(p => p.stock <= p.reorder && !lines.find(l => l.id === p.id));
  const add = (p, qty = 1) => setLines(l => l.find(x => x.id === p.id) ? l : [...l, { id: p.id, qty }]);
  return <div className="grid" style={{ gridTemplateColumns: 'minmax(0, 1fr) 340px', gap: 16, alignItems: 'start' }}>
    <div className="card" style={{ overflow: 'visible' }}>
      <div className="card-head"><h3>الأصناف المطلوبة</h3><span className="meta">{fmt(lines.length)} أصناف · {fmt(lines.reduce((s, l) => s + l.qty, 0))} قطعة</span></div>
      <div style={{ padding: 16, position: 'relative' }}>
        <Search value={q} onChange={setQ} placeholder="أضف صنفًا بالاسم أو الكود…" />
        {res.length > 0 && <div className="scan-results" style={{ insetInline: 16 }}>{res.map(p => <button key={p.id} className="scan-row" onClick={() => { add(p); setQ(''); }}><PImg p={p} size="xs" /><span className="grow fs-13">{p.name}</span><span className="badge b-neutral">رصيدنا {fmt(p.stock)}</span></button>)}</div>}
      </div>
      <table className="tbl">
        <thead><tr><th>الصنف</th><th className="n">رصيدنا</th><th className="n">حد الطلب</th><th style={{ textAlign: 'center' }}>الكمية المطلوبة</th><th></th></tr></thead>
        <tbody>{lines.map(l => { const p = pById(l.id); return <tr key={l.id}>
          <td><div className="row gap-3"><PImg p={p} size="xs" /><div><div className="fw-5">{p.name}</div><div className="mono t-3" style={{ fontSize: 11 }}>{p.sku}</div></div></div></td>
          <td className={cx('n', p.stock <= p.reorder && 't-warn fw-6')}>{fmt(p.stock)}</td>
          <td className="n t-2">{fmt(p.reorder)}</td>
          <td style={{ textAlign: 'center' }}><div className="stepper-q"><button onClick={() => setLines(x => x.map(y => y.id === l.id ? { ...y, qty: y.qty + 1 } : y))}><Icon n="plus" s={13} /></button><span>{fmt(l.qty)}</span><button onClick={() => setLines(x => x.map(y => y.id === l.id ? { ...y, qty: Math.max(1, y.qty - 1) } : y))}><Icon n="minus" s={13} /></button></div></td>
          <td><button className="icon-btn sm" onClick={() => setLines(x => x.filter(y => y.id !== l.id))}><Icon n="trash" s={15} /></button></td>
        </tr>; })}</tbody>
      </table>
      {lines.length === 0 && <Empty icon="box" title="لم تتم إضافة أصناف" body="ابحث عن صنف أو اختر من المقترحات." />}
      {suggestions.length > 0 && <div style={{ padding: 16, borderTop: '1px solid var(--line)' }}>
        <div className="section-label row gap-2" style={{ marginBottom: 10 }}><Icon n="sparkle" s={13} className="t-acc" />مقترح — أصناف تحت حد الطلب</div>
        <div className="row gap-2 wrap">{suggestions.map(p => <button key={p.id} className="chip" onClick={() => add(p, Math.max(1, p.reorder * 2 - p.stock))}><Icon n="plus" s={12} />{p.name.split('—')[0]}<span className="count">{fmt(p.stock)}</span></button>)}</div>
      </div>}
    </div>
    <div className="card card-pad col gap-4" style={{ position: 'sticky', top: 0 }}>
      <div className="col gap-2"><span className="fs-13 fw-5">الفرع المصدر</span>
        <div className="col gap-2">{SOURCES.map(s => <button key={s.id} role="radio" aria-checked={src === s.id} onClick={() => setSrc(s.id)} className="row gap-3" style={{ padding: '10px 12px', borderRadius: 12, border: '1.5px solid ' + (src === s.id ? 'var(--acc)' : 'var(--line-strong)'), background: src === s.id ? 'var(--acc-tint)' : 'var(--bg-card)', textAlign: 'start' }}>
          <span className="radio-dot" /><Icon n={s.kind} s={17} className="t-3" /><span className="fs-13 fw-5 grow">{s.name}</span>
        </button>)}</div>
      </div>
      <div className="route" style={{ padding: '10px 12px', borderRadius: 12, background: 'var(--bg-sunken)' }}><Icon n="warehouse" s={15} /><span className="truncate">{SOURCES.find(s => s.id === src).name}</span><span className="ln" /><Icon n="store" s={15} className="t-acc" />{BRANCH.name}</div>
      <Field label={<>ملاحظة <span className="t-3 fs-12">(اختياري)</span></>}><textarea className="input" rows={2} value={note} onChange={e => setNote(e.target.value)} placeholder="مثال: مطلوب قبل الويك إند" /></Field>
      <button className="btn btn-accent btn-lg" disabled={!lines.length} onClick={() => { toast('تم إرسال طلب التحويل للاعتماد'); setLines([]); }}><Icon n="arrowLeft" s={16} />إرسال طلب التحويل</button>
      <div className="col gap-1"><Api m="POST" path="/pos/transfer-requests" /><span className="perm"><Icon n="shield" s={11} />transfers.create · Idempotency-Key</span></div>
    </div>
  </div>;
}

function StocktakePage({ toast }) {
  const SCOPE = PRODUCTS.slice(0, 12);
  const [counts, setCounts] = useState({ 101: 14, 103: 21, 107: 41, 111: 85 });
  const [code, setCode] = useState('');
  const [qty, setQty] = useState('1');
  const [listening, setListening] = useState(false);
  const [heard, setHeard] = useState('');
  const [last, setLast] = useState({ id: 111, qty: 85, via: 'voice', words: 'خمسة وتمانين' });
  const [log, setLog] = useState([{ id: 111, add: 85, via: 'voice', words: 'خمسة وتمانين', t: '٠٣:٤٢' }, { id: 107, add: 41, via: 'barcode', t: '٠٣:٣٨' }, { id: 103, add: 21, via: 'barcode', t: '٠٣:٣١' }, { id: 101, add: 14, via: 'barcode', t: '٠٣:٢٥' }]);
  const counted = Object.keys(counts).length;
  const scan = (p, n, via, words) => {
    setCounts(c => ({ ...c, [p.id]: (c[p.id] || 0) + n }));
    setLast({ id: p.id, qty: (counts[p.id] || 0) + n, via, words });
    setLog(l => [{ id: p.id, add: n, via, words, t: new Date().toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' }).replace(/\s?[صم]$/, '') }, ...l].slice(0, 8));
  };
  const submitCode = () => { const p = PRODUCTS.find(x => x.barcode === code.trim() || x.sku.toLowerCase() === code.trim().toLowerCase()) || SCOPE[4]; scan(p, Number(qty || 1), 'barcode'); setCode(''); setQty('1'); };
  const mic = () => {
    if (listening) return;
    setListening(true); setHeard('');
    const words = 'اتناشر'; let i = 0;
    const iv = setInterval(() => { i++; setHeard(words.slice(0, i)); if (i >= words.length) { clearInterval(iv); setTimeout(() => { setListening(false); scan(pById(105), 12, 'voice', words); toast('سُجّل ١٢ — باك باك لابتوب'); }, 500); } }, 140);
  };
  const lp = pById(last.id);
  return <div className="page" data-screen-label="10 Stocktake"><div className="page-in page-enter">
    <div className="page-title">
      <div><div className="row gap-2" style={{ marginBottom: 4 }}><span className="badge b-acc"><span className="dot" />جرد جارٍ</span><span className="mono t-3">STK-NSR-0019</span></div><h1>جرد الفرع</h1><p>امسح الباركود أو قل الكمية بصوتك — بالعامية المصرية.</p></div>
      <div className="col gap-2" style={{ minWidth: 280 }}>
        <div className="row between fs-13"><span className="t-2">تقدم الجرد</span><b className="num">{fmt(counted)} / {fmt(SCOPE.length)} صنف</b></div>
        <div className="progress"><span style={{ width: counted / SCOPE.length * 100 + '%' }} /></div>
      </div>
    </div>
    <div className="st-grid">
      <div className="col gap-4">
        <div className="card card-pad row gap-6" style={{ alignItems: 'center' }}>
          <button className={cx('mic', listening && 'on')} onClick={mic} aria-label="الإدخال الصوتي"><Icon n="mic" s={44} w={1.6} /></button>
          <div className="grow col gap-3">
            <div className="fs-13 t-2">{listening ? 'بسمعك… قول الكمية' : 'اضغط الميكروفون وقل الكمية للصنف الحالي'}</div>
            <div className="row gap-3" style={{ minHeight: 44 }}>
              {listening ? <div className="wave">{Array.from({ length: 14 }).map((_, i) => <i key={i} style={{ animationDelay: i * 0.06 + 's' }} />)}</div> : null}
              <span className="display" style={{ fontSize: 30, fontWeight: 500 }}>{heard ? `«${heard}»` : <span className="t-3 fs-20">مثال: «اتناشر» ، «خمسة وعشرين»</span>}</span>
            </div>
            <div className="divider" style={{ height: 1, background: 'var(--line)' }} />
            <div className="row gap-2">
              <div className="scanbar grow" style={{ height: 48 }}>
                <span className="scan-ic" style={{ width: 30, height: 30 }}><Icon n="barcode" s={16} w={2} /></span>
                <input autoFocus value={code} onChange={e => setCode(e.target.value)} onKeyDown={e => e.key === 'Enter' && submitCode()} placeholder="امسح باركود الصنف" />
              </div>
              <div className="input-group" style={{ width: 110 }}><input className="input num" style={{ height: 48, textAlign: 'center', fontSize: 18, fontWeight: 600 }} value={qty} onChange={e => setQty(toAsciiNum(e.target.value))} aria-label="الكمية" /></div>
              <button className="btn btn-primary" style={{ height: 48 }} onClick={submitCode}>تسجيل</button>
            </div>
            <div className="row gap-2"><Api m="POST" path="/pos/stocktakes/19/scan" /><span className="fs-12 t-3 ltr mono">{'{ barcode, qty | qty_words }'}</span></div>
          </div>
        </div>
        <div className="last-scan" key={last.id + '-' + last.qty} style={{ animation: 'popIn .3s both' }}>
          <PImg p={lp} size="sm" />
          <div className="grow"><div className="fs-12 t-3">آخر تسجيل {last.via === 'voice' ? <span className="badge b-acc" style={{ height: 20 }}><Icon n="mic" s={11} />«{last.words}»</span> : <span className="badge b-neutral" style={{ height: 20 }}><Icon n="barcode" s={11} />باركود</span>}</div><div className="fs-16 fw-5">{lp.name}</div><div className="mono t-3" style={{ fontSize: 11 }}>{lp.sku}</div></div>
          <div className="col" style={{ alignItems: 'flex-end' }}><span className="big-qty">{fmt(last.qty)}</span><span className="fs-12 t-3">العدد الحالي</span></div>
        </div>
        <div className="card" style={{ overflow: 'hidden' }}>
          <div className="card-head"><h3>نطاق الجرد</h3><span className="meta">النظام لا يعرض الرصيد الدفتري أثناء العد</span></div>
          <table className="tbl"><thead><tr><th>الصنف</th><th>الكود</th><th className="n">المعدود</th><th></th></tr></thead>
            <tbody>{SCOPE.map(p => <tr key={p.id} className={last.id === p.id ? 'selected' : ''}>
              <td><div className="row gap-3"><PImg p={p} size="xs" /><span className="fw-5">{p.name}</span></div></td>
              <td><span className="mono">{p.sku}</span></td>
              <td className="n fs-16 fw-6">{counts[p.id] != null ? fmt(counts[p.id]) : <span className="t-3">—</span>}</td>
              <td>{counts[p.id] != null ? <span className="badge b-ok"><span className="dot" />تم العد</span> : <span className="badge b-neutral">لم يُعد</span>}</td>
            </tr>)}</tbody></table>
        </div>
      </div>
      <div className="card" style={{ position: 'sticky', top: 0 }}>
        <div className="card-head"><h3>سجل المسح</h3><span className="meta">آخر {fmt(log.length)}</span></div>
        <div className="col" style={{ padding: '6px 10px 10px' }}>{log.map((l, i) => { const p = pById(l.id); return <div key={i} className="row gap-3" style={{ padding: '10px 6px', borderBottom: i < log.length - 1 ? '1px solid var(--line)' : 0 }}>
          <span className="kpi-icon" style={{ width: 30, height: 30, background: l.via === 'voice' ? 'var(--acc-soft)' : 'var(--bg-sunken)', color: l.via === 'voice' ? 'var(--acc)' : 'var(--text-2)' }}><Icon n={l.via === 'voice' ? 'mic' : 'barcode'} s={14} /></span>
          <div className="grow" style={{ minWidth: 0 }}><div className="fs-13 fw-5 truncate">{p.name}</div><div className="fs-12 t-3">{l.words ? `«${l.words}»` : 'مسح باركود'} · <span className="num">{l.t}</span></div></div>
          <b className="num t-ok">+{fmt(l.add)}</b>
        </div>; })}</div>
        <div style={{ padding: 14, borderTop: '1px solid var(--line)' }}><button className="btn btn-secondary" style={{ width: '100%' }}>إنهاء الجرد وإرساله للمراجعة</button></div>
      </div>
    </div>
  </div></div>;
}

Object.assign(window, { StockPage, TransfersPage, StocktakePage });
