import type { ApiBanner } from './api/types';
import type { BannerItem } from './store-management';

const POS_TO_API = { slider: 'main_slider', middle: 'middle_banner', popup: 'popup' } as const;
const POS_FROM_API = { main_slider: 'slider', middle_banner: 'middle', popup: 'popup' } as const;
const CAIRO = 'Africa/Cairo';

function cairoParts(date: Date): Record<string, number> {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: CAIRO, year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
  }).formatToParts(date);
  return Object.fromEntries(parts.filter((part) => part.type !== 'literal').map((part) => [part.type, Number(part.value)]));
}

/** Interpret a datetime-local value as a wall clock time in Cairo, independent of browser timezone. */
export function localInputToDate(value?: string): Date | null {
  if (!value) return null;
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value);
  if (!match) return null;
  const [year, month, day, hour, minute] = match.slice(1).map(Number);
  const wallTime = Date.UTC(year, month - 1, day, hour, minute);
  const validWallTime = new Date(wallTime);
  if (validWallTime.getUTCFullYear() !== year || validWallTime.getUTCMonth() + 1 !== month ||
      validWallTime.getUTCDate() !== day || hour > 23 || minute > 59) return null;

  // Probe offsets on either side of the requested time. Checking candidates
  // against the formatted result handles Cairo daylight-saving transitions:
  // ambiguous times choose the earlier instant, nonexistent times are rejected.
  const offsets = new Set<number>();
  for (const hours of [-36, -24, -12, 0, 12, 24, 36]) {
    const probe = new Date(wallTime + hours * 60 * 60 * 1000);
    const p = cairoParts(probe);
    const asUtc = Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute, p.second);
    offsets.add(asUtc - Math.floor(probe.getTime() / 1000) * 1000);
  }
  const matches: number[] = [];
  for (const offset of offsets) {
    const candidateMs = wallTime - offset;
    const p = cairoParts(new Date(candidateMs));
    if (p.year === year && p.month === month && p.day === day && p.hour === hour && p.minute === minute) {
      matches.push(candidateMs);
    }
  }
  return matches.length ? new Date(Math.min(...matches)) : null;
}

export const isoToLocalInput = (iso?: string | null) => {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: CAIRO,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(d);
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '00';
  return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
};
export const localInputToIso = (v?: string) => {
  return localInputToDate(v)?.toISOString() ?? '';
};

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
