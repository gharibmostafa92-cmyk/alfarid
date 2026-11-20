import type { LegalPolicyId } from './legal-policies';

export const LEGAL_POLICY_VERSION = '2026-11-20-v2';

export function getLegalPolicyHref(slug: string, version = LEGAL_POLICY_VERSION): string {
  return '/legal/' + version + '/' + slug;
}

export const LEGAL_POLICY_LINKS: Array<{
  id: LegalPolicyId;
  slug: string;
  title: string;
  titleAr: string;
}> = [
  { id: 'terms', slug: 'terms-and-conditions', title: 'Terms & Conditions', titleAr: 'الشروط والأحكام' },
  { id: 'privacy', slug: 'privacy-policy', title: 'Privacy Policy', titleAr: 'سياسة الخصوصية' },
  { id: 'returns', slug: 'returns-refunds-exchanges', title: 'Returns & Refunds', titleAr: 'الاسترجاع والاسترداد' },
  { id: 'shipping', slug: 'shipping-delivery', title: 'Shipping & Delivery', titleAr: 'الشحن والتوصيل' },
  { id: 'payments', slug: 'payment-billing', title: 'Payment & Billing', titleAr: 'الدفع والفواتير' },
  { id: 'cookies', slug: 'cookies', title: 'Cookie Policy', titleAr: 'سياسة ملفات تعريف الارتباط' },
];
