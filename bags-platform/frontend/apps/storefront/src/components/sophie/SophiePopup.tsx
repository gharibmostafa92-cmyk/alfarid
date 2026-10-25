'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import type { StoreBanner } from '@alfarid/api-client';

export function SophiePopup({ banner }: { banner: StoreBanner }) {
  const [open, setOpen] = useState(false);
  const [failed, setFailed] = useState(false);
  const dismissed = useRef(false);
  const dialog = useRef<HTMLDivElement>(null);
  const sessionKey = `sophie-popup-${banner.id}`;
  const close = useCallback(() => {
    dismissed.current = true;
    setOpen(false);
    try { sessionStorage.setItem(sessionKey, '1'); } catch { /* Remains dismissed for this mount when storage is blocked. */ }
  }, [sessionKey]);

  useEffect(() => {
    if (dismissed.current) return;
    try { if (sessionStorage.getItem(sessionKey)) return; } catch { /* Storage may be disabled. */ }
    let alive = true;
    let loaded = false;
    let elapsed = false;
    const image = new Image();
    const show = () => { if (alive && loaded && elapsed && !dismissed.current) setOpen(true); };
    image.onload = () => { loaded = true; show(); };
    image.onerror = () => { if (alive) setFailed(true); };
    image.src = banner.image;
    const timer = setTimeout(() => { elapsed = true; show(); }, 2500);
    return () => { alive = false; clearTimeout(timer); image.onload = null; image.onerror = null; };
  }, [banner.image, sessionKey]);

  useEffect(() => {
    if (!open || failed) return;
    const previousOverflow = document.body.style.overflow;
    const previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    document.body.style.overflow = 'hidden';
    dialog.current?.querySelector<HTMLButtonElement>('button')?.focus();
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') { event.preventDefault(); close(); }
      if (event.key !== 'Tab') return;
      const controls = dialog.current?.querySelectorAll<HTMLElement>('button, a[href]');
      if (!controls?.length) return;
      const first = controls[0];
      const last = controls[controls.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    };
    document.addEventListener('keydown', onKey);
    return () => { document.body.style.overflow = previousOverflow; document.removeEventListener('keydown', onKey); previousFocus?.focus(); };
  }, [open, failed, close]);

  if (!open || failed) return null;
  const image = <img src={banner.image} alt={banner.title} className="w-full h-full object-cover" onError={() => { setFailed(true); setOpen(false); }} />;
  return <div className="fixed inset-0 z-[60] bg-black/50 backdrop-blur-[2px] flex items-center justify-center" onClick={(event) => { if (event.target === event.currentTarget) close(); }}>
    <div ref={dialog} role="dialog" aria-modal="true" aria-label={banner.title} className="sophie-pop-in w-[88vw] max-w-[380px] aspect-[4/5] bg-white overflow-hidden relative max-h-[90dvh]">
      <button onClick={close} aria-label="Close offer" className="absolute z-10 right-3 top-3 rounded-full bg-white w-8 h-8 flex items-center justify-center text-black">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6" /></svg>
      </button>
      {banner.href ? <a href={banner.href} onClick={close} className="block w-full h-full" {...(banner.is_external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}>{image}</a> : image}
    </div>
  </div>;
}
