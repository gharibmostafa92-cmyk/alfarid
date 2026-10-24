import React, { useState, useEffect, useMemo, ReactNode } from 'react';
import {
  Icon,
  Toggle,
  Badge,
  Field,
  TextInput,
  GhostButton,
  PageHeader,
  StickyBar,
  Skeleton,
  EmptyState,
  Notice,
  toast,
  fmtDate,
} from './ui';
import { DOC_TYPES, AdminSettings } from './data';
import { useSettingsQuery, runMutation, useQueryError, useMe } from './hooks';
import { adminApi } from './api/endpoints';
import { settingsPatch, settingsExpected, toAdminSettings } from './api/mappers';

export const SETTINGS_TABS = [
  { key: 'company', label: 'بيانات الشركة', icon: 'building', desc: 'الهوية وترويسة المستندات' },
  { key: 'tax', label: 'الضرائب والفواتير', icon: 'percent', desc: 'القيمة المضافة وطريقة الاحتساب' },
  { key: 'numbering', label: 'ترقيم المستندات', icon: 'hash', desc: '12 نوعاً من الوثائق' },
  { key: 'orders', label: 'سياسة الإرجاع', icon: 'undo', desc: 'مهلة إرجاع المنتجات' },
  { key: 'purchases', label: 'دورة المشتريات', icon: 'truck', desc: 'الاعتماد والرقابة الثنائية' },
];

const clone = <T,>(o: T): T => JSON.parse(JSON.stringify(o));
const fmtMoney = (n: number) => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

interface SectionCardProps {
  title: string;
  subtitle?: string;
  children: ReactNode;
  className?: string;
  aside?: ReactNode;
}

function SectionCard({ title, subtitle, children, className = '', aside }: SectionCardProps) {
  return (
    <div className={`v-card p-6 ${className}`}>
      <div className="flex items-start justify-between gap-4 mb-5">
        <div>
          <h3 className="font-display text-[17px] font-semibold" style={{ color: 'var(--text-primary)' }}>{title}</h3>
          {subtitle && <p className="text-[12.5px] mt-1" style={{ color: 'var(--text-muted)' }}>{subtitle}</p>}
        </div>
        {aside}
      </div>
      {children}
    </div>
  );
}

export function SettingsScreen({ onNavigate, can }: { onNavigate?: (page: 'users' | 'roles' | 'settings') => void; can: (p: string) => boolean }) {
  const query = useSettingsQuery();
  useQueryError(query.error);
  const [server, setServer] = useState<AdminSettings | null>(null);
  const [s, setS] = useState<AdminSettings | null>(null);
  const [tab, setTab] = useState('company');
  const [saving, setSaving] = useState(false);
  const [conflict, setConflict] = useState<string | false>(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string,string[]>>({});
  const { centralAccess } = useMe();
  const editable = centralAccess && ( can('settings.update') || can('settings.manage'));
  const loading = query.loading && !server;
  useEffect(() => { if (query.data) { setServer(clone(query.data)); setS(clone(query.data)); setFieldErrors({}); } }, [query.data]);
  const set = (group: keyof AdminSettings, key: string, val: any) =>
    setS(p => p && editable ? ({ ...p, [group]: { ...(p[group] as any), [key]: val } }) : p);

  const setNum = (doc: string, key: string, val: any) =>
    setS(p => p && editable ? ({ ...p, numbering: { ...p.numbering, [doc]: { ...p.numbering[doc], [key]: val } } }) : p);

  // compute changed fields (flat)
  const diffs = useMemo(() => {
    const out: [string, string][] = [];
    if (!s || !server) return out;
    (Object.keys(s) as (keyof AdminSettings)[]).forEach(g => {
      Object.keys(s[g]).forEach(k => {
        if (JSON.stringify((s[g] as any)[k]) !== JSON.stringify((server[g] as any)[k])) {
          out.push([g, k]);
        }
      });
    });
    return out;
  }, [s, server]);

  const dirtyGroups = new Set(diffs.map(d => d[0]));
  const isDirty = diffs.length > 0;

  useEffect(() => {
    if (!isDirty) return;
    const guard = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = ''; };
    window.addEventListener('beforeunload', guard);
    return () => window.removeEventListener('beforeunload', guard);
  }, [isDirty]);
  const save = async () => {
    if (!s || !server || !editable || saving) return;
    const patch = settingsPatch(s, server);
    if (!Object.keys(patch).length) return;
    setSaving(true); setFieldErrors({});
    const result = await runMutation(() => adminApi.updateSettings({...patch, expected: settingsExpected(patch, server)}));
    setSaving(false);
    if (!result.ok) {
      if (result.error.status === 422) setFieldErrors(result.error.fields);
      if (result.error.status === 409) setConflict(result.error.message);
      if (result.error.status !== 401) toast(result.error.message, 'err');
      return;
    }
    const fresh = toAdminSettings(result.data.data);
    setServer(clone(fresh)); setS(clone(fresh)); setConflict(false);
    if (result.data.warnings?.length) result.data.warnings.forEach(message => toast(message, 'warn'));
    else toast('تم حفظ الإعدادات بنجاح');
  };
  const reload = () => { setConflict(false); query.reload(); };
  if (query.error && !server) return <EmptyState icon="lock" title={query.error.message} action={<GhostButton onClick={query.reload}>إعادة المحاولة</GhostButton>} />;
  if (!s || !server) return <SettingsSkeleton />;

  return (
    <section className="space-y-5 pb-20" data-screen-label="03 Settings">
      <PageHeader
        title="الإعدادات العامة"
        subtitle="هوية المؤسسة، الضرائب، الترقيم التلقائي للمستندات، سياسات الإرجاع وسير عمل المشتريات."
        action={
          <div className="flex items-center gap-3 stagger-item stagger-2">
            {onNavigate && (
              <GhostButton icon="users" onClick={() => onNavigate('users')}>
                فريق العمل
              </GhostButton>
            )}
          </div>
        }
      />

      {conflict && (
        <div className="v-conflict rounded-[22px] p-4 flex flex-wrap items-center gap-4 v-pop-in">
          <span className="w-10 h-10 rounded-full flex items-center justify-center bg-white/15 shrink-0"><Icon name="alert" className="w-5 h-5" strokeWidth={2.2} /></span>
          <div className="flex-1 min-w-[240px]">
            <div className="font-semibold text-[14px]">تعارض في البيانات (409)</div>
            <div className="text-[12.5px] opacity-85 mt-0.5">{conflict}</div>
          </div>
          <div className="flex items-center gap-2">
            <button type="button" onClick={() => setConflict(false)} className="h-9 px-4 rounded-full text-[12.5px] font-semibold hover:bg-white/10">لاحقاً</button>
            <button type="button" onClick={reload} className="h-9 px-4 rounded-full text-[12.5px] font-semibold bg-white flex items-center gap-1.5" style={{ color: '#c2410c' }}><Icon name="refresh" className="w-3.5 h-3.5" strokeWidth={2.2} />إعادة التحميل</button>
          </div>
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
        {/* Tabs rail */}
        <nav className="lg:col-span-3 v-card p-2 stagger-item stagger-2 lg:sticky lg:top-5">
          {SETTINGS_TABS.map((t) => {
            const active = tab === t.key;
            return (
              <button type="button" key={t.key} onClick={() => setTab(t.key)}
                className={`w-full flex items-center gap-3 p-3 rounded-2xl text-start transition ${active ? 'v-tab-active' : 'v-tab'}`}>
                <span className={`w-9 h-9 rounded-xl flex items-center justify-center shrink-0 ${active ? 'text-white v-accent-btn' : 'v-icon-well'}`}><Icon name={t.icon} className="w-4 h-4" /></span>
                <div className="flex-1 min-w-0">
                  <div className="text-[13px] font-semibold" style={{ color: 'var(--text-primary)' }}>{t.label}</div>
                  <div className="text-[11px] truncate" style={{ color: 'var(--text-muted)' }}>{t.desc}</div>
                </div>
                {dirtyGroups.has(t.key) && <span className="w-2 h-2 rounded-full shrink-0" style={{ background: 'var(--accent-orange)' }} title="تعديلات غير محفوظة" />}
              </button>
            );
          })}
        </nav>

        <div className="lg:col-span-9 stagger-item stagger-3" key={tab}>
          {loading ? <SettingsSkeleton /> : (
            <fieldset disabled={!editable || saving} className="v-tab-in space-y-5">
              {tab === 'company' && <CompanyTab errors={fieldErrors} v={s.company} set={(k, val) => set('company', k, val)} />}
              {tab === 'tax' && <TaxTab errors={fieldErrors} v={s.tax} set={(k, val) => set('tax', k, val)} />}
              {tab === 'numbering' && <NumberingTab errors={fieldErrors} v={s.numbering} server={server.numbering} setNum={setNum} />}
              {tab === 'orders' && <ReturnsTab v={s.orders} set={(k, val) => set('orders', k, val)} />}
              {Object.entries(fieldErrors).filter(([key]) => !['company','tax','numbering'].includes(tab) && key in s[tab as 'orders' | 'purchases']).map(([key,messages]) => <div role="alert" key={key}>{messages.join('، ')}</div>)}
              {tab === 'purchases' && <PurchasesTab v={s.purchases} set={(k, val) => set('purchases', k, val)} />}
            </fieldset>
          )}
        </div>
      </div>

      <StickyBar show={isDirty && editable} onSave={save} onReset={() => setS(clone(server))} saving={saving} count={diffs.length} />
    </section>
  );
}

function SettingsSkeleton() {
  return (
    <div className="v-card p-6 space-y-5">
      <Skeleton className="h-5 w-40 rounded" /><Skeleton className="h-3 w-64 rounded" />
      <div className="flex gap-4 pt-2"><Skeleton className="w-24 h-24 rounded-2xl" /><div className="flex-1 space-y-3"><Skeleton className="h-11 rounded-2xl" /><Skeleton className="h-3 w-40 rounded" /></div></div>
      <div className="grid grid-cols-2 gap-4">{[1, 2, 3, 4].map(i => <Skeleton key={i} className="h-11 rounded-2xl" />)}</div>
      <Skeleton className="h-24 rounded-2xl" />
    </div>
  );
}

/* ---------- Tab 1: Company ---------- */
function CompanyTab({ errors, v, set }: { errors: Record<string,string[]>; v: AdminSettings['company']; set: (k: keyof AdminSettings['company'], val: any) => void }) {
  const [logoErr, setLogoErr] = useState(false);
  useEffect(() => setLogoErr(false), [v.company_logo]);

  return <>
    <SectionCard title="بيانات الشركة" subtitle="تظهر هذه البيانات في ترويسة الفواتير المطبوعة والمستندات الرسمية.">
      <div className="v-subcard p-4 flex flex-wrap items-center gap-5 mb-6">
        <div className="w-[92px] h-[92px] rounded-[20px] flex items-center justify-center overflow-hidden shrink-0 v-logo-box">
          {v.company_logo && !logoErr ? <img src={v.company_logo} onError={() => setLogoErr(true)} className="w-full h-full object-contain p-2" alt="Logo" />
            : <div className="flex flex-col items-center gap-1" style={{ color: 'var(--text-muted)' }}><Icon name="image" className="w-6 h-6" strokeWidth={1.6} /><span className="text-[10px]">{logoErr ? 'رابط غير صالح' : 'لا يوجد شعار'}</span></div>}
        </div>
        <div className="flex-1 min-w-[240px]">
          <Field label="رابط شعار المنشأة" hint="PNG أو SVG بخلفية شفافة، يُفضّل 512×512 بكسل." error={errors.company_logo?.[0] || (logoErr ? 'تعذّر تحميل الصورة من هذا الرابط' : null)}>
            <TextInput value={v.company_logo} onChange={x => set('company_logo', x)} icon="link" dir="ltr" placeholder="https://cdn.company.com/logo.png" error={logoErr} />
          </Field>
        </div>
      </div>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-x-5 gap-y-4">
        <Field label="اسم الشركة" required className="md:col-span-2" error={errors.company_name?.[0] || (!v.company_name.trim() ? 'اسم الشركة مطلوب' : null)}>
          <TextInput value={v.company_name} onChange={x => set('company_name', x)} icon="building" placeholder="الاسم التجاري للمنشأة" error={!v.company_name.trim()} />
        </Field>
        <Field error={errors.company_email?.[0]} label="البريد الإلكتروني للشركة"><TextInput value={v.company_email} onChange={x => set('company_email', x)} icon="mail" dir="ltr" /></Field>
        <Field error={errors.company_phone?.[0]} label="رقم الهاتف الرسمي"><TextInput value={v.company_phone} onChange={x => set('company_phone', x)} icon="phone" dir="ltr" /></Field>
        <Field error={errors.commercial_register?.[0]} label="رقم السجل التجاري"><TextInput value={v.commercial_register} onChange={x => set('commercial_register', x)} icon="doc" dir="ltr" /></Field>
        <div />
        <Field error={errors.company_address?.[0]} label="عنوان المقر الرئيسي" className="md:col-span-2">
          <textarea value={v.company_address} onChange={e => set('company_address', e.target.value)} rows={3}
            className="v-input w-full px-3.5 py-3 text-[13px] outline-none resize-none leading-relaxed" style={{ color: 'var(--text-primary)' }} />
        </Field>
      </div>
    </SectionCard>

    {/* Invoice header preview */}
    <SectionCard title="معاينة ترويسة الفاتورة" subtitle="هكذا ستظهر بيانات الشركة أعلى المستندات المطبوعة.">
      <div className="v-paper rounded-2xl p-6">
        <div className="flex items-start justify-between gap-6 pb-5" style={{ borderBottom: '1.5px dashed rgba(24,21,28,.14)' }}>
          <div className="flex items-start gap-4">
            <div className="w-14 h-14 rounded-xl flex items-center justify-center overflow-hidden shrink-0" style={{ background: '#f5f2f6' }}>
              {v.company_logo && !logoErr ? <img src={v.company_logo} className="w-full h-full object-contain p-1" alt="Logo preview" /> :
                <div className="w-9 h-9 rounded-full flex items-center justify-center" style={{ background: 'radial-gradient(circle at 30% 25%, #ff8a65 0%, #e0491f 75%)' }}><svg className="w-4 h-4 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"><path d="M5 5l7 14 7-14" /></svg></div>}
            </div>
            <div>
              <div className="font-display text-[17px] font-bold text-[#18151c]">{v.company_name || '—'}</div>
              <div className="text-[11.5px] text-[#78737e] mt-1 whitespace-pre-line leading-relaxed">{v.company_address}</div>
            </div>
          </div>
          <div className="text-[11.5px] text-[#78737e] space-y-1 text-end shrink-0">
            <div dir="ltr">{v.company_phone}</div>
            <div dir="ltr">{v.company_email}</div>
            <div>س.ت: <span className="text-[#18151c] font-semibold">{v.commercial_register}</span></div>
          </div>
        </div>
        <div className="flex items-center justify-between pt-4">
          <span className="font-display text-[22px] font-bold text-[#18151c] tracking-tight">فاتورة ضريبية</span>
          <span className="font-mono text-[12px] font-semibold px-3 py-1 rounded-full" style={{ background: '#fde8e1', color: '#c2410c' }}>INV-001042</span>
        </div>
      </div>
    </SectionCard>
  </>;
}

/* ---------- Tab 2: Tax ---------- */
function TaxTab({ errors, v, set }: { errors: Record<string,string[]>; v: AdminSettings['tax']; set: (k: keyof AdminSettings['tax'], val: any) => void }) {
  const [sample, setSample] = useState(1000);
  const rate = Number(v.default_tax_rate) || 0;
  const on = v.tax_enabled;
  let net: number, tax: number, gross: number;
  if (!on) { net = sample; tax = 0; gross = sample; }
  else if (v.prices_include_tax) { gross = sample; net = sample / (1 + rate / 100); tax = gross - net; }
  else { net = sample; tax = (sample * rate) / 100; gross = net + tax; }
  const rateErr = rate < 0 || rate > 100;

  return (
    <div className="grid grid-cols-1 xl:grid-cols-5 gap-5 items-start">
      <SectionCard title="ضريبة القيمة المضافة" subtitle="إعدادات الضريبة المطبّقة على الفواتير الإلكترونية." className="xl:col-span-3">
        <div className="v-subcard p-4 flex items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <span className={`w-10 h-10 rounded-full flex items-center justify-center ${on ? 'v-badge-green' : 'v-icon-well'}`}><Icon name="percent" className="w-4 h-4" /></span>
            <div>
              <div className="text-[13.5px] font-semibold" style={{ color: 'var(--text-primary)' }}>تفعيل ضريبة القيمة المضافة</div>
              <div className="text-[11.5px]" style={{ color: 'var(--text-muted)' }}>{on ? 'تُحتسب الضريبة على جميع الفواتير' : 'لن تُضاف أي ضريبة على الفواتير'}</div>
            </div>
          </div>
          <Toggle on={on} onChange={x => set('tax_enabled', x)} />
        </div>

        <div className={`transition-opacity ${on ? '' : 'opacity-45 pointer-events-none'}`}>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-5">
            <Field error={errors.tax_number?.[0]} label="الرقم الضريبي" hint="يُطبع على الفواتير الإلكترونية.">
              <TextInput value={v.tax_number} onChange={x => set('tax_number', x)} icon="hash" dir="ltr" />
            </Field>
            <Field label="نسبة الضريبة الافتراضية" error={errors.default_tax_rate?.[0] || (rateErr ? 'النسبة يجب أن تكون بين 0 و 100' : null)}>
              <TextInput value={v.default_tax_rate} onChange={x => set('default_tax_rate', x === '' ? '' : Number(x))} type="number" dir="ltr" error={rateErr}
                suffix={<span className="text-[13px] font-semibold" style={{ color: 'var(--text-muted)' }}>%</span>} />
            </Field>
          </div>
          <div className="flex flex-wrap gap-1.5 mt-2.5">
            {[5, 10, 14, 15].map(r => (
              <button type="button" key={r} onClick={() => set('default_tax_rate', r)} className="px-3 h-7 rounded-full text-[11.5px] font-semibold transition"
                style={rate === r ? { background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' } : { background: 'var(--bg-subcard)', color: 'var(--text-secondary)' }}>{r}%</button>
            ))}
          </div>

          <div className="mt-6">
            <div className="text-[12.5px] font-semibold mb-2.5" style={{ color: 'var(--text-primary)' }}>طريقة احتساب الأسعار</div>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              {[
                { val: true, title: 'الأسعار شاملة الضريبة', en: 'Tax-Inclusive', desc: 'السعر المعروض هو ما يدفعه العميل، وتُستخرج الضريبة منه.' },
                { val: false, title: 'الأسعار غير شاملة الضريبة', en: 'Tax-Exclusive', desc: 'تُضاف الضريبة فوق السعر المعروض عند إصدار الفاتورة.' },
              ].map(o => {
                const act = v.prices_include_tax === o.val;
                return (
                  <button type="button" key={String(o.val)} onClick={() => set('prices_include_tax', o.val)} className={`v-choice rounded-2xl p-4 text-start ${act ? 'is-on' : ''}`}>
                    <div className="flex items-center justify-between">
                      <span className="text-[13px] font-semibold" style={{ color: 'var(--text-primary)' }}>{o.title}</span>
                      <span className="v-radio"><span /></span>
                    </div>
                    <div className="text-[10.5px] font-mono mt-0.5" style={{ color: 'var(--accent-orange)' }}>{o.en}</div>
                    <div className="text-[11.5px] mt-2 leading-relaxed" style={{ color: 'var(--text-muted)' }}>{o.desc}</div>
                  </button>
                );
              })}
            </div>
          </div>
        </div>
      </SectionCard>

      {Object.entries(errors).filter(([key]) => ['tax_number','default_tax_rate','tax_enabled','prices_include_tax'].includes(key)).map(([key, messages]) => <div role="alert" key={key}>{messages.join('، ')}</div>)}
      {/* Live calculator */}
      <div className="xl:col-span-2 v-card p-5 xl:sticky xl:top-5">
        <div className="flex items-center justify-between">
          <h3 className="font-display text-[16px] font-semibold" style={{ color: 'var(--text-primary)' }}>محاكاة حية</h3>
          <Badge tone={on ? 'green' : 'neutral'} dot>{on ? (v.prices_include_tax ? 'شامل' : 'غير شامل') : 'بدون ضريبة'}</Badge>
        </div>
        <p className="text-[11.5px] mt-1" style={{ color: 'var(--text-muted)' }}>غيّر سعر المنتج لترى أثر إعداداتك الحالية.</p>

        <div className="v-subcard p-4 mt-4">
          <div className="text-[11.5px] font-medium" style={{ color: 'var(--text-secondary)' }}>سعر المنتج المُدخل</div>
          <div className="flex items-baseline gap-2 mt-1">
            <input type="number" value={sample} onChange={e => setSample(Math.max(0, Number(e.target.value)))} dir="ltr"
              className="bg-transparent outline-none font-display text-[30px] font-bold w-full tabular-nums" style={{ color: 'var(--text-primary)' }} />
            <span className="text-[13px] font-semibold shrink-0" style={{ color: 'var(--text-muted)' }}>ج.م</span>
          </div>
          <input type="range" min="0" max="10000" step="50" value={sample} onChange={e => setSample(Number(e.target.value))} className="v-slider mt-2"
            style={{ background: `linear-gradient(to left, #e0491f ${(sample / 10000) * 100}%, var(--toggle-off-bg) ${(sample / 10000) * 100}%)` }} />
        </div>

        <div className="mt-4 space-y-0 v-divide">
          <div className="flex items-center justify-between py-3 text-[13px]"><span style={{ color: 'var(--text-secondary)' }}>الصافي قبل الضريبة</span><span className="font-semibold tabular-nums" style={{ color: 'var(--text-primary)' }}>{fmtMoney(net)}</span></div>
          <div className="flex items-center justify-between py-3 text-[13px]"><span style={{ color: 'var(--text-secondary)' }}>قيمة الضريبة ({on ? rate : 0}%)</span><span className="font-semibold tabular-nums" style={{ color: 'var(--accent-orange)' }}>+ {fmtMoney(tax)}</span></div>
        </div>

        <div className="v-orange-earnings rounded-[18px] p-4 mt-2">
          <div className="text-[12px] text-white/85">الإجمالي المستحق</div>
          <div className="font-display text-[28px] font-bold tabular-nums mt-0.5">{fmtMoney(gross)} <span className="text-[14px] font-medium text-white/80">ج.م</span></div>
          <div className="mt-3 h-3 w-full rounded-full overflow-hidden flex items-center gap-1 p-0.5 bg-white/10">
            <div className="h-full rounded-full bg-white transition-all duration-300" style={{ width: `${gross ? (net / gross) * 100 : 100}%` }} />
            <div className="h-full flex-1 rounded-full rewards-hatched-track" />
          </div>
          <div className="flex justify-between text-[10.5px] text-white/80 mt-1.5"><span>صافي</span><span>ضريبة</span></div>
        </div>
      </div>
    </div>
  );
}

/* ---------- Tab 3: Numbering ---------- */
function NumberingTab({ errors, v, server, setNum }: { errors: Record<string,string[]>; v: AdminSettings['numbering']; server: AdminSettings['numbering']; setNum: (doc: string, key: string, val: any) => void }) {
  const [q, setQ] = useState('');
  const docs = DOC_TYPES.filter(d => !q || d.label.includes(q) || d.key.includes(q.toLowerCase()) || v[d.key]?.prefix?.toLowerCase().includes(q.toLowerCase()));

  return (
    <SectionCard title="ترقيم المستندات" subtitle="حدد البادئة وطول الرقم والرقم التسلسلي التالي لكل نوع من وثائق النظام."
      aside={<div className="v-subcard px-3.5 h-9 rounded-full flex items-center gap-2 shrink-0">
        <Icon name="search" className="w-3.5 h-3.5" style={{ color: 'var(--text-muted)' }} />
        <input value={q} onChange={e => setQ(e.target.value)} placeholder="بحث عن مستند" className="bg-transparent text-[12.5px] outline-none w-32" style={{ color: 'var(--text-primary)' }} />
      </div>}>
      {docs.length === 0 && <EmptyState title="لا توجد مستندات مطابقة" text="جرّب البحث باسم المستند أو البادئة مثل INV-." />}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
        {docs.map(d => {
          const n = v[d.key];
          if (!n) return null;
          const s = server[d.key] || n;
          const pad = Math.min(20, Math.max(1, Number(n.padding) || 1));
          const preview = `${n.prefix}${String(n.next_number || 0).padStart(pad, '0')}`;
          const changed = JSON.stringify(n) !== JSON.stringify(s);
          const padErr = n.padding < 1 || n.padding > 20;
          const lower = Number(n.next_number) < s.next_number;
          return (
            <div key={d.key} className={`v-subcard p-4 v-doc ${changed ? 'is-changed' : ''}`}>
              <div className="flex items-center justify-between gap-2 mb-3.5">
                <div className="flex items-center gap-2.5 min-w-0">
                  <span className="w-8 h-8 rounded-[10px] flex items-center justify-center shrink-0" style={{ background: 'var(--bg-card)', color: 'var(--text-secondary)' }}><Icon name="doc" className="w-4 h-4" /></span>
                  <div className="min-w-0">
                    <div className="text-[13px] font-semibold truncate" style={{ color: 'var(--text-primary)' }}>{d.label}</div>
                    <div className="text-[10.5px] font-mono" style={{ color: 'var(--text-muted)' }}>{d.key}</div>
                  </div>
                </div>
                <span dir="ltr" className="v-preview-badge font-mono text-[12px] font-bold px-3 py-1.5 rounded-full truncate max-w-[55%]" title="المستند التالي">{preview}</span>
              </div>
              <div className="grid grid-cols-[1.1fr_.8fr_1.2fr] gap-2">
                <div>
                  <div className="text-[10.5px] font-medium mb-1" style={{ color: 'var(--text-muted)' }}>البادئة</div>
                  <input dir="ltr" value={n.prefix} onChange={e => setNum(d.key, 'prefix', e.target.value.toUpperCase())} className="v-mini-input w-full font-mono" />
                </div>
                <div>
                  <div className="text-[10.5px] font-medium mb-1" style={{ color: 'var(--text-muted)' }}>حشو الأصفار</div>
                  <div className={`v-mini-input flex items-center !px-1 ${padErr ? 'is-error' : ''}`}>
                    <button type="button" onClick={() => setNum(d.key, 'padding', Math.max(1, pad - 1))} className="w-6 h-6 rounded-md flex items-center justify-center hover:opacity-60 text-[14px] font-bold" style={{ color: 'var(--text-secondary)' }}>−</button>
                    <input dir="ltr" type="number" min="1" max="20" value={n.padding} onChange={e => setNum(d.key, 'padding', Number(e.target.value))} className="bg-transparent outline-none w-full text-center tabular-nums" />
                    <button type="button" onClick={() => setNum(d.key, 'padding', Math.min(20, pad + 1))} className="w-6 h-6 rounded-md flex items-center justify-center hover:opacity-60 text-[14px] font-bold" style={{ color: 'var(--text-secondary)' }}>+</button>
                  </div>
                </div>
                <div>
                  <div className="text-[10.5px] font-medium mb-1" style={{ color: 'var(--text-muted)' }}>الرقم التالي</div>
                  <input dir="ltr" type="number" min="1" max="999999999" value={n.next_number} onChange={e => setNum(d.key, 'next_number', Number(e.target.value))} className={`v-mini-input w-full tabular-nums ${lower ? 'is-warn' : ''}`} />
                </div>
              </div>
              {(['prefix','padding','next_number'] as const).map(key => errors[d.key+'_'+key]?.map(message => <div role="alert" key={key+message} className="text-[11px] v-badge-red">{message}</div>))}
              {padErr && <div role="alert" className="text-[11px] v-badge-red">حشو الأصفار يجب أن يكون بين 1 و20</div>}
              {lower && <div className="text-[10.5px] mt-2 flex items-center gap-1 font-medium" style={{ color: '#b45309' }}><Icon name="alert" className="w-3 h-3" />رقم أقل من الحالي ({s.next_number}) لا يمكن حفظه</div>}
            </div>
          );
        })}
      </div>
    </SectionCard>
  );
}

/* ---------- Tab 4: Returns ---------- */
function ReturnsTab({ v, set }: { v: AdminSettings['orders']; set: (k: keyof AdminSettings['orders'], val: any) => void }) {
  const d = v.return_window_days;
  const mode = d === 0 ? 'off' : d === -1 ? 'unlimited' : 'days';
  const [lastDays, setLastDays] = useState(d > 0 ? d : 14);
  useEffect(() => { if (d > 0) setLastDays(d); }, [d]);

  const cards = [
    { key: 'off', title: 'متوقف', desc: 'لا يُسمح بإرجاع المنتجات نهائياً.', icon: 'ban', val: 0, code: '0' },
    { key: 'days', title: 'خلال عدد أيام', desc: 'الإرجاع متاح لمدة محددة من تاريخ تسليم الطلب.', icon: 'calendar', val: lastDays, code: 'n' },
    { key: 'unlimited', title: 'غير محدود', desc: 'الإرجاع متاح في أي وقت دون قيود زمنية.', icon: 'infinity', val: -1, code: '-1' },
  ];

  const delivered = new Date();
  const deadline = new Date(delivered.getTime() + lastDays * 864e5);

  return (
    <SectionCard title="سياسة المبيعات والمرتجعات" subtitle="حدد المهلة المسموح خلالها للعميل بإرجاع المنتجات بعد التسليم.">
      <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
        {cards.map(c => {
          const act = mode === c.key;
          return (
            <button type="button" key={c.key} onClick={() => set('return_window_days', c.val)} className={`v-choice rounded-[18px] p-4 text-start ${act ? 'is-on' : ''}`}>
              <div className="flex items-center justify-between">
                <span className={`w-10 h-10 rounded-xl flex items-center justify-center ${act ? 'text-white v-accent-btn' : 'v-icon-well'}`}><Icon name={c.icon} className="w-[18px] h-[18px]" /></span>
                <span className="v-radio"><span /></span>
              </div>
              <div className="text-[14px] font-semibold mt-3" style={{ color: 'var(--text-primary)' }}>{c.title}</div>
              <div className="text-[11.5px] mt-1 leading-relaxed" style={{ color: 'var(--text-muted)' }}>{c.desc}</div>
              <div className="text-[10.5px] font-mono mt-2" style={{ color: 'var(--text-muted)' }}>return_window_days = {c.key === 'days' ? lastDays : c.code}</div>
            </button>
          );
        })}
      </div>

      <div className="v-collapse" style={{ gridTemplateRows: mode === 'days' ? '1fr' : '0fr' }}>
        <div className="overflow-hidden">
          <div className="v-subcard p-5 mt-4">
            <div className="flex flex-wrap items-center justify-between gap-4">
              <div>
                <div className="text-[13px] font-semibold" style={{ color: 'var(--text-primary)' }}>عدد الأيام المسموح بها</div>
                <div className="text-[11.5px]" style={{ color: 'var(--text-muted)' }}>تُحتسب من تاريخ تسليم الطلب للعميل</div>
              </div>
              <div className="flex items-center gap-2">
                {[7, 14, 30, 60].map(n => (
                  <button type="button" key={n} onClick={() => set('return_window_days', n)} className="px-3.5 h-8 rounded-full text-[12px] font-semibold transition"
                    style={d === n ? { background: 'var(--pill-active-bg)', color: 'var(--pill-active-text)' } : { background: 'var(--bg-card)', color: 'var(--text-secondary)' }}>{n} يوم</button>
                ))}
                <div className="flex items-center gap-1.5 h-8 ps-3 pe-1 rounded-full" style={{ background: 'var(--bg-card)' }}>
                  <input type="number" min="1" max="365" value={d > 0 ? d : lastDays} onChange={e => set('return_window_days', Math.max(1, Number(e.target.value)))} dir="ltr"
                    className="bg-transparent outline-none w-10 text-center text-[13px] font-bold tabular-nums" style={{ color: 'var(--text-primary)' }} />
                  <span className="text-[11.5px] pe-2" style={{ color: 'var(--text-muted)' }}>يوم</span>
                </div>
              </div>
            </div>
            {/* Timeline */}
            <div className="mt-6">
              <div className="h-3.5 w-full rounded-full overflow-hidden flex items-center gap-1 p-0.5">
                <div className="h-full rounded-full transition-all duration-500" style={{ width: `${Math.min(100, (lastDays / 90) * 100)}%`, background: 'var(--accent-orange)' }} />
                <div className="h-full flex-1 rounded-full spending-hatched-track" />
              </div>
              <div className="flex justify-between mt-2.5 text-[11.5px]">
                <span style={{ color: 'var(--text-secondary)' }}><b style={{ color: 'var(--text-primary)' }}>التسليم</b> · {fmtDate(delivered)}</span>
                <span style={{ color: 'var(--text-secondary)' }}>آخر موعد للإرجاع · <b style={{ color: 'var(--accent-orange)' }}>{fmtDate(deadline)}</b></span>
              </div>
            </div>
          </div>
        </div>
      </div>

      {mode === 'off' && <Notice tone="warn" className="mt-4" title="الإرجاع متوقف">لن يتمكن الموظفون من إنشاء مرتجعات مبيعات جديدة من أي فرع.</Notice>}
      {mode === 'unlimited' && <Notice tone="info" className="mt-4">يمكن إرجاع أي طلب مُسلّم دون قيود زمنية، مع خضوع المرتجع لدورة الاعتماد المعتادة.</Notice>}
    </SectionCard>
  );
}

/* ---------- Tab 5: Purchases ---------- */
function PurchasesTab({ v, set }: { v: AdminSettings['purchases']; set: (k: keyof AdminSettings['purchases'], val: any) => void }) {
  const on = v.allow_self_approve_purchases;

  const Step = ({ who, label, active, icon, last }: { who: string; label: string; active?: boolean; icon: string; last?: boolean }) => (
    <div className="flex items-center gap-3 flex-1 min-w-[140px]">
      <div className="flex flex-col items-center gap-2 flex-1">
        <span className={`w-11 h-11 rounded-full flex items-center justify-center ${active ? 'text-white v-accent-btn' : 'v-icon-well'}`}><Icon name={icon} className="w-[18px] h-[18px]" /></span>
        <div className="text-center"><div className="text-[12.5px] font-semibold" style={{ color: 'var(--text-primary)' }}>{label}</div><div className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{who}</div></div>
      </div>
      {!last && <Icon name="chevLeft" className="w-4 h-4 shrink-0 -mt-8" style={{ color: 'var(--text-muted)' }} />}
    </div>
  );

  return <>
    <SectionCard title="دورة المشتريات" subtitle="التحكم في صلاحيات اعتماد طلبات وأوامر الشراء.">
      <div className="v-subcard p-5 flex items-start justify-between gap-5">
        <div className="flex items-start gap-3.5">
          <span className={`w-11 h-11 rounded-full flex items-center justify-center shrink-0 ${on ? 'v-badge-orange' : 'v-badge-green'}`}><Icon name={on ? 'userCheck' : 'dual'} className="w-5 h-5" /></span>
          <div>
            <div className="text-[14px] font-semibold" style={{ color: 'var(--text-primary)' }}>السماح بالاعتماد الذاتي لأوامر الشراء</div>
            <div className="text-[12px] mt-1 leading-relaxed max-w-[560px]" style={{ color: 'var(--text-secondary)' }}>
              هل يحق لنفس الموظف الذي أنشأ طلب/أمر الشراء أن يعتمده بنفسه (إذا كان يملك صلاحية <span className="font-mono text-[11px] px-1.5 py-0.5 rounded" style={{ background: 'var(--bg-card)' }} dir="ltr">purchases.approve</span>)، أم يشترط النظام موافقة موظف أو مدير آخر؟
            </div>
            <div className="text-[10.5px] font-mono mt-2" style={{ color: 'var(--text-muted)' }} dir="ltr">allow_self_approve_purchases = {String(on)}</div>
          </div>
        </div>
        <Toggle on={on} onChange={x => set('allow_self_approve_purchases', x)} />
      </div>

      <div className="mt-5">
        <div className="text-[12px] font-semibold mb-4" style={{ color: 'var(--text-muted)' }}>سير العمل الحالي</div>
        <div className="flex flex-wrap items-start gap-2">
          <Step icon="doc" label="إنشاء أمر الشراء" who="الموظف (أ)" active />
          <Step icon={on ? 'userCheck' : 'dual'} label="الاعتماد" who={on ? 'الموظف (أ) نفسه' : 'موظف/مدير آخر (ب)'} active />
          <Step icon="truck" label="استلام البضاعة" who="أمين المستودع" last />
        </div>
      </div>

      {on ? (
        <Notice tone="warn" className="mt-5" title="الرقابة الثنائية معطّلة">
          يمكن للموظف إنشاء أمر شراء واعتماده دون مراجعة. يُنصح بتعطيل هذا الخيار في الشركات الكبيرة لضمان الفصل بين الصلاحيات.
        </Notice>
      ) : (
        <div className="v-notice-ok rounded-2xl px-4 py-3 flex items-start gap-3 mt-5">
          <Icon name="shieldCheck" className="w-4 h-4 mt-0.5 shrink-0" strokeWidth={2} />
          <div className="text-[12.5px] leading-relaxed"><div className="font-semibold mb-0.5">الرقابة الثنائية (Dual Control) مفعّلة</div><div className="opacity-90">الخيار الموصى به للشركات الكبيرة — يضمن الفصل بين صلاحيات الإنشاء والاعتماد.</div></div>
        </div>
      )}
    </SectionCard>
  </>;
}
