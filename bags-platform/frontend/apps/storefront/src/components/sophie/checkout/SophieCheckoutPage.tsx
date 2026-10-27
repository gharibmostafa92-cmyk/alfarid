'use client';

import React, { useEffect, useState } from 'react';
import { storeEndpoints } from '@alfarid/api-client';
import { formatStorePrice } from '@alfarid/utils';
import { toCardVM } from '@/lib/catalog-mappers';
import { useCheckout } from './useCheckout';
import { ContactSection } from './ContactSection';
import { ShippingSection } from './ShippingSection';
import { DeliverySection } from './DeliverySection';
import { PaymentSection } from './PaymentSection';
import { OrderSummary, type SuggestionCard } from './OrderSummary';

const steps = ['بيانات', 'الشحن', 'الدفع'];

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

  useEffect(() => {
    let cancelled = false;
    storeEndpoints.showcase().then((products) => {
      if (cancelled) return;
      setSuggestions(products.map(toCardVM).filter((c) => c.inStock).slice(0, 4)
        .map((c) => ({ slug: c.slug, name: c.name, img: c.img, priceLabel: c.priceLabel })));
    }).catch(() => {});
    return () => { cancelled = true; };
  }, []);

  const grand = vm.quote?.preview?.grand_total ?? null;

  return (
    <section className="checkout-wrapper" id="checkout-view" aria-label="Checkout">
      <div className="container" style={{ maxWidth: '1140px', margin: '0 auto', padding: '0 20px' }}>
        <div className="checkout-top-bar">
          <h1 className="checkout-giant-title">CHECKOUT</h1>
          <button type="button" className="back-to-shop-btn" id="back-to-shop-btn" onClick={onContinueShopping}>
            <i className="fa-solid fa-arrow-left" aria-hidden="true" /> Continue Shopping
          </button>
        </div>

        {/* Steps indicator */}
        <ol className="checkout-steps" aria-label="Checkout steps">
          {steps.map((label, i) => {
            const n = i + 1;
            const done = vm.step > n;
            const current = vm.step === n;
            return (
              <li key={label} className={`checkout-step${done ? ' is-done' : ''}${current ? ' is-current' : ''}`} aria-current={current ? 'step' : undefined}>
                <span className="checkout-step-dot" aria-hidden="true">{done ? '✓' : n}</span>
                <span>{label}</span>
              </li>
            );
          })}
        </ol>

        {vm.banner && (
          <div className={`checkout-banner is-${vm.banner.kind}`} role="alert">
            <span>{vm.banner.text}</span>
            {vm.banner.action && (
              <button type="button" className="btn-apply-promo" onClick={vm.banner.action.onClick}>
                {vm.banner.action.label}
              </button>
            )}
            <button type="button" className="bag-remove-btn" onClick={() => vm.setBanner(null)} aria-label="Dismiss">✕</button>
          </div>
        )}
        {vm.cartError && vm.cartError !== vm.banner?.text && (
          <div className="checkout-banner is-error" role="alert"><span>{vm.cartError}</span><button type="button" onClick={() => vm.loadCart()}>إعادة تحميل السلة</button></div>
        )}

        <div className="checkout-grid">
          <div className="checkout-left-col">
            <fieldset disabled={vm.locked} className="checkout-form-fields">
            <ContactSection vm={vm} />
            <ShippingSection vm={vm} />
            <DeliverySection vm={vm} />
            <PaymentSection vm={vm} />
            </fieldset>
            <button
              type="button"
              className="btn-place-order checkout-submit-btn"
              id="pay-place-order-btn"
              onClick={() => vm.submit()}
              disabled={!vm.canSubmit}
            >
              {vm.submitting ? <span className="mega-btn-spinner" aria-hidden="true" /> : null}
              {vm.submitting ? 'جاري إتمام الطلب...' : vm.uncertain ? 'إعادة المحاولة' : 'إتمام الطلب'}
            </button>
          </div>

          <div><fieldset disabled={vm.locked} className="checkout-form-fields checkout-summary-sticky"><OrderSummary vm={vm} suggestions={suggestions} /></fieldset></div>
        </div>

        {/* Mobile sticky total bar */}
        {vm.items.length > 0 && (
          <div className="checkout-mobile-bar">
            <div className="checkout-mobile-total">
              <span>الإجمالي</span>
              <strong>{grand !== null ? formatStorePrice(grand) : '...'}</strong>
            </div>
            <button
              type="button"
              className="btn-place-order"
              onClick={() => vm.submit()}
              disabled={!vm.canSubmit}
            >
              {vm.submitting ? 'جاري الإرسال...' : vm.uncertain ? 'إعادة المحاولة' : 'إتمام الطلب'}
            </button>
          </div>
        )}
      </div>
    </section>
  );
}

export default SophieCheckoutPage;
