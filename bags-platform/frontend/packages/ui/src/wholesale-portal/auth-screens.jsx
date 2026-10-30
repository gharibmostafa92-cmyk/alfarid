import { AuthHalftone } from '../components/auth-halftone';
import React, { useState } from 'react';
import { wholesaleEndpoints } from '@alfarid/api-client';
import { useApi } from './data/useApi';
import { DataBoundary } from './data/DataBoundary';
import { apiError } from './data/helpers';
import { APP_STATUS, DOC_STATUS, DOC_TYPE } from './data/constants';

/* Screens 4–5 — share the luxury-auth split frame (luxuryAuth.css). */

export const Halftone = AuthHalftone;

export const AuthFrame = ({ children, wide }) => (
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

const DOC_ST = { verified: 'approved' };

/* ---------- 4. Application status ---------- */
export const ApplicationStatus = ({ onLogout, onEnter }) => {
  const [entering, setEntering] = useState(false);
  const [enterError, setEnterError] = useState('');
  const api = useApi((signal) => wholesaleEndpoints.applicationStatus(signal), []);
  const d = api.data;
  const status = d?.status || 'pending';
  const s = APP_STATUS[status] || APP_STATUS.pending;
  const docs = (d?.documents || []).map((doc) => ({
    name: doc.original_name, type: DOC_TYPE[doc.document_type] || doc.document_type, status: DOC_ST[doc.status] || doc.status,
  }));
  const reason = status === 'rejected' ? d?.rejection_reason : status === 'suspended' ? d?.suspension_reason : null;
  const enter = async () => {
    if (entering || !onEnter) return;
    setEntering(true);
    setEnterError('');
    try { await onEnter(); }
    catch (error) { setEnterError(apiError(error).message); }
    finally { setEntering(false); }
  };

  const inner = (
    <AuthFrame wide>
      <h1 className="luxury-title">حالة طلب الاعتماد</h1>
      <p className="luxury-sub">نراجع بيانات شركتك ومستنداتها قبل تفعيل أسعار الجملة والشراء الآجل وعروض الأسعار.</p>

      {api.state === 'loading' ? (
        <div className="luxury-auth-fields">{[80, 60, 90, 70].map((w, i) => <div key={i} className="sk" style={{ height: 14, width: w + '%' }} />)}</div>
      ) : api.state === 'error' ? (
        <div className="luxury-auth-fields">
          <p className="luxury-form-error" role="alert">تعذر تحميل حالة الطلب. حاول مرة أخرى.</p>
          <button className="pill-btn-primary" onClick={api.reload}>إعادة المحاولة</button>
        </div>
      ) : (
        <div className="luxury-auth-fields">
          <div className="as-status" data-s={status}>
            <div className="as-status-ic"><i className={`fa-solid ${s.icon}`} /></div>
            <div style={{ flex: 1, minWidth: 0 }}>
              <div className="luxury-field-label" style={{ margin: 0, color: '#737373', fontWeight: 400 }}>حالة الحساب</div>
              <div className="as-status-label">{s.label}</div>
            </div>
            {status === 'approved' && d?.approved_at && <div className="as-date"><span>تاريخ الاعتماد</span><b className="num">{String(d.approved_at).slice(0, 10)}</b></div>}
          </div>

          {reason && <p className="luxury-form-error" role="alert"><b>{status === 'rejected' ? 'سبب الرفض: ' : 'سبب الإيقاف: '}</b>{reason}</p>}
          {status === 'pending' && <p className="luxury-success">المراجعة عادةً تستغرق من يوم إلى يومي عمل. هيوصلك إشعار بمجرد تغيير الحالة.</p>}

          <div className="as-block">
            <div className="as-block-title">بيانات الحساب والشركة</div>
            <dl className="as-kv">
              <dt>اسم التاجر</dt><dd>{d?.name}</dd>
              <dt>الموبايل</dt><dd className="num">{d?.phone}</dd>
              <dt>البريد</dt><dd className="num">{d?.email}</dd>
              <dt>الشركة</dt><dd>{d?.company_name}</dd>
              <dt>الرقم الضريبي</dt><dd className="num">{d?.tax_number}</dd>
              <dt>السجل التجاري</dt><dd>{d?.commercial_register}</dd>
            </dl>
          </div>

          <div className="as-block">
            <div className="as-block-title">المستندات <span className="num" style={{ color: '#a3a3a3' }}>{docs.length}</span></div>
            <ul className="luxury-file-list as-docs">
              {docs.map((doc) => {
                const [l, v] = DOC_STATUS[doc.status] || DOC_STATUS.pending;
                const rej = status !== 'approved' && doc.status === 'rejected';
                return (
                  <li key={doc.name} className={rej ? 'is-rej' : ''}>
                    <span>
                      <i className={`fa-regular ${doc.name.endsWith('.pdf') ? 'fa-file-pdf' : 'fa-file-image'}`} />
                      <span><b>{doc.type}</b><small className="num">{doc.name}</small></span>
                    </span>
                    <span className={`badge ${v}`}>{l}</span>
                  </li>
                );
              })}
            </ul>
            {(status === 'rejected' || docs.some((x) => x.status === 'rejected')) && status !== 'approved' && (
              <p className="luxury-field-hint" style={{ marginBottom: 0 }}><i className="fa-solid fa-circle-info" /> إعادة رفع المستندات غير متاحة من البوابة حاليًا — تواصل مع فريق المبيعات لاستكمالها.</p>
            )}
          </div>

          {enterError && <p className="luxury-form-error" role="alert">{enterError}</p>}
          {status === 'approved'
            ? <button className="pill-btn-primary" disabled={entering} onClick={enter}>الدخول إلى بوابة الجملة</button>
            : <button className="pill-btn-primary" onClick={api.reload}>تحديث حالة الطلب</button>}
          <div className="luxury-bottom-switch" style={{ marginTop: 4 }}>
            <button className="luxury-switch-link" onClick={onLogout}>تسجيل الخروج</button>
          </div>
        </div>
      )}
    </AuthFrame>
  );

  return <DataBoundary api={api}>{inner}</DataBoundary>;
};

/* ---------- 5. Forgot / reset password ---------- */
export const ForgotPassword = ({ onBack, onGoReset, onForgotSubmit }) => {
  const [login, setLogin] = useState('');
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const submit = async (e) => {
    e.preventDefault();
    if (!login.trim()) { setErr('أدخل البريد الإلكتروني أو رقم الموبايل.'); return; }
    if (!onForgotSubmit) { setErr('خدمة الاستعادة غير متاحة حاليًا.'); return; }
    setErr(''); setBusy(true);
    try {
      await onForgotSubmit(login.trim());
      setSent(true);
    } catch (er) {
      setErr(apiError(er).message);
    } finally {
      setBusy(false);
    }
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

const readResetParams = () => {
  if (typeof window === 'undefined') return { token: '', login: '' };
  const p = new URLSearchParams(window.location.search);
  return { token: p.get('token') || '', login: p.get('email') || p.get('phone') || '' };
};

export const ResetPassword = ({ onBack, linkState = 'valid', onForgot, onResetSubmit }) => {
  const [{ token: urlToken, login: urlLogin }] = useState(readResetParams);
  const [show, setShow] = useState(false);
  const [login, setLogin] = useState(urlLogin);
  const [p1, setP1] = useState(''); const [p2, setP2] = useState('');
  const [errs, setErrs] = useState({});
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const token = urlToken;
  const submit = async (e) => {
    e.preventDefault();
    const er = {};
    if (!login.trim()) er.login = 'أدخل البريد الإلكتروني أو رقم الموبايل.';
    if (!token) er.token = 'رابط إعادة التعيين ناقص الرمز (token).';
    if (p1.length < 8) er.p1 = 'كلمة المرور لازم تكون 8 حروف على الأقل.';
    if (p1 !== p2) er.p2 = 'تأكيد كلمة المرور غير مطابق.';
    setErrs(er);
    if (Object.keys(er).length) return;
    if (!onResetSubmit) { setErrs({ ...er, token: 'خدمة إعادة التعيين غير متاحة حاليًا.' }); return; }
    setBusy(true);
    try {
      await onResetSubmit(login.trim(), token, p1, p2);
      setDone(true);
    } catch (err) {
      const info = apiError(err);
      const mapped = {};
      const fields = { password: 'p1', password_confirmation: 'p2', email: 'login', phone: 'login' };
      Object.entries(info.fields || {}).forEach(([k, msgs]) => { mapped[fields[k] || k] = Array.isArray(msgs) ? msgs[0] : String(msgs); });
      setErrs({ ...mapped, ...(Object.keys(mapped).length ? {} : { p1: info.message }) });
    } finally {
      setBusy(false);
    }
  };
  const rules = [[p1.length >= 8, '8 حروف على الأقل'], [/[A-Za-z]/.test(p1) && /\d/.test(p1), 'حروف وأرقام'], [p1 && p1 === p2, 'التأكيد مطابق']];
  if (linkState !== 'valid' || !token) return (
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
            <label className="luxury-field-label">البريد الإلكتروني أو رقم الموبايل<span className="luxury-req-mark">*</span></label>
            <input className="pill-input" dir="ltr" value={login} onChange={(e) => setLogin(e.target.value)} aria-invalid={!!errs.login} />
            {errs.login && <p className="luxury-field-error" role="alert">{errs.login}</p>}
            {errs.token && <p className="luxury-field-error" role="alert">{errs.token}</p>}
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
          <button className="pill-btn-primary" disabled={busy}>{busy ? 'جارٍ الحفظ…' : 'حفظ كلمة المرور'}</button>
          <div className="luxury-bottom-switch"><button type="button" className="luxury-switch-link" onClick={onBack}>العودة لتسجيل الدخول</button></div>
        </form>
      )}
    </AuthFrame>
  );
};
