/**
 * HID barcode scanners type characters faster than a human (<40ms apart) and end with Enter.
 * This detector turns that pattern into a scan, regardless of which element has focus.
 */
export interface ScanKeyEvent {
  key: string;
  target: EventTarget | null;
  preventDefault: () => void;
  ctrlKey?: boolean;
  altKey?: boolean;
  metaKey?: boolean;
}

export interface ScanDetectorOptions {
  onScan: (code: string) => void;
  now?: () => number;
  minLength?: number;
  gapMs?: number;
  ignore?: (target: EventTarget | null) => boolean;
}

/** Ignore focused text inputs, textareas, selects, and contenteditable elements so scanner never hijacks user typing. */
export function defaultScanIgnore(target: EventTarget | null): boolean {
  // F-12 hardening: tagName-based (no HTMLElement global) so this also runs
  // in SSR/worker contexts and plain-node harnesses.
  const tag = typeof (target as HTMLElement | null)?.tagName === 'string'
    ? ((target as HTMLElement).tagName ?? '').toUpperCase()
    : '';
  if (!tag) return false;
  const el = target as HTMLElement;
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable) {
    return true;
  }
  return Boolean(el.closest?.('[data-money-input]') || el.closest?.('[data-scan-input]'));
}

/** F-12: wireless (2.4GHz/Bluetooth) scanners stall the UI thread past 40ms;
 * 55ms keeps their bursts intact without merging human typing. */
export const DEFAULT_SCAN_GAP_MS = 55;

/**
 * F-12: a key arriving within one scan gap of the previous key is scanner
 * spew, not deliberate typing — human +/- shortcuts must stand down for it.
 */
export function isRapidScannerKey(now: number, lastKeyTime: number | null, gapMs: number = DEFAULT_SCAN_GAP_MS): boolean {
  return lastKeyTime != null && now - lastKeyTime < gapMs;
}

export function createScanDetector(opts: ScanDetectorOptions): (e: ScanKeyEvent) => void {
  const now = opts.now ?? (() => performance.now());
  const minLength = opts.minLength ?? 6;
  const gapMs = opts.gapMs ?? DEFAULT_SCAN_GAP_MS;
  const ignore = opts.ignore ?? defaultScanIgnore;
  let buf = '';
  let last = 0;

  return (e) => {
    if (ignore(e.target) || e.ctrlKey || e.altKey || e.metaKey) {
      buf = '';
      return;
    }
    const t = now();
    if (t - last > gapMs) buf = '';
    last = t;
    if (e.key === 'Enter') {
      if (buf.length >= minLength) {
        e.preventDefault();
        const code = buf;
        buf = '';
        opts.onScan(code);
      } else {
        buf = '';
      }
      return;
    }
    if (e.key.length === 1) buf += e.key;
  };
}
