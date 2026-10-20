import React, { useState, useMemo, useRef } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { PageHeader } from '../../components/admin/PageHeader';
import { SearchSelect } from '../../components/admin/SearchSelect';
import { Field, Toggle, RadioCards } from '../../components/admin/FormFields';
import { AlertBanner } from '../../components/admin/States';
import { ConfirmModal } from '../../components/admin/ConfirmModal';
import { Button, Badge } from '@alfarid/ui';
import { formatCurrency } from '@alfarid/utils';
import {
  useAdminDataStore,
  ProductItem,
  VariantItem,
  COLOR_PRESETS,
  SIZE_PRESETS,
  CAP_PRESETS,
  catPath,
  slugify,
} from '../../stores/admin-data.store';
import {
  ArrowRight,
  Save,
  Send,
  Wand2,
  CheckCircle2,
  AlertCircle,
  X,
  UploadCloud,
  Play,
  Trash2,
  Plus,
  Star,
  Eye,
  Check,
} from 'lucide-react';

export function ProductFormPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const isNew = id === 'new' || !id;

  const { products, categories, brands, addProduct, updateProduct } = useAdminDataStore();
  const existingProduct = products.find((p) => p.id === Number(id));

  const [tab, setTab] = useState<'basics' | 'variants' | 'media' | 'review'>('basics');
  const [dirty, setDirty] = useState(false);

  // Form State
  const [formData, setFormData] = useState({
    ar: existingProduct?.ar || '',
    en: existingProduct?.en || '',
    cat: existingProduct?.cat || null,
    brand: existingProduct?.brand || null,
    channel: existingProduct?.channel || 'both',
    slug: existingProduct?.slug || '',
    dar: existingProduct?.dar || '',
    den: existingProduct?.den || '',
    mat_ar: existingProduct?.mat_ar || 'جلد طبيعي',
    mat_en: existingProduct?.mat_en || 'Genuine leather',
    l: existingProduct?.l || '32',
    w: existingProduct?.w || '12',
    h: existingProduct?.h || '24',
    kg: existingProduct?.kg || '0.8',
    warranty: existingProduct?.warranty || '12',
    origin: existingProduct?.origin || 'مصر',
    featured: existingProduct?.featured || false,
    status: existingProduct?.status || 'draft',
    mode: existingProduct?.mode || 'multi',
  });

  // Generator axes state
  const [axes, setAxes] = useState({
    colors: [COLOR_PRESETS[0], COLOR_PRESETS[1]],
    sizes: ['وسط', 'كبير'],
    caps: [] as string[],
  });

  // Variants state
  const [variants, setVariants] = useState<VariantItem[]>(() => {
    if (existingProduct?.variantsList && existingProduct.variantsList.length > 0) {
      return existingProduct.variantsList;
    }
    return [
      {
        uid: 'v1',
        color_ar: 'أسود',
        color_en: 'Black',
        hex: '#151515',
        size: 'وسط',
        capacity: '',
        sku: 'LAI-BLK-M',
        barcode: '6221048810',
        retail: '2450',
        wholesale: '1900',
        cost: '1150',
        compare: '2800',
        weight: '0.7',
        reorder: '4',
        order: '1',
        active: true,
      },
      {
        uid: 'v2',
        color_ar: 'أسود',
        color_en: 'Black',
        hex: '#151515',
        size: 'كبير',
        capacity: '',
        sku: 'LAI-BLK-L',
        barcode: '6221048811',
        retail: '2850',
        wholesale: '2200',
        cost: '1350',
        compare: '3200',
        weight: '0.9',
        reorder: '4',
        order: '2',
        active: true,
      },
      {
        uid: 'v3',
        color_ar: 'جملي',
        color_en: 'Camel',
        hex: '#b98a5a',
        size: 'وسط',
        capacity: '',
        sku: 'LAI-CAM-M',
        barcode: '6221048812',
        retail: '2450',
        wholesale: '1900',
        cost: '1150',
        compare: '2800',
        weight: '0.7',
        reorder: '4',
        order: '3',
        active: true,
      },
    ];
  });

  // Selected variants for bulk operations
  const [selectedVariants, setSelectedVariants] = useState<Set<number>>(new Set());
  const [bulkPrice, setBulkPrice] = useState('');
  const [bulkField, setBulkField] = useState<'retail' | 'wholesale' | 'cost'>('retail');

  // Media state
  const [mediaList, setMediaList] = useState<
    { id: string; name: string; size: string; color?: string; cover?: boolean }[]
  >([
    { id: '1', name: 'laila-front-black.jpg', size: '1.8 MB', color: 'Black', cover: true },
    { id: '2', name: 'laila-side-black.jpg', size: '2.1 MB', color: 'Black' },
    { id: '3', name: 'laila-camel-front.jpg', size: '1.9 MB', color: 'Camel' },
  ]);

  // Pipeline simulation modal state
  const [pipelineState, setPipelineState] = useState<{
    open: boolean;
    intent: 'draft' | 'publish';
    step: number;
    done: boolean;
  }>({ open: false, intent: 'draft', step: 0, done: false });

  const setField = (key: string, value: any) => {
    setFormData((prev) => ({ ...prev, [key]: value }));
    setDirty(true);
  };

  // Generate Matrix
  const handleGenerateVariants = () => {
    const generated: VariantItem[] = [];
    const colorsList = axes.colors.length > 0 ? axes.colors : [{ ar: '', en: '', hex: '' }];
    const sizesList = axes.sizes.length > 0 ? axes.sizes : [''];
    const capsList = axes.caps.length > 0 ? axes.caps : [''];

    let count = 1;
    colorsList.forEach((c) => {
      sizesList.forEach((s) => {
        capsList.forEach((k) => {
          const skuCode = `PRD-${(c.en || 'VAR').slice(0, 3).toUpperCase()}-${s ? s.slice(0, 1) : 'STD'}`;
          generated.push({
            uid: `gen_${Date.now()}_${count}`,
            color_ar: c.ar,
            color_en: c.en,
            hex: c.hex,
            size: s,
            capacity: k,
            sku: skuCode,
            barcode: `62210${48800 + count}`,
            retail: '2450',
            wholesale: '1900',
            cost: '1150',
            compare: '',
            weight: '0.8',
            reorder: '4',
            order: String(count),
            active: true,
          });
          count++;
        });
      });
    });

    setVariants(generated);
    setDirty(true);
  };

  // Readiness checklist calculations
  const activeVariants = variants.filter((v) => v.active);
  const pricesComplete =
    activeVariants.length > 0 &&
    activeVariants.every(
      (v) =>
        (formData.channel === 'wholesale' || v.retail !== '') &&
        (formData.channel === 'retail' || v.wholesale !== '')
    );

  const checks = [
    {
      ok: Boolean(formData.cat && formData.ar && formData.en),
      title: 'البيانات الأساسية وتصنيف القسم',
      desc: formData.cat ? 'القسم والأسماء مكتملة' : 'برجاء اختيار قسم واسم المنتج',
      tab: 'basics' as const,
    },
    {
      ok: activeVariants.length > 0,
      title: 'متغير نشط واحد على الأقل',
      desc: `${activeVariants.length} متغير نشط من أصل ${variants.length}`,
      tab: 'variants' as const,
    },
    {
      ok: pricesComplete,
      title: 'اكتمال الأسعار وفق قناة البيع',
      desc: `قناة البيع: ${formData.channel === 'both' ? 'تجزئة وجملة' : formData.channel === 'retail' ? 'تجزئة' : 'جملة'}`,
      tab: 'variants' as const,
    },
    {
      ok: mediaList.length > 0,
      title: 'صورة واحدة على الأقل للمنتج',
      desc: `${mediaList.length} صور مرفوعة`,
      tab: 'media' as const,
    },
  ];

  const isReadyToPublish = checks.every((c) => c.ok);

  // Bulk price fill
  const handleApplyBulkPrice = () => {
    if (!bulkPrice || selectedVariants.size === 0) return;
    setVariants((prev) =>
      prev.map((v, idx) => (selectedVariants.has(idx) ? { ...v, [bulkField]: bulkPrice } : v))
    );
    setDirty(true);
  };

  // Bulk fill down from top row
  const handleFillDown = (fieldKey: keyof VariantItem) => {
    if (variants.length <= 1) return;
    const topValue = variants[0][fieldKey];
    setVariants((prev) => prev.map((v) => ({ ...v, [fieldKey]: topValue })));
    setDirty(true);
  };

  // Pipeline runner (Save draft / publish simulation)
  const triggerPipeline = (intent: 'draft' | 'publish') => {
    setPipelineState({ open: true, intent, step: 1, done: false });

    setTimeout(() => {
      setPipelineState((prev) => ({ ...prev, step: 2 }));
      setTimeout(() => {
        setPipelineState((prev) => ({ ...prev, step: 3, done: true }));

        // Save into local store!
        const pricesArr = variants.map((v) => Number(v.retail)).filter(Boolean);
        const minPrice = pricesArr.length ? Math.min(...pricesArr) : 1000;
        const maxPrice = pricesArr.length ? Math.max(...pricesArr) : 1000;

        const payload: Partial<ProductItem> = {
          ar: formData.ar || 'منتج جديد',
          en: formData.en || 'New Product',
          cat: formData.cat,
          brand: formData.brand,
          channel: formData.channel as any,
          status: intent === 'publish' ? 'published' : 'draft',
          variantsCount: variants.length,
          price: [minPrice, maxPrice],
          featured: formData.featured,
          slug: formData.slug || slugify(formData.en || 'product'),
          dar: formData.dar,
          den: formData.den,
          mat_ar: formData.mat_ar,
          mat_en: formData.mat_en,
          l: formData.l,
          w: formData.w,
          h: formData.h,
          kg: formData.kg,
          warranty: formData.warranty,
          origin: formData.origin,
          variantsList: variants,
        };

        if (isNew) {
          addProduct(payload as any);
        } else if (existingProduct) {
          updateProduct(existingProduct.id, payload);
        }
        setDirty(false);
      }, 700);
    }, 700);
  };

  return (
    <div className="pb-20">
      <PageHeader
        crumbs={[['المنتجات', '/admin/products'], [isNew ? 'منتج جديد' : formData.ar]]}
        light={isNew ? 'منتج' : null}
        bold={isNew ? 'جديد' : formData.ar}
        sub={
          <div className="flex items-center gap-2 mt-1">
            <Badge variant={formData.status === 'published' ? 'success' : 'warning'}>
              {formData.status === 'published' ? 'منشور' : 'مسودة'}
            </Badge>
            <span className="text-zinc-400 font-mono text-xs">
              {existingProduct?.code || 'الكود يتولد بعد الحفظ'}
            </span>
            {formData.featured && (
              <span className="inline-flex items-center gap-1 text-amber-600 font-medium text-xs">
                <Star className="w-3.5 h-3.5 fill-amber-400 text-amber-500" />
                <span>مميز</span>
              </span>
            )}
          </div>
        }
        actions={
          <Button
            variant="outline"
            size="sm"
            className="gap-2"
            onClick={() => navigate('/admin/products')}
          >
            <ArrowRight className="w-4 h-4" />
            <span>العودة للمنتجات</span>
          </Button>
        }
      />

      {/* Tabs Bar */}
      <div className="bg-white border border-zinc-200 rounded-2xl p-1.5 shadow-sm mb-6 flex items-center gap-1 text-xs font-semibold overflow-x-auto">
        {[
          { key: 'basics', label: '1. البيانات الأساسية' },
          { key: 'variants', label: `2. المتغيرات والأسعار (${variants.length})` },
          { key: 'media', label: `3. الصور والفيديو (${mediaList.length})` },
          { key: 'review', label: '4. المراجعة والنشر' },
        ].map((t) => (
          <button
            key={t.key}
            onClick={() => setTab(t.key as any)}
            className={`flex-1 py-2.5 px-4 rounded-xl text-center transition-all whitespace-nowrap ${
              tab === t.key
                ? 'bg-brand-600 text-white shadow-sm'
                : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-100'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {/* Tab 1: Basics */}
      {tab === 'basics' && (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div className="md:col-span-2 space-y-6">
            {/* Identity Card */}
            <div className="bg-white border border-zinc-200 rounded-2xl p-6 shadow-sm space-y-4">
              <h3 className="text-sm font-bold text-zinc-900 border-b border-zinc-100 pb-3">
                الهوية والتصنيف
              </h3>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Field label="الاسم العربي" req>
                  <input
                    type="text"
                    value={formData.ar}
                    onChange={(e) => setField('ar', e.target.value)}
                    placeholder="حقيبة يد جلد طبيعي «ليلى»"
                    className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
                  />
                </Field>
                <Field label="الاسم الإنجليزي" req>
                  <input
                    type="text"
                    dir="ltr"
                    value={formData.en}
                    onChange={(e) => setField('en', e.target.value)}
                    placeholder="Laila Leather Handbag"
                    className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
                  />
                </Field>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Field label="القسم الرئيسي / الفرعي" req help="اختر القسم المطابق لنوع المنتج.">
                  <SearchSelect
                    value={formData.cat}
                    onChange={(val) => setField('cat', val)}
                    placeholder="اختر القسم"
                    options={categories.map((c) => ({
                      value: c.id,
                      label: catPath(categories, c.id)
                        .map((x) => x.ar)
                        .join(' / '),
                    }))}
                  />
                </Field>
                <Field label="البراند / الماركة" opt>
                  <SearchSelect
                    value={formData.brand}
                    onChange={(val) => setField('brand', val)}
                    placeholder="بدون براند"
                    clearable
                    options={brands.map((b) => ({
                      value: b.id,
                      label: b.en,
                      sub: b.ar,
                    }))}
                  />
                </Field>
              </div>

              <Field label="قناة البيع المستهدفة" req>
                <RadioCards
                  value={formData.channel}
                  onChange={(v) => setField('channel', v)}
                  cols="grid-cols-3"
                  options={[
                    { value: 'retail', title: 'تجزئة فقط', description: 'سعر القطاعي مطلوب' },
                    { value: 'wholesale', title: 'جملة فقط', description: 'سعر الجملة مطلوب' },
                    { value: 'both', title: 'تجزئة وجملة', description: 'السعران مطلوبان' },
                  ]}
                />
              </Field>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
                <Field label="الرابط المخصص (Slug)" opt help="يتولد تلقائياً من الاسم الإنجليزي.">
                  <input
                    type="text"
                    dir="ltr"
                    value={formData.slug}
                    onChange={(e) => setField('slug', e.target.value)}
                    placeholder={slugify(formData.en || 'laila-handbag')}
                    className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs font-mono focus:border-brand-500 focus:outline-none"
                  />
                </Field>
                <div className="pt-6">
                  <Toggle
                    checked={formData.featured}
                    onChange={(v) => setField('featured', v)}
                    label={
                      <span className="flex items-center gap-1.5 font-bold text-zinc-900">
                        <span>منتج مميز في الواجهة</span>
                        <Star className="w-3.5 h-3.5 fill-amber-400 text-amber-500" />
                      </span>
                    }
                  />
                </div>
              </div>
            </div>

            {/* Description & Materials */}
            <div className="bg-white border border-zinc-200 rounded-2xl p-6 shadow-sm space-y-4">
              <h3 className="text-sm font-bold text-zinc-900 border-b border-zinc-100 pb-3">
                الوصف والمواصفات
              </h3>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Field label="الوصف بالعربية" opt>
                  <textarea
                    rows={3}
                    value={formData.dar}
                    onChange={(e) => setField('dar', e.target.value)}
                    placeholder="وصف تفصيلي للحقيبة، الجيوب الداخلية، والبطانة..."
                    className="w-full p-3 rounded-lg border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
                  />
                </Field>
                <Field label="الوصف بالإنجليزية" opt>
                  <textarea
                    rows={3}
                    dir="ltr"
                    value={formData.den}
                    onChange={(e) => setField('den', e.target.value)}
                    placeholder="Detailed description of craftsmanship..."
                    className="w-full p-3 rounded-lg border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
                  />
                </Field>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Field label="الخامة (عربي)" opt>
                  <input
                    type="text"
                    value={formData.mat_ar}
                    onChange={(e) => setField('mat_ar', e.target.value)}
                    placeholder="جلد طبيعي مدبوغ نباتياً"
                    className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
                  />
                </Field>
                <Field label="الخامة (إنجليزي)" opt>
                  <input
                    type="text"
                    dir="ltr"
                    value={formData.mat_en}
                    onChange={(e) => setField('mat_en', e.target.value)}
                    placeholder="Vegetable tanned genuine leather"
                    className="w-full h-10 px-3.5 rounded-lg border border-zinc-300 text-xs focus:border-brand-500 focus:outline-none"
                  />
                </Field>
              </div>
            </div>
          </div>

          {/* Side specs: Dimensions & Warranty */}
          <div className="space-y-6">
            <div className="bg-white border border-zinc-200 rounded-2xl p-6 shadow-sm space-y-4">
              <h3 className="text-sm font-bold text-zinc-900 border-b border-zinc-100 pb-3">
                الأبعاد وحسابات الشحن
              </h3>
              <div className="grid grid-cols-2 gap-3">
                <Field label="الطول (سم)">
                  <input
                    type="number"
                    value={formData.l}
                    onChange={(e) => setField('l', e.target.value)}
                    className="w-full h-9 px-3 rounded-lg border border-zinc-300 text-xs text-center font-mono"
                  />
                </Field>
                <Field label="العرض (سم)">
                  <input
                    type="number"
                    value={formData.w}
                    onChange={(e) => setField('w', e.target.value)}
                    className="w-full h-9 px-3 rounded-lg border border-zinc-300 text-xs text-center font-mono"
                  />
                </Field>
                <Field label="الارتفاع (سم)">
                  <input
                    type="number"
                    value={formData.h}
                    onChange={(e) => setField('h', e.target.value)}
                    className="w-full h-9 px-3 rounded-lg border border-zinc-300 text-xs text-center font-mono"
                  />
                </Field>
                <Field label="الوزن (كجم)">
                  <input
                    type="number"
                    step="0.1"
                    value={formData.kg}
                    onChange={(e) => setField('kg', e.target.value)}
                    className="w-full h-9 px-3 rounded-lg border border-zinc-300 text-xs text-center font-mono"
                  />
                </Field>
              </div>
            </div>

            <div className="bg-white border border-zinc-200 rounded-2xl p-6 shadow-sm space-y-4">
              <h3 className="text-sm font-bold text-zinc-900 border-b border-zinc-100 pb-3">
                الضمان والمنشأ
              </h3>
              <Field label="مدة الضمان (بالأشهر)" opt>
                <input
                  type="number"
                  value={formData.warranty}
                  onChange={(e) => setField('warranty', e.target.value)}
                  placeholder="12"
                  className="w-full h-9 px-3 rounded-lg border border-zinc-300 text-xs font-mono"
                />
              </Field>
              <Field label="بلد المنشأ" opt>
                <SearchSelect
                  value={formData.origin}
                  onChange={(v) => setField('origin', v)}
                  options={['مصر', 'إيطاليا', 'تركيا', 'الصين', 'الهند'].map((c) => ({
                    value: c,
                    label: c,
                  }))}
                />
              </Field>
            </div>
          </div>
        </div>
      )}

      {/* Tab 2: Variants & Pricing Matrix */}
      {tab === 'variants' && (
        <div className="space-y-6">
          {/* Matrix Generator Box */}
          <div className="bg-white border border-zinc-200 rounded-2xl p-6 shadow-sm space-y-5">
            <div className="flex items-center justify-between border-b border-zinc-100 pb-4">
              <div>
                <h3 className="text-sm font-bold text-zinc-900 flex items-center gap-2">
                  <Wand2 className="w-4 h-4 text-brand-600" />
                  <span>مولّد المتغيرات (Matrix Generator)</span>
                </h3>
                <p className="text-xs text-zinc-500 mt-0.5">
                  حدد محاور التركيبة (الألوان × المقاسات × السعة) لتوليد جدول المتغيرات تلقائياً
                </p>
              </div>
              <Button size="sm" className="gap-2" onClick={handleGenerateVariants}>
                <Wand2 className="w-3.5 h-3.5" />
                <span>توليد التركيبات</span>
              </Button>
            </div>

            {/* Colors axis */}
            <div className="space-y-2">
              <span className="text-xs font-bold text-zinc-800">الألوان المحددة:</span>
              <div className="flex items-center gap-2 flex-wrap">
                {axes.colors.map((c) => (
                  <span
                    key={c.en}
                    className="inline-flex items-center gap-2 px-3 py-1 bg-zinc-100 rounded-lg text-xs font-medium text-zinc-800"
                  >
                    <span
                      className="w-3 h-3 rounded-full border border-black/10 shadow-inner"
                      style={{ backgroundColor: c.hex }}
                    />
                    <span>{c.ar}</span>
                    <button
                      onClick={() =>
                        setAxes((prev) => ({
                          ...prev,
                          colors: prev.colors.filter((x) => x.en !== c.en),
                        }))
                      }
                      className="text-zinc-400 hover:text-red-500"
                    >
                      <X className="w-3.5 h-3.5" />
                    </button>
                  </span>
                ))}
              </div>
              <div className="flex items-center gap-1.5 flex-wrap pt-1 text-xs text-zinc-500">
                <span className="text-[11px] text-zinc-400">إضافة سريعة:</span>
                {COLOR_PRESETS.filter((p) => !axes.colors.some((c) => c.en === p.en)).map((p) => (
                  <button
                    key={p.en}
                    onClick={() => setAxes((prev) => ({ ...prev, colors: [...prev.colors, p] }))}
                    className="px-2 py-0.5 bg-zinc-50 hover:bg-zinc-100 border border-dashed border-zinc-300 rounded text-[11px] text-zinc-700 flex items-center gap-1"
                  >
                    <span className="w-2 h-2 rounded-full" style={{ backgroundColor: p.hex }} />
                    <span>+ {p.ar}</span>
                  </button>
                ))}
              </div>
            </div>

            {/* Sizes axis */}
            <div className="space-y-2 pt-2 border-t border-zinc-100">
              <span className="text-xs font-bold text-zinc-800">المقاسات:</span>
              <div className="flex items-center gap-2 flex-wrap">
                {axes.sizes.map((s) => (
                  <span
                    key={s}
                    className="inline-flex items-center gap-2 px-3 py-1 bg-zinc-100 rounded-lg text-xs font-medium text-zinc-800"
                  >
                    <span>{s}</span>
                    <button
                      onClick={() =>
                        setAxes((prev) => ({
                          ...prev,
                          sizes: prev.sizes.filter((x) => x !== s),
                        }))
                      }
                      className="text-zinc-400 hover:text-red-500"
                    >
                      <X className="w-3.5 h-3.5" />
                    </button>
                  </span>
                ))}
              </div>
              <div className="flex items-center gap-1.5 flex-wrap pt-1">
                {SIZE_PRESETS.filter((s) => !axes.sizes.includes(s)).map((s) => (
                  <button
                    key={s}
                    onClick={() => setAxes((prev) => ({ ...prev, sizes: [...prev.sizes, s] }))}
                    className="px-2.5 py-0.5 bg-zinc-50 hover:bg-zinc-100 border border-dashed border-zinc-300 rounded text-[11px] text-zinc-700"
                  >
                    + {s}
                  </button>
                ))}
              </div>
            </div>
          </div>

          {/* Bulk Action Bar */}
          <div className="bg-zinc-900 text-white rounded-2xl p-4 flex items-center justify-between flex-wrap gap-3">
            <div className="flex items-center gap-3 text-xs">
              <span className="font-bold text-brand-300">{selectedVariants.size} محدد</span>
              <span className="text-zinc-600">|</span>
              <span>تطبيق سريع:</span>
              <select
                value={bulkField}
                onChange={(e) => setBulkField(e.target.value as any)}
                className="bg-zinc-800 border border-zinc-700 text-white rounded-lg px-2.5 py-1 text-xs"
              >
                <option value="retail">سعر التجزئة</option>
                <option value="wholesale">سعر الجملة</option>
                <option value="cost">سعر التكلفة</option>
              </select>
              <input
                type="number"
                value={bulkPrice}
                onChange={(e) => setBulkPrice(e.target.value)}
                placeholder="2450.00"
                className="w-24 bg-zinc-800 border border-zinc-700 text-white rounded-lg px-2.5 py-1 text-xs font-mono text-center"
              />
              <Button size="sm" onClick={handleApplyBulkPrice}>
                تطبيق على المحدد
              </Button>
            </div>

            <div className="flex items-center gap-2 text-xs">
              <button
                onClick={() => handleFillDown('retail')}
                className="px-2.5 py-1 bg-zinc-800 hover:bg-zinc-700 rounded-lg text-zinc-300 transition-colors"
              >
                نسخ قطاعي الصف الأول للكل ↓
              </button>
              <button
                onClick={() => handleFillDown('wholesale')}
                className="px-2.5 py-1 bg-zinc-800 hover:bg-zinc-700 rounded-lg text-zinc-300 transition-colors"
              >
                نسخ جملة الصف الأول للكل ↓
              </button>
            </div>
          </div>

          {/* Variants Table */}
          <div className="bg-white border border-zinc-200 rounded-2xl shadow-sm overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-right text-xs">
                <thead className="bg-zinc-50 border-b border-zinc-200 font-bold text-zinc-700">
                  <tr>
                    <th className="p-3 w-10 text-center">
                      <input
                        type="checkbox"
                        checked={selectedVariants.size === variants.length && variants.length > 0}
                        onChange={(e) => {
                          if (e.target.checked) {
                            setSelectedVariants(new Set(variants.map((_, i) => i)));
                          } else {
                            setSelectedVariants(new Set());
                          }
                        }}
                      />
                    </th>
                    <th className="p-3">اللون</th>
                    <th className="p-3">المقاس</th>
                    <th className="p-3">SKU</th>
                    <th className="p-3">الباركود</th>
                    <th className="p-3">سعر التجزئة (ج.م) *</th>
                    <th className="p-3">سعر الجملة (ج.م) *</th>
                    <th className="p-3">التكلفة (ج.م)</th>
                    <th className="p-3 text-center">نشط</th>
                    <th className="p-3 text-center">إجراءات</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-zinc-200">
                  {variants.map((v, idx) => (
                    <tr
                      key={v.uid || idx}
                      className={`hover:bg-zinc-50/70 ${selectedVariants.has(idx) ? 'bg-brand-50/40' : ''}`}
                    >
                      <td className="p-3 text-center">
                        <input
                          type="checkbox"
                          checked={selectedVariants.has(idx)}
                          onChange={(e) => {
                            const next = new Set(selectedVariants);
                            if (e.target.checked) next.add(idx);
                            else next.delete(idx);
                            setSelectedVariants(next);
                          }}
                        />
                      </td>
                      <td className="p-3">
                        <div className="flex items-center gap-2">
                          <span
                            className="w-3 h-3 rounded-full border border-black/10 shrink-0"
                            style={{ backgroundColor: v.hex || '#ccc' }}
                          />
                          <span className="font-semibold text-zinc-900">{v.color_ar || '—'}</span>
                          <span className="text-zinc-400 text-[10px] ltr">{v.color_en}</span>
                        </div>
                      </td>
                      <td className="p-3 font-medium text-zinc-800">{v.size || 'قياسي'}</td>
                      <td className="p-3 font-mono text-[11px] text-zinc-600 ltr">{v.sku}</td>
                      <td className="p-3 font-mono text-[11px] text-zinc-600 ltr">{v.barcode}</td>
                      <td className="p-3">
                        <input
                          type="number"
                          value={v.retail}
                          onChange={(e) => {
                            const val = e.target.value;
                            setVariants((prev) =>
                              prev.map((item, i) => (i === idx ? { ...item, retail: val } : item))
                            );
                            setDirty(true);
                          }}
                          className="w-20 h-8 px-2 rounded border border-zinc-300 text-center font-mono font-bold text-zinc-900 focus:border-brand-500 focus:outline-none"
                        />
                      </td>
                      <td className="p-3">
                        <input
                          type="number"
                          value={v.wholesale}
                          onChange={(e) => {
                            const val = e.target.value;
                            setVariants((prev) =>
                              prev.map((item, i) => (i === idx ? { ...item, wholesale: val } : item))
                            );
                            setDirty(true);
                          }}
                          className="w-20 h-8 px-2 rounded border border-zinc-300 text-center font-mono text-zinc-800 focus:border-brand-500 focus:outline-none"
                        />
                      </td>
                      <td className="p-3">
                        <input
                          type="number"
                          value={v.cost}
                          onChange={(e) => {
                            const val = e.target.value;
                            setVariants((prev) =>
                              prev.map((item, i) => (i === idx ? { ...item, cost: val } : item))
                            );
                            setDirty(true);
                          }}
                          className="w-20 h-8 px-2 rounded border border-zinc-200 text-center font-mono text-zinc-500 focus:border-brand-500 focus:outline-none"
                        />
                      </td>
                      <td className="p-3 text-center">
                        <input
                          type="checkbox"
                          checked={v.active}
                          onChange={(e) => {
                            const checked = e.target.checked;
                            setVariants((prev) =>
                              prev.map((item, i) => (i === idx ? { ...item, active: checked } : item))
                            );
                            setDirty(true);
                          }}
                          className="rounded text-brand-600"
                        />
                      </td>
                      <td className="p-3 text-center">
                        <button
                          onClick={() => setVariants((prev) => prev.filter((_, i) => i !== idx))}
                          className="text-zinc-400 hover:text-red-500 p-1 transition-colors"
                        >
                          <Trash2 className="w-3.5 h-3.5" />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* Tab 3: Media */}
      {tab === 'media' && (
        <div className="space-y-6">
          <div className="bg-white border border-zinc-200 rounded-2xl p-6 shadow-sm space-y-4">
            <div className="flex items-center justify-between border-b border-zinc-100 pb-3">
              <div>
                <h3 className="text-sm font-bold text-zinc-900">معرض صور المنتج</h3>
                <p className="text-xs text-zinc-500 mt-0.5">
                  اسحب الصور لإعادة الترتيب. الصورة الأولى دائماً هي صورة الغلاف في المتجر.
                </p>
              </div>
              <Button
                size="sm"
                variant="outline"
                className="gap-2"
                onClick={() => {
                  setMediaList((prev) => [
                    ...prev,
                    {
                      id: String(Date.now()),
                      name: `product-image-${prev.length + 1}.jpg`,
                      size: '2.4 MB',
                    },
                  ]);
                  setDirty(true);
                }}
              >
                <Plus className="w-4 h-4" />
                <span>إضافة صورة تجريبية</span>
              </Button>
            </div>

            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
              {mediaList.map((m, idx) => (
                <div
                  key={m.id}
                  className="group relative aspect-square rounded-2xl border-2 border-dashed border-zinc-300 bg-zinc-50 overflow-hidden flex flex-col items-center justify-center p-3 text-center shadow-sm"
                >
                  <UploadCloud className="w-8 h-8 text-zinc-400 mb-2 group-hover:scale-110 transition-transform" />
                  <span className="text-xs font-semibold text-zinc-800 line-clamp-1">{m.name}</span>
                  <span className="text-[10px] text-zinc-400 mt-0.5">{m.size}</span>

                  {idx === 0 && (
                    <span className="absolute top-2 right-2 bg-zinc-900 text-white text-[10px] font-bold px-2 py-0.5 rounded-full">
                      الغلاف
                    </span>
                  )}

                  {m.color && (
                    <span className="absolute top-2 left-2 bg-white/90 border border-zinc-200 text-zinc-700 text-[10px] font-medium px-1.5 py-0.5 rounded">
                      {m.color}
                    </span>
                  )}

                  <button
                    onClick={() => setMediaList((prev) => prev.filter((x) => x.id !== m.id))}
                    className="absolute bottom-2 left-2 p-1.5 rounded-lg bg-white/90 shadow text-zinc-400 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-opacity"
                  >
                    <Trash2 className="w-3.5 h-3.5" />
                  </button>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}

      {/* Tab 4: Review & Readiness */}
      {tab === 'review' && (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div className="md:col-span-2 space-y-6">
            <div className="bg-white border border-zinc-200 rounded-2xl p-6 shadow-sm space-y-4">
              <div className="flex items-center justify-between border-b border-zinc-100 pb-3">
                <div>
                  <h3 className="text-sm font-bold text-zinc-900">قائمة الجاهزية للنشر (Checklist)</h3>
                  <p className="text-xs text-zinc-500 mt-0.5">
                    يتم التحقق من هذه المتطلبات للتأكد من ظهور المنتج بشكل سليم
                  </p>
                </div>
                <Badge variant={isReadyToPublish ? 'success' : 'warning'}>
                  {isReadyToPublish ? 'جاهز للنشر' : 'متطلبات ناقصة'}
                </Badge>
              </div>

              <div className="space-y-3">
                {checks.map((chk, i) => (
                  <div
                    key={i}
                    className={`p-3.5 rounded-xl border flex items-center justify-between gap-3 ${
                      chk.ok ? 'bg-emerald-50/50 border-emerald-200' : 'bg-amber-50/50 border-amber-200'
                    }`}
                  >
                    <div className="flex items-center gap-3">
                      {chk.ok ? (
                        <CheckCircle2 className="w-5 h-5 text-emerald-600" />
                      ) : (
                        <AlertCircle className="w-5 h-5 text-amber-600" />
                      )}
                      <div>
                        <div className="text-xs font-bold text-zinc-900">{chk.title}</div>
                        <div className="text-[11px] text-zinc-500 mt-0.5">{chk.desc}</div>
                      </div>
                    </div>

                    {!chk.ok && (
                      <button
                        onClick={() => setTab(chk.tab)}
                        className="text-xs text-brand-600 hover:underline font-bold"
                      >
                        إصلاح ←
                      </button>
                    )}
                  </div>
                ))}
              </div>
            </div>
          </div>

          {/* Storefront Card Preview */}
          <div>
            <div className="bg-white border border-zinc-200 rounded-2xl p-5 shadow-sm space-y-4">
              <div className="text-xs font-bold text-zinc-500 uppercase">معاينة بطاقة المتجر</div>
              <div className="rounded-xl border border-zinc-200 overflow-hidden bg-white shadow-sm p-3 space-y-3 text-center">
                <div className="aspect-square bg-zinc-100 rounded-lg flex items-center justify-center text-zinc-400">
                  <UploadCloud className="w-10 h-10 stroke-1" />
                </div>
                <div>
                  <h4 className="font-bold text-zinc-900 text-sm">{formData.ar || 'اسم المنتج بالعربية'}</h4>
                  <span className="text-[11px] text-zinc-400 font-medium">{formData.en || 'Product English Name'}</span>
                </div>
                <div className="text-base font-extrabold text-brand-600">
                  {variants.length > 0 ? formatCurrency(Number(variants[0].retail)) : '0.00 ج.م'}
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Sticky Bottom Action Bar */}
      <div className="fixed bottom-0 left-0 right-0 md:mr-64 bg-white/95 backdrop-blur border-t border-zinc-200 px-8 py-3.5 shadow-lg flex items-center justify-between z-40" dir="rtl">
        <div className="flex items-center gap-3">
          <Button
            variant="outline"
            size="sm"
            onClick={() => navigate('/admin/products')}
          >
            إلغاء ومغادرة
          </Button>
          {dirty ? (
            <span className="text-xs text-amber-600 font-medium">● تغييرات غير محفوظة</span>
          ) : (
            <span className="text-xs text-emerald-600 font-medium flex items-center gap-1">
              <Check className="w-3.5 h-3.5" />
              <span>محفوظ</span>
            </span>
          )}
        </div>

        <div className="flex items-center gap-2.5">
          <Button
            variant="outline"
            size="sm"
            className="gap-2"
            onClick={() => triggerPipeline('draft')}
          >
            <Save className="w-4 h-4" />
            <span>حفظ كمسودة</span>
          </Button>
          <Button
            size="sm"
            className="gap-2 bg-emerald-600 hover:bg-emerald-700"
            disabled={!isReadyToPublish}
            onClick={() => triggerPipeline('publish')}
          >
            <Send className="w-4 h-4" />
            <span>نشر المنتج الآن</span>
          </Button>
        </div>
      </div>

      {/* Pipeline execution modal */}
      {pipelineState.open && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" dir="rtl">
          <div className="fixed inset-0 bg-black/50 backdrop-blur-sm" />
          <div className="relative w-full max-w-sm bg-white rounded-2xl shadow-2xl p-6 z-10 space-y-4 text-center">
            <h4 className="text-base font-bold text-zinc-900">
              {pipelineState.done
                ? pipelineState.intent === 'publish'
                  ? 'تم نشر المنتج بنجاح!'
                  : 'تم حفظ المسودة بنجاح!'
                : 'جاري تنفيذ خطوات الحفظ...'}
            </h4>

            <div className="space-y-2.5 text-xs text-right pt-2">
              <div className="flex items-center gap-3">
                {pipelineState.step >= 1 ? (
                  <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                ) : (
                  <div className="w-4 h-4 rounded-full border border-zinc-300" />
                )}
                <span>1. حفظ البيانات والمتغيرات ({variants.length} صنف)</span>
              </div>
              <div className="flex items-center gap-3">
                {pipelineState.step >= 2 ? (
                  <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                ) : (
                  <div className="w-4 h-4 rounded-full border border-zinc-300" />
                )}
                <span>2. معالجة وتوليد أكواد SKU والباركود</span>
              </div>
              <div className="flex items-center gap-3">
                {pipelineState.step >= 3 ? (
                  <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                ) : (
                  <div className="w-4 h-4 rounded-full border border-zinc-300" />
                )}
                <span>3. ربط الصور والوسائط وتحديث المتجر</span>
              </div>
            </div>

            {pipelineState.done && (
              <div className="pt-4 flex items-center justify-center gap-2">
                <Button size="sm" onClick={() => navigate('/admin/products')}>
                  العودة لقائمة المنتجات
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setPipelineState((p) => ({ ...p, open: false }))}
                >
                  متابعة التعديل
                </Button>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
