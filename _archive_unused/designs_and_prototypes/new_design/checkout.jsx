// Screen 5 — Checkout & payment;  Screen 6 — Hold + Held drawer
function CheckoutModal({ open, onClose, cart, customer, discount, onPaid }) {
  const [method, setMethod] = useState('cash');
  const [received, setReceived] = useState('');
  const [cashPart, setCashPart] = useState('');
  const [notes, setNotes] = useState('');
  const [stage, setStage] = useState('pay'); // pay | sending | done
  const [idem, setIdem] = useState('');
  const subtotal = cart.reduce((s, l) => s + l.price * l.qty, 0);
  const total = Math.max(0, subtotal - discount);
  useEffect(() => { if (open) { setMethod('cash'); setReceived(''); setCashPart(''); setNotes(''); setStage('pay'); setIdem(uuid()); } }, [open]);
  useEffect(() => {
    if (!open || stage !== 'pay') return;
    const h = e => { if (e.target.tagName === 'TEXTAREA') return; if (e.key === 'F1') { e.preventDefault(); setMethod('cash'); } if (e.key === 'F3') { e.preventDefault(); setMethod('card'); } if (e.key === 'F4') { e.preventDefault(); setMethod('mixed'); } };
    window.addEventListener('keydown', h); return () => window.removeEventListener('keydown', h);
  }, [open, stage]);
  useEsc(() => stage !== 'sending' && onClose(), open);
  if (!open) return null;

  const rec = Number(received || 0);
  const change = rec - total;
  const cashA = Math.min(Number(cashPart || 0), total);
  const cardA = Math.max(0, total - cashA);
  const valid = method === 'card' || (method === 'cash' && rec >= total) || (method === 'mixed' && cashA > 0 && cardA > 0 && Math.abs(cashA + cardA - total) < 0.01);
  const roundUps = [...new Set([total, Math.ceil(total / 50) * 50, Math.ceil(total / 100) * 100, Math.ceil(total / 200) * 200, Math.ceil(total / 500) * 500])].filter(v => v >= total).slice(0, 4);
  const press = k => {
    const set = method === 'mixed' ? setCashPart : setReceived;
    const cur = method === 'mixed' ? cashPart : received;
    if (k === '⌫') set(cur.slice(0, -1)); else if (k === '.') { if (!cur.includes('.')) set((cur || '0') + '.'); } else set(cur + k);
  };
  const payload = {
    items: cart.map(l => ({ product_variant_id: l.id, qty: l.qty, unit_price: l.price, discount_amount: 0 })),
    customer_id: customer?.id ?? null, payment_method: method,
    cash_amount: method === 'cash' ? total : method === 'mixed' ? cashA : 0,
    card_amount: method === 'card' ? total : method === 'mixed' ? cardA : 0, notes,
  };
  const confirm = () => { if (!valid) return; setStage('sending'); setTimeout(() => setStage('done'), 900); };

  return ReactDOM.createPortal(<div dir="rtl" className={document.querySelector('.app')?.classList.contains('theme-dark') ? 'theme-dark' : ''}>
    <div className="overlay" onClick={() => stage === 'pay' && onClose()} />
    <div className="modal" style={{ width: 'min(960px, calc(100vw - 32px))' }} data-screen-label="05 Checkout" role="dialog" aria-modal="true">
      <div className="pop">
        {stage !== 'done' ? <div className="co">
          <div className="co-main">
            <div className="row between">
              <h3 className="fs-16">الدفع وطرق التحصيل</h3>
              <button className="icon-btn sm" onClick={onClose} aria-label="إغلاق"><Icon n="x" s={16} /></button>
            </div>
            <div className="due">
              <div className="col gap-1"><span className="fs-13 t-2">المطلوب سداده</span><span className="v">{egp(total)}<small>ج.م</small></span></div>
              {customer && <span className="badge b-neutral"><Icon n="users" s={13} />{customer.name}</span>}
            </div>
            <div className="methods" role="radiogroup">
              {[['cash', 'نقدي', 'cash', 'F1'], ['card', 'بطاقة / فيزا', 'card', 'F3'], ['mixed', 'دفع مختلط', 'split', 'F4']].map(([v, l, ic, k]) =>
                <button key={v} role="radio" className="method" aria-checked={method === v} onClick={() => setMethod(v)}><Kbd>{k}</Kbd><Icon n={ic} s={24} w={1.6} />{l}</button>)}
            </div>

            {method === 'cash' && <div className="grid" style={{ gridTemplateColumns: '1fr 220px', gap: 16 }}>
              <div className="col gap-3">
                <span className="fs-13 fw-5">المبلغ المستلم من العميل</span>
                <MoneyInput value={received} onChange={setReceived} autoFocus placeholder={String(total)} onEnter={confirm} />
                <div className="denoms">{roundUps.map((v, i) => <button key={v} className="denom" onClick={() => setReceived(String(v))}>{i === 0 && v === total ? <span className="fs-13" style={{ fontFamily: 'var(--f-body)' }}>المبلغ بالضبط</span> : <>{fmt(v)}<small>ج</small></>}</button>)}</div>
                <div className={cx('change', change < 0 && received && 'neg')}>
                  <span className="col"><span className="fs-12" style={{ opacity: .75 }}>{change < 0 && received ? 'المبلغ غير كافٍ — متبقٍ' : 'المتبقي للعميل (الفكة)'}</span></span>
                  <span className="v">{received ? egp(Math.abs(change)) : '٠'}<small className="fs-13" style={{ opacity: .6, marginInlineStart: 4 }}>ج</small></span>
                </div>
              </div>
              <div className="keypad">{['7', '8', '9', '4', '5', '6', '1', '2', '3', '.', '0', '⌫'].map(k => <button key={k} onClick={() => press(k)}>{k}</button>)}</div>
            </div>}

            {method === 'card' && <div className="col gap-3">
              <div className="card card-pad row gap-4" style={{ background: 'var(--bg-sunken)' }}>
                <div style={{ width: 64, height: 88, borderRadius: 12, background: 'var(--bg-ink)', color: 'var(--on-ink)', display: 'grid', placeItems: 'center', flexShrink: 0 }}><Icon n="card" s={26} w={1.5} /></div>
                <div className="col gap-1 grow">
                  <b className="fs-14">ادفع على ماكينة البطاقات</b>
                  <p className="fs-13 t-2">أدخل مبلغ <b className="num t-1">{egp(total)} ج</b> على الماكينة، وبعد خروج إيصال الموافقة اضغط «تأكيد الدفع».</p>
                </div>
              </div>
              <Field label="آخر ٤ أرقام / رقم الموافقة" hint="اختياري — يظهر في تقرير الوردية"><input className="input ltr num" style={{ textAlign: 'right', maxWidth: 240 }} placeholder="•••• 4821" /></Field>
            </div>}

            {method === 'mixed' && <div className="col gap-3">
              <div className="grid" style={{ gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                <div className="col gap-2"><span className="fs-13 fw-5 row gap-2"><Icon n="cash" s={16} />الجزء النقدي</span><MoneyInput size="md" value={cashPart} onChange={v => setCashPart(String(Math.min(Number(v || 0), total) || ''))} autoFocus placeholder="0" /></div>
                <div className="col gap-2"><span className="fs-13 fw-5 row gap-2"><Icon n="card" s={16} />الجزء بالبطاقة <span className="t-3 fs-12">(تلقائي)</span></span>
                  <div className="money-in md" style={{ background: 'var(--bg-sunken)' }}><span className="cur">ج.م</span><input readOnly value={cashPart ? String(cardA) : ''} placeholder="—" /></div></div>
              </div>
              <div className="split-bar"><span style={{ width: `${total ? cashA / total * 100 : 0}%`, background: 'var(--ok)' }} /><span style={{ width: `${total && cashPart ? cardA / total * 100 : 0}%`, background: 'var(--info)' }} /></div>
              <div className="row between fs-12">
                <span className="row gap-3"><span className="row gap-1"><i style={{ width: 8, height: 8, borderRadius: 99, background: 'var(--ok)' }} />نقدي <b className="num">{egp(cashA)}</b></span><span className="row gap-1"><i style={{ width: 8, height: 8, borderRadius: 99, background: 'var(--info)' }} />بطاقة <b className="num">{egp(cashPart ? cardA : 0)}</b></span></span>
                {valid ? <span className="t-ok row gap-1 fw-6"><Icon n="check" s={14} w={2.5} />المجموع = إجمالي الفاتورة</span> : <span className="t-3">حدد جزءًا نقديًا أقل من الإجمالي</span>}
              </div>
              <div className="row gap-2">{[0.25, 0.5, 0.75].map(r => <button key={r} className="note-chip" onClick={() => setCashPart(String(Math.round(total * r)))}>{fmt(r * 100)}٪ نقدي</button>)}</div>
            </div>}

            <Field label={<>ملاحظات على الفاتورة <span className="t-3 fs-12">(اختياري)</span></>}><input className="input" value={notes} onChange={e => setNotes(e.target.value)} placeholder="تظهر في أسفل الإيصال" /></Field>
            <div className="row gap-2" style={{ marginTop: 'auto' }}>
              <button className="btn btn-secondary btn-lg" onClick={onClose}>رجوع</button>
              <button className="pay-btn grow" style={{ margin: 0 }} disabled={!valid || stage === 'sending'} onClick={confirm}>
                <span className="row gap-2"><Icon n="printer" s={18} />{stage === 'sending' ? 'جارِ تسجيل البيع…' : 'تأكيد الدفع وطباعة الإيصال'}</span>
                <span className="kbd" style={{ background: 'rgba(255,255,255,.15)', borderColor: 'rgba(255,255,255,.35)', color: '#fff' }}>Enter</span>
              </button>
            </div>
          </div>
          <div className="co-side">
            <div className="row between"><span className="section-label">ملخص الفاتورة</span><span className="fs-12 t-3 num">{fmt(cart.reduce((s, l) => s + l.qty, 0))} قطعة</span></div>
            <div className="col gap-2" style={{ flex: 1, overflow: 'auto', maxHeight: 300 }}>
              {cart.map(l => { const p = pById(l.id); return <div key={l.id} className="row gap-2"><PImg p={p} size="xs" /><div className="grow" style={{ minWidth: 0 }}><div className="fs-12 fw-5 truncate">{p.name}</div><div className="fs-12 t-3 num">{fmt(l.qty)} × {egp(l.price)}</div></div><span className="price fs-13">{egp(l.qty * l.price)}</span></div>; })}
            </div>
            <div className="col gap-1 fs-13" style={{ borderTop: '1px dashed var(--line-strong)', paddingTop: 10 }}>
              <div className="row between t-2"><span>المجموع</span><span className="num">{egp(subtotal)}</span></div>
              {discount > 0 && <div className="row between t-2"><span>خصم</span><span className="num t-ok">−{egp(discount)}</span></div>}
              <div className="row between fw-6"><span>الإجمالي</span><span className="num fs-16">{egp(total)}</span></div>
            </div>
            <div className="col gap-2" style={{ borderTop: '1px solid var(--line)', paddingTop: 12 }}>
              <Api m="POST" path="/api/v1/pos/sales" />
              <div className="fs-12 t-3">Idempotency-Key</div>
              <code className="mono" style={{ fontSize: 10.5, direction: 'ltr', wordBreak: 'break-all', color: 'var(--text-2)' }}>{idem}</code>
              <div className="fs-12 t-3">يمنع تكرار الخصم عند الضغط المزدوج</div>
            </div>
          </div>
        </div> : <ReceiptDone total={total} method={method} received={rec} change={change} cashA={cashA} cardA={cardA} cart={cart} customer={customer} discount={discount} notes={notes} onNew={() => onPaid(payload)} />}
      </div>
    </div>
  </div>, document.body);
}

function ReceiptDone({ total, method, received, change, cashA, cardA, cart, customer, discount, notes, onNew }) {
  useEffect(() => { const h = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onNew(); } }; const t = setTimeout(() => window.addEventListener('keydown', h), 300); return () => { clearTimeout(t); window.removeEventListener('keydown', h); }; }, []);
  return <div className="grid" style={{ gridTemplateColumns: '1fr 360px' }}>
    <div className="col center" style={{ padding: 36, gap: 18, alignItems: 'flex-start', justifyContent: 'center' }}>
      <span style={{ width: 60, height: 60, borderRadius: 20, background: 'var(--ok-bg)', color: 'var(--ok)', display: 'grid', placeItems: 'center' }}><Icon n="check" s={30} w={2.4} /></span>
      <div><h2 className="fs-26">تم تسجيل البيع بنجاح</h2><p className="t-2" style={{ marginTop: 4 }}>فاتورة <b className="num ltr t-1">POS-NSR-10268</b> — تم خصم المخزون وإيداع النقدية بالدرج.</p></div>
      {method !== 'card' && <div className="change" style={{ width: '100%', maxWidth: 380 }}><span className="fs-13" style={{ opacity: .8 }}>{method === 'cash' ? 'الفكة للعميل' : 'تم تحصيل نقدي'}</span><span className="v">{egp(method === 'cash' ? Math.max(0, change) : cashA)} <small className="fs-13" style={{ opacity: .6 }}>ج</small></span></div>}
      <div className="row gap-2">
        <button className="btn btn-secondary btn-lg"><Icon n="printer" s={17} />إعادة الطباعة</button>
        <button className="btn btn-primary btn-lg" autoFocus onClick={onNew}>فاتورة جديدة <Kbd>Enter</Kbd></button>
      </div>
    </div>
    <div style={{ background: 'var(--bg-sunken)', padding: '28px 0 40px', borderInlineStart: '1px solid var(--line)' }}>
      <div className="receipt">
        <div style={{ textAlign: 'center' }}><b className="fs-14 display">الفريد للشنط</b><div style={{ color: '#6f6a76' }}>{BRANCH.name}</div><div className="num" style={{ color: '#6f6a76' }}>POS-NSR-10268 · {new Date().toLocaleString('ar-EG', { dateStyle: 'short', timeStyle: 'short' })}</div></div>
        <div className="dash" />
        {cart.map(l => { const p = pById(l.id); return <div key={l.id}><div className="truncate">{p.name}</div><div className="rr" style={{ color: '#6f6a76' }}><span className="num">{fmt(l.qty)} × {egp(l.price)}</span><span className="num" style={{ color: '#18151c' }}>{egp(l.qty * l.price)}</span></div></div>; })}
        <div className="dash" />
        {discount > 0 && <div className="rr"><span>خصم</span><span className="num">−{egp(discount)}</span></div>}
        <div className="rr" style={{ fontWeight: 700, fontSize: 14 }}><span>الإجمالي</span><span className="num">{egp(total)} ج</span></div>
        {method === 'cash' && <><div className="rr"><span>نقدي مستلم</span><span className="num">{egp(received)}</span></div><div className="rr"><span>الباقي</span><span className="num">{egp(Math.max(0, change))}</span></div></>}
        {method === 'card' && <div className="rr"><span>بطاقة</span><span className="num">{egp(total)}</span></div>}
        {method === 'mixed' && <><div className="rr"><span>نقدي</span><span className="num">{egp(cashA)}</span></div><div className="rr"><span>بطاقة</span><span className="num">{egp(cardA)}</span></div></>}
        {customer && <><div className="dash" /><div className="rr"><span>العميل</span><span>{customer.name}</span></div></>}
        {notes && <div style={{ marginTop: 6, color: '#6f6a76' }}>{notes}</div>}
        <div className="dash" />
        <div style={{ textAlign: 'center', color: '#6f6a76' }}>الكاشير: {CASHIER.name} — الاستبدال خلال ١٤ يومًا بالإيصال</div>
        <div className="bars" />
      </div>
    </div>
  </div>;
}

function HoldModal({ open, onClose, onHold, cart, customer }) {
  const [note, setNote] = useState('');
  useEffect(() => { if (open) setNote(''); }, [open]);
  const total = cart.reduce((s, l) => s + l.price * l.qty, 0);
  return <Modal open={open} onClose={onClose} title="تعليق الفاتورة" width={460}
    footer={<><span className="grow"><Api m="POST" path="/pos/sales/hold" /></span><button className="btn btn-secondary" onClick={onClose}>إلغاء</button><button className="btn btn-primary" onClick={() => onHold(note)}><Icon n="pause" s={15} />تعليق وخدمة زبون آخر</button></>}>
    <div className="col gap-4">
      <div className="sunken row gap-3" style={{ padding: 12 }}>
        <div className="stack-thumbs">{cart.slice(0, 3).map(l => <PImg key={l.id} p={pById(l.id)} size="xs" />)}</div>
        <div className="grow"><div className="fs-13 fw-6 num">{fmt(cart.reduce((s, l) => s + l.qty, 0))} قطعة</div><div className="fs-12 t-3">{customer ? customer.name : 'بدون عميل'}</div></div>
        <span className="price fs-16">{egp(total)} ج</span>
      </div>
      <Field label={<>ملاحظة <span className="t-3 fs-12">(اختياري)</span></>}>
        <input className="input" autoFocus value={note} onChange={e => setNote(e.target.value)} onKeyDown={e => e.key === 'Enter' && onHold(note)} placeholder="مثال: زبون بيجيب محفظة إضافية" />
      </Field>
      <div className="row gap-2 wrap">{['بانتظار العميل', 'بيجيب فلوس', 'بيقيس لون تاني', 'هيرجع كمان شوية'].map(s => <button key={s} className="note-chip" onClick={() => setNote(s)}>{s}</button>)}</div>
    </div>
  </Modal>;
}

function HeldDrawer({ open, onClose, held, onResume, onDelete, cartBusy }) {
  return <Drawer open={open} onClose={onClose} title="الفواتير المعلقة" sub={<span className="row gap-2">{fmt(held.length)} فاتورة في {BRANCH.name} <Api path="/pos/sales/held" /></span>}>
    {held.length === 0 ? <Empty icon="inbox" title="لا توجد فواتير معلقة" body="الفواتير التي تعلّقها من شاشة البيع ستظهر هنا لاستكمالها لاحقًا." /> :
      <div className="col gap-3">
        {cartBusy && <div className="banner b-warn"><Icon n="alert" s={16} />السلة الحالية بها أصناف — الاسترجاع سيضيف الأصناف إليها.</div>}
        {held.map(h => {
          const total = h.items.reduce((s, i) => s + pById(i.id).price * i.qty, 0);
          const pcs = h.items.reduce((s, i) => s + i.qty, 0);
          return <div key={h.ref} className="held-card" data-screen-label="06 Held Sale">
            <div className="row gap-3">
              <div className="stack-thumbs">{h.items.slice(0, 3).map(i => <PImg key={i.id} p={pById(i.id)} size="sm" />)}</div>
              <div className="grow">
                <div className="row gap-2"><b className="mono" style={{ fontSize: 13 }}>{h.ref}</b>{h.mins > 60 && <span className="badge b-warn" style={{ height: 20, fontSize: 11 }}>منذ أكثر من ساعة</span>}</div>
                <div className="fs-12 t-3 row gap-1"><Icon n="history" s={12} />{h.at}<span className="dot-sep" />{fmt(pcs)} قطعة<span className="dot-sep" />{h.customer || 'بدون عميل'}</div>
              </div>
              <span className="price fs-20">{egp(total)}<small>ج</small></span>
            </div>
            {h.note && <div className="fs-13 t-2 row gap-2" style={{ padding: '8px 10px', borderRadius: 10, background: 'var(--bg-sunken)' }}><Icon n="edit" s={14} />{h.note}</div>}
            <div className="row gap-2">
              <button className="btn btn-primary grow" onClick={() => onResume(h)}><Icon n="arrowUpLeft" s={15} />استرجاع الفاتورة</button>
              <button className="btn btn-ghost" onClick={() => onDelete(h)}><Icon n="trash" s={15} />حذف</button>
            </div>
          </div>;
        })}
      </div>}
  </Drawer>;
}

Object.assign(window, { CheckoutModal, HoldModal, HeldDrawer });
