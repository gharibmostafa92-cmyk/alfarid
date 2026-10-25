'use client';

import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { DEFAULT_BRANDING, type BrandIdentity } from '../lib/brand-identity';
export type { BrandIdentity } from '../lib/brand-identity';

const BrandingContext = createContext({ branding: DEFAULT_BRANDING, refreshBranding: async () => {} });

export function BrandingProvider({ children, loadBranding, initialBranding = DEFAULT_BRANDING, titleSuffix = '', manageHead = true, pollIntervalMs = 60_000 }: {
  children: ReactNode;
  loadBranding: (signal?: AbortSignal) => Promise<BrandIdentity>;
  initialBranding?: BrandIdentity;
  titleSuffix?: string;
  manageHead?: boolean;
  pollIntervalMs?: number;
}) {
  const [branding, setBranding] = useState(initialBranding);
  const controller = useRef<AbortController | null>(null);
  const pending = useRef<Promise<void> | null>(null);
  const lastAttempt = useRef(-Infinity);
  const refresh = useCallback(async (force = false): Promise<void> => {
    if (pending.current && !controller.current?.signal.aborted) {
      await pending.current;
      if (force) await refresh(true);
      return;
    }
    if (!force && !controller.current?.signal.aborted && Date.now() - lastAttempt.current < 30_000) return;
    lastAttempt.current = Date.now();
    const requestController = new AbortController();
    controller.current = requestController;
    const request = (async () => {
      try {
        const next = await loadBranding(requestController.signal);
        if (!requestController.signal.aborted) setBranding({ ...DEFAULT_BRANDING, ...next, name: next.name?.trim() || DEFAULT_BRANDING.name });
      } catch (error) {
        if (!requestController.signal.aborted) console.warn('Brand identity update failed; keeping the last known identity.', error instanceof Error ? error.message : 'Unknown error');
      }
    })();
    pending.current = request;
    await request;
    if (pending.current === request) pending.current = null;
  }, [loadBranding]);
  const refreshBranding = useCallback(() => refresh(true), [refresh]);

  useEffect(() => {
    void refresh();
    const refreshVisible = () => { if (document.visibilityState === 'visible') void refresh(); };
    const timer = pollIntervalMs > 0 ? setInterval(refreshVisible, pollIntervalMs) : null;
    window.addEventListener('focus', refreshVisible);
    document.addEventListener('visibilitychange', refreshVisible);
    return () => {
      controller.current?.abort();
      if (timer !== null) clearInterval(timer);
      window.removeEventListener('focus', refreshVisible);
      document.removeEventListener('visibilitychange', refreshVisible);
    };
  }, [refresh, pollIntervalMs]);

  useEffect(() => {
    if (manageHead) document.title = titleSuffix ? `${branding.name} | ${titleSuffix}` : branding.name;
  }, [branding.name, titleSuffix, manageHead]);

  useEffect(() => {
    if (!manageHead) return;
    const existing = document.head.querySelector<HTMLLinkElement>('link[data-brand-icon]');
    if (!branding.logo) { existing?.remove(); return; }
    const favicon = existing ?? document.createElement('link');
    favicon.dataset.brandIcon = 'true';
    favicon.rel = 'icon';
    favicon.href = branding.logo;
    if (!favicon.isConnected) document.head.appendChild(favicon);
    return () => favicon.remove();
  }, [branding.logo, manageHead]);

  return <BrandingContext.Provider value={{ branding, refreshBranding }}>{children}</BrandingContext.Provider>;
}

export const useBranding = () => useContext(BrandingContext);

export function BrandLogo({ className = '', fallback }: { className?: string; fallback?: ReactNode }) {
  const { branding } = useBranding();
  const [failed, setFailed] = useState<string | null>(null);
  if (!branding.logo || failed === branding.logo) return <>{fallback ?? null}</>;
  return <img src={branding.logo} alt="" className={`object-contain shrink-0 ${className}`} onError={() => setFailed(branding.logo)} />;
}
