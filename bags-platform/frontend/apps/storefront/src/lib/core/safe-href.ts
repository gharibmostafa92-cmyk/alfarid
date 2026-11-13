export function safeStoreHref(value: unknown): string | null {
  if (typeof value !== 'string') return null;
  const trimmed = value.trim();
  if (!trimmed || /[\u0000-\u001f\u007f\\]/.test(trimmed)) return null;
  if (trimmed.startsWith('/') && !trimmed.startsWith('//') && !trimmed.includes('\\')) {
    return trimmed;
  }
  try {
    const url = new URL(trimmed);
    return url.protocol === 'https:' && !url.username && !url.password ? url.toString() : null;
  } catch {
    return null;
  }
}

export function safeMailtoHref(value: unknown): string | null {
  if (typeof value !== 'string') return null;
  const email = value.trim();
  const match = /^([A-Z0-9.!#$%&'*+/=?^_`{|}~-]+)@([A-Z0-9-]+(?:\.[A-Z0-9-]+)+)$/i.exec(email);
  return match ? `mailto:${encodeURIComponent(match[1])}@${match[2]}` : null;
}

export function safeTelHref(value: unknown): string | null {
  if (typeof value !== 'string') return null;
  const phone = value.trim().replace(/[ ().-]/g, '');
  return /^\+?\d{7,15}$/.test(phone) ? `tel:${phone}` : null;
}
