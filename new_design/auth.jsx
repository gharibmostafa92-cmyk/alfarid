/* Screens 4–5 — share the luxury-auth split frame (luxuryAuth.css). */

const Halftone = () => {
  const ref = useRef(null);
  useEffect(() => {
    const W = 560, H = 640, c = ref.current, ctx = c.getContext('2d');
    const off = document.createElement('canvas'); off.width = W; off.height = H;
    const o = off.getContext('2d');
    // Stand-in silhouette (real build uses BIRKIN_IMG_DATA)
    o.fillStyle = '#fff'; o.fillRect(0, 0, W, H);
    const g = o.createRadialGradient(250, 360, 40, 280, 400, 260);
    g.addColorStop(0, '#222'); g.addColorStop(1, '#bbb');
    o.fillStyle = g;
    o.beginPath(); o.moveTo(110, 270); o.lineTo(450, 270); o.lineTo(490, 520); o.quadraticCurveTo(490, 540, 470, 540); o.lineTo(90, 540); o.quadraticCurveTo(70, 540, 70, 520); o.closePath(); o.fill();
    o.lineWidth = 26; o.strokeStyle = '#444'; o.beginPath(); o.moveTo(190, 275); o.bezierCurveTo(190, 150, 370, 150, 370, 275); o.stroke();
    o.fillStyle = '#eee'; o.fillRect(250, 330, 60, 34);
    const px = o.getImageData(0, 0, W, H).data;
    let mouse = { x: -999, y: -999 };
    const draw = () => {
      ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H); ctx.fillStyle = '#09090b';
      const s = 7, h = s / 2;
      for (let y = h; y < H; y += s) for (let x = h; x < W; x += s) {
        const i = ((y | 0) * W + (x | 0)) * 4, dark = 1 - px[i] / 255;
        if (dark < 0.06) continue;
        const dx = x - mouse.x, dy = y - mouse.y, d = Math.hypot(dx, dy);
        let X = x, Y = y, k = 1;
        if (d < 110) { const f = 1 - d / 110; X += dx / (d + 1) * f * 2.2; Y += dy / (d + 1) * f * 2.2; k = 1 + f * .16; }
        const r = Math.pow(dark, .92) * s * .68 * k; if (r < .45) continue;
        ctx.beginPath(); ctx.moveTo(X, Y - r); ctx.lineTo(X + r, Y); ctx.lineTo(X, Y + r); ctx.lineTo(X - r, Y); ctx.fill();
      }
    };
    draw();
    const mv = (e) => { const b = c.getBoundingClientRect(); mouse = { x: (e.clientX - b.left) / b.width * W, y: (e.clientY - b.top) / b.height * H }; requestAnimationFrame(draw); };
    const lv = () => { mouse = { x: -999, y: -999 }; requestAnimationFrame(draw); };
    c.addEventListener('pointermove', mv); c.addEventListener('pointerleave', lv);
    return () => { c.removeEventListener('pointermove', mv); c.removeEventListener('pointerleave', lv); };
  }, []);
  return <canvas ref={ref} width="560" height="640" aria-hidden="true" />;
};

const AuthFrame = ({ children, wide }) => (
  <div className="luxury-auth-page" dir="ltr">
    <div className="auth-card">
      <section className="luxury-left-col" dir="rtl">
        <div className="luxury-top-bar">
          <span className="luxury-brand-btn">
            <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true"><polygon points="4,10 10,4 10,13 4,19" fill="#09090b" /><polygon points="11,11 17,5 17,14 11,20" fill="#09090b" /></svg>
            <span className="luxury-brand-tag">SOPHIE · WHOLESALE</span>
          </span>
          <button className="luxury-lang-btn" lang="en">English</button>
        </div>
        <div className="luxury-form-wrap" style={wide ? { maxWidth: 460 } : null}>{children}</div>
      </section>
      <section className="luxury-right-col"><Halftone /></section>
    </div>
  </div>
);

/* ---------- 4. Application status ---------- */
const ApplicationStatus = ({ status, onRefresh, onLogout, onEnter }) => {
  const s = APP_STATUS[status];
  const [refreshing, setRefreshing] = useState(false);
  const { state, retry } = React.useContext(DataStateCtx);
  const refresh = () => { setRefreshing(true); setTimeout(() => { setRefreshing(false); onRefresh && onRefresh(); }, 900); };
  const reason = status === 'rejected' ? 'البطاقة الضريبية المرفقة غير واضحة، والسجل التجاري منتهي الصلاحية.'
    : status === 'suspended' ? 'تم إيقاف الحساب مؤقتًا لتجاوز مدة السداد المتفق عليها. تواصل مع إدارة الحسابات.' : null;

  return (
    <AuthFrame wide>
      <h1 className="luxury-title">حالة طلب الاعتماد</h1>
      <p className="luxury-sub">نراجع بيانات شركتك ومستنداتها قبل تفعيل أسعار الجملة والشراء الآجل وعروض الأسعار.</p>

      {state === 'loading' ? (
        <div className="luxury-auth-fields">{[80, 60, 90, 70].map((w, i) => <div key={i} className="sk" style={{ height: 14, width: w + '%' }} />)}</div>
      ) : state === 'error' ? (
        <div className="luxury-auth-fields">
          <p className="luxury-form-error" role="alert">تعذر تحميل حالة الطلب. حاول مرة أخرى.</p>
          <button className="pill-btn-primary" onClick={retry}>إعادة المحاولة</button>
        </div>
      ) : (
        <div className="luxury-auth-fields">
          <div className="as-status" data-s={status}>
            <div className="as-status-ic"><i className={`fa-solid ${s.icon}`} /></div>
            <div style={{ flex: 1, minWidth: 0 }}>
              <div className="luxury-field-label" style={{ margin: 0, color: '#737373', fontWeight: 400 }}>حالة الحساب</div>
              <div className="as-status-label">{s.label}</div>
            </div>
            {status === 'approved' && <div className="as-date"><span>تاريخ الاعتماد</span><b className="num">{ACCOUNT.approved_at}</b></div>}
          </div>

          {reason && <p className="luxury-form-error" role="alert"><b>{status === 'rejected' ? 'سبب الرفض: ' : 'سبب الإيقاف: '}</b>{reason}</p>}
          {status === 'pending' && <p className="luxury-success">المراجعة عادةً تستغرق من يوم إلى يومي عمل. هيوصلك إشعار بمجرد تغيير الحالة.</p>}

          <div className="as-block">
            <div className="as-block-title">بيانات الحساب والشركة</div>
            <dl className="as-kv">
              <dt>اسم التاجر</dt><dd>{ACCOUNT.name}</dd>
              <dt>الموبايل</dt><dd className="num">{ACCOUNT.phone}</dd>
              <dt>البريد</dt><dd className="num">{ACCOUNT.email}</dd>
              <dt>الشركة</dt><dd>{ACCOUNT.company}</dd>
              <dt>الرقم الضريبي</dt><dd className="num">{ACCOUNT.tax_number}</dd>
              <dt>السجل التجاري</dt><dd>{ACCOUNT.commercial_register}</dd>
            </dl>
          </div>

          <div className="as-block">
            <div className="as-block-title">المستندات <span className="num" style={{ color: '#a3a3a3' }}>{DOCS.length}</span></div>
            <ul className="luxury-file-list as-docs">
              {DOCS.map((d) => {
                const [l, v] = DOC_STATUS[status === 'approved' ? 'approved' : d.status];
                const rej = status !== 'approved' && d.status === 'rejected';
                return (
                  <li key={d.name} className={rej ? 'is-rej' : ''}>
                    <span>
                      <i className={`fa-regular ${d.name.endsWith('.pdf') ? 'fa-file-pdf' : 'fa-file-image'}`} />
                      <span><b>{d.type}</b><small className="num">{d.name}</small>{rej && d.note && <em>{d.note}</em>}</span>
                    </span>
                    <span className={`badge ${v}`}>{l}</span>
                  </li>
                );
              })}
            </ul>
            {(status === 'rejected' || DOCS.some((d) => d.status === 'rejected')) && status !== 'approved' && (
              <p className="luxury-field-hint" style={{ marginBottom: 0 }}><i className="fa-solid fa-circle-info" /> إعادة رفع المستندات غير متاحة من البوابة حاليًا — تواصل مع فريق المبيعات لاستكمالها.</p>
            )}
          </div>

          {status === 'approved'
            ? <button className="pill-btn-primary" onClick={onEnter}>الدخول إلى بوابة الجملة</button>
            : <button className="pill-btn-primary" onClick={refresh} disabled={refreshing}>{refreshing ? <><span className="spin" style={{ marginInlineEnd: 10 }} /> جارٍ التحديث…</> : 'تحديث حالة الطلب'}</button>}
          <div className="luxury-bottom-switch" style={{ marginTop: 4 }}>
            <button className="luxury-switch-link" onClick={onLogout}>تسجيل الخروج</button>
          </div>
        </div>
      )}
    </AuthFrame>
  );
};

/* ---------- 5. Forgot / reset password ---------- */
const ForgotPassword = ({ onBack, onGoReset }) => {
  const [login, setLogin] = useState('');
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const submit = (e) => {
    e.preventDefault();
    if (!login.trim()) { setErr('أدخل البريد الإلكتروني أو رقم الموبايل.'); return; }
    setErr(''); setBusy(true); setTimeout(() => { setBusy(false); setSent(true); }, 900);
  };
  return (
    <AuthFrame>
      <h1 className="luxury-title">نسيت كلمة المرور؟</h1>
      <p className="luxury-sub">أدخل البريد أو رقم الموبايل المسجل، وهنبعتلك رابط لإعادة تعيين كلمة المرور.</p>
      {sent ? (
        <div className="luxury-auth-fields">
          <p className="luxury-success" role="status"><b>تم إرسال الطلب.</b><br />لو الحساب موجود، هيوصلك رابط إعادة التعيين على <span className="num">{login}</span> خلال دقائق. الرابط صالح لمدة 60 دقيقة.</p>
          <button className="pill-btn-primary" onClick={onGoReset}>عندي الرابط — إعادة التعيين</button>
          <div className="luxury-bottom-switch"><button className="luxury-switch-link" onClick={() => setSent(false)}>إعادة الإرسال</button><span className="luxury-link-sep">·</span><button className="luxury-switch-link" onClick={onBack}>العودة لتسجيل الدخول</button></div>
        </div>
      ) : (
        <form className="luxury-form" onSubmit={submit} noValidate>
          <div>
            <label className="luxury-field-label" htmlFor="fp">البريد الإلكتروني أو رقم الموبايل<span className="luxury-req-mark">*</span></label>
            <input id="fp" className="pill-input" dir="ltr" value={login} onChange={(e) => setLogin(e.target.value)} placeholder="name@company.com / 01xxxxxxxxx" aria-invalid={!!err} />
            {err && <p className="luxury-field-error" role="alert">{err}</p>}
          </div>
          <button className="pill-btn-primary" disabled={busy}>{busy ? 'جارٍ الإرسال…' : 'إرسال رابط إعادة التعيين'}</button>
          <div className="luxury-bottom-switch"><button type="button" className="luxury-switch-link" onClick={onBack}>العودة لتسجيل الدخول</button></div>
        </form>
      )}
    </AuthFrame>
  );
};

const ResetPassword = ({ onBack, linkState = 'valid', onForgot }) => {
  const [show, setShow] = useState(false);
  const [p1, setP1] = useState(''); const [p2, setP2] = useState('');
  const [errs, setErrs] = useState({});
  const [done, setDone] = useState(false);
  const submit = (e) => {
    e.preventDefault();
    const er = {};
    if (p1.length < 8) er.p1 = 'كلمة المرور لازم تكون 8 حروف على الأقل.';
    if (p1 !== p2) er.p2 = 'تأكيد كلمة المرور غير مطابق.';
    setErrs(er); if (!Object.keys(er).length) setDone(true);
  };
  const rules = [[p1.length >= 8, '8 حروف على الأقل'], [/[A-Za-z]/.test(p1) && /\d/.test(p1), 'حروف وأرقام'], [p1 && p1 === p2, 'التأكيد مطابق']];
  if (linkState !== 'valid') return (
    <AuthFrame>
      <h1 className="luxury-title">{linkState === 'expired' ? 'انتهت صلاحية الرابط' : 'الرابط غير صالح'}</h1>
      <p className="luxury-sub">{linkState === 'expired' ? 'روابط إعادة التعيين صالحة لمدة 60 دقيقة فقط. اطلب رابط جديد.' : 'الرابط ده مستخدم قبل كده أو ناقص. اطلب رابط جديد من صفحة استعادة كلمة المرور.'}</p>
      <p className="luxury-form-error" role="alert">لا يمكن إعادة تعيين كلمة المرور بهذا الرابط.</p>
      <button className="pill-btn-primary" style={{ marginTop: 22 }} onClick={onForgot}>طلب رابط جديد</button>
      <div className="luxury-bottom-switch"><button className="luxury-switch-link" onClick={onBack}>العودة لتسجيل الدخول</button></div>
    </AuthFrame>
  );
  return (
    <AuthFrame>
      <h1 className="luxury-title">{done ? 'تم تغيير كلمة المرور' : 'إعادة تعيين كلمة المرور'}</h1>
      <p className="luxury-sub">{done ? 'تقدر دلوقتي تسجل الدخول بكلمة المرور الجديدة.' : 'اختر كلمة مرور جديدة لحسابك.'}</p>
      {done ? (
        <div className="luxury-auth-fields">
          <p className="luxury-success" role="status"><span className="luxury-verified-badge">تم التحديث بنجاح</span></p>
          <button className="pill-btn-primary" onClick={onBack}>تسجيل الدخول</button>
        </div>
      ) : (
        <form className="luxury-form" onSubmit={submit} noValidate>
          <div>
            <label className="luxury-field-label">البريد الإلكتروني</label>
            <input className="pill-input" dir="ltr" value={ACCOUNT.email} readOnly style={{ background: '#fafafa', color: '#737373' }} />
          </div>
          {[['p1', 'كلمة المرور الجديدة', p1, setP1], ['p2', 'تأكيد كلمة المرور', p2, setP2]].map(([k, l, v, set]) => (
            <div key={k}>
              <label className="luxury-field-label">{l}<span className="luxury-req-mark">*</span></label>
              <div className="luxury-pwd-wrap">
                <input className="pill-input" style={{ paddingLeft: 52 }} type={show ? 'text' : 'password'} value={v} onChange={(e) => set(e.target.value)} aria-invalid={!!errs[k]} />
                <button type="button" className="luxury-eye-btn" style={{ right: 'auto', left: 8 }} onClick={() => setShow(!show)} aria-label="إظهار كلمة المرور"><i className={`fa-regular ${show ? 'fa-eye-slash' : 'fa-eye'}`} /></button>
              </div>
              {errs[k] && <p className="luxury-field-error" role="alert">{errs[k]}</p>}
            </div>
          ))}
          <ul className="as-rules">{rules.map(([ok, l]) => <li key={l} className={ok ? 'ok' : ''}><i className={`fa-solid ${ok ? 'fa-check' : 'fa-circle'}`} />{l}</li>)}</ul>
          <button className="pill-btn-primary">حفظ كلمة المرور</button>
          <div className="luxury-bottom-switch"><button type="button" className="luxury-switch-link" onClick={onBack}>العودة لتسجيل الدخول</button></div>
        </form>
      )}
    </AuthFrame>
  );
};

Object.assign(window, { ApplicationStatus, ForgotPassword, ResetPassword });
