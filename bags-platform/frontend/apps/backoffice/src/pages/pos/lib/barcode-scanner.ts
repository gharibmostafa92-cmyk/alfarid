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
  if (!target || !(target instanceof HTMLElement)) return false;
  const tag = target.tagName.toUpperCase();
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable) {
    return true;
  }
  return Boolean(target.closest?.('[data-money-input]') || target.closest?.('[data-scan-input]'));
}

export function createScanDetector(opts: ScanDetectorOptions): (e: ScanKeyEvent) => void {
  const now = opts.now ?? (() => performance.now());
  const minLength = opts.minLength ?? 6;
  const gapMs = opts.gapMs ?? 40;
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
