import React, { useState } from 'react';
import { PageHeader } from '../../components/admin/PageHeader';
import { Field, Toggle, RadioCards } from '../../components/admin/FormFields';
import { AlertBanner } from '../../components/admin/States';
import { Button, Input } from '@alfarid/ui';
import { useAdminDataStore, AdminSettings } from '../../stores/admin-data.store';
import {
  Building2,
  Percent,
  Hash,
  RotateCcw,
  FileSignature,
  FileText,
  Check,
  AlertTriangle,
  Sparkles,
} from 'lucide-react';

type SectionKey = 'company' | 'tax' | 'numbering' | 'returns' | 'purchases';

const SECTIONS = [
  { key: 'company' as SectionKey, icon: Building2, label: 'بيانات الشركة' },
  { key: 'tax' as SectionKey, icon: Percent, label: 'الضرائب' },
  { key: 'numbering' as SectionKey, icon: Hash, label: 'ترقيم المستندات' },
  { key: 'returns' as SectionKey, icon: RotateCcw, label: 'سياسة الإرجاع' },
  { key: 'purchases' as SectionKey, icon: FileSignature, label: 'المشتريات' },
];

export function SettingsPage() {
  const { settings, updateSettings } = useAdminDataStore();
  const [activeSec, setActiveSec] = useState<SectionKey>('company');
  const [dirty, setDirty] = useState(false);
  const [savedSuccess, setSavedSuccess] = useState(false);

  // Local editable copies
  const [company, setCompany] = useState(settings.company);
  const [tax, setTax] = useState(settings.tax);
  const [numbering, setNumbering] = useState(settings.numbering);
  const [returns, setReturns] = useState(settings.returns);
  const [purchases, setPurchases] = useState(settings.purchases);

  // Numbering preview computation
  const previewNumber = [
    numbering.prefix,
    numbering.year ? '2026' : null,
    String(numbering.next).padStart(numbering.pad, '0'),
  ]
    .filter(Boolean)
    .join(numbering.sep);

  const handleSave = () => {
    updateSettings({
      company,
      tax,
      numbering,
      returns,
      purchases,
    });
    setDirty(false);
    setSavedSuccess(true);
    setTimeout(() => setSavedSuccess(false), 3000);
  };

  const markDirty = () => {
    if (!dirty) setDirty(true);
  };

  return (
    <div className="space-y-6" dir="rtl">
      <PageHeader
        crumbs={[['الرئيسية', '/admin/products'], ['الإدارة'], ['الإعدادات']]}
        bold="الإعدادات"
        sub="إعدادات عامة تنطبق على كل الفروع وتتحكم في فواتير النظام والضرائب وسياسات الإرجاع."
      />

      <div className="flex flex-col lg:flex-row gap-6 items-start">
        {/* Vertical Navigation Bar */}
        <div className="w-full lg:w-64 bg-white border border-zinc-200 rounded-2xl p-2 shadow-sm shrink-0">
          <div className="px-3 py-2 text-[11px] font-bold text-zinc-400 tracking-wider">
            أقسام الإعدادات
          </div>
          <nav className="space-y-1">
            {SECTIONS.map((sec) => {
              const Icon = sec.icon;
              const isActive = activeSec === sec.key;
              return (
                <button
                  key={sec.key}
                  type="button"
                  onClick={() => setActiveSec(sec.key)}
                  className={`w-full flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-xs font-semibold transition-all ${
                    isActive
                      ? 'bg-zinc-900 text-white shadow-sm'
                      : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900'
                  }`}
                >
                  <Icon className={`w-4 h-4 ${isActive ? 'text-white' : 'text-zinc-400'}`} />
                  <span>{sec.label}</span>
                </button>
              );
            })}
          </nav>
        </div>

        {/* Form Panel */}
        <div className="flex-1 w-full bg-white border border-zinc-200 rounded-2xl shadow-sm overflow-hidden flex flex-col">
          {/* Section Header */}
          <div className="px-6 py-4 border-b border-zinc-100 flex items-center justify-between">
            <h3 className="text-sm font-bold text-zinc-900">
              {SECTIONS.find((s) => s.key === activeSec)?.label}
            </h3>
            {activeSec === 'numbering' && (
              <select className="text-xs border border-zinc-200 rounded-lg px-2.5 py-1 text-zinc-700 bg-zinc-50 font-medium">
                <option>فواتير البيع</option>
                <option>أوامر الشراء</option>
                <option>المرتجعات</option>
                <option>التحويلات المخزنية</option>
              </select>
            )}
          </div>

          {/* Section Content */}
          <div className="p-6">
            {activeSec === 'company' && (
              <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                <Field label="اسم الشركة" req>
                  <Input
                    value={company.nameAr}
                    onChange={(e) => {
                      setCompany({ ...company, nameAr: e.target.value });
                      markDirty();
                    }}
                  />
                </Field>
                <Field label="الاسم التجاري (إنجليزي)">
                  <Input
                    dir="ltr"
                    value={company.nameEn}
                    onChange={(e) => {
                      setCompany({ ...company, nameEn: e.target.value });
                      markDirty();
                    }}
                  />
                </Field>

                <div className="md:col-span-2">
                  <Field
                    label="رابط اللوجو"
                    help="الحقل حاليًا يقبل رابطًا نصيًا. رفع اللوجو مباشرة يحتاج مسار رفع في الـ API أولًا."
                  >
                    <div className="flex items-center gap-3">
                      <div className="w-10 h-10 rounded-xl bg-zinc-900 flex items-center justify-center text-white font-black text-xs shrink-0 shadow-sm">
                        AF
                      </div>
                      <div className="flex-1" dir="ltr">
                        <Input
                          value={company.logo}
                          onChange={(e) => {
                            setCompany({ ...company, logo: e.target.value });
                            markDirty();
                          }}
                        />
                      </div>
                    </div>
                  </Field>
                </div>

                <Field label="الهاتف">
                  <Input
                    dir="ltr"
                    value={company.phone}
                    onChange={(e) => {
                      setCompany({ ...company, phone: e.target.value });
                      markDirty();
                    }}
                  />
                </Field>
                <Field label="البريد الإلكتروني">
                  <Input
                    dir="ltr"
                    value={company.email}
                    onChange={(e) => {
                      setCompany({ ...company, email: e.target.value });
                      markDirty();
                    }}
                  />
                </Field>
                <div className="md:col-span-2">
                  <Field label="العنوان">
                    <Input
                      value={company.address}
                      onChange={(e) => {
                        setCompany({ ...company, address: e.target.value });
                        markDirty();
                      }}
                    />
                  </Field>
                </div>
              </div>
            )}

            {activeSec === 'tax' && (
              <div className="space-y-6">
                <div>
                  <Toggle
                    checked={tax.enabled}
                    onChange={(v) => {
                      setTax({ ...tax, enabled: v });
                      markDirty();
                    }}
                    label="تفعيل ضريبة القيمة المضافة في النظام"
                  />
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
                  <Field label="نسبة الضريبة" req help="حتى منزلتين عشريتين.">
                    <div className="relative flex items-center" dir="ltr">
                      <Input
                        disabled={!tax.enabled}
                        value={tax.rate}
                        onChange={(e) => {
                          setTax({ ...tax, rate: e.target.value });
                          markDirty();
                        }}
                      />
                      <span className="absolute right-3 text-xs font-bold text-zinc-400 select-none">
                        %
                      </span>
                    </div>
                  </Field>

                  <Field label="رقم التسجيل الضريبي">
                    <Input
                      dir="ltr"
                      disabled={!tax.enabled}
                      className="font-mono text-zinc-800"
                      value={tax.reg}
                      onChange={(e) => {
                        setTax({ ...tax, reg: e.target.value });
                        markDirty();
                      }}
                    />
                  </Field>
                </div>

                <Field label="طريقة عرض الأسعار للعملاء">
                  <div className="inline-flex rounded-xl border border-zinc-200 p-1 bg-zinc-50">
                    <button
                      type="button"
                      onClick={() => {
                        setTax({ ...tax, inclusive: true });
                        markDirty();
                      }}
                      className={`px-4 py-2 text-xs font-semibold rounded-lg transition-all ${
                        tax.inclusive
                          ? 'bg-white text-zinc-900 shadow-sm'
                          : 'text-zinc-600 hover:text-zinc-900'
                      }`}
                    >
                      الأسعار شاملة الضريبة
                    </button>
                    <button
                      type="button"
                      onClick={() => {
                        setTax({ ...tax, inclusive: false });
                        markDirty();
                      }}
                      className={`px-4 py-2 text-xs font-semibold rounded-lg transition-all ${
                        !tax.inclusive
                          ? 'bg-white text-zinc-900 shadow-sm'
                          : 'text-zinc-600 hover:text-zinc-900'
                      }`}
                    >
                      الضريبة تُضاف عند البيع
                    </button>
                  </div>
                </Field>
              </div>
            )}

            {activeSec === 'numbering' && (
              <div className="space-y-6">
                {/* Number Preview Box */}
                <div className="p-4 rounded-xl bg-gradient-to-r from-zinc-900 to-zinc-800 text-white flex items-center justify-between shadow-sm">
                  <div>
                    <div className="text-[11px] font-medium text-zinc-400">
                      معاينة الرقم التالي الصادر من النظام
                    </div>
                    <div className="text-2xl font-mono font-bold tracking-wider text-amber-400 mt-1" dir="ltr">
                      {previewNumber}
                    </div>
                  </div>
                  <FileText className="w-8 h-8 text-zinc-500" />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                  <Field label="البادئة">
                    <Input
                      dir="ltr"
                      className="font-mono uppercase"
                      value={numbering.prefix}
                      onChange={(e) => {
                        setNumbering({ ...numbering, prefix: e.target.value.toUpperCase() });
                        markDirty();
                      }}
                    />
                  </Field>

                  <Field label="الفاصل">
                    <select
                      className="w-full px-3 py-2 text-sm rounded-lg border border-zinc-300 bg-white focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-600 font-medium"
                      value={numbering.sep}
                      onChange={(e) => {
                        setNumbering({ ...numbering, sep: e.target.value });
                        markDirty();
                      }}
                    >
                      <option value="-">شرطة (-)</option>
                      <option value="/">شرطة مائلة (/)</option>
                      <option value="">بدون فاصل</option>
                    </select>
                  </Field>

                  <Field label="عدد الخانات">
                    <Input
                      dir="ltr"
                      type="number"
                      min={3}
                      max={10}
                      value={numbering.pad}
                      onChange={(e) => {
                        setNumbering({ ...numbering, pad: +e.target.value || 3 });
                        markDirty();
                      }}
                    />
                  </Field>

                  <Field
                    label="الرقم التالي"
                    help="لا يمكن أن يقل عن آخر رقم صادر (1283)."
                    error={numbering.next < 1284 ? 'يجب أن يكون أكبر من 1283' : null}
                  >
                    <Input
                      dir="ltr"
                      type="number"
                      value={numbering.next}
                      onChange={(e) => {
                        setNumbering({ ...numbering, next: +e.target.value });
                        markDirty();
                      }}
                    />
                  </Field>
                </div>

                <div>
                  <Toggle
                    checked={numbering.year}
                    onChange={(v) => {
                      setNumbering({ ...numbering, year: v });
                      markDirty();
                    }}
                    label="تضمين السنة الحالية (2026) في تركيبة الرقم"
                  />
                </div>
              </div>
            )}

            {activeSec === 'returns' && (
              <div className="space-y-6">
                <div>
                  <label className="block text-xs font-semibold text-zinc-700 mb-2">
                    قواعد وقيد الإرجاع
                  </label>
                  <RadioCards
                    cols="grid-cols-1 md:grid-cols-3"
                    value={returns.type}
                    onChange={(val) => {
                      setReturns({ ...returns, type: val as any });
                      markDirty();
                    }}
                    options={[
                      {
                        value: 'off',
                        title: 'متوقف',
                        description: 'لا يُسمح بالإرجاع نهائيًا.',
                      },
                      {
                        value: 'unlimited',
                        title: 'بدون حد زمني',
                        description: 'الإرجاع مسموح في أي وقت.',
                      },
                      {
                        value: 'days',
                        title: 'خلال عدد أيام',
                        description: 'فترة محددة من تاريخ إصدار الفاتورة.',
                      },
                    ]}
                  />
                </div>

                {returns.type === 'days' && (
                  <div className="max-w-xs">
                    <Field label="عدد الأيام المسموح بها" req>
                      <div className="relative flex items-center" dir="ltr">
                        <Input
                          type="number"
                          min={1}
                          value={returns.days || 14}
                          onChange={(e) => {
                            setReturns({ ...returns, days: +e.target.value });
                            markDirty();
                          }}
                        />
                        <span className="absolute right-3 text-xs font-semibold text-zinc-400 select-none">
                          يوم
                        </span>
                      </div>
                    </Field>
                  </div>
                )}

                <div className="p-3 bg-zinc-50 border border-zinc-200 rounded-xl text-xs text-zinc-600 flex items-center gap-2">
                  <span className="font-semibold text-zinc-700">بيانات التكوين المرسلة:</span>
                  <code className="font-mono text-zinc-800 bg-white px-2 py-0.5 rounded border border-zinc-200" dir="ltr">
                    {returns.type === 'off'
                      ? '{ returns_enabled: false }'
                      : returns.type === 'unlimited'
                      ? '{ returns_enabled: true, return_days: null }'
                      : `{ returns_enabled: true, return_days: ${returns.days || 14} }`}
                  </code>
                </div>
              </div>
            )}

            {activeSec === 'purchases' && (
              <div className="space-y-4">
                <Toggle
                  checked={purchases.selfApprove}
                  onChange={(v) => {
                    setPurchases({ selfApprove: v });
                    markDirty();
                  }}
                  label="السماح لمنشئ أمر الشراء باعتماده ذاتيًا"
                />
                <p className="text-xs text-zinc-500 mr-7">
                  عند إيقاف هذا الخيار، يجب أن يعتمد الأمر مستخدم آخر لديه صلاحية{' '}
                  <code className="font-mono text-zinc-800 bg-zinc-100 px-1.5 py-0.5 rounded">
                    purchases.approve
                  </code>
                  .
                </p>

                {purchases.selfApprove && (
                  <AlertBanner
                    tone="warn"
                    title="تنبيه رقابي"
                    text="الاعتماد الذاتي يقلل الرقابة على عمليات المشتريات. يُنصح به للفرق الصغيرة فقط."
                  />
                )}
              </div>
            )}
          </div>

          {/* Sticky Bottom Bar */}
          <div className="px-6 py-4 bg-zinc-50 border-t border-zinc-200 flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Button
                type="button"
                variant="primary"
                disabled={!dirty || (activeSec === 'numbering' && numbering.next < 1284)}
                onClick={handleSave}
              >
                حفظ الإعدادات
              </Button>
            </div>

            <div>
              {savedSuccess ? (
                <div className="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-600 bg-emerald-50 px-3 py-1.5 rounded-lg border border-emerald-200">
                  <Check className="w-4 h-4" />
                  <span>تم حفظ الإعدادات بنجاح</span>
                </div>
              ) : dirty ? (
                <span className="text-xs font-semibold text-amber-600 bg-amber-50 px-3 py-1.5 rounded-lg border border-amber-200">
                  تغييرات غير محفوظة
                </span>
              ) : (
                <span className="text-xs font-medium text-zinc-400">
                  جميع الإعدادات محدثة
                </span>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
