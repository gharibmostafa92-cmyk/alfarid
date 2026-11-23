import { useEffect, useState } from 'react';
import { assistantApi, diffSettings, isSafeQrDataUrl, type AgentSettings } from './api';
import { Icon } from './icons';

type Identity = { id: number; user_id: number; name: string | null; phone_e164: string; status: string; has_pin: boolean };
type LinkQr = { url: string; qr_data_url: string | null; expires_at: string };
type Notice = { tone: 'success' | 'danger' | 'info'; text: string };

const messageOf = (error: unknown) => (error as { response?: { data?: { message?: string } } }).response?.data?.message ?? 'تعذر تنفيذ الطلب.';

const STATE_META: Record<string, { label: string; badge: string; dot?: boolean }> = {
  connected: { label: 'متصل', badge: 'as-badge--success', dot: true },
  awaiting_scan: { label: 'في انتظار المسح', badge: 'as-badge--warning', dot: true },
  connecting: { label: 'جارٍ الاتصال…', badge: 'as-badge--info', dot: true },
  disconnected: { label: 'غير متصل', badge: 'as-badge--danger' },
  active: { label: 'مربوط', badge: 'as-badge--success' },
  pending: { label: 'في انتظار الربط', badge: 'as-badge--warning' },
  revoked: { label: 'ملغي', badge: 'as-badge--danger' },
  queued: { label: 'في الطابور', badge: 'as-badge--info' },
  processing: { label: 'قيد المعالجة', badge: 'as-badge--info' },
  replied: { label: 'تم الرد', badge: 'as-badge--success' },
  completed: { label: 'مكتمل', badge: 'as-badge--success' },
  ignored: { label: 'تم التجاهل', badge: '' },
  failed: { label: 'فشل', badge: 'as-badge--danger' },
};

const stateMeta = (state?: string | null) =>
  (state && STATE_META[state]) || { label: state || '—', badge: '' };

const formatShortTime = (iso?: string | null) => {
  if (!iso) return '—';
  const ms = Date.parse(iso);
  if (!Number.isFinite(ms)) return iso;
  return new Intl.DateTimeFormat('ar-EG', {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(ms);
};

// WhatsApp channel keys owned by this tab; saves send only these (delta).
const CHANNEL_KEYS = ['ai_whatsapp_enabled', 'ai_whatsapp_allow_writes', 'ai_whatsapp_pin_required', 'ai_whatsapp_max_risk', 'ai_whatsapp_idle_rotate_hours', 'ai_whatsapp_link_token_ttl_minutes'] as const;

export default function WhatsAppPanel({ settings, original, onSettingsChange, onSaved }: {
  // Single source of truth lives in AssistantAdministration: both tabs edit
  // the same snapshot, so saving one tab can never 409-loop or overwrite the
  // other tab's pending edits with stale values.
  settings: AgentSettings | undefined;
  original: AgentSettings | undefined;
  onSettingsChange: (next: AgentSettings) => void;
  onSaved: (snapshot: AgentSettings) => void;
}) {
  const [status, setStatus] = useState<{ state: string; phone?: string | null } | null>(null);
  const [qr, setQr] = useState<string | null>(null);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [identities, setIdentities] = useState<Identity[]>([]);
  const [staff, setStaff] = useState<{ id: number; name: string }[]>([]);
  const [userId, setUserId] = useState<number | ''>('');
  const [phone, setPhone] = useState('');
  const [pin, setPin] = useState('');
  const [query, setQuery] = useState('');
  const [link, setLink] = useState<LinkQr | null>(null);
  const [copiedKey, setCopiedKey] = useState<string | null>(null);
  const [mobileTab, setMobileTab] = useState<'all' | 'bot' | 'staff' | 'logs'>('all');
  const [addFormOpen, setAddFormOpen] = useState(false);
  const [refreshingStatus, setRefreshingStatus] = useState(false);
  const [refreshingMonitor, setRefreshingMonitor] = useState(false);
  const [monitor, setMonitor] = useState<Awaited<ReturnType<typeof assistantApi.whatsappMonitor>>>();
  const [loggingOut, setLoggingOut] = useState(false);
  const [savingChannel, setSavingChannel] = useState(false);
  const [savingIdentity, setSavingIdentity] = useState(false);
  const [channelDrafts, setChannelDrafts] = useState<Record<string, string>>({});

  // Sequential chained polling: the next tick is scheduled only after the
  // previous request settles, so a slow gateway can never pile overlapping
  // requests. Hidden tabs pause entirely and refresh on return.
  useEffect(() => {
    let live = true;
    let timer = 0;
    let inFlight = false;
    const schedule = (ms: number) => { if (live) timer = window.setTimeout(tick, ms); };
    const tick = () => {
      if (!live) return;
      if (document.hidden || inFlight) { schedule(3000); return; }
      inFlight = true;
      assistantApi.whatsappStatus().then(async next => {
        if (!live) return;
        setStatus(next);
        if (next.state === 'awaiting_scan') {
          const image = await assistantApi.whatsappQr().catch(() => null);
          if (live) setQr(isSafeQrDataUrl(image?.data_url) ? image.data_url : null);
        } else if (live) setQr(null);
      }).catch(error => { if (live) setNotice({ tone: 'danger', text: messageOf(error) }); })
        .finally(() => { inFlight = false; schedule(3000); });
    };
    const onVisible = () => { if (live && !document.hidden) { window.clearTimeout(timer); tick(); } };
    document.addEventListener('visibilitychange', onVisible);
    tick();
    let monitorTimer = 0;
    let monitorFlight = false;
    const monitorTick = () => {
      if (!live) return;
      if (document.hidden || monitorFlight) { if (live) monitorTimer = window.setTimeout(monitorTick, 15000); return; }
      monitorFlight = true;
      assistantApi.whatsappMonitor().then(result => { if (live) setMonitor(result); }).catch(() => undefined)
        .finally(() => { monitorFlight = false; if (live) monitorTimer = window.setTimeout(monitorTick, 15000); });
    };
    monitorTick();
    assistantApi.whatsappIdentities().then(rows => { if (live) setIdentities(rows); }).catch(() => undefined);
    return () => { live = false; window.clearTimeout(timer); window.clearTimeout(monitorTimer); document.removeEventListener('visibilitychange', onVisible); };
  }, []);

  useEffect(() => {
    let live = true;
    const timer = window.setTimeout(() => {
      assistantApi.whatsappStaff(query).then(rows => {
        if (!live) return;
        setStaff(rows);
        setUserId(prev => (prev !== '' && !rows.some(r => r.id === prev) ? '' : prev));
      }).catch(() => { if (live) setStaff([]); });
    }, 250);
    return () => { live = false; window.clearTimeout(timer); };
  }, [query]);

  const saveIdentity = async () => {
    if (!userId || !phone || savingIdentity) return;
    setSavingIdentity(true);
    setNotice(null);
    try {
      await assistantApi.whatsappSaveIdentity({ user_id: Number(userId), phone, ...(pin ? { pin } : {}) });
      setUserId('');
      setPhone('');
      setPin('');
      setAddFormOpen(false);
      setIdentities(await assistantApi.whatsappIdentities());
      setNotice({ tone: 'success', text: 'اتسجل الرقم وباقي يمسح الموظف باركود الربط.' });
    } catch (error) { setNotice({ tone: 'danger', text: messageOf(error) }); }
    finally { setSavingIdentity(false); }
  };

  // Sends only this tab's changed keys: the backend 422s unless data and
  // expected carry identical key sets, so a full-object save would fail on
  // untouched keys whose snapshot drifted.
  const saveChannel = async () => {
    if (!settings || !original || savingChannel) return;
    const idleDraft = channelDrafts.ai_whatsapp_idle_rotate_hours;
    const ttlDraft = channelDrafts.ai_whatsapp_link_token_ttl_minutes;
    const idleHours = idleDraft !== undefined ? Number(idleDraft) : (settings.ai_whatsapp_idle_rotate_hours ?? 12);
    const ttlMinutes = ttlDraft !== undefined ? Number(ttlDraft) : (settings.ai_whatsapp_link_token_ttl_minutes ?? 10);
    if (!Number.isInteger(idleHours) || idleHours < 1 || idleHours > 168) {
      setNotice({ tone: 'danger', text: 'ساعات تدوير المحادثة لازم تكون رقم صحيح بين 1 و 168.' });
      return;
    }
    if (!Number.isInteger(ttlMinutes) || ttlMinutes < 1 || ttlMinutes > 60) {
      setNotice({ tone: 'danger', text: 'صلاحية رابط الموظف لازم تكون رقم صحيح بين 1 و 60 دقيقة.' });
      return;
    }
    const { data, expected } = diffSettings(settings, original, [...CHANNEL_KEYS]);
    if (Object.keys(data).length === 0) { setNotice({ tone: 'info', text: 'مفيش تعديلات للحفظ.' }); return; }
    setSavingChannel(true);
    try {
      await assistantApi.saveSettings(data, expected);
      onSaved({ ...original, ...data });
      setChannelDrafts({});
      setNotice({ tone: 'success', text: 'اتحفظت إعدادات واتساب.' });
    } catch (error) { setNotice({ tone: 'danger', text: messageOf(error) }); }
    finally { setSavingChannel(false); }
  };

  // Never commit an empty numeric field: Number('') is 0, and 0 means
  // "unlimited" for the daily budget. Keep draft text while editing.
  const setChannelNumber = (key: 'ai_whatsapp_idle_rotate_hours' | 'ai_whatsapp_link_token_ttl_minutes', raw: string) => {
    setChannelDrafts(current => ({ ...current, [key]: raw }));
    if (!settings || raw.trim() === '') return;
    const n = Number(raw);
    if (Number.isInteger(n)) onSettingsChange({ ...settings, [key]: n });
  };

  const copyText = async (key: string, value: string) => {
    try {
      await navigator.clipboard?.writeText(value);
      setCopiedKey(key);
      window.setTimeout(() => setCopiedKey(current => (current === key ? null : current)), 1800);
    } catch {
      setNotice({ tone: 'info', text: 'تعذر النسخ التلقائي — انسخ القيمة يدويًا.' });
    }
  };

  const refreshBotNow = async () => {
    if (refreshingStatus) return;
    setRefreshingStatus(true);
    try {
      const next = await assistantApi.whatsappStatus();
      setStatus(next);
      if (next.state === 'awaiting_scan') {
        const image = await assistantApi.whatsappQr().catch(() => null);
        setQr(isSafeQrDataUrl(image?.data_url) ? image.data_url : null);
      } else {
        setQr(null);
      }
    } catch (error) {
      setNotice({ tone: 'danger', text: messageOf(error) });
    } finally {
      setRefreshingStatus(false);
    }
  };

  const refreshMonitorNow = async () => {
    if (refreshingMonitor) return;
    setRefreshingMonitor(true);
    try {
      setMonitor(await assistantApi.whatsappMonitor());
    } catch (error) {
      setNotice({ tone: 'danger', text: messageOf(error) });
    } finally {
      setRefreshingMonitor(false);
    }
  };

  const botMeta = stateMeta(status?.state);
  const gatewayMeta = stateMeta(monitor?.gateway.state);
  const channelDirty = !!settings && !!original && Object.keys(diffSettings(settings, original, [...CHANNEL_KEYS]).data).length > 0;
  const showAddForm = addFormOpen || identities.length === 0;
  const activeIdentitiesCount = identities.filter(row => row.status === 'active').length;
  const pendingIdentitiesCount = identities.filter(row => row.status === 'pending').length;
  const total24hMessages = monitor?.counts.reduce((sum, row) => sum + Number(row.aggregate || 0), 0) ?? 0;
  const failed24hMessages = monitor?.counts
    .filter(row => row.status === 'failed')
    .reduce((sum, row) => sum + Number(row.aggregate || 0), 0) ?? 0;

  return (
    <div className="as-admin-grid as-wa-grid" data-wa-tab={mobileTab}>
      {notice && (
        <div className={`as-alert as-alert--${notice.tone} as-wa-notice`} role="status">
          <Icon name={notice.tone === 'success' ? 'check' : notice.tone === 'danger' ? 'alert' : 'info'} size={16} />
          <div className="as-alert-body"><span>{notice.text}</span></div>
          <button type="button" className="as-iconbtn as-iconbtn--sm" aria-label="إخفاء" onClick={() => setNotice(null)}>
            <Icon name="x" size={14} />
          </button>
        </div>
      )}

      <div className="as-wa-mobile-head as-only-mobile">
        <div className="as-wa-overview" aria-label="ملخص حالة واتساب">
          <button type="button" className="as-wa-pill" onClick={() => setMobileTab('bot')}>
            <span className="as-wa-pill-label">البوت</span>
            <span className={`as-badge ${botMeta.badge}`}>
              {botMeta.dot && <i className="as-wa-dot as-wa-dot--pulse" aria-hidden="true" />}
              {botMeta.label}
            </span>
          </button>
          <button type="button" className="as-wa-pill" onClick={() => setMobileTab('logs')}>
            <span className="as-wa-pill-label">البوابة</span>
            <span className={`as-badge ${gatewayMeta.badge}`}>
              {gatewayMeta.dot && <i className="as-wa-dot" aria-hidden="true" />}
              {gatewayMeta.label}
            </span>
          </button>
          <button type="button" className="as-wa-pill" onClick={() => setMobileTab('staff')}>
            <span className="as-wa-pill-label">الموظفين</span>
            <strong className="as-num">{identities.length}</strong>
          </button>
        </div>

        <div className="as-seg as-wa-subnav" role="tablist" aria-label="أقسام واتساب">
          <button type="button" role="tab" className="as-seg-btn" aria-selected={mobileTab === 'all'} onClick={() => setMobileTab('all')}>الكل</button>
          <button type="button" role="tab" className="as-seg-btn" aria-selected={mobileTab === 'bot'} onClick={() => setMobileTab('bot')}>
            البوت
            {channelDirty && <i className="as-seg-dot" aria-hidden="true" />}
          </button>
          <button type="button" role="tab" className="as-seg-btn" aria-selected={mobileTab === 'staff'} onClick={() => setMobileTab('staff')}>الموظفين</button>
          <button type="button" role="tab" className="as-seg-btn" aria-selected={mobileTab === 'logs'} onClick={() => setMobileTab('logs')}>الرسائل</button>
        </div>
      </div>

      <div className="as-wa-desktop-strip as-only-desktop" aria-label="ملخص حالة واتساب">
        <div className="as-wa-kpi">
          <div className="as-wa-kpi-top">
            <span>حالة البوت</span>
            <span className={`as-badge ${botMeta.badge}`}>
              {botMeta.dot && <i className="as-wa-dot as-wa-dot--pulse" aria-hidden="true" />}
              {botMeta.label}
            </span>
          </div>
          <div className="as-wa-kpi-val">
            <strong dir="ltr" className="as-num">{status?.phone || 'غير مربوط'}</strong>
            {status?.phone && (
              <button type="button" className="as-iconbtn as-iconbtn--sm" aria-label="نسخ رقم البوت" onClick={() => void copyText('kpi-bot-phone', status.phone!)}>
                <Icon name={copiedKey === 'kpi-bot-phone' ? 'check' : 'copy'} size={13} />
              </button>
            )}
          </div>
          <small>{status?.state === 'connected' ? 'الجهاز متصل وجاهز للاستقبال' : 'اربط رقم مخصص للبوت'}</small>
        </div>

        <div className="as-wa-kpi">
          <div className="as-wa-kpi-top">
            <span>بوابة واتساب</span>
            <span className={`as-badge ${gatewayMeta.badge}`}>
              {gatewayMeta.dot && <i className="as-wa-dot" aria-hidden="true" />}
              {gatewayMeta.label}
            </span>
          </div>
          <div className="as-wa-kpi-val">
            <strong className="as-num">{settings?.ai_whatsapp_enabled ? 'القناة مفعّلة' : 'القناة مقفلة'}</strong>
          </div>
          <small>{settings?.ai_whatsapp_allow_writes ? 'مسموح بالقراءة والكتابة' : 'وضع القراءة فقط'}</small>
        </div>

        <div className="as-wa-kpi">
          <div className="as-wa-kpi-top">
            <span>أرقام الموظفين</span>
            <span className="as-badge as-num">{identities.length}</span>
          </div>
          <div className="as-wa-kpi-val">
            <strong className="as-num">{activeIdentitiesCount} مربوط</strong>
          </div>
          <small>{pendingIdentitiesCount > 0 ? `${pendingIdentitiesCount} في انتظار الربط` : 'كل الأرقام المسجلة مفعّلة'}</small>
        </div>

        <div className="as-wa-kpi">
          <div className="as-wa-kpi-top">
            <span>رسائل ٢٤ ساعة</span>
            {failed24hMessages > 0 && <span className="as-badge as-badge--danger as-num">{failed24hMessages} فشل</span>}
          </div>
          <div className="as-wa-kpi-val">
            <strong className="as-num">{total24hMessages}</strong>
          </div>
          <small>{monitor?.recent.length ? `آخر رسالة ${formatShortTime(monitor.recent[0].created_at)}` : 'لا توجد رسائل حديثة'}</small>
        </div>
      </div>

      <section className="as-card as-wa-sec" data-wa-sec="bot" aria-label="ربط جهاز البوت">
        <div className="as-card-head">
          <h3>ربط جهاز البوت</h3>
          <div className="as-wa-head-actions">
            <span className={`as-badge ${botMeta.badge}`}>
              {botMeta.dot && <i className="as-wa-dot as-wa-dot--pulse" aria-hidden="true" />}
              {botMeta.label}
            </span>
            <button type="button" className="as-iconbtn as-iconbtn--sm" aria-label="تحديث حالة البوت" onClick={() => void refreshBotNow()} disabled={refreshingStatus}>
              <Icon name="refresh" size={14} className={refreshingStatus ? 'as-spin' : undefined} />
            </button>
          </div>
        </div>
        <div className="as-card-body as-wa-stack">
          <div className="as-wa-kv">
            <span>الرقم المربوط</span>
            <div className="as-wa-kv-val">
              <b dir="ltr" className="as-num">{status?.phone || '—'}</b>
              {status?.phone && (
                <button type="button" className="as-iconbtn as-iconbtn--sm" aria-label="نسخ رقم البوت" onClick={() => void copyText('bot-phone', status.phone!)}>
                  <Icon name={copiedKey === 'bot-phone' ? 'check' : 'copy'} size={14} />
                </button>
              )}
            </div>
          </div>
          <p className="as-wa-hint">الباركود ده للجهاز مرة واحدة. رقم البوت يكون رقم مخصص، مش رقم شخصي.</p>
          {status?.state === 'connected' && !qr && (
            <div className="as-wa-device-ok">
              <span className="as-wa-device-icon" aria-hidden="true">
                <Icon name="check" size={18} />
              </span>
              <div>
                <strong>الجهاز متصل بنجاح</strong>
                <small>يستقبل الرسائل من الأرقام المربوطة فقط وفق إعدادات القناة.</small>
              </div>
            </div>
          )}
          {qr && (
            <div className="as-wa-qr-wrap">
              <div className="as-wa-qr">
                <img alt="باركود ربط جهاز واتساب" src={qr} width={220} height={220} />
              </div>
              <small>امسح الباركود من واتساب ← الأجهزة المرتبطة ← ربط جهاز</small>
            </div>
          )}
        </div>
        <div className="as-wa-card-foot">
          <button
            type="button"
            className={`as-btn as-wa-btn-full ${status?.state === 'connected' ? 'as-btn--danger' : ''}`}
            disabled={status?.state !== 'connected' || loggingOut}
            onClick={() => {
              if (loggingOut) return;
              setLoggingOut(true);
              void assistantApi.whatsappLogout()
                .then(() => { setStatus({ state: 'disconnected', phone: null }); setNotice({ tone: 'success', text: 'اتفك الربط.' }); })
                .catch(error => setNotice({ tone: 'danger', text: messageOf(error) }))
                .finally(() => setLoggingOut(false));
            }}
          >
            {loggingOut ? 'جارٍ فك الربط…' : 'فك الربط'}
          </button>
        </div>
      </section>

      {settings && (
        <section className="as-card as-wa-sec" data-wa-sec="bot" aria-label="إعدادات قناة واتساب">
          <div className="as-card-head">
            <h3>قناة واتساب</h3>
            {channelDirty && <span className="as-dirty">تعديلات غير محفوظة</span>}
          </div>
          <div className="as-form">
            <label className="as-field as-field--switch"><span className="as-field-label"><strong>تشغيل واتساب</strong><small>مقفل افتراضيًا. الردود تروح للأرقام المربوطة بس.</small></span>
              <input type="checkbox" role="switch" className="as-switch" checked={!!settings.ai_whatsapp_enabled} onChange={e => onSettingsChange({ ...settings, ai_whatsapp_enabled: e.target.checked })} /></label>
            <label className="as-field as-field--switch"><span className="as-field-label"><strong>السماح بالكتابة</strong><small>سيبه مقفل في أول تشغيل عشان المساعد يقرأ بس.</small></span>
              <input type="checkbox" role="switch" className="as-switch" checked={!!settings.ai_whatsapp_allow_writes} onChange={e => onSettingsChange({ ...settings, ai_whatsapp_allow_writes: e.target.checked })} /></label>
            <label className="as-field as-field--switch"><span className="as-field-label"><strong>رقم سري للجلسة</strong><small>بعد الربط، الموظف يفتح الجلسة بـ «فتح» والرقم.</small></span>
              <input type="checkbox" role="switch" className="as-switch" checked={!!settings.ai_whatsapp_pin_required} onChange={e => onSettingsChange({ ...settings, ai_whatsapp_pin_required: e.target.checked })} /></label>
            <label className="as-field"><span className="as-field-label"><strong>أعلى خطورة</strong></span>
              <select className="as-input" value={settings.ai_whatsapp_max_risk ?? 'medium'} onChange={e => onSettingsChange({ ...settings, ai_whatsapp_max_risk: e.target.value as AgentSettings['ai_whatsapp_max_risk'] })}>
                <option value="low">منخفضة</option><option value="medium">متوسطة</option><option value="high">عالية</option>
              </select></label>
            <label className="as-field"><span className="as-field-label"><strong>تدوير المحادثة بعد الخمول (ساعات)</strong></span>
              <input className="as-input" type="number" inputMode="numeric" min={1} max={168} value={channelDrafts.ai_whatsapp_idle_rotate_hours ?? String(settings.ai_whatsapp_idle_rotate_hours ?? 12)} onChange={e => setChannelNumber('ai_whatsapp_idle_rotate_hours', e.target.value)} /></label>
            <label className="as-field"><span className="as-field-label"><strong>صلاحية رابط الموظف (دقائق)</strong></span>
              <input className="as-input" type="number" inputMode="numeric" min={1} max={60} value={channelDrafts.ai_whatsapp_link_token_ttl_minutes ?? String(settings.ai_whatsapp_link_token_ttl_minutes ?? 10)} onChange={e => setChannelNumber('ai_whatsapp_link_token_ttl_minutes', e.target.value)} /></label>
          </div>
          <div className="as-wa-card-foot">
            <button type="button" className="as-btn as-btn--primary as-wa-btn-full" disabled={savingChannel} onClick={() => void saveChannel()}>
              {savingChannel ? 'جارٍ الحفظ…' : 'حفظ إعدادات واتساب'}
            </button>
          </div>
        </section>
      )}

      <section className="as-card as-wa-sec as-wa-sec--full" data-wa-sec="staff" aria-label="أرقام واتساب المسموحة">
        <div className="as-card-head">
          <div className="as-wa-head-actions">
            <h3>أرقام الموظفين</h3>
            <span className="as-badge as-num">{identities.length}</span>
          </div>
          {identities.length > 0 && (
            <button
              type="button"
              className="as-btn as-btn--sm"
              aria-expanded={showAddForm}
              onClick={() => setAddFormOpen(v => !v)}
            >
              <Icon name={showAddForm ? 'minus' : 'plus'} size={14} />
              <span>{showAddForm ? 'إخفاء' : 'إضافة رقم'}</span>
            </button>
          )}
        </div>
        {showAddForm && (
          <div className="as-wa-add-wrap">
            <div className="as-form as-wa-add-form">
              <label className="as-field"><span className="as-field-label"><strong>بحث عن موظف</strong></span>
                <input className="as-input" value={query} onChange={e => setQuery(e.target.value)} placeholder="ابحث بالاسم…" /></label>
              <label className="as-field"><span className="as-field-label"><strong>الموظف</strong></span>
                <select className="as-input" value={userId} onChange={e => setUserId(e.target.value ? Number(e.target.value) : '')}>
                  <option value="">اختار موظف</option>
                  {staff.map(person => <option key={person.id} value={person.id}>{person.name}</option>)}
                </select></label>
              <label className="as-field"><span className="as-field-label"><strong>رقم واتساب</strong><small>صيغة دولية، مثل +201012345678. مش رقم users.phone.</small></span>
                <input className="as-input" type="tel" inputMode="tel" dir="ltr" placeholder="+201012345678" value={phone} onChange={e => setPhone(e.target.value)} /></label>
              <label className="as-field"><span className="as-field-label"><strong>رقم سري اختياري</strong></span>
                <input className="as-input" type="password" inputMode="numeric" placeholder="••••" value={pin} onChange={e => setPin(e.target.value)} /></label>
            </div>
            <div className="as-wa-card-foot">
              <button type="button" className="as-btn as-btn--primary as-wa-btn-full" disabled={!userId || !phone || savingIdentity} onClick={() => void saveIdentity()}>
                {savingIdentity ? 'جارٍ الإضافة…' : 'إضافة الرقم'}
              </button>
            </div>
          </div>
        )}

        {link && (
          <div className="as-wa-link-box" role="region" aria-label="باركود ربط الموظف">
            <div className="as-wa-link-head">
              <strong>رابط ربط الموظف</strong>
              <button type="button" className="as-iconbtn as-iconbtn--sm" aria-label="إغلاق الباركود" onClick={() => setLink(null)}>
                <Icon name="x" size={14} />
              </button>
            </div>
            <div className="as-wa-link-layout">
              {isSafeQrDataUrl(link.qr_data_url) && (
                <div className="as-wa-qr-wrap">
                  <div className="as-wa-qr">
                    <img alt="باركود ربط الموظف" src={link.qr_data_url} width={180} height={180} />
                  </div>
                </div>
              )}
              <div className="as-wa-link-meta">
                <p className="as-wa-hint">خلي الموظف يمسح الباركود أو يفتح الرابط من واتساب لتأكيد ربط رقمه.</p>
                <div className="as-wa-copy-row">
                  <code dir="ltr">{link.url}</code>
                  <button type="button" className="as-btn as-btn--sm" onClick={() => void copyText('link-url', link.url)}>
                    <Icon name={copiedKey === 'link-url' ? 'check' : 'copy'} size={14} />
                    <span>{copiedKey === 'link-url' ? 'تم النسخ' : 'نسخ'}</span>
                  </button>
                </div>
                <small className="as-wa-hint">ينتهي {formatShortTime(link.expires_at)}</small>
              </div>
            </div>
          </div>
        )}

        <div className="as-table-wrap as-only-desktop" style={{ border: 0, borderRadius: 0 }}>
          <table className="as-table as-wa-table">
            <thead><tr><th>الموظف</th><th>رقم واتساب</th><th>الحالة</th><th></th></tr></thead>
            <tbody>
              {identities.map(row => {
                const meta = stateMeta(row.status);
                const initial = (row.name || 'م').trim().slice(0, 1);
                const phoneKey = `staff-dt-${row.id}`;
                return (
                  <tr key={row.id}>
                    <td>
                      <div className="as-wa-person">
                        <span className="as-wa-avatar" aria-hidden="true">{initial}</span>
                        <strong>{row.name || 'موظف'}</strong>
                      </div>
                    </td>
                    <td>
                      <div className="as-wa-phone-cell">
                        <span dir="ltr" className="as-num as-wa-item-phone">{row.phone_e164}</span>
                        <button type="button" className="as-iconbtn as-iconbtn--sm" aria-label="نسخ الرقم" onClick={() => void copyText(phoneKey, row.phone_e164)}>
                          <Icon name={copiedKey === phoneKey ? 'check' : 'copy'} size={13} />
                        </button>
                      </div>
                    </td>
                    <td>
                      <span className={`as-badge ${meta.badge}`}>{meta.label}</span>
                      {row.has_pin && <span className="as-badge" style={{ marginInlineStart: 4 }}>PIN</span>}
                    </td>
                    <td>
                      <div className="as-wa-table-actions">
                        <button type="button" className={`as-btn as-btn--sm ${row.status === 'pending' ? 'as-btn--primary' : ''}`} disabled={row.status !== 'pending'} onClick={() => void assistantApi.whatsappLink(row.id).then(r => { setCopiedKey(null); setLink(r); }).catch(error => setNotice({ tone: 'danger', text: messageOf(error) }))}>باركود الربط</button>
                        <button type="button" className="as-btn as-btn--sm as-btn--danger" disabled={row.status === 'revoked'} onClick={() => void assistantApi.whatsappRevoke(row.id).then(() => {
                          setLink(null);
                          return assistantApi.whatsappIdentities().then(setIdentities);
                        }).catch(error => setNotice({ tone: 'danger', text: messageOf(error) }))}>إلغاء الربط</button>
                      </div>
                    </td>
                  </tr>
                );
              })}
              {!identities.length && <tr><td colSpan={4} className="as-wa-empty-cell">لسه مفيش أرقام مسجلة.</td></tr>}
            </tbody>
          </table>
        </div>

        <div className="as-wa-list as-only-mobile">
          {identities.map(row => {
            const meta = stateMeta(row.status);
            const initial = (row.name || 'م').trim().slice(0, 1);
            const phoneKey = `staff-${row.id}`;
            return (
              <div className="as-wa-item" key={row.id}>
                <div className="as-wa-item-top">
                  <div className="as-wa-person">
                    <span className="as-wa-avatar" aria-hidden="true">{initial}</span>
                    <strong>{row.name || 'موظف'}</strong>
                  </div>
                  <div className="as-wa-item-badges">
                    {row.has_pin && <span className="as-badge">PIN</span>}
                    <span className={`as-badge ${meta.badge}`}>{meta.label}</span>
                  </div>
                </div>
                <div className="as-wa-kv-val">
                  <span className="as-wa-item-phone as-num" dir="ltr">{row.phone_e164}</span>
                  <button type="button" className="as-iconbtn as-iconbtn--sm" aria-label="نسخ الرقم" onClick={() => void copyText(phoneKey, row.phone_e164)}>
                    <Icon name={copiedKey === phoneKey ? 'check' : 'copy'} size={14} />
                  </button>
                </div>
                <div className="as-wa-item-actions">
                  <button
                    type="button"
                    className={`as-btn ${row.status === 'pending' ? 'as-btn--primary' : ''}`}
                    disabled={row.status !== 'pending'}
                    onClick={() => void assistantApi.whatsappLink(row.id).then(r => { setCopiedKey(null); setLink(r); }).catch(error => setNotice({ tone: 'danger', text: messageOf(error) }))}
                  >
                    باركود الربط
                  </button>
                  <button
                    type="button"
                    className="as-btn as-btn--danger"
                    disabled={row.status === 'revoked'}
                    onClick={() => void assistantApi.whatsappRevoke(row.id).then(() => {
                      setLink(null);
                      return assistantApi.whatsappIdentities().then(setIdentities);
                    }).catch(error => setNotice({ tone: 'danger', text: messageOf(error) }))}
                  >
                    إلغاء الربط
                  </button>
                </div>
              </div>
            );
          })}
          {!identities.length && <div className="as-wa-empty">لسه مفيش أرقام مسجلة.</div>}
        </div>
      </section>

      <section className="as-card as-wa-sec as-wa-sec--full" data-wa-sec="logs" aria-label="مراقبة واتساب">
        <div className="as-card-head">
          <h3>آخر الرسائل</h3>
          <div className="as-wa-head-actions">
            <span className={`as-badge ${gatewayMeta.badge}`}>
              {gatewayMeta.dot && <i className="as-wa-dot" aria-hidden="true" />}
              {gatewayMeta.label}
            </span>
            <button type="button" className="as-iconbtn as-iconbtn--sm" aria-label="تحديث الرسائل" onClick={() => void refreshMonitorNow()} disabled={refreshingMonitor}>
              <Icon name="refresh" size={14} className={refreshingMonitor ? 'as-spin' : undefined} />
            </button>
          </div>
        </div>

        {!!monitor?.counts.length && (
          <ul className="as-wa-chips" aria-label="ملخص آخر 24 ساعة">
            {monitor.counts.map(row => {
              const meta = stateMeta(row.status);
              return (
                <li key={row.status + (row.ignore_reason ?? '')} className={`as-badge ${meta.badge}`}>
                  <span>{meta.label}{row.ignore_reason ? ` (${row.ignore_reason})` : ''}</span>
                  <strong className="as-num">{row.aggregate}</strong>
                </li>
              );
            })}
          </ul>
        )}

        <div className="as-table-wrap as-only-desktop" style={{ border: 0, borderRadius: 0 }}>
          <table className="as-table as-wa-table">
            <thead><tr><th>الحالة</th><th>السبب</th><th>وقت الوصول</th><th>مدة المعالجة</th></tr></thead>
            <tbody>
              {monitor?.recent.map(row => {
                const raw = (Date.parse(row.updated_at) - Date.parse(row.created_at)) / 1000;
                const seconds = Number.isFinite(raw) ? Math.max(0, Math.round(raw * 10) / 10) : NaN;
                const meta = stateMeta(row.status);
                return (
                  <tr key={row.id}>
                    <td><span className={`as-badge ${meta.badge}`}>{meta.label}</span></td>
                    <td>{row.ignore_reason ?? '—'}</td>
                    <td title={row.created_at}>{formatShortTime(row.created_at)}</td>
                    <td className="as-num">{row.status === 'queued' || !Number.isFinite(seconds) ? '—' : `${seconds} ث`}</td>
                  </tr>
                );
              })}
              {!monitor?.recent.length && <tr><td colSpan={4} className="as-wa-empty-cell">مفيش رسائل.</td></tr>}
            </tbody>
          </table>
        </div>

        <div className="as-wa-list as-only-mobile">
          {monitor?.recent.map(row => {
            const raw = (Date.parse(row.updated_at) - Date.parse(row.created_at)) / 1000;
            const seconds = Number.isFinite(raw) ? Math.max(0, Math.round(raw * 10) / 10) : NaN;
            const meta = stateMeta(row.status);
            return (
              <div className="as-wa-item" key={row.id}>
                <div className="as-wa-item-top">
                  <span className={`as-badge ${meta.badge}`}>{meta.label}</span>
                  <span className="as-wa-duration as-num">{row.status === 'queued' || !Number.isFinite(seconds) ? '—' : `${seconds} ث`}</span>
                </div>
                {row.ignore_reason && <div className="as-wa-item-reason">{row.ignore_reason}</div>}
                <div className="as-wa-item-time" title={row.created_at}>{formatShortTime(row.created_at)}</div>
              </div>
            );
          })}
          {!monitor?.recent.length && <div className="as-wa-empty">مفيش رسائل.</div>}
        </div>
      </section>
    </div>
  );
}
