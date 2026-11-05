import { useEffect, useState } from 'react';
import { assistantApi, type AgentSettings } from './api';

type Identity = { id: number; user_id: number; name: string | null; phone_e164: string; status: string; has_pin: boolean };
type LinkQr = { url: string; qr_data_url: string | null; expires_at: string };
const messageOf = (error: unknown) => (error as { response?: { data?: { message?: string } } }).response?.data?.message ?? 'تعذر تنفيذ الطلب.';

export default function WhatsAppPanel() {
  const [status, setStatus] = useState<{ state: string; phone?: string | null } | null>(null);
  const [qr, setQr] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [identities, setIdentities] = useState<Identity[]>([]);
  const [staff, setStaff] = useState<{ id: number; name: string }[]>([]);
  const [userId, setUserId] = useState<number | ''>('');
  const [phone, setPhone] = useState('');
  const [pin, setPin] = useState('');
  const [query, setQuery] = useState('');
  const [link, setLink] = useState<LinkQr | null>(null);
  const [settings, setSettings] = useState<AgentSettings>();
  const [original, setOriginal] = useState<AgentSettings>();
  const [monitor, setMonitor] = useState<Awaited<ReturnType<typeof assistantApi.whatsappMonitor>>>();

  useEffect(() => {
    let live = true;
    const tick = () => {
      assistantApi.whatsappStatus().then(async next => {
        if (!live) return;
        setStatus(next);
        if (next.state === 'awaiting_scan') {
          const image = await assistantApi.whatsappQr().catch(() => null);
          if (live) setQr(image?.data_url ?? null);
        } else if (live) setQr(null);
      }).catch(error => { if (live) setNotice(messageOf(error)); });
    };
    tick();
    const timer = window.setInterval(tick, 3000);
    const refreshMonitor = () => {
      assistantApi.whatsappMonitor().then(result => { if (live) setMonitor(result); }).catch(() => undefined);
    };
    refreshMonitor();
    const monitorTimer = window.setInterval(refreshMonitor, 15000);
    assistantApi.whatsappIdentities().then(rows => { if (live) setIdentities(rows); }).catch(() => undefined);
    assistantApi.settings().then(result => { if (live) { setSettings(result.data); setOriginal(result.data); } }).catch(() => undefined);
    return () => { live = false; window.clearInterval(timer); window.clearInterval(monitorTimer); };
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      assistantApi.whatsappStaff(query).then(setStaff).catch(() => setStaff([]));
    }, 250);
    return () => window.clearTimeout(timer);
  }, [query]);

  const saveIdentity = async () => {
    if (!userId || !phone) return;
    setNotice(null);
    try {
      await assistantApi.whatsappSaveIdentity({ user_id: Number(userId), phone, ...(pin ? { pin } : {}) });
      setPhone('');
      setPin('');
      setIdentities(await assistantApi.whatsappIdentities());
      setNotice('اتسجل الرقم وباقي يمسح الموظف باركود الربط.');
    } catch (error) { setNotice(messageOf(error)); }
  };

  const saveChannel = async () => {
    if (!settings || !original) return;
    const keys = ['ai_whatsapp_enabled', 'ai_whatsapp_allow_writes', 'ai_whatsapp_pin_required', 'ai_whatsapp_max_risk', 'ai_whatsapp_idle_rotate_hours', 'ai_whatsapp_link_token_ttl_minutes'] as const;
    const data = Object.fromEntries(keys.map(key => [key, settings[key]])) as unknown as AgentSettings;
    const expected = Object.fromEntries(keys.map(key => [key, original[key]])) as unknown as AgentSettings;
    try {
      await assistantApi.saveSettings(data, expected);
      setOriginal({ ...original, ...data });
      setNotice('اتحفظت إعدادات واتساب.');
    } catch (error) { setNotice(messageOf(error)); }
  };

  return (
    <div className="as-admin-grid">
      {notice && <div className="as-alert as-alert--info" role="status"><div className="as-alert-body"><span>{notice}</span></div></div>}
      <section className="as-card" aria-label="ربط جهاز البوت">
        <div className="as-card-head"><h3>ربط جهاز البوت</h3><span className="as-badge">{status?.state ?? '...'}</span></div>
        <div className="as-card-body">
          <p>الرقم المربوط: <b dir="ltr">{status?.phone || '—'}</b></p>
          <p>الباركود ده للجهاز مرة واحدة. رقم البوت يكون رقم مخصص، مش رقم شخصي.</p>
          {qr && <img alt="باركود ربط جهاز واتساب" src={qr} width={280} height={280} />}
          <button type="button" className="as-btn" disabled={status?.state !== 'connected'} onClick={() => void assistantApi.whatsappLogout().then(() => setNotice('اتفك الربط.')).catch(error => setNotice(messageOf(error)))}>فك الربط</button>
        </div>
      </section>

      {settings && (
        <section className="as-card" aria-label="إعدادات قناة واتساب">
          <div className="as-card-head"><h3>قناة واتساب</h3></div>
          <div className="as-form">
            <label className="as-field as-field--switch"><span className="as-field-label"><strong>تشغيل واتساب</strong><small>مقفل افتراضيًا. الردود تروح للأرقام المربوطة بس.</small></span>
              <input type="checkbox" role="switch" className="as-switch" checked={!!settings.ai_whatsapp_enabled} onChange={e => setSettings({ ...settings, ai_whatsapp_enabled: e.target.checked })} /></label>
            <label className="as-field as-field--switch"><span className="as-field-label"><strong>السماح بالكتابة</strong><small>سيبه مقفل في أول تشغيل عشان المساعد يقرأ بس.</small></span>
              <input type="checkbox" role="switch" className="as-switch" checked={!!settings.ai_whatsapp_allow_writes} onChange={e => setSettings({ ...settings, ai_whatsapp_allow_writes: e.target.checked })} /></label>
            <label className="as-field as-field--switch"><span className="as-field-label"><strong>رقم سري للجلسة</strong><small>بعد الربط، الموظف يفتح الجلسة بـ «فتح» والرقم.</small></span>
              <input type="checkbox" role="switch" className="as-switch" checked={!!settings.ai_whatsapp_pin_required} onChange={e => setSettings({ ...settings, ai_whatsapp_pin_required: e.target.checked })} /></label>
            <label className="as-field"><span className="as-field-label"><strong>أعلى خطورة</strong></span>
              <select className="as-input" value={settings.ai_whatsapp_max_risk ?? 'medium'} onChange={e => setSettings({ ...settings, ai_whatsapp_max_risk: e.target.value as AgentSettings['ai_whatsapp_max_risk'] })}>
                <option value="low">منخفضة</option><option value="medium">متوسطة</option><option value="high">عالية</option>
              </select></label>
            <label className="as-field"><span className="as-field-label"><strong>تدوير المحادثة بعد الخمول (ساعات)</strong></span>
              <input className="as-input" type="number" min={1} max={168} value={settings.ai_whatsapp_idle_rotate_hours ?? 12} onChange={e => setSettings({ ...settings, ai_whatsapp_idle_rotate_hours: Number(e.target.value) })} /></label>
            <label className="as-field"><span className="as-field-label"><strong>صلاحية رابط الموظف (دقائق)</strong></span>
              <input className="as-input" type="number" min={1} max={60} value={settings.ai_whatsapp_link_token_ttl_minutes ?? 10} onChange={e => setSettings({ ...settings, ai_whatsapp_link_token_ttl_minutes: Number(e.target.value) })} /></label>
            <button type="button" className="as-btn as-btn--primary" onClick={() => void saveChannel()}>حفظ إعدادات واتساب</button>
          </div>
        </section>
      )}

      <section className="as-card" aria-label="أرقام واتساب المسموحة">
        <div className="as-card-head"><h3>أرقام الموظفين</h3></div>
        <div className="as-form">
          <label className="as-field"><span className="as-field-label"><strong>بحث عن موظف</strong></span>
            <input className="as-input" value={query} onChange={e => setQuery(e.target.value)} placeholder="الاسم" /></label>
          <label className="as-field"><span className="as-field-label"><strong>الموظف</strong></span>
            <select className="as-input" value={userId} onChange={e => setUserId(e.target.value ? Number(e.target.value) : '')}>
              <option value="">اختار</option>
              {staff.map(person => <option key={person.id} value={person.id}>{person.name}</option>)}
            </select></label>
          <label className="as-field"><span className="as-field-label"><strong>رقم واتساب</strong><small>صيغة دولية، مثل +201012345678. مش رقم users.phone.</small></span>
            <input className="as-input" dir="ltr" value={phone} onChange={e => setPhone(e.target.value)} /></label>
          <label className="as-field"><span className="as-field-label"><strong>رقم سري اختياري</strong></span>
            <input className="as-input" type="password" inputMode="numeric" value={pin} onChange={e => setPin(e.target.value)} /></label>
          <button type="button" className="as-btn as-btn--primary" onClick={() => void saveIdentity()}>إضافة الرقم</button>
        </div>
        <div className="as-table-wrap">
          <table className="as-table">
            <thead><tr><th>الموظف</th><th>الرقم</th><th>الحالة</th><th></th></tr></thead>
            <tbody>
              {identities.map(row => (
                <tr key={row.id}>
                  <td>{row.name}</td>
                  <td dir="ltr">{row.phone_e164}</td>
                  <td>{row.status}{row.has_pin ? ' · PIN' : ''}</td>
                  <td>
                    <button type="button" className="as-btn as-btn--sm" disabled={row.status !== 'pending'} onClick={() => void assistantApi.whatsappLink(row.id).then(setLink).catch(error => setNotice(messageOf(error)))}>باركود الربط</button>
                    <button type="button" className="as-btn as-btn--sm" disabled={row.status === 'revoked'} onClick={() => void assistantApi.whatsappRevoke(row.id).then(() => {
                      setLink(null);
                      return assistantApi.whatsappIdentities().then(setIdentities);
                    }).catch(error => setNotice(messageOf(error)))}>إلغاء الربط</button>
                  </td>
                </tr>
              ))}
              {!identities.length && <tr><td colSpan={4}>لسه مفيش أرقام.</td></tr>}
            </tbody>
          </table>
        </div>
        {link && (
          <div className="as-card-body">
            {link.qr_data_url && <img alt="باركود ربط الموظف" src={link.qr_data_url} width={240} height={240} />}
            <p dir="ltr">{link.url}</p>
            <p>ينتهي {link.expires_at}</p>
          </div>
        )}
      </section>

      <section className="as-card" aria-label="مراقبة واتساب">
        <div className="as-card-head"><h3>آخر الرسائل</h3><span className="as-badge">{monitor?.gateway.state ?? '—'}</span></div>
        {!!monitor?.counts.length && <ul aria-label="ملخص آخر 24 ساعة">
          {monitor.counts.map(row => <li key={row.status + (row.ignore_reason ?? '')}>
            {row.status}{row.ignore_reason && ` (${row.ignore_reason})`} · {row.aggregate}
          </li>)}
        </ul>}
        <div className="as-table-wrap">
          <table className="as-table">
            <thead><tr><th>الحالة</th><th>السبب</th><th>وقت الوصول</th><th>مدة المعالجة</th></tr></thead>
            <tbody>
              {monitor?.recent.map(row => {
                const seconds = (Date.parse(row.updated_at) - Date.parse(row.created_at)) / 1000;
                return <tr key={row.id}><td>{row.status}</td><td>{row.ignore_reason ?? '—'}</td><td dir="ltr">{row.created_at}</td>
                  <td>{row.status === 'queued' || !Number.isFinite(seconds) ? '—' : `${seconds} ث`}</td></tr>;
              })}
              {!monitor?.recent.length && <tr><td colSpan={4}>مفيش رسائل.</td></tr>}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}
