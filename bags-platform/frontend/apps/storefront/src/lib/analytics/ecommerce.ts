'use client';

export type AnalyticsItem = { item_id: string; item_name?: string; price?: number; quantity?: number };
type EventName = 'view_item' | 'add_to_cart' | 'begin_checkout' | 'purchase';
type EcommerceEvent = { currency: 'EGP'; value?: number; transaction_id?: string; items: AnalyticsItem[] };

declare global {
  interface Window {
    dataLayer?: unknown[];
    gtag?: (...args: unknown[]) => void;
  }
}

const CONSENT_KEY = 'varya_analytics_consent';
const PURCHASES_KEY = 'varya_analytics_purchases';
export const analyticsId = process.env.NEXT_PUBLIC_GA_MEASUREMENT_ID || '';
export const analyticsConfigured = /^G-[A-Z0-9]+$/.test(analyticsId);

export function analyticsConsent(): boolean {
  try { return localStorage.getItem(CONSENT_KEY) === 'granted'; } catch { return false; }
}

export function setAnalyticsConsent(granted: boolean): void {
  try { localStorage.setItem(CONSENT_KEY, granted ? 'granted' : 'denied'); } catch { return; }
  window.location.reload();
}

export function hasAnalyticsChoice(): boolean {
  try { return ['granted', 'denied'].includes(localStorage.getItem(CONSENT_KEY) ?? ''); } catch { return false; }
}

export function initializeAnalytics(nonce: string): void {
  if (!analyticsConfigured || !analyticsConsent() || window.gtag) return;
  window.dataLayer = window.dataLayer || [];
  window.gtag = function (...args: unknown[]) { window.dataLayer!.push(args); };
  window.gtag('js', new Date());
  window.gtag('config', analyticsId, {
    send_page_view: false, allow_google_signals: false, allow_ad_personalization_signals: false,
    page_location: window.location.origin + window.location.pathname, page_referrer: '',
  });
  const script = document.createElement('script');
  script.async = true;
  script.nonce = nonce;
  script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(analyticsId);
  document.head.appendChild(script);
}

export function trackEcommerce(name: EventName, event: EcommerceEvent): void {
  try {
    if (!analyticsConfigured || !analyticsConsent()) return;
    let purchases: string[] = [];
    if (name === 'purchase' && event.transaction_id) {
      try {
        const stored = JSON.parse(localStorage.getItem(PURCHASES_KEY) ?? '[]');
        purchases = Array.isArray(stored) ? stored.filter((value): value is string => typeof value === 'string') : [];
      } catch { purchases = []; }
      if (purchases.includes(event.transaction_id)) return;
    }
    initializeAnalytics(document.querySelector<HTMLMetaElement>('meta[property="csp-nonce"]')?.content || '');
    window.gtag?.('event', name, { ...event, page_location: window.location.origin + window.location.pathname, page_referrer: '' });
    if (name === 'purchase' && event.transaction_id) {
      localStorage.setItem(PURCHASES_KEY, JSON.stringify([...purchases, event.transaction_id].slice(-100)));
    }
  } catch {
    // Analytics must not interrupt shopping or order placement.
  }
}
