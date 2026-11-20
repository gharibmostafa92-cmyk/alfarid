'use client';

import Link from 'next/link';
import { useEffect } from 'react';
import {
  ArrowLeft,
  ArrowRight,
  BadgeCheck,
  CreditCard,
  Cookie,
  FileText,
  RotateCcw,
  ShieldCheck,
  Truck,
} from 'lucide-react';
import { useBranding } from '@alfarid/ui/branding';
import { useStoreLang } from '@/providers/store-lang-provider';
import { getLegalPolicyById, type LegalPolicyId } from '@/lib/legal-policies';
import { LEGAL_POLICY_LINKS, LEGAL_POLICY_VERSION, getLegalPolicyHref } from '@/lib/legal-policy-links';
import { SophieFooter } from '@/components/sophie/layout/SophieFooter';
import '@/styles/legal.css';

const icons = {
  terms: FileText,
  privacy: ShieldCheck,
  returns: RotateCcw,
  shipping: Truck,
  payments: CreditCard,
  cookies: Cookie,
};

export function LegalPolicyPage({ policyId, policyVersion = LEGAL_POLICY_VERSION }: { policyId: LegalPolicyId; policyVersion?: string }) {
  const { branding } = useBranding();
  const { isAr, dir } = useStoreLang();
  const policy = getLegalPolicyById(policyId, policyVersion)!;
  const BackIcon = isAr ? ArrowRight : ArrowLeft;
  const officialReferences = policyId === 'privacy'
    ? [{ href: 'https://pdpc.gov.eg/', label: isAr ? 'مركز حماية البيانات الشخصية' : 'Personal Data Protection Center' }]
    : policyId === 'returns'
      ? [
          { href: 'https://www.wipo.int/wipolex/ar/legislation/details/19866', label: isAr ? 'قانون حماية المستهلك رقم 181 لسنة 2018' : 'Egypt Consumer Protection Law No. 181 of 2018' },
          { href: 'https://cpa.gov.eg/ar-eg/بيانات-اعلامية/ArtMID/654/ArticleID/6790', label: isAr ? 'إرشاد الجهاز للسلع المعيبة' : 'CPA defective-item guidance' },
        ]
      : policyId === 'terms'
        ? [
            { href: 'https://www.wipo.int/wipolex/ar/legislation/details/19866', label: isAr ? 'قانون حماية المستهلك رقم 181 لسنة 2018' : 'Egypt Consumer Protection Law No. 181 of 2018' },
            { href: 'https://cpa.gov.eg/ar-eg/كيف-تتقدم-بشكوى', label: isAr ? 'قنوات تقديم شكوى لجهاز حماية المستهلك' : 'How to file a Consumer Protection Agency complaint' },
          ]
        : [];
  const browserStorageEntries = isAr
    ? [
        ['varya_store_lang', 'localStorage', 'تذكر اللغة المختارة', 'حتى حذف بيانات الموقع'],
        ['cart_session', 'localStorage', 'ربط سلة الزائر بجلسة المتجر', 'حتى حذف السلة أو الجلسة أو بيانات الموقع'],
        ['pending_checkout', 'localStorage أو sessionStorage', 'استكمال محاولة شراء غير مكتملة', 'صلاحية 15 دقيقة؛ يحذف السجل المنتهي عند فحص محاولة الشراء التالية'],
        ['last_order', 'sessionStorage', 'عرض ملخص الطلب بعد الشراء', 'صلاحية 30 دقيقة؛ يحذف عند قراءته بعد انتهاء المهلة أو إغلاق الجلسة'],
      ]
    : [
        ['varya_store_lang', 'localStorage', 'Remember the selected language', 'Until site data is cleared'],
        ['cart_session', 'localStorage', 'Connect a guest basket to the store session', 'Until the cart, session, or site data is cleared'],
        ['pending_checkout', 'localStorage or sessionStorage', 'Resume an unfinished checkout', '15-minute validity; expired entry is deleted when checkout next checks it'],
        ['last_order', 'sessionStorage', 'Show the order summary after checkout', '30-minute validity; deleted when read after expiry or when the session ends'],
      ];

  useEffect(() => {
    const root = document.documentElement;
    const previousAttributes = {
      type: root.getAttribute('data-type'),
      radius: root.getAttribute('data-radius'),
      buttons: root.getAttribute('data-buttons'),
      motion: root.getAttribute('data-motion'),
    };
    root.dataset.type = 'product';
    root.dataset.radius = 'soft';
    root.dataset.buttons = 'solid';
    root.dataset.motion = 'quiet';

    return () => {
      for (const [attribute, value] of Object.entries(previousAttributes)) {
        const name = 'data-' + attribute;
        if (value === null) root.removeAttribute(name);
        else root.setAttribute(name, value);
      }
    };
  }, []);

  return (
    <>
      <main id="main-content" className="legal-page" dir={dir} lang={isAr ? 'ar' : 'en'}>
        <div className="legal-page__wrap">
          <nav className="legal-breadcrumb" aria-label={isAr ? 'مسار الصفحة' : 'Breadcrumb'}>
            <Link href="/">{isAr ? 'الرئيسية' : 'Home'}</Link>
            <span aria-hidden="true">/</span>
            <span>{isAr ? 'خدمة العملاء' : 'Customer care'}</span>
          </nav>

          <header className="legal-hero" data-enter>
            <div className="legal-hero__copy">
              <span className="legal-eyebrow">
                <span className="legal-eyebrow__line" aria-hidden="true" />
                {isAr ? 'معلومات تهمك' : 'CUSTOMER CARE'}
              </span>
              <h1>{isAr ? policy.titleAr : policy.title}</h1>
              <p>{isAr ? policy.summaryAr : policy.summary}</p>
            </div>
            <Link href="/shop" className="legal-back-link">
              <BackIcon size={17} aria-hidden="true" />
              {isAr ? 'العودة للتسوق' : 'Continue shopping'}
            </Link>
          </header>

          <div className="legal-layout">
            <nav className="legal-nav" aria-label={isAr ? 'السياسات' : 'Policies'}>
              <p className="legal-nav__heading">{isAr ? 'السياسات' : 'STORE POLICIES'}</p>
              {LEGAL_POLICY_LINKS.map((entry) => {
                const Icon = icons[entry.id];
                const active = entry.id === policyId;
                return (
                  <Link
                    key={entry.id}
                    href={getLegalPolicyHref(entry.slug, policyVersion)}
                    className={'legal-nav__link' + (active ? ' is-active' : '')}
                    aria-current={active ? 'page' : undefined}
                  >
                    <Icon size={17} aria-hidden="true" />
                    <span>{isAr ? entry.titleAr : entry.title}</span>
                    <span className="legal-nav__chevron" aria-hidden="true">›</span>
                  </Link>
                );
              })}
            </nav>

            <article className="legal-article">
              <div className="legal-article__topline">
                <span className="legal-article__mark" aria-hidden="true">
                  <BadgeCheck size={18} />
                </span>
                <p>{isAr ? 'الوضوح جزء من تجربتك معنا' : 'Clear information is part of your experience with us'}</p>
                <small className="legal-policy-version" dir="ltr">
                  {isAr ? 'الإصدار' : 'Version'} {policyVersion}
                </small>
              </div>

              <div className="legal-article__content">
                {policy.sections.map((section, index) => (
                  <section className="legal-section" key={section.heading}>
                    <span className="legal-section__number" aria-hidden="true">
                      {String(index + 1).padStart(2, '0')}
                    </span>
                    <div>
                      <h2>{isAr ? section.headingAr : section.heading}</h2>
                      {(isAr ? section.paragraphsAr : section.paragraphs).map((paragraph) => (
                        <p key={paragraph}>{paragraph}</p>
                      ))}
                    </div>
                  </section>
                ))}

                {policyId === 'cookies' && (
                  <section className="legal-storage" aria-labelledby="legal-storage-title">
                    <h2 id="legal-storage-title">{isAr ? 'مساحة التخزين المستخدمة' : 'Browser storage in use'}</h2>
                    <div className="legal-storage__scroll">
                      <table aria-label={isAr ? 'تفاصيل مساحة تخزين المتصفح' : 'Browser storage details'}>
                        <thead>
                          <tr>
                            <th>{isAr ? 'الاسم' : 'Name'}</th>
                            <th>{isAr ? 'المكان' : 'Location'}</th>
                            <th>{isAr ? 'الغرض' : 'Purpose'}</th>
                            <th>{isAr ? 'المدة' : 'Duration'}</th>
                          </tr>
                        </thead>
                        <tbody>
                          {browserStorageEntries.map(([name, location, purpose, duration]) => (
                            <tr key={name}>
                              <td><code>{name}</code></td>
                              <td>{location}</td>
                              <td>{purpose}</td>
                              <td>{duration}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </section>
                )}

                {officialReferences.length > 0 && (
                  <p className="legal-external-note">
                    {isAr ? 'مرجع رسمي' : 'Official references'}{' '}
                    {officialReferences.map((reference, index) => (
                      <span key={reference.href}>
                        {index > 0 ? ' · ' : null}
                        <a href={reference.href} target="_blank" rel="noreferrer">
                          {reference.label}
                        </a>
                      </span>
                    ))}
                  </p>
                )}
              </div>

              {branding.legalName && (
                <section className="legal-seller" aria-labelledby="legal-seller-title">
                  <div>
                    <p className="legal-seller__eyebrow">{isAr ? 'الجهة المسؤولة' : 'SELLER DETAILS'}</p>
                    <h2 id="legal-seller-title">{branding.legalName}</h2>
                    {branding.name !== branding.legalName && (
                      <p>{isAr ? 'الاسم التجاري: ' : 'Trading name: '}{branding.name}</p>
                    )}
                    <p>{isAr ? 'للاستفسارات المتعلقة بطلبك أو بهذه السياسة، تواصل معنا.' : 'For questions about an order or this policy, contact our team.'}</p>
                  </div>
                  <dl className="legal-seller__details">
                    {branding.address && <div><dt>{isAr ? 'العنوان' : 'Address'}</dt><dd dir="auto">{branding.address}</dd></div>}
                    {branding.email && <div><dt>{isAr ? 'البريد الإلكتروني' : 'Email'}</dt><dd><a href={'mailto:' + branding.email}>{branding.email}</a></dd></div>}
                    {branding.phone && <div><dt>{isAr ? 'الهاتف' : 'Phone'}</dt><dd><a href={'tel:' + branding.phone} dir="ltr">{branding.phone}</a></dd></div>}
                    {branding.commercialRegister && <div><dt>{isAr ? 'السجل التجاري' : 'Commercial register'}</dt><dd dir="ltr">{branding.commercialRegister}</dd></div>}
                    {branding.taxNumber && <div><dt>{isAr ? 'التسجيل الضريبي' : 'Tax registration'}</dt><dd dir="ltr">{branding.taxNumber}</dd></div>}
                  </dl>
                </section>
              )}

              <div className="legal-article__footer">
                <span>{isAr ? 'تحتاج مساعدة إضافية؟' : 'Need more help?'}</span>
                <Link href={getLegalPolicyHref('returns-refunds-exchanges', policyVersion)}>
                  {isAr ? 'عرض سياسة الإرجاع' : 'Review the returns policy'}
                  <BackIcon size={15} aria-hidden="true" />
                </Link>
              </div>
            </article>
          </div>
        </div>
      </main>
      <div className="legal-page__footer"><span className="legal-footer-accent" aria-hidden="true" /></div>
      <SophieFooter />
    </>
  );
}

export type { LegalPolicyId };
