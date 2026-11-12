'use client';

import '@/styles/checkout.css';
import React, { useEffect, useState } from 'react';
import { storeEndpoints } from '@alfarid/api-client';
import { formatStorePrice } from '@alfarid/utils';
import { toCardVM } from '@/lib/catalog/catalog-mappers';
import { useCheckout } from './useCheckout';
import { ContactSection } from './ContactSection';
import { ShippingSection } from './ShippingSection';
import { DeliverySection } from './DeliverySection';
import { PaymentSection } from './PaymentSection';
import { OrderSummary, type SuggestionCard } from './OrderSummary';

const steps = [
  { label: 'بيانات', targetId: 'checkout-section-contact' },
  { label: 'الشحن', targetId: 'checkout-section-shipping' },
  { label: 'الدفع', targetId: 'checkout-section-payment' },
];


/**
 * Real-data checkout page: contact + shipping + delivery + payment sections
 * on the left, live server-priced order summary on the right.
 */
export function SophieCheckoutPage({
  onContinueShopping,
}: {
  onContinueShopping?: () => void;
  onLoginClick?: () => void;
  onOrderPlaced?: (orderData: unknown) => void;
}) {
  const vm = useCheckout();
  const [suggestions, setSuggestions] = useState<SuggestionCard[]>([]);
  const [mobileBagOpen, setMobileBagOpen] = useState(false);

  useEffect(() => {
    let cancelled = false;
    storeEndpoints
      .showcase()
      .then((products) => {
        if (cancelled) return;
        setSuggestions(
          products
            .map(toCardVM)
            .filter((c) => c.inStock)
            .slice(0, 4)
            .map((c) => ({
              slug: c.slug,
              name: c.name,
              img: c.img,
              priceLabel: c.priceLabel,
            })),
        );
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, []);

  const grand = vm.quote?.preview?.grand_total ?? null;
  const fallbackSubtotal = vm.totals?.items_total ?? null;
  const totalItemsCount = vm.items.reduce((n, i) => n + i.quantity, 0);

  const scrollToSection = (id: string) => {
    const el = document.getElementById(id);
    if (el) {
      el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  };

  const focusNextMissingStep = () => {
    if (!vm.form.fullName.trim()) {
      document.getElementById('chk-fullname')?.focus();
      return;
    }
    if (!vm.form.phone.trim()) {
      document.getElementById('chk-phone')?.focus();
      return;
    }
    if (vm.form.cityId === null) {
      document.getElementById('chk-city')?.focus();
      return;
    }
    if (!vm.form.street.trim()) {
      document.getElementById('chk-street')?.focus();
      return;
    }
    scrollToSection('checkout-section-payment');
  };

  return (
    <section className="checkout-wrapper" id="checkout-view" aria-label="Checkout">
      <div className="container checkout-main-container">
        <div className="checkout-top-bar">
          <h1 className="checkout-giant-title">CHECKOUT</h1>
          <button
            type="button"
            className="back-to-shop-btn"
            id="back-to-shop-btn"
            onClick={onContinueShopping}
          >
            <i className="fa-solid fa-arrow-left" aria-hidden="true" /> Continue Shopping
          </button>
        </div>

        {/* Interactive Steps indicator for quick one-handed navigation */}
        <ol className="checkout-steps" aria-label="Checkout steps">
          {steps.map(({ label, targetId }, i) => {
            const n = i + 1;
            const done = vm.step > n;
            const current = vm.step === n;
            return (
              <li
                key={label}
                className={`checkout-step${done ? ' is-done' : ''}${
                  current ? ' is-current' : ''
                }`}
                aria-current={current ? 'step' : undefined}
              >
                <button
                  type="button"
                  className="checkout-step-btn"
                  onClick={() => scrollToSection(targetId)}
                >
                  <span className="checkout-step-dot" aria-hidden="true">
                    {done ? '✓' : n}
                  </span>
                  <span>{label}</span>
                </button>
              </li>
            );
          })}
        </ol>

        {/* Mobile quick collapsible order summary toggle */}
        {vm.items.length > 0 && (
          <div className="mobile-bag-accordion">
            <button
              type="button"
              className="mobile-bag-accordion-trigger"
              aria-expanded={mobileBagOpen}
              onClick={() => setMobileBagOpen((o) => !o)}
            >
              <span className="mobile-bag-trigger-left">
                <i className="fa-solid fa-bag-shopping" aria-hidden="true" />
                <span>
                  {mobileBagOpen ? 'إخفاء ملخص السلة' : 'عرض ملخص السلة وكود الخصم'} ({totalItemsCount})
                </span>
              </span>
              <span className="mobile-bag-trigger-right">
                <span>
                  {grand !== null
                    ? formatStorePrice(grand)
                    : fallbackSubtotal !== null
                      ? formatStorePrice(fallbackSubtotal)
                      : '...'}
                </span>
                <i
                  className={`fa-solid fa-chevron-${mobileBagOpen ? 'up' : 'down'}`}
                  aria-hidden="true"
                />
              </span>
            </button>
            {mobileBagOpen && (
              <div className="mobile-bag-accordion-body">
                <button
                  type="button"
                  className="checkout-readiness-btn"
                  style={{ width: '100%' }}
                  onClick={() => {
                    setMobileBagOpen(false);
                    scrollToSection('checkout-order-summary');
                  }}
                >
                  الانتقال لتعديل المنتجات أو إضافة كوبون خصم ↓
                </button>
              </div>
            )}
          </div>
        )}

        {vm.banner && (
          <div className={`checkout-banner is-${vm.banner.kind}`} role="alert">
            <span>{vm.banner.text}</span>
            {vm.banner.action && (
              <button
                type="button"
                className="btn-apply-promo"
                onClick={vm.banner.action.onClick}
              >
                {vm.banner.action.label}
              </button>
            )}
            <button
              type="button"
              className="bag-remove-btn"
              onClick={() => vm.setBanner(null)}
              aria-label="Dismiss"
            >
              ✕
            </button>
          </div>
        )}

        {vm.cartError && vm.cartError !== vm.banner?.text && (
          <div className="checkout-banner is-error" role="alert">
            <span>{vm.cartError}</span>
            <button type="button" onClick={() => vm.loadCart()}>
              إعادة تحميل السلة
            </button>
          </div>
        )}

        <div className="checkout-grid">
          <div className="checkout-left-col">
            <fieldset disabled={vm.locked} className="checkout-form-fields">
              <ContactSection vm={vm} />
              <ShippingSection vm={vm} />
              <DeliverySection vm={vm} />
              <PaymentSection vm={vm} />
            </fieldset>

            {!vm.canSubmit && !vm.submitting && vm.items.length > 0 && (
              <div className="checkout-readiness-helper" role="status" aria-live="polite">
                <span>
                  {vm.quoteLoading
                    ? 'جاري حساب تكلفة الشحن لمدينتك...'
                    : vm.form.cityId === null
                      ? 'اختار المدينة في قسم الشحن لحساب الإجمالي وتفعيل زر إتمام الطلب.'
                      : 'تأكد من إكمال البيانات المطلوبة لاختيار طريقة الشحن والدفع.'}
                </span>
                {!vm.quoteLoading && (
                  <button
                    type="button"
                    className="checkout-readiness-btn"
                    onClick={focusNextMissingStep}
                  >
                    استكمال البيانات
                  </button>
                )}
              </div>
            )}

            <button
              type="button"
              className="btn-place-order checkout-submit-btn"
              id="pay-place-order-btn"
              onClick={() => vm.submit()}
              disabled={!vm.canSubmit}
            >
              {vm.submitting ? (
                <span className="mega-btn-spinner" aria-hidden="true" />
              ) : null}
              {vm.submitting
                ? 'جاري إتمام الطلب...'
                : vm.uncertain
                  ? 'إعادة المحاولة'
                  : 'إتمام الطلب'}
            </button>
          </div>

          <div>
            <fieldset
              disabled={vm.locked}
              className="checkout-form-fields checkout-summary-sticky"
            >
              <OrderSummary vm={vm} suggestions={suggestions} />
            </fieldset>
          </div>
        </div>

        {/* Mobile sticky total bar optimized for one-handed thumb operation */}
        {vm.items.length > 0 && (
          <div className="checkout-mobile-bar" role="region" aria-label="ملخص وإتمام الطلب">
            <div className="checkout-mobile-total">
              <span>{grand !== null ? 'الإجمالي شحن وضريبة' : 'اختار المدينة للشحن'}</span>
              <strong>
                {grand !== null
                  ? formatStorePrice(grand)
                  : fallbackSubtotal !== null
                    ? formatStorePrice(fallbackSubtotal)
                    : '...'}
              </strong>
            </div>
            <button
              type="button"
              className="btn-place-order"
              onClick={() => vm.submit()}
              disabled={!vm.canSubmit}
            >
              {vm.submitting
                ? 'جاري الإرسال...'
                : vm.uncertain
                  ? 'إعادة المحاولة'
                  : 'إتمام الطلب'}
            </button>
          </div>
        )}
      </div>
    </section>
  );
}

export default SophieCheckoutPage;
