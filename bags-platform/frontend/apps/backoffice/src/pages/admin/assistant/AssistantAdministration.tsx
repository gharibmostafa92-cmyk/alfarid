import { useEffect, useMemo, useState } from 'react';
import { assistantApi, diffSettings, type AgentSettings, type ToolStatsSummary } from './api';
import { Icon } from './icons';
import WhatsAppPanel from './WhatsAppPanel';

type UsageRow = { day: string; input_tokens: number; output_tokens: number };
type AdminSection = 'usage' | 'settings' | 'whatsapp';
const fmt = (n: number) => Number(n).toLocaleString('ar-EG');
const compact = (n: number) => new Intl.NumberFormat('ar-EG', { notation: 'compact', maximumFractionDigits: 1 }).format(n);

const NUMERIC_FIELDS = [
  ['ai_inventory_high_risk_value', 'حد قيمة عملية المخزون عالية الخطورة', 'فوق القيمة دي التأكيد بالكتابة مطلوب', 0, 9999999999],
  ['ai_daily_token_budget_per_user', 'حد التوكنز اليومي لكل مستخدم', '0 = بلا حد', 0, 10000000],
  ['ai_action_ttl_minutes', 'مهلة التأكيد', 'بالدقائق — بعدها الاقتراح بينتهي', 1, 1440],
  ['ai_retention_days', 'مدة الاحتفاظ بالمحادثات', 'بالأيام', 1, 3650],
  ['ai_bulk_max_items', 'الحد الأقصى للعملية الجماعية', 'عدد العناصر في العملية الواحدة', 1, 100],
  ['ai_catalog_bulk_price_max_items', 'الحد الأقصى لتعديل أسعار الكتالوج', 'عدد المتغيرات في الاقتراح الواحد', 1, 1000],
  ['ai_max_steps', 'الحد الأقصى لخطوات المساعد', 'عدد خطوات البحث والتجهيز في الطلب الواحد', 1, 10],
  ['ai_pricing_max_items_per_action', 'الحد الأقصى لبنود التسعير', 'عدد البنود في اقتراح التسعير الواحد', 1, 1000],
  ['ai_coupon_high_risk_percent', 'عتبة خطورة الكوبون %', 'النسبة اللي فوقها الكوبون عالي الخطورة', 0, 100],
  ['ai_finance_high_risk_amount', 'حد المبلغ المالي عالي الخطورة', 'فوق المبلغ ده التأكيد بالكتابة مطلوب', 0, 9999999999],
  ['ai_wallet_adjust_max_amount', 'الحد الأقصى لتعديل المحفظة', 'أي تعديل فوق المبلغ ده مرفوض من المساعد', 0, 9999999999],
] as const;

type SwitchRowProps = {
  label: string;
  help: string;
  checked: boolean;
  disabled?: boolean;
  sub?: boolean;
  onChange: (checked: boolean) => void;
};

function SwitchRow({ label, help, checked, disabled, sub, onChange }: SwitchRowProps) {
  return (
    <label className="as-field as-field--switch" data-sub={sub || undefined} data-disabled={disabled || undefined}>
      <span className="as-field-label">
        <strong>{label}</strong>
        <small>{help}</small>
      </span>
      <input
        type="checkbox"
        role="switch"
        className="as-switch"
        checked={checked}
        disabled={disabled}
        onChange={e => onChange(e.target.checked)}
      />
    </label>
  );
}

export default function AssistantAdministration({ can }: { can: (permission: string) => boolean }) {
  const [settings, setSettings] = useState<AgentSettings>();
  const [original, setOriginal] = useState<AgentSettings>();
  const [tools, setTools] = useState<string[]>([]);
  const [usage, setUsage] = useState<UsageRow[]>([]);
  const [toolStats, setToolStats] = useState<ToolStatsSummary>();
  const [usageLoading, setUsageLoading] = useState(true);
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger' | 'info'; text: string } | null>(null);
  const [saving, setSaving] = useState(false);
  const [hover, setHover] = useState<number | null>(null);
  const [toolQuery, setToolQuery] = useState('');
  const [showAllDays, setShowAllDays] = useState(false);
  const canSettings = can('ai_agent.settings');
  const allUsers = can('ai_agent.logs.view');
  const [section, setSection] = useState<AdminSection>('usage');
  const [channel, setChannel] = useState<'' | 'web' | 'whatsapp'>('');
  // Raw text for numeric fields while typing: committing Number('') would
  // store 0, and 0 means "unlimited" for the daily budget. Only finite
  // numbers reach settings; clearing a field leaves the last valid value
  // until a new number is typed (the form is required, so empties cannot
  // be submitted).
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const numericFallback = (key: string) => key === 'ai_inventory_high_risk_value' ? 50000 : key === 'ai_max_steps' ? 6 : key === 'ai_catalog_bulk_price_max_items' ? 200 : key === 'ai_finance_high_risk_amount' ? 20000 : key === 'ai_wallet_adjust_max_amount' ? 5000 : '';
  const setNumeric = (key: keyof AgentSettings, raw: string) => {
    setDrafts(current => ({ ...current, [key]: raw }));
    if (raw === '') return;
    const n = Number(raw);
    if (Number.isFinite(n)) setSettings(current => (current ? { ...current, [key]: n } : current));
  };

  useEffect(() => {
    let live = true;
    setUsageLoading(true);
    assistantApi.usageWithStats(allUsers, channel || undefined)
      .then(result => { if (live) { setUsage(result.data); setToolStats(result.tool_stats); } })
      .catch(() => { if (live) setNotice({ tone: 'danger', text: 'تعذر تحميل الاستهلاك.' }); })
      .finally(() => { if (live) setUsageLoading(false); });
    if (canSettings) assistantApi.settings()
      .then(result => { if (live) { setSettings(result.data); setOriginal(result.data); setTools(result.tools); } })
      .catch(() => { if (live) setNotice({ tone: 'info', text: 'إعدادات المساعد متاحة للإدارة المركزية فقط.' }); });
    return () => { live = false; };
  }, [can, canSettings, allUsers, channel]);

  const stats = useMemo(() => {
    const input = usage.reduce((s, d) => s + Number(d.input_tokens), 0);
    const output = usage.reduce((s, d) => s + Number(d.output_tokens), 0);
    const total = input + output;
    const peak = usage.reduce((m, d) => Math.max(m, Number(d.input_tokens) + Number(d.output_tokens)), 0);
    const avg = usage.length ? Math.round(total / usage.length) : 0;
    return { input, output, total, peak, avg };
  }, [usage]);

  const dirty = !!settings && !!original && JSON.stringify(settings) !== JSON.stringify(original);
  const sorted = useMemo(() => [...usage].sort((a, b) => a.day.localeCompare(b.day)), [usage]);
  const reversedDays = useMemo(() => [...sorted].reverse(), [sorted]);
  const mobileDays = showAllDays ? reversedDays : reversedDays.slice(0, 7);
  const hv = hover !== null ? sorted[hover] : undefined;
  const visibleTools = tools.filter(t => t.toLowerCase().includes(toolQuery.trim().toLowerCase()));

  return (
    <div className="as-admin">
      <section className="as-admin-inner" aria-label="إدارة المساعد">
        <div className="as-admin-head">
          <div>
            <h2>الاستهلاك والإعدادات</h2>
            <p>استهلاك آخر ٣٠ يوم {allUsers ? 'لجميع الموظفين' : 'لحسابك'}</p>
          </div>
          <div className="as-seg" role="tablist" aria-label="أقسام الإدارة">
            <button type="button" role="tab" className="as-seg-btn" aria-selected={section === 'usage'} onClick={() => setSection('usage')}>
              <Icon name="chart" size={15} />
              <span>الاستهلاك</span>
            </button>
            {canSettings && (
              <button type="button" role="tab" className="as-seg-btn" aria-selected={section === 'settings'} onClick={() => setSection('settings')}>
                <Icon name="settings" size={15} />
                <span>الإعدادات</span>
                {dirty && <i className="as-seg-dot" aria-label="تعديلات غير محفوظة" />}
              </button>
            )}
            {canSettings && (
              <button type="button" role="tab" className="as-seg-btn" aria-selected={section === 'whatsapp'} onClick={() => setSection('whatsapp')}>
                <Icon name="chat" size={15} />
                <span>واتساب</span>
              </button>
            )}
          </div>
        </div>

        {notice && (!dirty || notice.tone === 'danger') && (
          <div className={`as-alert as-alert--${notice.tone}`} role="status">
            <Icon name={notice.tone === 'success' ? 'check' : notice.tone === 'danger' ? 'alert' : 'info'} size={16} />
            <div className="as-alert-body"><span>{notice.text}</span></div>
            <button type="button" className="as-iconbtn as-iconbtn--sm" aria-label="إخفاء" onClick={() => setNotice(null)}><Icon name="x" size={14} /></button>
          </div>
        )}

        {section === 'whatsapp' && canSettings && settings && original ? (
          <WhatsAppPanel settings={settings} original={original} onSettingsChange={setSettings} onSaved={setOriginal} />
        ) : null}

        {section === 'usage' && (
          <>
            <div className="as-stats">
              <div className="as-stat">
                <span><Icon name="chart" size={14} />الإجمالي</span>
                <strong className="as-num">{compact(stats.total)}</strong>
                <small className="as-num">{fmt(stats.total)} توكن</small>
              </div>
              <div className="as-stat">
                <span><i className="as-legend-out" />إدخال</span>
                <strong className="as-num">{compact(stats.input)}</strong>
                <small>{stats.total ? `${Math.round((stats.input / stats.total) * 100)}٪ من الإجمالي` : '—'}</small>
              </div>
              <div className="as-stat">
                <span><i className="as-legend-in" />إخراج</span>
                <strong className="as-num">{compact(stats.output)}</strong>
                <small>{stats.total ? `${Math.round((stats.output / stats.total) * 100)}٪ من الإجمالي` : '—'}</small>
              </div>
              <div className="as-stat">
                <span><Icon name="bolt" size={14} />متوسط يومي</span>
                <strong className="as-num">{compact(stats.avg)}</strong>
                <small className="as-num">الذروة {compact(stats.peak)}</small>
              </div>
            </div>

            <div className="as-card">
              <div className="as-card-head as-chart-head">
                <div className="as-chart-title-row">
                  <h3>الاستهلاك اليومي</h3>
                  <span className="as-chart-legend">
                    <span><i className="as-legend-in" />إخراج</span>
                    <span><i className="as-legend-out" />إدخال</span>
                  </span>
                </div>
                <label className="as-channel-filter">
                  <span className="as-sr">القناة</span>
                  <select className="as-input" aria-label="فلتر القناة" value={channel} onChange={e => setChannel(e.target.value as '' | 'web' | 'whatsapp')}>
                    <option value="">كل القنوات</option>
                    <option value="web">الويب</option>
                    <option value="whatsapp">واتساب</option>
                  </select>
                </label>
              </div>
              <div className="as-card-body">
                {usageLoading ? <div className="as-skel" style={{ height: 160 }} /> : sorted.length ? (
                  <>
                    <div className="as-chart" role="img" aria-label="رسم بياني للاستهلاك اليومي" onMouseLeave={() => setHover(null)} dir="ltr">
                      {sorted.map((d, i) => {
                        const t = Number(d.input_tokens) + Number(d.output_tokens);
                        const h = stats.peak ? (t / stats.peak) * 100 : 0;
                        const active = hover === i;
                        return (
                          <button
                            type="button"
                            className="as-chart-col"
                            key={d.day}
                            data-active={active || undefined}
                            aria-label={`${d.day}: ${fmt(t)} توكن`}
                            aria-pressed={active}
                            onMouseEnter={() => setHover(i)}
                            onFocus={() => setHover(i)}
                            onClick={() => setHover(cur => (cur === i ? null : i))}
                          >
                            <span className="as-chart-bar" style={{ height: `${Math.max(h, 3)}%` }}>
                              <i style={{ height: `${t ? (Number(d.input_tokens) / t) * 100 : 0}%` }} />
                              <i style={{ height: `${t ? (Number(d.output_tokens) / t) * 100 : 0}%` }} />
                            </span>
                          </button>
                        );
                      })}
                    </div>
                    <div className="as-chart-axis" dir="ltr"><span>{sorted[0].day}</span><span>{sorted.at(-1)!.day}</span></div>
                    <p className="as-chart-tip as-num" data-selected={!!hv || undefined}>
                      {hv
                        ? `${hv.day} — إدخال ${fmt(hv.input_tokens)} · إخراج ${fmt(hv.output_tokens)} · الإجمالي ${fmt(Number(hv.input_tokens) + Number(hv.output_tokens))}`
                        : 'اضغط أو مرّر على أي عمود لعرض تفاصيل اليوم'}
                    </p>
                  </>
                ) : (
                  <div className="as-list-empty"><span className="as-empty-icon"><Icon name="chart" size={20} /></span><strong>مفيش استهلاك مسجّل</strong><span>هيظهر هنا أول ما تبدأ تستخدم المساعد.</span></div>
                )}
              </div>
            </div>

            {toolStats && (
              <section className="as-card" aria-label="أداء الأدوات">
                <div className="as-card-head">
                  <h3>أداء الأدوات</h3>
                  <span className="as-badge as-num">{fmt(toolStats.total_proposals)} اقتراح</span>
                </div>
                <div className="as-card-body">
                  <div className="as-mini-kpis">
                    <div className="as-mini-kpi"><span>التأكيد</span><strong className="as-num">{fmt(toolStats.confirm_rate)}٪</strong></div>
                    <div className="as-mini-kpi"><span>الإلغاء</span><strong className="as-num">{fmt(toolStats.cancel_rate)}٪</strong></div>
                    <div className="as-mini-kpi"><span>تعارضات 409</span><strong className="as-num">{fmt(toolStats.conflict_409_rate)}٪</strong></div>
                  </div>
                  <p className="as-hint-note">لما إلغاء أداة يتجاوز ٤٠٪، راجع تعليماتها ومعاينة التعديل.</p>
                </div>

                <div className="as-table-wrap as-only-desktop" style={{ border: 0, borderRadius: 0 }}>
                  <table className="as-table">
                    <caption className="as-sr">استخدام الأدوات ونسب التأكيد والإلغاء خلال آخر ٣٠ يوم</caption>
                    <thead><tr><th scope="col">الأداة</th><th scope="col">الاقتراحات</th><th scope="col">التأكيد</th><th scope="col">الإلغاء</th><th scope="col">المتابعة</th></tr></thead>
                    <tbody>
                      {toolStats.tools.map(tool => (
                        <tr key={tool.tool}>
                          <th scope="row"><code dir="ltr">{tool.tool}</code></th>
                          <td className="as-num">{fmt(tool.total)}</td>
                          <td className="as-num">{fmt(tool.confirm_rate)}٪</td>
                          <td className="as-num">{fmt(tool.cancel_rate)}٪</td>
                          <td>{tool.needs_attention ? <span className="as-badge as-badge--warning">إلغاء مرتفع — راجع المعاينة</span> : '—'}</td>
                        </tr>
                      ))}
                      {!toolStats.tools.length && <tr><td colSpan={5}>مفيش اقتراحات مسجّلة لسه.</td></tr>}
                    </tbody>
                  </table>
                </div>

                <div className="as-mobile-cards as-only-mobile">
                  {toolStats.tools.map(tool => (
                    <div className="as-mcard" key={tool.tool} data-warn={tool.needs_attention || undefined}>
                      <div className="as-mcard-top">
                        <code dir="ltr">{tool.tool}</code>
                        {tool.needs_attention && <span className="as-badge as-badge--warning">إلغاء مرتفع</span>}
                      </div>
                      <div className="as-mcard-metrics">
                        <div><span>الاقتراحات</span><strong className="as-num">{fmt(tool.total)}</strong></div>
                        <div><span>التأكيد</span><strong className="as-num">{fmt(tool.confirm_rate)}٪</strong></div>
                        <div><span>الإلغاء</span><strong className="as-num">{fmt(tool.cancel_rate)}٪</strong></div>
                      </div>
                    </div>
                  ))}
                  {!toolStats.tools.length && <p className="as-pop-empty">مفيش اقتراحات مسجّلة لسه.</p>}
                </div>
              </section>
            )}

            <div className="as-card">
              <div className="as-card-head">
                <h3>السجل اليومي</h3>
                <span className="as-badge as-num">{fmt(usage.length)} يوم</span>
              </div>
              <div className="as-table-wrap as-only-desktop" style={{ border: 0, borderRadius: 0, maxHeight: 420, overflowY: 'auto' }}>
                <table className="as-table">
                  <thead><tr><th scope="col">اليوم</th><th scope="col" className="as-num">إدخال</th><th scope="col" className="as-num">إخراج</th><th scope="col" className="as-num">الإجمالي</th></tr></thead>
                  <tbody>
                    {reversedDays.map(day => (
                      <tr key={day.day}>
                        <td className="as-num" dir="ltr" style={{ textAlign: 'end' }}>{day.day}</td>
                        <td className="as-num">{fmt(day.input_tokens)}</td>
                        <td className="as-num">{fmt(day.output_tokens)}</td>
                        <td className="as-num" style={{ fontWeight: 600 }}>{fmt(Number(day.input_tokens) + Number(day.output_tokens))}</td>
                      </tr>
                    ))}
                    {!usage.length && !usageLoading && <tr><td colSpan={4} style={{ textAlign: 'center', color: 'var(--as-muted)' }}>لا توجد بيانات</td></tr>}
                  </tbody>
                </table>
              </div>

              <div className="as-mobile-cards as-only-mobile">
                {mobileDays.map(day => {
                  const total = Number(day.input_tokens) + Number(day.output_tokens);
                  return (
                    <div className="as-mcard as-mcard--day" key={day.day}>
                      <div className="as-mcard-top">
                        <span className="as-num as-mcard-date" dir="ltr">{day.day}</span>
                        <strong className="as-num">{fmt(total)} توكن</strong>
                      </div>
                      <div className="as-mcard-sub">
                        <span className="as-num">إدخال: {fmt(day.input_tokens)}</span>
                        <span className="as-num">إخراج: {fmt(day.output_tokens)}</span>
                      </div>
                    </div>
                  );
                })}
                {!usage.length && !usageLoading && <p className="as-pop-empty">لا توجد بيانات</p>}
                {reversedDays.length > 7 && (
                  <button type="button" className="as-btn as-btn--ghost as-btn--block" onClick={() => setShowAllDays(v => !v)}>
                    {showAllDays ? 'عرض أحدث ٧ أيام' : `عرض كل الأيام (${fmt(reversedDays.length)})`}
                  </button>
                )}
              </div>
            </div>

            {!canSettings && (
              <div className="as-card">
                <div className="as-list-empty">
                  <span className="as-empty-icon"><Icon name="lock" size={20} /></span>
                  <strong>الإعدادات للإدارة المركزية</strong>
                  <span>تقدر تشوف استهلاكك بس. تواصل مع الإدارة لتعديل حدود المساعد.</span>
                </div>
              </div>
            )}
          </>
        )}

        {section === 'settings' && (
          settings && original ? (
            <form className="as-settings-stack" onSubmit={async e => {
              e.preventDefault(); setNotice(null);
              for (const [k, raw] of Object.entries(drafts)) {
                if (raw.trim() === '') {
                  setNotice({ tone: 'danger', text: `يرجى إدخال قيمة رقمية صالحة للحقل (${k}).` });
                  return;
                }
                const n = Number(raw);
                const numericField = NUMERIC_FIELDS.find(([field]) => field === k);
                if (!Number.isInteger(n) || !numericField || n < numericField[3] || n > numericField[4]) {
                  setNotice({ tone: 'danger', text: `القيمة المدخلة للحقل (${k}) يجب أن تكون عددًا صحيحًا بين ${numericField?.[3] ?? 0} و ${numericField?.[4] ?? 0}.` });
                  return;
                }
              }
              setSaving(true);
              // Changed-keys-only delta: the backend 422s unless data and
              // expected share identical key sets, and a full-object save
              // would clobber the other tab's edits with stale values.
              try {
                const { data, expected } = diffSettings(settings, original);
                await assistantApi.saveSettings(data, expected);
                setOriginal(settings); setDrafts({});
                setNotice({ tone: 'success', text: 'اتحفظت الإعدادات.' });
              }
              catch (error) { setNotice({ tone: 'danger', text: (error as { response?: { data?: { message?: string } } }).response?.data?.message ?? 'تعذر الحفظ. أعد فتح الإعدادات لو اتعدلت من مستخدم تاني.' }); }
              finally { setSaving(false); }
            }}>
              <div className="as-card">
                <div className="as-card-head">
                  <h3>التشغيل العام</h3>
                  {settings.ai_enabled ? <span className="as-badge as-badge--success">مفعّل</span> : <span className="as-badge as-badge--danger">متوقف</span>}
                </div>
                <div className="as-form">
                  <SwitchRow
                    label="تفعيل المساعد"
                    help="إيقافه بيمنع كل المستخدمين من بدء محادثات جديدة."
                    checked={settings.ai_enabled}
                    disabled={saving}
                    onChange={checked => setSettings({ ...settings, ai_enabled: checked })}
                  />
                </div>
              </div>

              <div className="as-card">
                <div className="as-card-head"><h3>العملاء والتجار</h3></div>
                <div className="as-form">
                  <SwitchRow
                    label="تفعيل أدوات العملاء والتجار"
                    help="يسمح للمساعد بمراجعة طلبات التجار وعرض وإدارة ملفات العملاء."
                    checked={!!settings.ai_customer_tools_enabled}
                    disabled={saving}
                    onChange={checked => setSettings({ ...settings, ai_customer_tools_enabled: checked })}
                  />
                  <SwitchRow
                    sub
                    label="تحليل مستندات التجار بالذكاء الاصطناعي"
                    help="يسمح بفحص صور السجل التجاري والبطاقة الضريبية استشاريًا."
                    checked={!!settings.ai_document_analysis_enabled}
                    disabled={saving || !settings.ai_customer_tools_enabled}
                    onChange={checked => setSettings({ ...settings, ai_document_analysis_enabled: checked })}
                  />
                </div>
              </div>

              <div className="as-card">
                <div className="as-card-head"><h3>الكتالوج والمنتجات</h3></div>
                <div className="as-form">
                  <SwitchRow
                    label="تفعيل أدوات الكتالوج"
                    help="بحث وإدارة الأقسام والمنتجات، مع مراجعة أي تعديل قبل تأكيده."
                    checked={!!settings.ai_catalog_tools_enabled}
                    disabled={saving}
                    onChange={checked => setSettings({ ...settings, ai_catalog_tools_enabled: checked })}
                  />
                  <SwitchRow
                    sub
                    label="تحليل صور المنتجات"
                    help="اقتراح بيانات المنتج من الصور بعد مراجعتها، مع احتساب التوكنز."
                    checked={!!settings.ai_catalog_vision_enabled}
                    disabled={saving || !settings.ai_catalog_tools_enabled}
                    onChange={checked => setSettings({ ...settings, ai_catalog_vision_enabled: checked })}
                  />
                  <SwitchRow
                    sub
                    label="كتابة أوصاف المنتجات"
                    help="توليد مسودة للوصف والشارة تراجعها قبل الحفظ."
                    checked={!!settings.ai_catalog_copywriting_enabled}
                    disabled={saving || !settings.ai_catalog_tools_enabled}
                    onChange={checked => setSettings({ ...settings, ai_catalog_copywriting_enabled: checked })}
                  />
                </div>
              </div>

              <div className="as-card">
                <div className="as-card-head"><h3>التسعير والعروض</h3></div>
                <div className="as-form">
                  <SwitchRow
                    label="تفعيل أدوات التسعير"
                    help="يسمح للمساعد بشرح الأسعار وفحص صحة التسعير وإدارة قوائم الأسعار."
                    checked={!!settings.ai_pricing_tools_enabled}
                    disabled={saving}
                    onChange={checked => setSettings({ ...settings, ai_pricing_tools_enabled: checked })}
                  />
                  <SwitchRow
                    sub
                    label="تفعيل أدوات العروض والكوبونات"
                    help="يسمح للمساعد بإنشاء وإدارة الكوبونات والعروض الترويجية."
                    checked={!!settings.ai_promotions_tools_enabled}
                    disabled={saving || !settings.ai_pricing_tools_enabled}
                    onChange={checked => setSettings({ ...settings, ai_promotions_tools_enabled: checked })}
                  />
                  <SwitchRow
                    sub
                    label="قراءة صور شيتات الأسعار"
                    help="يسمح للمساعد بقراءة صور شيتات أسعار الموردين."
                    checked={!!settings.ai_pricing_vision_enabled}
                    disabled={saving || !settings.ai_pricing_tools_enabled}
                    onChange={checked => setSettings({ ...settings, ai_pricing_vision_enabled: checked })}
                  />
                  <label className="as-field">
                    <span className="as-field-label"><strong>سياسة السعر تحت التكلفة</strong><small>منع (block) أو تحذير فقط (warn) عند سعر تحت التكلفة.</small></span>
                    <select className="as-input" value={settings.ai_price_below_cost_policy ?? 'block'} disabled={saving} onChange={e => setSettings({ ...settings, ai_price_below_cost_policy: e.target.value })}>
                      <option value="block">منع</option>
                      <option value="warn">تحذير فقط</option>
                    </select>
                  </label>
                </div>
              </div>

              <div className="as-card">
                <div className="as-card-head"><h3>الشحن والمتجر</h3></div>
                <div className="as-form">
                  <SwitchRow
                    label="تفعيل أدوات الشحن"
                    help="شرح تكلفة التوصيل وإدارة الشركات والمناطق والتعريفات حسب صلاحيات الموظف."
                    checked={!!settings.ai_shipping_tools_enabled}
                    disabled={saving}
                    onChange={checked => setSettings({ ...settings, ai_shipping_tools_enabled: checked })}
                  />
                  <SwitchRow
                    label="تفعيل أدوات المتجر"
                    help="معاينة السلة وإتمام الشراء وإدارة بانرات المتجر."
                    checked={!!settings.ai_storefront_tools_enabled}
                    disabled={saving}
                    onChange={checked => setSettings({ ...settings, ai_storefront_tools_enabled: checked })}
                  />
                </div>
              </div>

              <div className="as-card">
                <div className="as-card-head"><h3>المخزون</h3></div>
                <div className="as-form">
                  {([
                    ['ai_inventory_tools_enabled', 'تفعيل أدوات المخزون', 'الأرصدة والتحويلات والجرد واستلام الإنتاج وفق صلاحيات الموظف.'],
                    ['ai_inventory_vision_enabled', 'قراءة صور الجرد وكشوف الإنتاج', 'تجهيز بنود الصورة للمراجعة قبل التسجيل.'],
                    ['ai_inventory_share_cost_with_model', 'مشاركة التكلفة مع نموذج الذكاء الاصطناعي', 'عند الإيقاف تظهر التكلفة للمصرح لهم في الكارت فقط.'],
                    ['ai_inventory_proactive_enabled', 'اقتراحات المخزون الاستباقية', 'اقتراح إعادة توزيع البضاعة وملاحظات المراجعة.'],
                  ] as const).map(([key, label, help]) => (
                    <SwitchRow
                      key={key}
                      sub={key !== 'ai_inventory_tools_enabled'}
                      label={label}
                      help={help}
                      checked={!!settings[key]}
                      disabled={saving || (key !== 'ai_inventory_tools_enabled' && !settings.ai_inventory_tools_enabled)}
                      onChange={checked => setSettings({ ...settings, [key]: checked })}
                    />
                  ))}
                </div>
              </div>

              <div className="as-card">
                <div className="as-card-head"><h3>المالية والمحفظة</h3></div>
                <div className="as-form">
                  {([
                    ['ai_finance_tools_enabled', 'تفعيل أدوات المالية', 'الخزائن والتحويلات والمصروفات والمحفظة وفق صلاحيات الموظف.'],
                    ['ai_finance_wallet_tools_enabled', 'تعديلات المحفظة من المساعد', 'مقفولة لو أدوات المالية الرئيسية مقفولة.'],
                    ['ai_finance_receipt_vision_enabled', 'قراءة صور الإيصالات', 'الصورة تجهّز المصروف للمراجعة قبل تسجيله. مقفولة لو أدوات المالية الرئيسية مقفولة.'],
                    ['ai_finance_proactive_enabled', 'اقتراحات المالية الاستباقية', 'فحص الأرصدة والشواذ والتوقعات وملخص القفلة.'],
                  ] as const).map(([key, label, help]) => (
                    <SwitchRow
                      key={key}
                      sub={key !== 'ai_finance_tools_enabled'}
                      label={label}
                      help={help}
                      checked={!!settings[key]}
                      disabled={saving || (key !== 'ai_finance_tools_enabled' && !settings.ai_finance_tools_enabled)}
                      onChange={checked => setSettings({ ...settings, [key]: checked })}
                    />
                  ))}
                </div>
              </div>

              <div className="as-card">
                <div className="as-card-head"><h3>الحدود والمهل الرقمية</h3></div>
                <div className="as-form">
                  {NUMERIC_FIELDS.map(([key, label, help, min, max]) => (
                    <label className="as-field" key={key}>
                      <span className="as-field-label"><strong>{label}</strong><small>{help}</small></span>
                      <input className="as-input" type="number" inputMode="numeric" min={min} max={max} required value={drafts[key] ?? String(settings[key] ?? numericFallback(key))} disabled={saving} onChange={e => setNumeric(key, e.target.value)} />
                    </label>
                  ))}
                </div>
              </div>

              <div className="as-card">
                <div className="as-card-head">
                  <h3>الأدوات المقفولة</h3>
                  <span className="as-badge as-num">
                    {settings.ai_disabled_tools.length ? `${fmt(settings.ai_disabled_tools.length)} من ${fmt(tools.length)} مقفولة` : 'كل الأدوات متاحة'}
                  </span>
                </div>
                <fieldset disabled={saving}>
                  <legend className="as-sr">الأدوات المقفولة</legend>
                  {tools.length > 6 && (
                    <div className="as-searchfield" style={{ margin: '.75rem 1rem .35rem' }}>
                      <Icon name="search" size={15} />
                      <input className="as-input" type="search" placeholder="بحث في الأدوات…" aria-label="بحث في الأدوات" value={toolQuery} onChange={e => setToolQuery(e.target.value)} />
                    </div>
                  )}
                  <div className="as-tools-list">
                    {visibleTools.map(tool => {
                      const locked = settings.ai_disabled_tools.includes(tool);
                      return (
                        <label className="as-tool" key={tool} data-locked={locked}>
                          <input type="checkbox" className="as-check" checked={locked} onChange={e => setSettings({ ...settings, ai_disabled_tools: e.target.checked ? [...settings.ai_disabled_tools, tool] : settings.ai_disabled_tools.filter(t => t !== tool) })} />
                          <code>{tool}</code>
                          {locked && <Icon name="lock" size={14} />}
                        </label>
                      );
                    })}
                    {!visibleTools.length && <p className="as-pop-empty">مفيش أداة مطابقة.</p>}
                  </div>
                </fieldset>
              </div>

              <div className="as-savebar" data-dirty={dirty || undefined}>
                {dirty ? <span className="as-dirty">تعديلات غير محفوظة</span> : notice ? <span role="status" style={{ fontSize: '.8rem', color: notice.tone === 'success' ? 'var(--as-success)' : 'var(--as-danger)' }}>{notice.text}</span> : null}
                <span className="as-spacer" />
                <div className="as-savebar-actions">
                  {dirty && <button type="button" className="as-btn as-btn--ghost" disabled={saving} onClick={() => { setSettings(original); setDrafts({}); }}>تراجع</button>}
                  <button className="as-btn as-btn--primary" disabled={saving || !dirty} type="submit">{saving ? <><span className="as-spin" />جارٍ الحفظ…</> : 'حفظ الإعدادات'}</button>
                </div>
              </div>
            </form>
          ) : canSettings ? (
            <div className="as-card"><div className="as-card-body"><div className="as-skel" /><div className="as-skel" /><div className="as-skel" /></div></div>
          ) : (
            <div className="as-card"><div className="as-list-empty"><span className="as-empty-icon"><Icon name="lock" size={20} /></span><strong>الإعدادات للإدارة المركزية</strong><span>تقدر تشوف استهلاكك بس. تواصل مع الإدارة لتعديل حدود المساعد.</span></div></div>
          )
        )}
      </section>
    </div>
  );
}
