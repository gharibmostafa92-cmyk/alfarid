// Screens 1 & 2 — Open shift gate, Close shift modal
function OpenShiftGate({ onOpened }) {
  const [treasury, setTreasury] = useState(1);
  const [cash, setCash] = useState('500');
  const [busy, setBusy] = useState(false);
  const amount = Number(cash || 0);
  const now = new Date();
  const hr = now.getHours();
  const greet = hr < 12 ? 'صباح الخير' : 'مساء الخير';

  const submit = () => {
    if (!treasury || busy) return;
    setBusy(true);
    setTimeout(() => {
      onOpened({ id: 512, shift_number: 'SH-NSR-0512', treasury_id: treasury, treasury: TREASURIES.find(t => t.id === treasury), opening_cash: amount, opened_at: now, status: 'open' });
    }, 700);
  };

  return <div className="gate" data-screen-label="01 Open Shift">
    <div className="gate-card" role="dialog" aria-modal="true" aria-label="فتح الوردية">
      <div className="welcome">
        <div className="row gap-3" style={{ position: 'relative', zIndex: 1 }}>
          <span className="avatar">{CASHIER.initials}</span>
          <div className="grow">
            <div style={{ fontSize: 13, opacity: .85 }}>{greet}،</div>
            <div className="display" style={{ fontSize: 22, fontWeight: 500, lineHeight: 1.25 }}>{CASHIER.name}</div>
          </div>
          <div className="col" style={{ alignItems: 'flex-end', gap: 4 }}>
            <span className="badge" style={{ background: 'rgba(255,255,255,.18)', color: '#fff' }}><Icon n="store" s={13} />{BRANCH.name}</span>
            <span style={{ fontSize: 12, opacity: .85 }} className="num">{now.toLocaleDateString('ar-EG', { weekday: 'long', day: 'numeric', month: 'long' })}</span>
          </div>
        </div>
        <div className="row gap-2" style={{ position: 'relative', zIndex: 1, marginTop: 16, padding: '10px 12px', borderRadius: 12, background: 'rgba(0,0,0,.14)', fontSize: 12.5 }}>
          <Icon n="lock" s={15} />
          لا توجد وردية مفتوحة باسمك. افتح وردية لبدء البيع.
        </div>
      </div>

      <div style={{ padding: '20px 24px 8px', display: 'flex', flexDirection: 'column', gap: 18 }}>
        <div className="col gap-2">
          <div className="row between"><span className="fs-13 fw-5">درج النقدية</span><span className="fs-12 t-3">{TREASURIES.filter(t => t.state === 'free').length} متاح في الفرع</span></div>
          <div className="tres" role="radiogroup">
            {TREASURIES.map(t => <button key={t.id} role="radio" aria-checked={treasury === t.id} disabled={t.state === 'busy'} onClick={() => setTreasury(t.id)}>
              <div className="row between"><Icon n="cash" s={18} style={{ color: treasury === t.id ? 'var(--acc)' : 'var(--text-3)' }} />{t.state === 'busy' ? <span className="badge b-neutral" style={{ height: 20, fontSize: 11 }}>مشغول</span> : <span className="radio-dot" />}</div>
              <div className="fs-13 fw-6">{t.name}</div>
              <div className="fs-12 t-3 truncate" style={{ lineHeight: 1.3 }}>{t.last}</div>
            </button>)}
          </div>
        </div>

        <div className="col gap-2">
          <div className="row between"><span className="fs-13 fw-5">العهدة الافتتاحية</span><button className="fs-12 t-3" onClick={() => setCash('')}>مسح</button></div>
          <MoneyInput value={cash} onChange={setCash} autoFocus onEnter={submit} />
          <div className="denoms">
            {[200, 500, 1000].map(v => <button key={v} className="denom" onClick={() => setCash(String(amount + v))}>+{fmt(v)}<small>ج</small></button>)}
            <button className="denom ghost" onClick={() => setCash('500')}>نفس أمس</button>
          </div>
        </div>
      </div>

      <div style={{ padding: '16px 24px 22px' }}>
        <button className="pay-btn" style={{ margin: 0, width: '100%' }} disabled={!treasury || busy} onClick={submit}>
          <span className="row gap-2">{busy ? 'جارِ فتح الوردية…' : 'فتح الوردية وبدء البيع'}</span>
          <span className="row gap-2"><span className="amt num">{egp(amount)}</span><Icon n="arrowLeft" s={18} /></span>
        </button>
        <div className="api-block center" style={{ justifyContent: 'center' }}>
          <Api path="/api/v1/pos/shifts/current" /> <Api m="POST" path="/api/v1/pos/shifts/open" />
        </div>
      </div>
    </div>
  </div>;
}

function CloseShiftModal({ open, onClose, shift, onClosed }) {
  const [actual, setActual] = useState('');
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  useEffect(() => { if (open) { setActual(''); setNotes(''); setBusy(false); } }, [open]);
  useEsc(onClose, open);
  if (!open || !shift) return null;
  const s = SHIFT_STATS;
  const expected = shift.opening_cash + s.cashSales - s.returns - s.expenses;
  const has = actual !== '';
  const diff = Number(actual || 0) - expected;
  const state = !has ? 'idle' : Math.abs(diff) < 0.01 ? 'ok' : diff > 0 ? 'over' : 'short';
  const needNote = state === 'short' || state === 'over';
  const canSubmit = has && (!needNote || notes.trim().length >= 3) && !busy;
  const diffLabel = { idle: 'أدخل النقد الفعلي لحساب الفرق', ok: 'مطابق تمامًا', over: 'زيادة في الدرج', short: 'عجز في الدرج' }[state];
  const diffIcon = { idle: 'info', ok: 'check', over: 'arrowUpLeft', short: 'alert' }[state];
  const started = shift.opened_at;

  return ReactDOM.createPortal(<div dir="rtl" className={document.querySelector('.app')?.classList.contains('theme-dark') ? 'theme-dark' : ''}>
    <div className="overlay" onClick={onClose} />
    <div className="modal" style={{ width: 'min(860px, calc(100vw - 32px))' }} data-screen-label="02 Close Shift" role="dialog" aria-modal="true">
      <div className="pop">
        <div className="row between" style={{ padding: '18px 22px', borderBottom: '1px solid var(--line)' }}>
          <div className="col">
            <h3 className="fs-16">إغلاق الوردية وجرد النقدية</h3>
            <div className="fs-12 t-3 row gap-1"><span className="num ltr">{shift.shift_number}</span><span className="dot-sep" />{shift.treasury.name}<span className="dot-sep" />بدأت <span className="num">{started.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' })}</span></div>
          </div>
          <button className="icon-btn sm" onClick={onClose} aria-label="إغلاق"><Icon n="x" s={16} /></button>
        </div>

        <div className="grid" style={{ gridTemplateColumns: '1fr 1fr' }}>
          <div style={{ padding: '18px 22px', borderInlineEnd: '1px solid var(--line)', background: 'var(--bg-sunken)' }}>
            <div className="section-label" style={{ marginBottom: 6 }}>ملخص حركة النقدية</div>
            <div className="ledger">
              <div className="lr"><span className="op base">·</span>العهدة الافتتاحية<span className="amt">{egp(shift.opening_cash)}</span></div>
              <div className="lr"><span className="op plus">+</span>مبيعات نقدية <span className="t-3 fs-12">({fmt(s.invoices)} فاتورة)</span><span className="amt">{egp(s.cashSales)}</span></div>
              <div className="lr"><span className="op minus">−</span>مرتجعات نقدية<span className="amt">{egp(s.returns)}</span></div>
              <div className="lr"><span className="op minus">−</span>مصروفات نثرية<span className="amt">{egp(s.expenses)}</span></div>
              <div className="lr" style={{ paddingTop: 14 }}><span className="op eq">=</span><b>النقد المتوقع بالدرج</b><span className="amt fs-20 fw-6">{egp(expected)}</span></div>
            </div>
            <div className="row gap-2 fs-12 t-3" style={{ marginTop: 10, padding: '10px 12px', borderRadius: 12, border: '1px dashed var(--line-strong)' }}>
              <Icon n="card" s={15} />مبيعات البطاقة <b className="num t-1">{egp(s.cardSales)}</b> ج — لا تدخل في جرد الدرج
            </div>
          </div>

          <div style={{ padding: '18px 22px', display: 'flex', flexDirection: 'column', gap: 14 }}>
            <div className="col gap-2">
              <span className="fs-13 fw-5">النقد الفعلي بالدرج</span>
              <MoneyInput value={actual} onChange={setActual} autoFocus placeholder={String(expected)} />
              <div className="row gap-2">
                <button className="note-chip" onClick={() => setActual(String(expected))}>مطابق للمتوقع</button>
              </div>
            </div>
            <div className={cx('diff', state)}>
              <span className="diff-ic"><Icon n={diffIcon} s={18} w={2} /></span>
              <div className="col"><span className="fs-12" style={{ opacity: .8 }}>الفرق</span><b className="fs-13">{diffLabel}</b></div>
              <span className="v">{has ? (diff > 0 ? '+' : diff < 0 ? '−' : '') + egp(Math.abs(diff)) : '—'}</span>
            </div>
            <Field label={<>ملاحظات {needNote ? <span className="t-bad fs-12">(مطلوبة لتبرير الفرق)</span> : <span className="t-3 fs-12">(اختياري)</span>}</>}>
              <textarea className={cx('input', needNote && notes.trim().length < 3 && 'is-error')} rows={3} value={notes} onChange={e => setNotes(e.target.value)} placeholder={state === 'short' ? `مثال: عجز ${egp(Math.abs(diff))} جنيه فرق فكة مع عميل` : 'أي ملاحظات على الوردية…'} />
            </Field>
          </div>
        </div>

        <div className="row gap-2" style={{ padding: '14px 22px', borderTop: '1px solid var(--line)' }}>
          <div className="row gap-2 fs-12 t-3 grow"><Icon n="info" s={14} />ستُرسل الوردية بحالة <span className="badge b-warn" style={{ height: 20 }}>بانتظار الاعتماد</span><Api m="POST" path="/pos/shifts/close" /></div>
          <button className="btn btn-secondary" onClick={onClose}>رجوع للبيع</button>
          <button className="btn" style={{ background: 'var(--bad)', color: '#fff' }} disabled={!canSubmit} onClick={() => { setBusy(true); setTimeout(() => onClosed({ actual_closing_cash: Number(actual), notes, cash_difference: diff }), 700); }}>
            <Icon n="lock" s={16} />{busy ? 'جارِ الإغلاق…' : 'إغلاق الوردية وإرسالها للاعتماد'}
          </button>
        </div>
      </div>
    </div>
  </div>, document.body);
}

Object.assign(window, { OpenShiftGate, CloseShiftModal });
