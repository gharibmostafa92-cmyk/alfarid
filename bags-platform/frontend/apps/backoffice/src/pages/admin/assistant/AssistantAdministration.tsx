import { useEffect, useMemo, useState } from 'react';
import { assistantApi, type AgentSettings } from './api';
import { Icon } from './icons';

type UsageRow = { day: string; input_tokens: number; output_tokens: number };
const fmt = (n: number) => Number(n).toLocaleString('ar-EG');
const compact = (n: number) => new Intl.NumberFormat('ar-EG', { notation: 'compact', maximumFractionDigits: 1 }).format(n);

const NUMERIC_FIELDS = [
  ['ai_daily_token_budget_per_user', 'حد التوكنز اليومي لكل مستخدم', '0 = بلا حد', 0, 10000000],
  ['ai_action_ttl_minutes', 'مهلة التأكيد', 'بالدقائق — بعدها الاقتراح بينتهي', 1, 1440],
  ['ai_retention_days', 'مدة الاحتفاظ بالمحادثات', 'بالأيام', 1, 3650],
  ['ai_bulk_max_items', 'الحد الأقصى للعملية الجماعية', 'عدد العناصر في العملية الواحدة', 1, 100],
] as const;

export default function AssistantAdministration({ can }: { can: (permission: string) => boolean }) {
  const [settings, setSettings] = useState<AgentSettings>();
  const [original, setOriginal] = useState<AgentSettings>();
  const [tools, setTools] = useState<string[]>([]);
  const [usage, setUsage] = useState<UsageRow[]>([]);
  const [usageLoading, setUsageLoading] = useState(true);
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger' | 'info'; text: string } | null>(null);
  const [saving, setSaving] = useState(false);
  const [hover, setHover] = useState<number | null>(null);
  const [toolQuery, setToolQuery] = useState('');
  const canSettings = can('ai_agent.settings');
  const allUsers = can('ai_agent.logs.view');

  useEffect(() => {
    let live = true;
    assistantApi.usage(allUsers)
      .then(data => { if (live) setUsage(data); })
      .catch(() => { if (live) setNotice({ tone: 'danger', text: 'تعذر تحميل الاستهلاك.' }); })
      .finally(() => { if (live) setUsageLoading(false); });
    if (canSettings) assistantApi.settings()
      .then(result => { if (live) { setSettings(result.data); setOriginal(result.data); setTools(result.tools); } })
      .catch(() => { if (live) setNotice({ tone: 'info', text: 'إعدادات المساعد متاحة للإدارة المركزية فقط.' }); });
    return () => { live = false; };
  }, [can, canSettings, allUsers]);

  const stats = useMemo(() => {
    const input = usage.reduce((s, d) => s + Number(d.input_tokens), 0);
    const output = usage.reduce((s, d) => s + Number(d.output_tokens), 0);
    const total = input + output;
    const peak = usage.reduce((m, d) => Math.max(m, Number(d.input_tokens) + Number(d.output_tokens)), 0);
    const avg = usage.length ? Math.round(total / usage.length) : 0;
    return { input, output, total, peak, avg };
  }, [usage]);

  const dirty = !!settings && !!original && JSON.stringify(settings) !== JSON.stringify(original);
  const sorted = [...usage].sort((a, b) => a.day.localeCompare(b.day));
  const hv = hover !== null ? sorted[hover] : undefined;
  const visibleTools = tools.filter(t => t.includes(toolQuery.trim()));

  return (
    <div className="as-admin">
      <section className="as-admin-inner" aria-label="إدارة المساعد">
        <div className="as-admin-head">
          <div>
            <h2>الاستهلاك والإعدادات</h2>
            <p>استهلاك آخر ٣٠ يوم {allUsers ? 'لجميع الموظفين' : 'لحسابك'}</p>
          </div>
        </div>

        {notice && !dirty && (
          <div className={`as-alert as-alert--${notice.tone}`} role="status">
            <Icon name={notice.tone === 'success' ? 'check' : notice.tone === 'danger' ? 'alert' : 'info'} size={16} />
            <div className="as-alert-body"><span>{notice.text}</span></div>
            <button type="button" className="as-iconbtn as-iconbtn--sm" aria-label="إخفاء" onClick={() => setNotice(null)}><Icon name="x" size={14} /></button>
          </div>
        )}

        <div className="as-stats">
          <div className="as-stat"><span><Icon name="chart" size={14} />الإجمالي</span><strong className="as-num">{compact(stats.total)}</strong><small className="as-num">{fmt(stats.total)} توكن</small></div>
          <div className="as-stat"><span><i className="as-legend-out" />إدخال</span><strong className="as-num">{compact(stats.input)}</strong><small>{stats.total ? `${Math.round((stats.input / stats.total) * 100)}٪ من الإجمالي` : '—'}</small></div>
          <div className="as-stat"><span><i className="as-legend-in" />إخراج</span><strong className="as-num">{compact(stats.output)}</strong><small>{stats.total ? `${Math.round((stats.output / stats.total) * 100)}٪ من الإجمالي` : '—'}</small></div>
          <div className="as-stat"><span><Icon name="bolt" size={14} />متوسط يومي</span><strong className="as-num">{compact(stats.avg)}</strong><small className="as-num">الذروة {compact(stats.peak)}</small></div>
        </div>

        <div className="as-card">
          <div className="as-card-head">
            <h3>الاستهلاك اليومي</h3>
            <span style={{ display: 'flex', gap: '.9rem', fontSize: '.76rem', color: 'var(--as-muted)' }}>
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: '.35rem' }}><i className="as-legend-in" />إخراج</span>
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: '.35rem' }}><i className="as-legend-out" />إدخال</span>
            </span>
          </div>
          <div className="as-card-body">
            {usageLoading ? <div className="as-skel" style={{ height: 150 }} /> : sorted.length ? (
              <>
                <div className="as-chart" role="img" aria-label="رسم بياني للاستهلاك اليومي" onMouseLeave={() => setHover(null)} dir="ltr">
                  {sorted.map((d, i) => {
                    const t = Number(d.input_tokens) + Number(d.output_tokens);
                    const h = stats.peak ? (t / stats.peak) * 100 : 0;
                    return (
                      <div className="as-chart-col" key={d.day} onMouseEnter={() => setHover(i)} style={{ height: `${Math.max(h, 2)}%` }}>
                        <i style={{ height: `${t ? (Number(d.input_tokens) / t) * 100 : 0}%` }} />
                        <i style={{ height: `${t ? (Number(d.output_tokens) / t) * 100 : 0}%` }} />
                      </div>
                    );
                  })}
                </div>
                <div className="as-chart-axis" dir="ltr"><span>{sorted[0].day}</span><span>{sorted.at(-1)!.day}</span></div>
                <p className="as-chart-tip as-num">{hv ? `${hv.day} — إدخال ${fmt(hv.input_tokens)} · إخراج ${fmt(hv.output_tokens)}` : 'مرّر على أي عمود لعرض تفاصيل اليوم'}</p>
              </>
            ) : (
              <div className="as-list-empty"><span className="as-empty-icon"><Icon name="chart" size={20} /></span><strong>مفيش استهلاك مسجّل</strong><span>هيظهر هنا أول ما تبدأ تستخدم المساعد.</span></div>
            )}
          </div>
        </div>

        <div className="as-admin-grid">
          <div className="as-card">
            <div className="as-card-head"><h3>السجل اليومي</h3><span className="as-badge as-num">{fmt(usage.length)} يوم</span></div>
            <div className="as-table-wrap" style={{ border: 0, borderRadius: 0, maxHeight: 420, overflowY: 'auto' }}>
              <table className="as-table">
                <thead><tr><th scope="col">اليوم</th><th scope="col" className="as-num">إدخال</th><th scope="col" className="as-num">إخراج</th><th scope="col" className="as-num">الإجمالي</th></tr></thead>
                <tbody>
                  {[...sorted].reverse().map(day => (
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
          </div>

          {settings && original ? (
            <form className="as-card" onSubmit={async e => {
              e.preventDefault(); setSaving(true); setNotice(null);
              try { await assistantApi.saveSettings(settings, original); setOriginal(settings); setNotice({ tone: 'success', text: 'اتحفظت الإعدادات.' }); }
              catch (error) { setNotice({ tone: 'danger', text: (error as { response?: { data?: { message?: string } } }).response?.data?.message ?? 'تعذر الحفظ. أعد فتح الإعدادات لو اتعدلت من مستخدم تاني.' }); }
              finally { setSaving(false); }
            }}>
              <div className="as-card-head"><h3>إعدادات المساعد</h3>{settings.ai_enabled ? <span className="as-badge as-badge--success">مفعّل</span> : <span className="as-badge as-badge--danger">متوقف</span>}</div>
              <div className="as-form">
                <label className="as-field as-field--switch">
                  <span className="as-field-label"><strong>تفعيل المساعد</strong><small>إيقافه بيمنع كل المستخدمين من بدء محادثات جديدة.</small></span>
                  <input type="checkbox" role="switch" className="as-switch" checked={settings.ai_enabled} disabled={saving} onChange={e => setSettings({ ...settings, ai_enabled: e.target.checked })} />
                </label>
                {NUMERIC_FIELDS.map(([key, label, help, min, max]) => (
                  <label className="as-field" key={key}>
                    <span className="as-field-label"><strong>{label}</strong><small>{help}</small></span>
                    <input className="as-input" type="number" inputMode="numeric" min={min} max={max} required value={settings[key]} disabled={saving} onChange={e => setSettings({ ...settings, [key]: Number(e.target.value) })} />
                  </label>
                ))}
                <fieldset disabled={saving} style={{ borderBottom: '1px solid var(--as-border)' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '.5rem', padding: '.85rem 1rem .4rem', flexWrap: 'wrap' }}>
                    <legend className="as-field-label" style={{ float: 'inline-start', flex: 1 }}><strong>الأدوات المقفولة</strong><small>{settings.ai_disabled_tools.length ? `${fmt(settings.ai_disabled_tools.length)} من ${fmt(tools.length)} مقفولة` : 'كل الأدوات متاحة'}</small></legend>
                  </div>
                  {tools.length > 6 && (
                    <div className="as-searchfield" style={{ margin: '0 1rem .3rem' }}>
                      <Icon name="search" size={15} />
                      <input className="as-input" type="search" placeholder="بحث في الأدوات…" aria-label="بحث في الأدوات" value={toolQuery} onChange={e => setToolQuery(e.target.value)} style={{ minHeight: 34 }} />
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
              <div className="as-savebar">
                {dirty ? <span className="as-dirty">تعديلات غير محفوظة</span> : notice ? <span role="status" style={{ fontSize: '.8rem', color: notice.tone === 'success' ? 'var(--as-success)' : 'var(--as-danger)' }}>{notice.text}</span> : null}
                <span className="as-spacer" />
                {dirty && <button type="button" className="as-btn as-btn--ghost" disabled={saving} onClick={() => setSettings(original)}>تراجع</button>}
                <button className="as-btn as-btn--primary" disabled={saving || !dirty} type="submit">{saving ? <><span className="as-spin" />جارٍ الحفظ…</> : 'حفظ الإعدادات'}</button>
              </div>
            </form>
          ) : canSettings ? (
            <div className="as-card"><div className="as-card-body"><div className="as-skel" /><div className="as-skel" /><div className="as-skel" /></div></div>
          ) : (
            <div className="as-card"><div className="as-list-empty"><span className="as-empty-icon"><Icon name="lock" size={20} /></span><strong>الإعدادات للإدارة المركزية</strong><span>تقدر تشوف استهلاكك بس. تواصل مع الإدارة لتعديل حدود المساعد.</span></div></div>
          )}
        </div>
      </section>
    </div>
  );
}
