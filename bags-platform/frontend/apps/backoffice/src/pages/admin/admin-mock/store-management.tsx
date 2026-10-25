import React, { useState, useEffect, useRef } from 'react';
import { Icon, Toggle, toast } from './ui';
import { adminApi } from './api/endpoints';
import { fromApi, toFormData } from './banner-mappers';
import { normalizeError } from '../catalog/errors';

// ============ Store management · Banners & Slider ============

export interface BannerItem {
  id: string;
  title: string;
  placement: 'slider' | 'middle' | 'popup';
  image?: string | null;
  linkType: 'none' | 'product' | 'category' | 'brand' | 'external';
  linkId: string | null;
  url: string;
  sort: number;
  start: string;
  end: string;
  active: boolean;
  linkLabel?: string | null;
  status?: 'live' | 'scheduled' | 'expired' | 'disabled';
  kicker?: string;
  headline?: string;
  headlineBold?: string;
  description?: string;
  ctaLabel?: string;
  bgColor?: string;
  imageFile?: File | null;
}

export const BN_PLACEMENTS: Record<string, { label: string; icon: string; ratio: string; hint: string }> = {
  slider: { label: 'السلايدر الرئيسي', icon: 'slides', ratio: '16 / 6', hint: 'صور تتقلّب في أعلى واجهة المتجر — كل صورة بانر مستقل' },
  middle: { label: 'بانرات وسط الصفحة', icon: 'mid', ratio: '16 / 5', hint: 'صور إعلانية بين أقسام الصفحة الرئيسية' },
  popup: { label: 'النوافذ المنبثقة', icon: 'popup', ratio: '4 / 5', hint: 'تظهر للزائر فوق محتوى المتجر' },
};

export const BN_LINK_TYPES: Record<string, { label: string; icon: string }> = {
  none: { label: 'بدون رابط', icon: 'slash' },
  product: { label: 'منتج', icon: 'box' },
  category: { label: 'قسم', icon: 'grid' },
  brand: { label: 'براند', icon: 'tag' },
  external: { label: 'رابط خارجي', icon: 'external' },
};

export function bnStatus(b: BannerItem): 'disabled' | 'scheduled' | 'expired' | 'live' {
  if (b.status) return b.status;
  const now = new Date();
  if (!b.active) return 'disabled';
  if (b.start && new Date(b.start) > now) return 'scheduled';
  if (b.end && new Date(b.end) < now) return 'expired';
  return 'live';
}

export const BN_STATUS: Record<string, { label: string; cls: string }> = {
  live: { label: 'ساري', cls: 'b-green' },
  scheduled: { label: 'مجدول', cls: 'b-blue' },
  expired: { label: 'منتهي', cls: 'b-amber' },
  disabled: { label: 'معطّل', cls: 'b-gray' },
};

export const bnDate = (s?: string) => {
  if (!s) return null;
  const d = new Date(s);
  return {
    d: d.toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' }),
    t: d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }),
  };
};

export function bnTarget(b: BannerItem): string | null | undefined {
  if (b.linkLabel) return b.linkLabel;
  switch (b.linkType) {
    case 'external': return b.url;
    default: return null;
  }
}

// ---------- Thumbnail / Art ----------
export function BannerArt({ b, ratio, big }: { b: Partial<BannerItem>; ratio?: string; big?: boolean }) {
  const [failedImage, setFailedImage] = useState<string | null>(null);
  if (b.image && failedImage !== b.image) {
    return <img src={b.image} alt={b.title || ''} onError={() => setFailedImage(b.image ?? null)} className="w-full h-full object-cover" />;
  }
  return (
    <div
      className="w-full h-full relative overflow-hidden flex flex-col justify-center select-none"
      style={{
        background: b.bgColor || 'var(--bg-subcard)',
        color: 'var(--text-primary)',
        padding: big ? '6% 7%' : '0 9%',
        aspectRatio: ratio,
      }}
    >
      <div className="relative font-display font-bold leading-tight" style={{ fontSize: big ? 26 : 12 }}>{b.title || 'معاينة البانر'}</div>
    </div>
  );
}

// ---------- Store Preview Device Mockup ----------
export function StorePreview({ tab, live }: { tab: 'slider' | 'middle' | 'popup'; live: BannerItem[] }) {
  const [i, setI] = useState(0);

  useEffect(() => { setI(0); }, [tab, live.length]);

  useEffect(() => {
    if (tab !== 'slider' || live.length < 2) return;
    const t = setInterval(() => setI(x => (x + 1) % live.length), 2600);
    return () => clearInterval(t);
  }, [tab, live.length]);

  const cur = live[i];
  const frame = (children: React.ReactNode) => (
    <div className="v-subcard p-2.5" style={{ borderRadius: 18 }}>
      <div className="rounded-[12px] overflow-hidden" style={{ background: 'var(--bg-card)', boxShadow: '0 0 0 1px var(--border-subtle)' }}>
        <div className="flex items-center gap-1.5 px-3 py-2 v-border-b">
          <span className="w-2 h-2 rounded-full" style={{ background: 'var(--badge-gray-bg)' }} />
          <span className="w-2 h-2 rounded-full" style={{ background: 'var(--badge-gray-bg)' }} />
          <span className="w-2 h-2 rounded-full" style={{ background: 'var(--badge-gray-bg)' }} />
          <span className="flex-1 mx-2 h-3.5 rounded-full" style={{ background: 'var(--bg-subcard)' }} />
        </div>
        {children}
      </div>
    </div>
  );

  const none = (
    <div className="rounded-lg flex items-center justify-center text-[11px] c-muted hatched" style={{ aspectRatio: '16 / 6' }}>
      لا يوجد بانر ساري
    </div>
  );

  const blocks = (n: number) => (
    <div className="grid grid-cols-4 gap-1.5 p-2.5">
      {Array.from({ length: n }).map((_, k) => (
        <div key={k} className="aspect-square rounded-md" style={{ background: 'var(--bg-subcard)' }} />
      ))}
    </div>
  );

  if (tab === 'slider') {
    return frame(
      <>
        <div className="p-2.5 pb-0">
          {cur ? (
            <div className="rounded-lg overflow-hidden relative" style={{ aspectRatio: '16 / 6' }}>
              <div key={cur.id} className="absolute inset-0 fade-in">
                <BannerArt b={cur} />
              </div>
            </div>
          ) : none}
          <div className="flex items-center justify-center gap-1 py-2">
            {live.map((b, k) => (
              <button
                key={b.id}
                type="button"
                onClick={() => setI(k)}
                className="h-1.5 rounded-full transition-all"
                style={{ width: k === i ? 16 : 6, background: k === i ? 'var(--accent-orange)' : 'var(--badge-gray-bg)' }}
              />
            ))}
          </div>
        </div>
        {blocks(4)}
      </>
    );
  }

  if (tab === 'middle') {
    return frame(
      <>
        {blocks(4)}
        <div className="px-2.5 space-y-1.5">
          {live.length ? live.map(b => (
            <div key={b.id} className="rounded-lg overflow-hidden" style={{ aspectRatio: '16 / 5' }}>
              <BannerArt b={b} />
            </div>
          )) : none}
        </div>
        {blocks(4)}
      </>
    );
  }

  return frame(
    <div className="relative">
      {blocks(8)}
      <div className="absolute inset-0 flex items-center justify-center" style={{ background: 'rgba(0,0,0,.45)' }}>
        {live[0] ? (
          <div className="w-[48%] rounded-lg overflow-hidden relative shadow-2xl" style={{ aspectRatio: '4 / 5' }}>
            <BannerArt b={live[0]} />
            <span className="absolute top-1.5 left-1.5 w-4 h-4 rounded-full bg-white/90 text-black flex items-center justify-center text-[10px]">✕</span>
          </div>
        ) : (
          <span className="text-[11px] text-white/80">لا توجد نافذة سارية</span>
        )}
      </div>
    </div>
  );
}

// ---------- Loading Skeleton ----------
function BannerSkeleton() {
  return (
    <div className="space-y-2.5">
      {[0, 1, 2, 3].map(i => (
        <div key={i} className="v-subcard p-3 flex items-center gap-4" style={{ opacity: 1 - i * 0.15 }}>
          <div className="sk w-7 h-7 !rounded-full" />
          <div className="sk w-[168px] h-[63px] !rounded-xl" />
          <div className="flex-1 space-y-2">
            <div className="sk h-3.5 w-1/2" />
            <div className="sk h-3 w-1/3" />
            <div className="sk h-3 w-2/5" />
          </div>
          <div className="sk w-9 h-5 !rounded-full" />
        </div>
      ))}
    </div>
  );
}

// ---------- Banner Form Modal ----------
interface BannerFormProps {
  open: boolean;
  initial: BannerItem | null;
  defaultPlacement: 'slider' | 'middle' | 'popup';
  onClose: () => void;
  onSave: (item: BannerItem, onProgress: (p: number) => void) => Promise<Record<string, string> | null>;
}

function BannerForm({ open, initial, defaultPlacement, onClose, onSave }: BannerFormProps) {
  const blank: Omit<BannerItem, 'id'> = {
    title: '',
    image: null,
    placement: defaultPlacement,
    linkType: 'none',
    linkId: null,
    url: '',
    sort: 1,
    start: '',
    end: '',
    active: true,
  };

  const [f, setF] = useState<any>(blank);
  const [sortStr, setSortStr] = useState('1');
  const [err, setErr] = useState<Record<string, string | null>>({});
  const [upl, setUpl] = useState({ state: 'idle', pct: 0, name: '', msg: '' });
  const [busy, setBusy] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);
  const objectUrlRef = useRef<string | null>(null);
  const [catOpts, setCatOpts] = useState<Array<{ value: string; label: string }>>([]);
  const [prodOpts, setProdOpts] = useState<Array<{ value: string; label: string }>>([]);
  const [prodQuery, setProdQuery] = useState('');

  const revokePreview = () => {
    if (objectUrlRef.current) { URL.revokeObjectURL(objectUrlRef.current); objectUrlRef.current = null; }
  };
  const closeForm = () => { revokePreview(); onClose(); };

  useEffect(() => () => revokePreview(), []);
  useEffect(() => { if (!open) revokePreview(); }, [open]);

  useEffect(() => {
    if (!open) return;
    let alive = true;
    adminApi.listBannerCategories().then((cats) => {
      if (!alive) return;
      const flat: Array<{ value: string; label: string }> = [];
      const walk = (list: Array<{ id: number; name_ar: string; name_en: string | null; children?: unknown[] }>, depth: number) => {
        list.forEach((c) => {
          flat.push({ value: String(c.id), label: `${depth ? '— ' : ''}${c.name_en || c.name_ar}` });
          if (Array.isArray(c.children)) walk(c.children as typeof list, depth + 1);
        });
      };
      walk(cats as never, 0);
      setCatOpts(flat);
    }).catch(() => {});
    return () => { alive = false; };
  }, [open ]);

  useEffect(() => {
    if (!open || f.linkType !== 'product') return;
    const controller = new AbortController();
    const t = setTimeout(() => {
      adminApi.searchBannerProducts(prodQuery, controller.signal).then((ps) => {
        if (controller.signal.aborted) return;
        const opts = ps.map((p) => ({ value: String(p.id), label: p.name_en || p.name_ar }));
        if (f.linkId && f.linkLabel && !opts.some((o) => o.value === f.linkId)) {
          opts.unshift({ value: f.linkId, label: f.linkLabel });
        }
        setProdOpts(opts);
      }).catch(() => {});
    }, 300);
    return () => { clearTimeout(t); controller.abort(); };
  }, [open, f.linkType, f.linkId, f.linkLabel, prodQuery]);

  useEffect(() => {
    if (!open) return;
    if (initial) {
      setF({ ...initial, status: undefined });
      setSortStr(String(initial.sort));
    } else {
      setF({ ...blank, placement: defaultPlacement });
      setSortStr('1');
    }
    setErr({});
    setUpl({ state: 'idle', pct: 0, name: '', msg: '' });
    setBusy(false);
    setProdQuery('');
  }, [open, initial, defaultPlacement]);

  const set = (k: string, v: any) => {
    setF((p: any) => ({ ...p, [k]: v, status: undefined }));
    if (err[k]) setErr(e => ({ ...e, [k]: null }));
  };

  const startUpload = (file?: File) => {
    if (!file) return;
    if (!/^image\/(png|jpe?g|webp)$/.test(file.type)) {
      setUpl({ state: 'error', pct: 0, name: file.name, msg: 'صيغة غير مدعومة. استخدم PNG أو JPG أو WEBP.' });
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      setUpl({ state: 'error', pct: 0, name: file.name, msg: 'حجم الصورة أكبر من 5 ميجابايت.' });
      return;
    }
    revokePreview();
    const url = URL.createObjectURL(file);
    objectUrlRef.current = url;
    setUpl({ state: 'done', pct: 100, name: file.name, msg: '' });
    setF((p: any) => ({ ...p, image: url, imageFile: file }));
  };

  const hasImage = Boolean(f.image);

  const validate = () => {
    const e: Record<string, string> = {};
    if (!f.title?.trim()) e.title = 'العنوان مطلوب';
    if (!hasImage) e.image = 'ارفع صورة للبانر';
    if (f.linkType === 'product' && !f.linkId) e.linkId = 'اختر المنتج';
    if (f.linkType === 'category' && !f.linkId) e.linkId = 'اختر القسم';
    if (f.linkType === 'brand' && !f.linkId) e.linkId = 'اختر البراند';
    if (f.linkType === 'external') {
      if (!f.url?.trim()) e.url = 'أدخل الرابط';
      else if (!/^https:\/\/\S+\.\S+/.test(f.url.trim())) e.url = 'الرابط يجب أن يبدأ بـ https:// ويكون صالحًا';
    }
    if (sortStr === '') e.sort = 'أدخل ترتيب الظهور';
    else if (!Number.isInteger(Number(sortStr)) || Number(sortStr) < 0 || Number(sortStr) > 9999) e.sort = 'الترتيب يجب أن يكون رقمًا من 0 إلى 9999';
    if (f.start && f.end && new Date(f.end) <= new Date(f.start)) e.end = 'نهاية العرض يجب أن تكون بعد البداية';
    return e;
  };

  const submit = async () => {
    if (busy || upl.state === 'uploading') return;
    const e = validate();
    setErr(e);
    if (Object.keys(e).length) return;
    setBusy(true);
    setUpl(u => ({ ...u, state: 'uploading', pct: 0 }));
    const apiErrs = await onSave({
      ...f,
      sort: Number(sortStr),
      linkId: ['none', 'external'].includes(f.linkType) ? null : f.linkId,
      url: f.linkType === 'external' ? (f.url || '').trim() : '',
    }, (p) => setUpl(u => ({ ...u, state: 'uploading', pct: p })));
    setBusy(false);
    if (apiErrs) {
      setUpl(u => ({ ...u, state: 'idle', pct: 0 }));
      setErr(apiErrs);
    }
  };

  const P = BN_PLACEMENTS[f.placement] || BN_PLACEMENTS.slider;

  const targetField = () => {
    const opts = f.linkType === 'product'
      ? prodOpts
      : f.linkType === 'category'
      ? catOpts
      : null;

    if (f.linkType === 'none') return null;
    if (f.linkType === 'brand') {
      return (
        <div className="space-y-1.5">
          <label className="text-[12.5px] font-semibold" style={{ color: 'var(--text-primary)' }}>البراند</label>
          <div className="inp flex items-center justify-between" style={{ background: 'var(--bg-subcard)' }}>
            <span>{f.linkLabel || f.linkId || '—'}</span>
            <span className="badge b-gray">قديم</span>
          </div>
        </div>
      );
    }
    if (f.linkType === 'external') {
      return (
        <div className="space-y-1.5">
          <label className="text-[12.5px] font-semibold flex items-center justify-between" style={{ color: 'var(--text-primary)' }}>
            <span>الرابط الخارجي <span className="c-orange">*</span></span>
          </label>
          <div className="relative">
            <input
              className={`inp mono text-left pl-9 ${err.url ? 'err' : ''}`}
              dir="ltr"
              value={f.url || ''}
              onChange={e => set('url', e.target.value)}
              placeholder="https://"
            />
            <span className="absolute left-3 top-1/2 -translate-y-1/2 c-muted">
              <Icon name="external" className="w-3.5 h-3.5" />
            </span>
          </div>
          {err.url && <div className="text-[11.5px] font-medium" style={{ color: 'var(--badge-red-text)' }}>{err.url}</div>}
        </div>
      );
    }
    return (
      <div className="space-y-1.5">
        <label className="text-[12.5px] font-semibold" style={{ color: 'var(--text-primary)' }}>
          اختر ال{BN_LINK_TYPES[f.linkType]?.label} <span className="c-orange">*</span>
        </label>
        {f.linkType === 'product' && (
          <input
            className="inp"
            value={prodQuery}
            onChange={e => setProdQuery(e.target.value)}
            placeholder="بحث عن منتج…"
          />
        )}
        <select
          value={f.linkId || ''}
          onChange={e => set('linkId', e.target.value || null)}
          className={`inp ${err.linkId ? 'err' : ''}`}
        >
          <option value="">اختر...</option>
          {opts?.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>
        {err.linkId && <div className="text-[11.5px] font-medium" style={{ color: 'var(--badge-red-text)' }}>{err.linkId}</div>}
      </div>
    );
  };

  if (!open) return null;

  return (
    <div
      className="fixed inset-0 z-[60] flex items-center justify-center p-4 fade-in"
      style={{ background: 'rgba(0,0,0,.45)', backdropFilter: 'blur(4px)' }}
      onMouseDown={e => e.target === e.currentTarget && !busy && closeForm()}
    >
      <div
        className="v-card w-full shadow-2xl flex flex-col max-h-[92vh] v-pop-in"
        style={{ maxWidth: 760 }}
      >
        <div className="flex items-start justify-between gap-4 p-6 pb-4 v-border-b">
          <div>
            <h3 className="font-display text-[19px] font-semibold c-primary">
              {initial ? 'تعديل بانر' : 'إضافة بانر'}
            </h3>
            <p className="text-[12px] c-secondary mt-0.5">
              {initial ? initial.title : `سيُضاف إلى «${P.label}»`}
            </p>
          </div>
          <button
            type="button"
            onClick={closeForm}
            disabled={busy}
            className="w-8 h-8 rounded-full v-subcard flex items-center justify-center c-secondary hover:c-primary shrink-0 transition"
          >
            <Icon name="close" className="w-4 h-4" />
          </button>
        </div>

        <div className="px-6 py-5 overflow-y-auto flex-1 space-y-5">
          {/* Title */}
          <div>
            <label className="block text-[12.5px] font-semibold mb-1.5" style={{ color: 'var(--text-primary)' }}>
              العنوان <span className="c-orange">*</span>
              <span className="text-[11px] font-normal c-muted mr-2">· للاستخدام الداخلي ولوصف الصورة</span>
            </label>
            <input
              className={`inp ${err.title ? 'err' : ''}`}
              value={f.title || ''}
              onChange={e => set('title', e.target.value)}
              placeholder="مثال: مجموعة الشتاء — خصم ٤٠٪"
            />
            {err.title && <div className="text-[11.5px] mt-1 font-medium" style={{ color: 'var(--badge-red-text)' }}>{err.title}</div>}
          </div>

          {/* Image Upload */}
          <div>
            <div className="text-[12.5px] font-semibold mb-1.5" style={{ color: 'var(--text-primary)' }}>
              الصورة <span className="c-orange">*</span>
              <span className="text-[11px] font-normal c-muted mr-2">· PNG / JPG / WEBP حتى 5MB</span>
            </div>
            <input
              ref={fileRef}
              type="file"
              accept="image/png,image/jpeg,image/webp"
              className="hidden"
              onChange={e => {
                if (e.target.files?.[0]) startUpload(e.target.files[0]);
                e.target.value = '';
              }}
            />

            {upl.state === 'uploading' ? (
              <div className="v-subcard p-5 flex items-center gap-4" style={{ minHeight: 140 }}>
                <div className="flex-1">
                  <div className="flex items-center justify-between text-[12.5px] mb-2">
                    <span className="c-primary font-medium truncate">{upl.name}</span>
                    <span className="num c-secondary">{Math.round(upl.pct)}%</span>
                  </div>
                  <div className="h-2 rounded-full hatched overflow-hidden">
                    <div className="h-full rounded-full bar-hatched-orange transition-all" style={{ width: `${upl.pct}%` }} />
                  </div>
                  <div className="text-[11px] c-muted mt-2">جارٍ رفع الصورة…</div>
                </div>
              </div>
            ) : hasImage ? (
              <div
                className="relative rounded-2xl overflow-hidden group"
                style={{ aspectRatio: P.ratio === '4 / 5' ? '16 / 7' : P.ratio, boxShadow: '0 0 0 1px var(--border-subtle)' }}
              >
                {f.placement === 'popup' ? (
                  <div className="w-full h-full flex items-center justify-center hatched">
                    <div className="h-[88%] rounded-lg overflow-hidden" style={{ aspectRatio: '4 / 5' }}>
                      <BannerArt b={f} big />
                    </div>
                  </div>
                ) : (
                  <BannerArt b={f} big />
                )}
                <div className="absolute bottom-3 left-3 flex items-center gap-2">
                  <button
                    type="button"
                    className="btn btn-sm shadow-md"
                    style={{ background: 'rgba(255,255,255,.95)', color: '#18151c' }}
                    onClick={() => fileRef.current?.click()}
                  >
                    <Icon name="refresh" className="w-3.5 h-3.5" /> استبدال الصورة
                  </button>
                </div>
                {upl.state === 'done' && (
                  <span className="absolute top-3 right-3 badge b-green">
                    <Icon name="check" className="w-3 h-3" /> تم الرفع
                  </span>
                )}
              </div>
            ) : (
              <button
                type="button"
                onClick={() => fileRef.current?.click()}
                onDragOver={e => e.preventDefault()}
                onDrop={e => { e.preventDefault(); if (e.dataTransfer.files?.[0]) startUpload(e.dataTransfer.files[0]); }}
                className="w-full rounded-2xl flex flex-col items-center justify-center gap-2 transition hover:opacity-90 py-8"
                style={{
                  border: `1.5px dashed ${err.image ? 'var(--badge-red-text)' : 'var(--border-strong)'}`,
                  background: 'var(--bg-subcard)',
                }}
              >
                <span className="w-11 h-11 rounded-full flex items-center justify-center" style={{ background: 'var(--bg-card)', color: 'var(--accent-orange)' }}>
                  <Icon name="upload" className="w-5 h-5" />
                </span>
                <span className="text-[13px] font-semibold c-primary">اسحب الصورة هنا أو اضغط للاختيار</span>
                <span className="text-[11px] c-muted">
                  المقاس المقترح لـ{P.label}: {f.placement === 'slider' ? '1920×720، وخلّي الـ 40% الشمال من الصورة هادية لو هتكتب نص' : f.placement === 'middle' ? '1920×600، وخلّي المحتوى المهم في النص' : '800×1000'}
                </span>
              </button>
            )}

            {upl.state === 'error' && (
              <div className="mt-2 text-[11.5px] font-medium" style={{ color: 'var(--badge-red-text)' }}>
                {upl.msg}
              </div>
            )}
            {err.image && <div className="text-[11.5px] mt-1 font-medium" style={{ color: 'var(--badge-red-text)' }}>{err.image}</div>}
          </div>

          {/* Storefront display text */}
          <div className="v-subcard p-4 space-y-3.5">
            <div className="text-[12.5px] font-semibold" style={{ color: 'var(--text-primary)' }}>نصوص العرض في المتجر (اختياري)</div>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
              <div className="space-y-1.5">
                <label className="text-[12px] font-semibold c-secondary">Kicker</label>
                <input className="inp" value={f.kicker || ''} onChange={e => set('kicker', e.target.value)} placeholder="NEW COLLECTION" />
              </div>
              <div className="space-y-1.5">
                <label className="text-[12px] font-semibold c-secondary">العنوان الخفيف</label>
                <input className="inp" value={f.headline || ''} onChange={e => set('headline', e.target.value)} />
              </div>
              <div className="space-y-1.5">
                <label className="text-[12px] font-semibold c-secondary">العنوان العريض</label>
                <input className="inp" value={f.headlineBold || ''} onChange={e => set('headlineBold', e.target.value)} />
              </div>
              <div className="space-y-1.5">
                <label className="text-[12px] font-semibold c-secondary">نص الزرار</label>
                <input className="inp" value={f.ctaLabel || ''} onChange={e => set('ctaLabel', e.target.value)} placeholder="SHOP NOW" />
              </div>
            </div>
            <div className="space-y-1.5">
              <label className="text-[12px] font-semibold c-secondary">الوصف</label>
              <textarea className="inp" rows={2} value={f.description || ''} onChange={e => set('description', e.target.value)} />
            </div>
            {f.placement === 'middle' && (
              <div className="space-y-1.5">
                <label className="text-[12px] font-semibold c-secondary">لون الخلفية</label>
                <div className="flex items-center gap-2">
                  {['', '#dfe6ec', '#ede3de', '#e7e9e1', '#efe6dc'].map((c) => (
                    <button
                      key={c || 'auto'}
                      type="button"
                      title={c || 'تلقائي'}
                      onClick={() => set('bgColor', c)}
                      className="w-8 h-8 rounded-full flex items-center justify-center text-[10px]"
                      style={{ background: c || 'var(--bg-card)', boxShadow: `0 0 0 2px ${(f.bgColor || '') === c ? 'var(--accent-orange)' : 'var(--border-subtle)'}` }}
                    >
                      {c ? '' : 'تلقائي'}
                    </button>
                  ))}
                </div>
              </div>
            )}
            <div className="text-[11.5px] c-muted">لو سبتها فاضية، البانر هيظهر كصورة كاملة قابلة للضغط.</div>
          </div>

          {/* Placement */}
          <div>
            <div className="text-[12.5px] font-semibold mb-1.5" style={{ color: 'var(--text-primary)' }}>
              مكان العرض <span className="c-orange">*</span>
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
              {Object.entries(BN_PLACEMENTS).map(([k, p]) => (
                <button
                  key={k}
                  type="button"
                  onClick={() => set('placement', k)}
                  className={`rounded-2xl p-3 flex items-center gap-2.5 text-right border transition ${
                    f.placement === k ? 'border-[var(--accent-orange)]' : 'border-transparent'
                  }`}
                  style={{ background: f.placement === k ? 'var(--accent-orange-light)' : 'var(--bg-subcard)' }}
                >
                  <span className={`w-8 h-8 rounded-full flex items-center justify-center shrink-0 ${
                    f.placement === k ? 'bg-[var(--accent-orange)] text-white' : 'v-icon-well'
                  }`}>
                    <Icon name={p.icon} className="w-4 h-4" />
                  </span>
                  <span className="text-[12.5px] font-semibold c-primary">{p.label}</span>
                </button>
              ))}
            </div>
          </div>

          {/* Link Type */}
          <div className="v-subcard p-4 space-y-3.5">
            <div>
              <div className="text-[12.5px] font-semibold mb-2" style={{ color: 'var(--text-primary)' }}>
                نوع الرابط
              </div>
              <div className="seg flex-wrap !bg-[var(--bg-card)]">
                {Object.entries(BN_LINK_TYPES).filter(([k]) => k !== 'brand' || f.linkType === 'brand').map(([k, l]) => (
                  <button
                    key={k}
                    type="button"
                    className={f.linkType === k ? 'on !bg-[var(--pill-active-bg)] !text-[var(--pill-active-text)]' : ''}
                    onClick={() => {
                      set('linkType', k);
                      set('linkId', null);
                      setErr(e => ({ ...e, linkId: null, url: null }));
                    }}
                  >
                    <span className="inline-flex items-center gap-1.5">
                      <Icon name={l.icon} className="w-3.5 h-3.5" /> {l.label}
                    </span>
                  </button>
                ))}
              </div>
            </div>
            {f.linkType === 'none' ? (
              <div className="text-[11.5px] c-muted">الصورة ستظهر بدون إمكانية الضغط عليها.</div>
            ) : (
              <div className="fade-in" key={f.linkType}>
                {targetField()}
              </div>
            )}
          </div>

          {/* Schedule & Sort */}
          <div className="grid grid-cols-1 sm:grid-cols-[120px_1fr_1fr] gap-4">
            <div>
              <label className="block text-[12.5px] font-semibold mb-1.5" style={{ color: 'var(--text-primary)' }}>
                ترتيب الظهور <span className="c-orange">*</span>
              </label>
              <input
                inputMode="numeric"
                className={`inp num text-center ${err.sort ? 'err' : ''}`}
                value={sortStr}
                onChange={e => setSortStr(e.target.value.replace(/[^\d]/g, ''))}
                placeholder="1"
              />
              {err.sort && <div className="text-[11px] mt-1 font-medium" style={{ color: 'var(--badge-red-text)' }}>{err.sort}</div>}
            </div>

            <div>
              <label className="block text-[12.5px] font-semibold mb-1.5" style={{ color: 'var(--text-primary)' }}>
                بداية العرض
                <span className="text-[11px] font-normal c-muted mr-1.5">{f.start ? '' : '· فارغ = فوراً'}</span>
              </label>
              <div className="flex items-center gap-1.5">
                <input
                  type="datetime-local"
                  className="inp num text-start text-[12px]"
                  value={f.start || ''}
                  onChange={e => { set('start', e.target.value); setErr(x => ({ ...x, end: null })); }}
                />
                {f.start && (
                  <button type="button" className="icon-btn shrink-0" title="مسح" onClick={() => set('start', '')}>
                    <Icon name="close" className="w-3.5 h-3.5" />
                  </button>
                )}
              </div>
            </div>

            <div>
              <label className="block text-[12.5px] font-semibold mb-1.5" style={{ color: 'var(--text-primary)' }}>
                نهاية العرض
                <span className="text-[11px] font-normal c-muted mr-1.5">{f.end ? '' : '· فارغ = دائم'}</span>
              </label>
              <div className="flex items-center gap-1.5">
                <input
                  type="datetime-local"
                  className={`inp num text-start text-[12px] ${err.end ? 'err' : ''}`}
                  value={f.end || ''}
                  onChange={e => set('end', e.target.value)}
                />
                {f.end && (
                  <button type="button" className="icon-btn shrink-0" title="مسح" onClick={() => set('end', '')}>
                    <Icon name="close" className="w-3.5 h-3.5" />
                  </button>
                )}
              </div>
              {err.end && <div className="text-[11px] mt-1 font-medium" style={{ color: 'var(--badge-red-text)' }}>{err.end}</div>}
            </div>
          </div>

          {/* Active status */}
          <div className="v-subcard p-3.5 flex items-center justify-between">
            <div>
              <div className="text-[13px] font-semibold c-primary flex items-center gap-2">
                التفعيل: {f.active ? 'مفعّل' : 'معطّل'}
                <span className={`badge ${BN_STATUS[bnStatus(f)].cls}`}>
                  {BN_STATUS[bnStatus(f)].label}
                </span>
              </div>
              <div className="text-[11px] c-secondary mt-0.5">
                الحالة تُحسب تلقائياً من التفعيل وتواريخ البداية والنهاية.
              </div>
            </div>
            <Toggle on={Boolean(f.active)} onChange={v => set('active', v)} />
          </div>
        </div>

        <div className="p-5 pt-3 flex items-center justify-start gap-2.5 flex-row-reverse v-border-t">
          <button
            type="button"
            className="btn btn-dark min-w-[110px]"
            onClick={submit}
            disabled={busy || upl.state === 'uploading'}
          >
            {busy ? (
              <>
                <span className="w-3.5 h-3.5 rounded-full border-2 border-current border-t-transparent animate-spin" />
                <span>جارٍ الحفظ…</span>
              </>
            ) : 'حفظ البانر'}
          </button>
          <button type="button" className="btn btn-soft" onClick={closeForm} disabled={busy}>
            إلغاء
          </button>
          <span className="flex-1" />
          {upl.state === 'uploading' && <span className="text-[11.5px] c-muted">انتظر اكتمال رفع الصورة</span>}
        </div>
      </div>
    </div>
  );
}

// ============ Main Store Management Screen Component ============
export function StoreManagementTab({ can }: { can?: (perm: string) => boolean }) {
  const toggleInFlight = useRef(new Set<string>());
  const canEdit = can ? (can('store.manage') || can('*')) : true;

  const [tab, setTab] = useState<'slider' | 'middle' | 'popup'>(() => {
    try {
      const saved = localStorage.getItem('vortix-bn-tab');
      if (saved === 'slider' || saved === 'middle' || saved === 'popup') return saved;
    } catch {}
    return 'slider';
  });

  useEffect(() => {
    try { localStorage.setItem('vortix-bn-tab', tab); } catch {}
  }, [tab]);

  const [phase, setPhase] = useState<'loading' | 'ready' | 'error'>('loading');
  const [items, setItems] = useState<BannerItem[]>([]);

  const loadBanners = React.useCallback(() => {
    setPhase('loading');
    adminApi.listBanners()
      .then((list) => { setItems(list.map(fromApi)); setPhase('ready'); })
      .catch(() => setPhase('error'));
  }, []);

  useEffect(() => { loadBanners(); }, [loadBanners]);

  const [q, setQ] = useState('');
  const [sf, setSf] = useState<string>('all');
  const [form, setForm] = useState<{ open: boolean; initial: BannerItem | null }>({ open: false, initial: null });
  const [del, setDel] = useState<BannerItem | null>(null);
  const [delBusy, setDelBusy] = useState(false);
  const [busyToggle, setBusyToggle] = useState<Set<string>>(() => new Set());

  const inTab = items.filter(b => b.placement === tab);
  const rows = inTab
    .filter(b => (!q || b.title.toLocaleLowerCase().includes(q.trim().toLocaleLowerCase())) && (sf === 'all' || bnStatus(b) === sf))
    .sort((a, b) => a.sort - b.sort);

  const tabCount = (k: string) => items.filter(b => b.placement === k).length;
  const stCount = (k: string) => inTab.filter(b => bnStatus(b) === k).length;

  const save = async (f: BannerItem, onProgress: (p: number) => void): Promise<Record<string, string> | null> => {
    const isNew = !f.id || !/^\d+$/.test(f.id);
    try {
      const saved = isNew
        ? await adminApi.createBanner(toFormData(f), onProgress)
        : await adminApi.updateBanner(Number(f.id), toFormData(f), onProgress);
      const mapped = fromApi(saved);
      setItems(xs => isNew ? [...xs, mapped] : xs.map(x => x.id === mapped.id ? mapped : x));
      setForm({ open: false, initial: null });
      if (mapped.placement !== tab) setTab(mapped.placement);
      toast(isNew ? 'تمت إضافة البانر بنجاح' : 'تم حفظ تعديلات البانر');
      return null;
    } catch (e) {
      const err = normalizeError(e);
      if (err.status === 422) {
        const out: Record<string, string> = {};
        let generalError: string | undefined;
        Object.entries(err.fields).forEach(([k, msgs]) => {
          if (k === 'title') out.title = msgs[0];
          else if (k === 'image') out.image = msgs[0];
          else if (k === 'link_value') out[f.linkType === 'external' ? 'url' : 'linkId'] = msgs[0];
          else if (k === 'sort_order') out.sort = msgs[0];
          else if (k === 'ends_at') out.end = msgs[0];
          else generalError ??= msgs[0];
        });
        if (generalError) toast(generalError, 'err');
        return out;
      }
      toast(err.message, 'err');
      return {};
    }
  };

  const toggle = (b: BannerItem) => {
    if (toggleInFlight.current.has(b.id)) return;
    toggleInFlight.current.add(b.id);
    const prev = b.active;
    setItems(xs => xs.map(x => x.id === b.id ? { ...x, active: !x.active, status: undefined } : x));
    setBusyToggle(ids => new Set(ids).add(b.id));
    adminApi.toggleBanner(Number(b.id), !prev)
      .then((saved) => {
        setItems(xs => xs.map(x => x.id === b.id ? fromApi(saved) : x));
        toast(prev ? 'تم تعطيل البانر' : 'تم تفعيل البانر');
      })
      .catch((e) => {
        setItems(xs => xs.map(x => x.id === b.id ? { ...x, active: prev, status: b.status } : x));
        toast(normalizeError(e).message, 'err');
      })
      .finally(() => { toggleInFlight.current.delete(b.id); setBusyToggle(ids => { const next = new Set(ids); next.delete(b.id); return next; }); });
  };

  const doDelete = () => {
    if (!del) return;
    setDelBusy(true);
    adminApi.deleteBanner(Number(del.id))
      .then(() => {
        setItems(xs => xs.filter(x => x.id !== del.id));
        setDel(null);
        toast('تم حذف البانر بنجاح');
      })
      .catch((e) => toast(normalizeError(e).message, 'err'))
      .finally(() => setDelBusy(false));
  };

  const openNew = () => setForm({ open: true, initial: null });

  const P = BN_PLACEMENTS[tab] || BN_PLACEMENTS.slider;
  const liveInTab = inTab.filter(b => bnStatus(b) === 'live').sort((a, b) => a.sort - b.sort);

  return (
    <section className="space-y-6 fade-in" data-screen-label="Store Management">
      {/* Sub-header info and Add button */}
      <div className="flex flex-wrap items-center justify-between gap-4 p-5 rounded-[22px] v-card">
        <div>
          <h2 className="font-display text-[20px] font-semibold c-primary flex items-center gap-2.5">
            <span className="w-8 h-8 rounded-xl flex items-center justify-center text-white v-accent-btn">
              <Icon name="storefront" className="w-4 h-4" />
            </span>
            <span>إدارة المتجر — السلايدر والبانرات</span>
          </h2>
          <p className="text-[12.5px] c-secondary mt-1">
            إدارة الصور والإعلانات الظاهرة في واجهة المتجر ومواعيد عرضها وروابط التوجيه.
          </p>
        </div>
        <div>
          {canEdit ? (
            <button
              type="button"
              className="btn btn-dark flex items-center gap-2"
              onClick={openNew}
            >
              <Icon name="plus" className="w-3.5 h-3.5" />
              <span>إضافة بانر جديد</span>
            </button>
          ) : (
            <span className="inline-flex items-center gap-1.5 text-[12px] c-muted px-3.5 py-2 rounded-full v-subcard">
              <Icon name="eye" className="w-3.5 h-3.5" /> صلاحية مشاهدة فقط
            </span>
          )}
        </div>
      </div>

      {/* Placement Tabs */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
        {Object.entries(BN_PLACEMENTS).map(([k, p]) => {
          const on = tab === k;
          return (
            <button
              key={k}
              type="button"
              onClick={() => { setTab(k as any); setSf('all'); setQ(''); }}
              className={`text-right rounded-[20px] p-4 flex items-center gap-3.5 transition ${
                on ? 'shadow-xs' : 'hover:opacity-90'
              }`}
              style={{
                background: on ? 'var(--pill-active-bg)' : 'var(--bg-card)',
                color: on ? 'var(--pill-active-text)' : 'var(--text-primary)',
                border: '1px solid var(--border-subtle)',
              }}
            >
              <span
                className="w-10 h-10 rounded-full flex items-center justify-center shrink-0"
                style={{
                  background: on ? 'var(--accent-orange)' : 'var(--icon-well-bg)',
                  color: on ? '#fff' : 'var(--text-secondary)',
                }}
              >
                <Icon name={p.icon} className="w-4 h-4" />
              </span>
              <span className="flex-1 min-w-0">
                <span className="block text-[14px] font-semibold">{p.label}</span>
                <span className="block text-[11px] truncate opacity-70">{p.hint}</span>
              </span>
              <span className="num font-display text-[22px] font-bold">
                {phase === 'ready' || !on ? tabCount(k) : '—'}
              </span>
            </button>
          );
        })}
      </div>

      {/* Main Grid: Banners list (8 cols) + Live Preview (4 cols) */}
      <div className="grid grid-cols-1 xl:grid-cols-12 gap-5 items-start">
        {/* Banners List */}
        <div className="xl:col-span-8 v-card p-5 space-y-4">
          <div className="flex flex-wrap items-center justify-between gap-3 pb-3 v-border-b">
            <div className="flex items-center gap-2.5">
              <h3 className="font-display text-[16px] font-semibold c-primary">{P.label}</h3>
              {phase === 'ready' && (
                <span className="text-[11.5px] c-muted">| &nbsp;{rows.length} من {inTab.length}</span>
              )}
            </div>
            <div className="flex items-center gap-2.5 flex-wrap">
              <div className="seg">
                <button
                  type="button"
                  className={sf === 'all' ? 'on' : ''}
                  onClick={() => setSf('all')}
                >
                  الكل
                </button>
                {Object.entries(BN_STATUS).map(([k, s]) => (
                  <button
                    key={k}
                    type="button"
                    className={sf === k ? 'on' : ''}
                    onClick={() => setSf(k)}
                  >
                    {s.label}
                    {phase === 'ready' && <span className="num c-muted mr-1">({stCount(k)})</span>}
                  </button>
                ))}
              </div>

              <div className="v-subcard px-3 py-1.5 rounded-full flex items-center gap-2">
                <Icon name="search" className="w-3.5 h-3.5 c-muted" />
                <input
                  value={q}
                  onChange={e => setQ(e.target.value)}
                  placeholder="بحث بالعنوان"
                  className="bg-transparent text-[12px] outline-none w-28 sm:w-36 c-primary"
                />
              </div>
            </div>
          </div>

          {!canEdit && phase === 'ready' && (
            <div className="rounded-2xl px-4 py-3 flex items-start gap-3" style={{ background: 'var(--badge-blue-bg)' }}>
              <Icon name="eye" className="w-4 h-4 mt-0.5" style={{ color: 'var(--badge-blue-text)' }} />
              <div className="text-[12.5px]" style={{ color: 'var(--badge-blue-text)' }}>
                وضع المشاهدة فقط. لإضافة أو تعديل أو تفعيل البانرات يلزم صلاحية إدارة المتجر.
              </div>
            </div>
          )}

          {phase === 'loading' && <BannerSkeleton />}

          {phase === 'error' && (
            <div className="v-subcard py-14 px-6 flex flex-col items-center text-center rounded-2xl fade-in">
              <Icon name="info" className="w-6 h-6 c-muted mb-2" />
              <div className="font-display text-[15px] font-semibold c-primary">تعذّر تحميل البانرات</div>
              <button type="button" className="btn btn-dark btn-sm mt-4" onClick={loadBanners}>إعادة المحاولة</button>
            </div>
          )}

          {phase === 'ready' && inTab.length === 0 && (
            <div className="v-subcard py-14 px-6 flex flex-col items-center text-center rounded-2xl fade-in">
              <div className="w-14 h-14 rounded-full flex items-center justify-center mb-4 relative" style={{ background: 'var(--bg-card)' }}>
                <Icon name={P.icon} className="w-6 h-6 c-secondary" />
              </div>
              <div className="font-display text-[15px] font-semibold c-primary">
                لا توجد بانرات في «{P.label}»
              </div>
              <div className="text-[12.5px] c-secondary mt-1 max-w-sm">
                {P.hint}. أضف أول صورة لتظهر في المتجر.
              </div>
              {canEdit && (
                <button type="button" className="btn btn-dark btn-sm mt-4" onClick={openNew}>
                  <Icon name="plus" className="w-3.5 h-3.5" /> إضافة أول بانر
                </button>
              )}
            </div>
          )}

          {phase === 'ready' && inTab.length > 0 && rows.length === 0 && (
            <div className="v-subcard py-12 px-6 flex flex-col items-center text-center rounded-2xl fade-in">
              <Icon name="filter" className="w-6 h-6 c-muted mb-2" />
              <div className="font-display text-[14px] font-semibold c-primary">
                لا توجد بانرات تطابق البحث أو الفلتر
              </div>
              <div className="text-[12px] c-secondary mt-1">
                غيّر كلمة البحث أو اختر حالة تصفية أخرى.
              </div>
            </div>
          )}

          {phase === 'ready' && rows.length > 0 && (
            <div className="space-y-2.5">
              {rows.map(b => {
                const st = bnStatus(b);
                const tgt = bnTarget(b);
                const L = BN_LINK_TYPES[b.linkType] || BN_LINK_TYPES.none;
                const s = bnDate(b.start);
                const e = bnDate(b.end);

                return (
                  <div
                    key={b.id}
                    className="v-subcard p-3 flex items-center gap-4 fade-in transition-opacity"
                    style={{ opacity: st === 'disabled' ? 0.72 : 1 }}
                  >
                    {/* Sort Index */}
                    <div
                      className="num w-7 h-7 rounded-full flex items-center justify-center text-[12px] font-bold shrink-0"
                      title="ترتيب الظهور"
                      style={{ background: 'var(--bg-card)', color: 'var(--text-primary)' }}
                    >
                      {b.sort}
                    </div>

                    {/* Thumbnail */}
                    <div
                      className="rounded-xl overflow-hidden shrink-0"
                      style={{
                        width: tab === 'popup' ? 72 : 160,
                        aspectRatio: P.ratio,
                        boxShadow: '0 0 0 1px var(--border-subtle)',
                      }}
                    >
                      <BannerArt b={b} />
                    </div>

                    {/* Details */}
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-semibold text-[13.5px] c-primary truncate">{b.title}</span>
                        <span className={`badge ${BN_STATUS[st].cls}`}>
                          <span className="dot" /> {BN_STATUS[st].label}
                        </span>
                      </div>

                      <div className="flex items-center gap-1.5 mt-1.5 text-[12px] min-w-0">
                        <span className="c-muted shrink-0">
                          <Icon name={L.icon} className="w-3.5 h-3.5" />
                        </span>
                        {b.linkType === 'none' ? (
                          <span className="c-muted">بدون رابط</span>
                        ) : (
                          <>
                            <span className="c-secondary shrink-0">{L.label}:</span>
                            <span className={`truncate ${b.linkType === 'external' ? 'mono text-[11.5px] c-orange' : 'c-primary font-medium'}`}>
                              {tgt || '—'}
                            </span>
                          </>
                        )}
                      </div>

                      <div className="flex items-center gap-2 mt-1.5 text-[11.5px] c-secondary">
                        <Icon name="calendar" className="w-3 h-3 c-muted" />
                        {s ? (
                          <span className="num">{s.d} <span className="c-muted">{s.t}</span></span>
                        ) : (
                          <span className="c-muted">بدون موعد بداية</span>
                        )}
                        <span className="c-muted">←</span>
                        {e ? (
                          <span className="num">{e.d} <span className="c-muted">{e.t}</span></span>
                        ) : (
                          <span className="c-muted">بدون موعد نهاية</span>
                        )}
                      </div>
                    </div>

                    {/* Actions */}
                    <div className="flex items-center gap-3 shrink-0">
                      <div className="flex flex-col items-center gap-1">
                        {busyToggle.has(b.id) ? (
                          <span className="w-9 h-5 flex items-center justify-center">
                            <span className="w-3.5 h-3.5 rounded-full border-2 border-[var(--accent-orange)] border-t-transparent animate-spin" />
                          </span>
                        ) : (
                          <Toggle on={b.active} disabled={!canEdit} onChange={() => toggle(b)} />
                        )}
                        <span className="text-[10px] c-muted">{b.active ? 'مفعّل' : 'معطّل'}</span>
                      </div>

                      {canEdit ? (
                        <div className="flex items-center gap-0.5">
                          <button
                            type="button"
                            className="icon-btn"
                            title="تعديل"
                            onClick={() => setForm({ open: true, initial: b })}
                          >
                            <Icon name="edit" className="w-3.5 h-3.5" />
                          </button>
                          <button
                            type="button"
                            className="icon-btn"
                            title="حذف"
                            onClick={() => setDel(b)}
                          >
                            <Icon name="trash" className="w-3.5 h-3.5" />
                          </button>
                        </div>
                      ) : (
                        <span className="icon-btn" title="لا تملك الصلاحية">
                          <Icon name="lock" className="w-3.5 h-3.5" />
                        </span>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>

        {/* Live Preview Card */}
        <div className="xl:col-span-4 v-card p-5 xl:sticky xl:top-5 space-y-3">
          <div className="flex items-center justify-between mb-1">
            <h3 className="font-display text-[15px] font-semibold c-primary">معاينة الواجهة</h3>
            <span className="text-[11px] c-muted">
              الساري الآن · <span className="num font-semibold">{phase === 'ready' ? liveInTab.length : 0}</span>
            </span>
          </div>

          <StorePreview tab={tab} live={phase === 'ready' ? liveInTab : []} />

          <div className="text-[11px] c-muted flex items-start gap-1.5 leading-relaxed">
            <Icon name="info" className="w-3 h-3 mt-0.5 shrink-0" />
            <span>تظهر فقط البانرات المفعّلة داخل فترة عرضها مرتبة حسب «ترتيب الظهور».</span>
          </div>
        </div>
      </div>

      {/* Banner Create / Edit Modal */}
      <BannerForm
        open={form.open}
        initial={form.initial}
        defaultPlacement={tab}
        onClose={() => setForm({ open: false, initial: null })}
        onSave={save}
      />

      {/* Delete Confirmation Modal */}
      {del && (
        <div
          className="fixed inset-0 z-[65] flex items-center justify-center p-4 fade-in"
          style={{ background: 'rgba(0,0,0,.45)', backdropFilter: 'blur(4px)' }}
          onMouseDown={e => e.target === e.currentTarget && !delBusy && setDel(null)}
        >
          <div className="v-card p-6 w-full max-w-md shadow-2xl v-pop-in space-y-4">
            <div className="flex items-start justify-between gap-4">
              <h3 className="font-display text-[18px] font-semibold c-primary">حذف البانر؟</h3>
              <button
                type="button"
                onClick={() => !delBusy && setDel(null)}
                className="w-8 h-8 rounded-full v-subcard flex items-center justify-center c-secondary"
              >
                <Icon name="close" className="w-3.5 h-3.5" />
              </button>
            </div>

            <div
              className="rounded-2xl overflow-hidden"
              style={{
                aspectRatio: BN_PLACEMENTS[del.placement]?.ratio === '4 / 5' ? '16 / 7' : BN_PLACEMENTS[del.placement]?.ratio,
                boxShadow: '0 0 0 1px var(--border-subtle)',
              }}
            >
              <BannerArt b={del} big />
            </div>

            <div>
              <div className="font-semibold c-primary text-[14px]">{del.title}</div>
              <div className="text-[12px] c-secondary mt-0.5">
                {BN_PLACEMENTS[del.placement]?.label} · ترتيب <span className="num font-semibold">{del.sort}</span>
              </div>
              <div className="text-[12.5px] c-secondary mt-2.5 leading-relaxed">
                سيختفي البانر من المتجر فوراً. لإخفائه مؤقتاً يمكنك استخدام مفتاح التعطيل بدلاً من الحذف.
              </div>
            </div>

            <div className="flex items-center justify-start gap-2.5 flex-row-reverse pt-2 v-border-t">
              <button
                type="button"
                className="btn btn-orange"
                onClick={doDelete}
                disabled={delBusy}
              >
                {delBusy ? (
                  <>
                    <span className="w-3.5 h-3.5 rounded-full border-2 border-current border-t-transparent animate-spin" />
                    <span>جارٍ الحذف…</span>
                  </>
                ) : 'حذف نهائي'}
              </button>
              <button
                type="button"
                className="btn btn-soft"
                onClick={() => setDel(null)}
                disabled={delBusy}
              >
                تراجع
              </button>
            </div>
          </div>
        </div>
      )}
    </section>
  );
}

