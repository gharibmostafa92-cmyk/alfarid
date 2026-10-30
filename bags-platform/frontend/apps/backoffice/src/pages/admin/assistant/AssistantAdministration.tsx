import { useEffect, useState } from 'react';
import { assistantApi, type AgentSettings } from './api';

export default function AssistantAdministration({ can }: { can: (permission: string) => boolean }) {
  const [settings, setSettings] = useState<AgentSettings>();
  const [original, setOriginal] = useState<AgentSettings>();
  const [tools, setTools] = useState<string[]>([]);
  const [usage, setUsage] = useState<{ day: string; input_tokens: number; output_tokens: number }[]>([]);
  const [notice, setNotice] = useState('');
  const [saving, setSaving] = useState(false);
  const canSettings = can('ai_agent.settings');
  useEffect(() => {
    let live = true;
    assistantApi.usage(can('ai_agent.logs.view')).then(data => { if (live) setUsage(data); }).catch(() => { if (live) setNotice('تعذر تحميل الاستهلاك.'); });
    if (canSettings) assistantApi.settings().then(result => { if (live) { setSettings(result.data); setOriginal(result.data); setTools(result.tools); } }).catch(() => { if (live) setNotice('إعدادات المساعد متاحة للإدارة المركزية فقط.'); });
    return () => { live = false; };
  }, [can, canSettings]);
  return <section className="assistant-block" aria-label="إدارة المساعد">
    <h2>الاستهلاك والإعدادات</h2>
    <p>استهلاك آخر ٣٠ يوم{can('ai_agent.logs.view') ? ' لجميع الموظفين' : ' لحسابك'}: {usage.reduce((sum, day) => sum + Number(day.input_tokens) + Number(day.output_tokens), 0).toLocaleString('ar-EG')} توكن</p>
    <div className="assistant-table"><table><thead><tr><th>اليوم</th><th>إدخال</th><th>إخراج</th></tr></thead><tbody>{usage.map(day => <tr key={day.day}><td>{day.day}</td><td>{Number(day.input_tokens).toLocaleString('ar-EG')}</td><td>{Number(day.output_tokens).toLocaleString('ar-EG')}</td></tr>)}</tbody></table></div>
    {settings && original && <form onSubmit={async e => {
      e.preventDefault(); setSaving(true); setNotice('');
      try { await assistantApi.saveSettings(settings, original); setOriginal(settings); setNotice('اتحفظت الإعدادات.'); }
      catch (error) { setNotice((error as { response?: { data?: { message?: string } } }).response?.data?.message ?? 'تعذر الحفظ. أعد فتح الإعدادات لو اتعدلت من مستخدم تاني.'); }
      finally { setSaving(false); }
    }}>
      <label><input type="checkbox" checked={settings.ai_enabled} disabled={saving} onChange={e => setSettings({ ...settings, ai_enabled: e.target.checked })} /> تفعيل المساعد</label>
      {([
        ['ai_daily_token_budget_per_user', 'حد التوكنز اليومي لكل مستخدم (0 = بلا حد)', 0, 10000000],
        ['ai_action_ttl_minutes', 'مهلة التأكيد بالدقائق', 1, 1440],
        ['ai_retention_days', 'مدة الاحتفاظ بالمحادثات بالأيام', 1, 3650],
        ['ai_bulk_max_items', 'الحد الأقصى للعملية الجماعية', 1, 100],
      ] as const).map(([key, label, min, max]) => <label key={key}>{label}<input type="number" min={min} max={max} required value={settings[key]} disabled={saving} onChange={e => setSettings({ ...settings, [key]: Number(e.target.value) })} /></label>)}
      <fieldset disabled={saving}><legend>الأدوات المقفولة</legend>{tools.map(tool => <label key={tool}><input type="checkbox" checked={settings.ai_disabled_tools.includes(tool)} onChange={e => setSettings({ ...settings, ai_disabled_tools: e.target.checked ? [...settings.ai_disabled_tools, tool] : settings.ai_disabled_tools.filter(t => t !== tool) })} /> <code>{tool}</code></label>)}</fieldset>
      <button className="assistant-primary" disabled={saving} type="submit">{saving ? 'جارٍ الحفظ…' : 'حفظ الإعدادات'}</button>
    </form>}
    {notice && <p role="status">{notice}</p>}
  </section>;
}
