import type { ApiBanner } from './api/types';
import type { BannerItem } from './store-management';

const POS_TO_API = { slider: 'main_slider', middle: 'middle_banner', popup: 'popup' } as const;
const POS_FROM_API = { main_slider: 'slider', middle_banner: 'middle', popup: 'popup' } as const;

const pad = (n: number) => String(n).padStart(2, '0');
export const isoToLocalInput = (iso?: string | null) => {
  if (!iso) return '';
  const d = new Date(iso);
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};
export const localInputToIso = (v?: string) => (v ? new Date(v).toISOString() : '');

export function fromApi(b: ApiBanner): BannerItem {
  return {
    id: String(b.id), title: b.title, placement: POS_FROM_API[b.position],
    image: b.image_url, linkType: b.link_type,
    linkId: ['product', 'category', 'brand'].includes(b.link_type) ? b.link_value : null,
    url: b.link_type === 'external' ? b.link_value ?? '' : '',
    linkLabel: b.link_label, sort: b.sort_order,
    start: isoToLocalInput(b.starts_at), end: isoToLocalInput(b.ends_at),
    active: b.is_active, status: b.status,
    kicker: b.kicker ?? '', headline: b.headline ?? '', headlineBold: b.headline_bold ?? '',
    description: b.description ?? '', ctaLabel: b.cta_label ?? '', bgColor: b.bg_color ?? '',
  };
}

export function toFormData(f: BannerItem & { imageFile?: File | null }): FormData {
  const fd = new FormData();
  fd.append('title', f.title.trim());
  if (f.imageFile) fd.append('image', f.imageFile);
  fd.append('position', POS_TO_API[f.placement]);
  fd.append('link_type', f.linkType);
  fd.append('link_value', f.linkType === 'external' ? f.url.trim() : f.linkType === 'none' ? '' : f.linkId ?? '');
  fd.append('sort_order', String(f.sort));
  fd.append('starts_at', localInputToIso(f.start));
  fd.append('ends_at', localInputToIso(f.end));
  fd.append('is_active', f.active ? '1' : '0');
  (['kicker', 'headline', 'description'] as const).forEach((k) => fd.append(k, (f as unknown as Record<string, string>)[k] ?? ''));
  fd.append('headline_bold', f.headlineBold ?? '');
  fd.append('cta_label', f.ctaLabel ?? '');
  fd.append('bg_color', f.bgColor ?? '');
  return fd;
}
