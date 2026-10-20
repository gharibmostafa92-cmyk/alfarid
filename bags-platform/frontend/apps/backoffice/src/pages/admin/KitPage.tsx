import React, { useState } from 'react';
import { PageHeader } from '../../components/admin/PageHeader';
import { Field, Toggle, Check, PasswordInput } from '../../components/admin/FormFields';
import { SearchSelect } from '../../components/admin/SearchSelect';
import { SingleImageUpload } from '../../components/admin/SingleImageUpload';
import { ConfirmModal } from '../../components/admin/ConfirmModal';
import { EmptyState, ErrorState, NoAccessState, AlertBanner } from '../../components/admin/States';
import { Button, Input, Badge } from '@alfarid/ui';
import { useAdminDataStore } from '../../stores/admin-data.store';
import {
  Plus,
  Trash2,
  AlertTriangle,
  RefreshCw,
  X,
  GripVertical,
  CheckCircle2,
  Package,
} from 'lucide-react';

export function KitPage() {
  const { branches, roles, categories } = useAdminDataStore();

  // SearchSelect demo state
  const [singleBranch, setSingleBranch] = useState<number | null>(null);
  const [multiRoles, setMultiRoles] = useState<number[]>([2]);

  // Toggles and Checks demo state
  const [toggle1, setToggle1] = useState(true);
  const [check1, setCheck1] = useState(false);

  // Single image upload demo state
  const [uploadedImage, setUploadedImage] = useState<string | null>(null);

  // Modal demo states
  const [confirmNormal, setConfirmNormal] = useState(false);
  const [confirmReject, setConfirmReject] = useState(false);

  // Toast banner trigger state
  const [showToast, setShowToast] = useState(false);

  const triggerToast = () => {
    setShowToast(true);
    setTimeout(() => setShowToast(false), 3000);
  };

  return (
    <div className="space-y-8" dir="rtl">
      <PageHeader
        crumbs={[['الرئيسية', '/admin/products'], ['مرجع التصميم']]}
        light="المكونات"
        bold="المشتركة"
        sub="كل مكون بحالاته — مشترك بين بوابة الإدارة والمورد. المتجر ونقطة البيع لهما تنقل مستقل."
      />

      {/* Floating Success Toast Demo */}
      {showToast && (
        <div className="fixed top-6 left-1/2 -translate-x-1/2 z-50 bg-zinc-900 text-white px-5 py-3 rounded-xl shadow-xl flex items-center gap-3 border border-zinc-700 animate-in fade-in slide-in-from-top-2">
          <CheckCircle2 className="w-4 h-4 text-emerald-400" />
          <span className="text-xs font-bold">تم حفظ التغييرات بنجاح</span>
        </div>
      )}

      {/* Section 1: Form Fields */}
      <section className="bg-white border border-zinc-200 rounded-2xl p-6 shadow-sm space-y-4">
        <div>
          <h2 className="text-base font-bold text-zinc-900">الحقول النصية والمدخلات</h2>
          <p className="text-xs text-zinc-500 mt-1">
            عنوان واضح، علامة المطلوب، نص مساعد، خطأ تحت الحقل. البريد وSKU والباركود والروابط LTR.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <div className="p-3.5 bg-zinc-50 rounded-xl border border-zinc-100">
            <span className="text-[10px] font-mono text-zinc-400 uppercase tracking-wider block mb-2">
              DEFAULT + REQUIRED
            </span>
            <Field label="اسم المنتج" req help="يظهر في المتجر والفواتير.">
              <Input placeholder="حقيبة يد «ليلى»" />
            </Field>
          </div>

          <div className="p-3.5 bg-zinc-50 rounded-xl border border-zinc-100">
            <span className="text-[10px] font-mono text-zinc-400 uppercase tracking-wider block mb-2">
              ERROR
            </span>
            <Field label="البريد الإلكتروني" req error="هذا البريد مسجّل لمستخدم آخر.">
              <Input dir="ltr" defaultValue="mona.a@alfarid.eg" error />
            </Field>
          </div>

          <div className="p-3.5 bg-zinc-50 rounded-xl border border-zinc-100">
            <span className="text-[10px] font-mono text-zinc-400 uppercase tracking-wider block mb-2">
              DISABLED
            </span>
            <Field label="نسبة الضريبة" help="فعّل الضريبة أولًا.">
              <Input dir="ltr" disabled defaultValue="14" />
            </Field>
          </div>

          <div className="p-3.5 bg-zinc-50 rounded-xl border border-zinc-100">
            <span className="text-[10px] font-mono text-zinc-400 uppercase tracking-wider block mb-2">
              READ-ONLY
            </span>
            <Field label="كود المنتج" help="يتولد من الخادم تلقائيًا.">
              <Input dir="ltr" className="font-mono bg-zinc-100" readOnly defaultValue="PRD-024180" />
            </Field>
          </div>

          <div className="p-3.5 bg-zinc-50 rounded-xl border border-zinc-100">
            <span className="text-[10px] font-mono text-zinc-400 uppercase tracking-wider block mb-2">
              LTR INSIDE RTL
            </span>
            <Field label="SKU" opt>
              <Input dir="ltr" className="font-mono" defaultValue="LAI-BLK-M" />
            </Field>
          </div>

          <div className="p-3.5 bg-zinc-50 rounded-xl border border-zinc-100">
            <span className="text-[10px] font-mono text-zinc-400 uppercase tracking-wider block mb-2">
              PASSWORD
            </span>
            <Field label="كلمة المرور" req>
              <PasswordInput defaultValue="alfarid2026" />
            </Field>
          </div>

          <div className="p-3.5 bg-zinc-50 rounded-xl border border-zinc-100">
            <span className="text-[10px] font-mono text-zinc-400 uppercase tracking-wider block mb-2">
              TOGGLE · CHECK
            </span>
            <div className="space-y-2 pt-1">
              <Toggle checked={toggle1} onChange={setToggle1} label="نشط" />
              <div>
                <Toggle checked={true} disabled onChange={() => {}} label="معطل (disabled)" />
              </div>
              <Check checked={check1} onChange={setCheck1} label="تضمين الأقسام الفرعية" />
              <Check checked={false} indeterminate label="تحديد جزئي (indeterminate)" />
            </div>
          </div>

          <div className="p-3.5 bg-zinc-50 rounded-xl border border-zinc-100">
            <span className="text-[10px] font-mono text-zinc-400 uppercase tracking-wider block mb-2">
              PRICE · 2 DECIMALS
            </span>
            <Field label="سعر التجزئة" req error="رقم بحد أقصى منزلتين عشريتين">
              <div className="relative flex items-center" dir="ltr">
                <Input error defaultValue="2450.999" />
                <span className="absolute right-3 text-[11px] font-mono font-bold text-zinc-400 select-none">
                  EGP
                </span>
              </div>
            </Field>
          </div>
        </div>
      </section>

      {/* Section 2: Searchable Select */}
      <section className="bg-white border border-zinc-200 rounded-2xl p-6 shadow-sm space-y-4">
        <div>
          <h2 className="text-base font-bold text-zinc-900">قوائم الاختيار القابلة للبحث (SearchSelect)</h2>
          <p className="text-xs text-zinc-500 mt-1">
            للأقسام والبراندات والفروع — تحميل، عدم وجود نتائج، خيارات معطلة مع إيضاح السبب، واختيار متعدد.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-5">
          <div className="p-4 bg-zinc-50 rounded-xl border border-zinc-100">
            <span className="text-[10px] font-mono text-zinc-400 uppercase tracking-wider block mb-2">
              SINGLE · DISABLED OPTIONS
            </span>
            <Field label="الفرع">
              <SearchSelect
                value={singleBranch}
                onChange={(val) => setSingleBranch(val as number)}
                clearable
                placeholder="اختر الفرع…"
                options={branches.map((b) => ({
                  value: b.id,
                  label: b.name,
                  sub: b.code,
                  disabled: !b.active,
                  why: b.active ? undefined : 'غير نشط حالياً',
                }))}
              />
            </Field>
          </div>

          <div className="p-4 bg-zinc-50 rounded-xl border border-zinc-100">
            <span className="text-[10px] font-mono text-zinc-400 uppercase tracking-wider block mb-2">
              MULTI SELECT
            </span>
            <Field label="الأدوار الصلاحية">
              <SearchSelect
                multi
                value={multiRoles}
                onChange={(val) => setMultiRoles(val as number[])}
                placeholder="اختر دوراً أو أكثر…"
                options={roles.map((r) => ({
                  value: r.id,
                  label: r.name,
                  sub: `${r.users} مستخدم`,
                }))}
              />
            </Field>
          </div>

          <div className="p-4 bg-zinc-50 rounded-xl border border-zinc-100">
            <span className="text-[10px] font-mono text-zinc-400 uppercase tracking-wider block mb-2">
              VALIDATION ERROR
            </span>
            <Field label="القسم" req error="يرجى اختيار القسم الرئيسي.">
              <SearchSelect
                error
                value={null}
                onChange={() => {}}
                placeholder="اختر القسم…"
                options={categories.map((c) => ({
                  value: c.id,
                  label: c.ar,
                  sub: c.en,
                }))}
              />
            </Field>
          </div>
        </div>
      </section>

      {/* Section 3: Buttons and Badges */}
      <section className="bg-white border border-zinc-200 rounded-2xl p-6 shadow-sm space-y-4">
        <div>
          <h2 className="text-base font-bold text-zinc-900">الأزرار والشارات</h2>
          <p className="text-xs text-zinc-500 mt-1">
            زوايا مصقولة، تباين عالٍ، وألوان محددة للحالات (موفق، تحذير، خطأ، محايد).
          </p>
        </div>

        <div className="p-4 bg-zinc-50 rounded-xl border border-zinc-100 flex flex-wrap items-center gap-3">
          <Button variant="primary">
            <Plus className="w-4 h-4 ml-1.5" />
            إجراء أساسي
          </Button>
          <Button variant="outline">إجراء ثانوي</Button>
          <Button variant="ghost">زر نصي</Button>
          <Button variant="danger">
            <Trash2 className="w-4 h-4 ml-1.5" />
            حذف
          </Button>
          <Button variant="outline" className="border-red-200 text-red-600 hover:bg-red-50">
            حذف ثانوي
          </Button>
          <Button variant="primary" disabled>
            <RefreshCw className="w-3.5 h-3.5 ml-1.5 animate-spin" />
            جاري الحفظ…
          </Button>
          <Button variant="outline" disabled>
            معطل
          </Button>

          <div className="h-6 w-px bg-zinc-300 mx-2" />

          <Badge variant="success">منشور</Badge>
          <Badge variant="warning">مسودة</Badge>
          <Badge variant="default">مؤرشف</Badge>
          <Badge variant="danger">خطأ في الإدخال</Badge>
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-brand-100 text-brand-800 border border-brand-200">
            كل الفروع
          </span>
          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-zinc-900 text-white">
            الغلاف الرئيسي
          </span>
        </div>
      </section>

      {/* Section 4: File Uploads */}
      <section className="bg-white border border-zinc-200 rounded-2xl p-6 shadow-sm space-y-4">
        <div>
          <h2 className="text-base font-bold text-zinc-900">رفع وإدارة الملفات</h2>
          <p className="text-xs text-zinc-500 mt-1">
            القيود معروضة قبل الاختيار، شريط التقدم، ومعالجة فشل الرفع وإعادة المحاولة والترتيب.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div className="p-4 bg-zinc-50 rounded-xl border border-zinc-100 space-y-2">
            <span className="text-[10px] font-mono text-zinc-400 uppercase tracking-wider block">
              SINGLE IMAGE (CATEGORY / BRAND)
            </span>
            <SingleImageUpload value={uploadedImage} onChange={setUploadedImage} />
          </div>

          <div className="p-4 bg-zinc-50 rounded-xl border border-zinc-100 space-y-3">
            <span className="text-[10px] font-mono text-zinc-400 uppercase tracking-wider block">
              FILE LIST STATES
            </span>
            <div className="space-y-2">
              {/* File 1: In progress */}
              <div className="flex items-center gap-3 p-2.5 bg-white border border-zinc-200 rounded-xl">
                <div className="w-10 h-10 rounded-lg bg-zinc-100 flex items-center justify-center text-zinc-500 shrink-0">
                  <Package className="w-5 h-5" />
                </div>
                <div className="flex-1 min-w-0 text-right">
                  <div className="text-xs font-bold text-zinc-900 truncate" dir="ltr">
                    laila-front.jpg
                  </div>
                  <div className="flex items-center gap-2 mt-1">
                    <div className="flex-1 h-1.5 bg-zinc-100 rounded-full overflow-hidden">
                      <div className="h-full bg-brand-600 rounded-full" style={{ width: '62%' }} />
                    </div>
                    <span className="text-[10px] text-zinc-400 font-mono">62%</span>
                  </div>
                </div>
                <button type="button" className="p-1 text-zinc-400 hover:text-zinc-600">
                  <X className="w-4 h-4" />
                </button>
              </div>

              {/* File 2: Failed file size */}
              <div className="flex items-center gap-3 p-2.5 bg-red-50/50 border border-red-200 rounded-xl">
                <div className="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center text-red-600 shrink-0">
                  <AlertTriangle className="w-5 h-5" />
                </div>
                <div className="flex-1 min-w-0 text-right">
                  <div className="text-xs font-bold text-zinc-900 truncate" dir="ltr">
                    mira-side.png
                  </div>
                  <div className="text-[11px] text-red-600 font-medium">
                    يتجاوز 5MB (7.4MB)
                  </div>
                </div>
                <Button size="sm" variant="outline" className="text-xs py-1 px-2.5">
                  إعادة
                </Button>
                <button type="button" className="p-1 text-red-500 hover:text-red-700">
                  <Trash2 className="w-4 h-4" />
                </button>
              </div>

              {/* File 3: Unsupported format */}
              <div className="flex items-center gap-3 p-2.5 bg-red-50/50 border border-red-200 rounded-xl">
                <div className="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center text-red-600 shrink-0">
                  <AlertTriangle className="w-5 h-5" />
                </div>
                <div className="flex-1 min-w-0 text-right">
                  <div className="text-xs font-bold text-zinc-900 truncate" dir="ltr">
                    logo.svg
                  </div>
                  <div className="text-[11px] text-red-600 font-medium">
                    صيغة SVG غير مدعومة
                  </div>
                </div>
                <button type="button" className="p-1 text-red-500 hover:text-red-700">
                  <Trash2 className="w-4 h-4" />
                </button>
              </div>

              {/* File 4: Done */}
              <div className="flex items-center gap-3 p-2.5 bg-white border border-zinc-200 rounded-xl">
                <div className="w-10 h-10 rounded-lg bg-zinc-100 flex items-center justify-center text-zinc-500 shrink-0">
                  <Package className="w-5 h-5" />
                </div>
                <div className="flex-1 min-w-0 text-right">
                  <div className="text-xs font-bold text-zinc-900 truncate" dir="ltr">
                    salim-card.webp
                  </div>
                  <div className="text-[11px] text-zinc-500" dir="ltr">
                    840KB
                  </div>
                </div>
                <Badge variant="success">مرفوع</Badge>
                <button type="button" className="p-1 text-zinc-400 hover:text-zinc-600 cursor-grab">
                  <GripVertical className="w-4 h-4" />
                </button>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Section 5: Alerts and System Statuses */}
      <section className="bg-white border border-zinc-200 rounded-2xl p-6 shadow-sm space-y-4">
        <div>
          <h2 className="text-base font-bold text-zinc-900">التنبيهات والأخطاء</h2>
          <p className="text-xs text-zinc-500 mt-1">
            النجاح يظهر في إشعار سريع، وأخطاء العمليات تظهر في مكانها أو كملخص مع الحقول المعنية.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <AlertBanner
            tone="danger"
            title="رفض الخادم الحفظ (خطأ 422 - التحقق من البيانات)"
          >
            <ul className="list-disc list-inside space-y-1 text-xs text-red-700 mt-1">
              <li>
                المتغير 3: سعر التجزئة يجب أن يكون أكبر من صفر.{' '}
                <code className="font-mono text-red-800 bg-red-100/70 px-1 py-0.5 rounded text-[11px]">
                  variants.2.retail_price
                </code>
              </li>
              <li>
                هذا الرابط مستخدم لمنتج آخر بالفعل.{' '}
                <code className="font-mono text-red-800 bg-red-100/70 px-1 py-0.5 rounded text-[11px]">
                  slug
                </code>
              </li>
            </ul>
          </AlertBanner>

          <div className="space-y-3">
            <AlertBanner
              tone="warn"
              title="تعارض في التعديل (409)"
              text="قام مستخدم آخر بتعديل هذا السجل أثناء العمل عليه. يرجى إعادة تحميل النسخة الأحدث."
            />
            <AlertBanner
              tone="info"
              title="محاولات كثيرة (429)"
              text="تم تجاوز الحد المسموح من الطلبات. حاول مرة أخرى بعد 60 ثانية."
            />
            <div>
              <Button variant="outline" size="sm" onClick={triggerToast}>
                تجربة إشعار نجاح (Toast)
              </Button>
            </div>
          </div>
        </div>
      </section>

      {/* Section 6: Screen States */}
      <section className="bg-white border border-zinc-200 rounded-2xl p-6 shadow-sm space-y-4">
        <div>
          <h2 className="text-base font-bold text-zinc-900">حالات الصفحات والشاشات</h2>
          <p className="text-xs text-zinc-500 mt-1">
            حالة الفراغ عند عدم وجود بيانات، الخطأ مع زر إعادة المحاولة، وحظر الصلاحيات.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div className="border border-zinc-200 rounded-xl p-4 bg-zinc-50">
            <EmptyState
              title="الكتالوج فارغ"
              text="أضف أول شنطة أو محفظة للبدء في البيع."
              action={
                <Button size="sm" variant="primary">
                  <Plus className="w-3.5 h-3.5 ml-1" />
                  منتج جديد
                </Button>
              }
            />
          </div>

          <div className="border border-zinc-200 rounded-xl p-4 bg-zinc-50">
            <ErrorState onRetry={() => alert('إعادة محاولة الاتصال')} />
          </div>

          <div className="border border-zinc-200 rounded-xl p-4 bg-zinc-50">
            <NoAccessState perm="roles.manage" />
          </div>
        </div>
      </section>

      {/* Section 7: Confirmation Modals */}
      <section className="bg-white border border-zinc-200 rounded-2xl p-6 shadow-sm space-y-4">
        <div>
          <h2 className="text-base font-bold text-zinc-900">نوافذ التأكيد (Confirm Modals)</h2>
          <p className="text-xs text-zinc-500 mt-1">
            رسائل مخصصة لكل عملية حذف أو تعديل، مع دعم رسائل الرفض من الخادم عند وجود ارتباطات.
          </p>
        </div>

        <div className="flex items-center gap-3">
          <Button
            variant="outline"
            className="border-red-200 text-red-600 hover:bg-red-50"
            onClick={() => setConfirmNormal(true)}
          >
            <Trash2 className="w-4 h-4 ml-1.5" />
            حذف متاح (ناجح)
          </Button>

          <Button
            variant="outline"
            className="border-red-200 text-red-600 hover:bg-red-50"
            onClick={() => setConfirmReject(true)}
          >
            <Trash2 className="w-4 h-4 ml-1.5" />
            حذف يرفضه الخادم (لوجود ارتباطات)
          </Button>
        </div>

        {/* Modal 1: Normal confirmation */}
        <ConfirmModal
          open={confirmNormal}
          onClose={() => setConfirmNormal(false)}
          onConfirm={() => {
            setConfirmNormal(false);
            triggerToast();
          }}
          title="حذف براند «Atlas»؟"
          text="سيتم حذف هذا البراند نهائيًا ولن يظهر في الفلاتر."
          confirmLabel="حذف البراند"
        />

        {/* Modal 2: Server Rejected confirmation */}
        <ConfirmModal
          open={confirmReject}
          onClose={() => setConfirmReject(false)}
          title="حذف قسم «محافظ»؟"
          text="الحذف نهائي ولا يمكن التراجع عنه."
          confirmLabel="حذف القسم"
          rejectWith="القسم يحتوي على 3 أقسام فرعية و 61 منتجاً مرتبطاً. لا يمكن حذفه قبل نقل المنتجات أو الأقسام الفرعية."
        />
      </section>
    </div>
  );
}
