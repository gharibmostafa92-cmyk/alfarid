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

const steps = [
  { label: 'بيانات', targetId: 'checkout-section-contact' },
  { label: 'الشحن', targetId: 'checkout-section-shipping' },
  { label: 'الدفع', targetId: 'checkout-section-payment' },
];

const CHECKOUT_RESPONSIVE_STYLES = `
  .checkout-wrapper {
    width: 100%;
    overflow-x: clip;
    padding-bottom: calc(116px + env(safe-area-inset-bottom, 0px));
    box-sizing: border-box;
  }
  .checkout-main-container {
    width: 100%;
    max-width: 1140px;
    margin: 0 auto;
    padding: clamp(16px, 3.5vw, 36px) clamp(12px, 4vw, 24px);
    box-sizing: border-box;
  }
  .sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
  }
  .checkout-top-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 18px;
  }
  .checkout-giant-title {
    font-size: clamp(1.5rem, 4.5vw, 2.25rem);
    line-height: 1.15;
    margin: 0;
    letter-spacing: -0.02em;
  }
  .back-to-shop-btn {
    min-height: 44px;
    padding: 8px 16px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    touch-action: manipulation;
    transition: background-color 0.15s ease, transform 0.1s ease;
  }
  .back-to-shop-btn:focus-visible,
  .btn-place-order:focus-visible,
  .btn-apply-promo:focus-visible,
  .qty-btn:focus-visible,
  .bag-remove-btn:focus-visible,
  .checkout-step-btn:focus-visible {
    outline: 2px solid #111827;
    outline-offset: 2px;
  }
  /* Interactive thumb-friendly progress steps */
  .checkout-steps {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
    list-style: none;
    padding: 6px;
    margin: 0 0 20px;
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid rgba(15, 23, 42, 0.07);
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.03);
  }
  .checkout-step {
    margin: 0;
  }
  .checkout-step-btn {
    width: 100%;
    min-height: 44px;
    padding: 8px 10px;
    border: none;
    border-radius: 10px;
    background: transparent;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    color: #6b7280;
    cursor: pointer;
    touch-action: manipulation;
    transition: background-color 0.15s ease, color 0.15s ease;
  }
  .checkout-step-dot {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.78rem;
    font-weight: 700;
    background: #e5e7eb;
    color: #374151;
    flex-shrink: 0;
  }
  .checkout-step.is-current .checkout-step-btn {
    background: #111827;
    color: #ffffff;
  }
  .checkout-step.is-current .checkout-step-dot {
    background: rgba(255, 255, 255, 0.2);
    color: #ffffff;
  }
  .checkout-step.is-done .checkout-step-btn {
    color: #047857;
    background: #ecfdf5;
  }
  .checkout-step.is-done .checkout-step-dot {
    background: #10b981;
    color: #ffffff;
  }
  /* Mobile quick collapsible order summary bar */
  .mobile-bag-accordion {
    display: block;
    margin-bottom: 18px;
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    box-shadow: 0 2px 12px rgba(15, 23, 42, 0.04);
    overflow: hidden;
  }
  .mobile-bag-accordion-trigger {
    width: 100%;
    min-height: 52px;
    padding: 12px 16px;
    border: none;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    font-size: 0.95rem;
    font-weight: 600;
    color: #111827;
    cursor: pointer;
    touch-action: manipulation;
  }
  .mobile-bag-trigger-left {
    display: inline-flex;
    align-items: center;
    gap: 8px;
  }
  .mobile-bag-trigger-right {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
  }
  .mobile-bag-accordion-body {
    padding: 12px 14px 16px;
    border-top: 1px solid #e5e7eb;
  }
  @media (min-width: 992px) {
    .mobile-bag-accordion {
      display: none;
    }
  }
  /* Main 2-column responsive layout */
  .checkout-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 20px;
    align-items: start;
  }
  @media (min-width: 992px) {
    .checkout-grid {
      grid-template-columns: minmax(0, 1.35fr) minmax(340px, 0.95fr);
      gap: 28px;
    }
    .checkout-summary-sticky {
      position: sticky;
      top: 112px;
    }
  }
  .checkout-form-fields {
    border: none;
    margin: 0;
    padding: 0;
    min-width: 0;
  }
  .checkout-block,
  .bag-summary-panel {
    background: #ffffff;
    border-radius: 16px;
    padding: clamp(16px, 3.5vw, 28px);
    margin-bottom: 18px;
    border: 1px solid rgba(15, 23, 42, 0.07);
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.035);
    box-sizing: border-box;
  }
  .checkout-block-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px 16px;
    margin-bottom: 14px;
  }
  .checkout-section-heading {
    font-size: clamp(1.15rem, 2.5vw, 1.35rem);
    font-weight: 700;
    color: #111827;
    margin: 0;
  }
  .checkout-section-subtitle {
    margin: 4px 0 0;
    font-size: 0.85rem;
    color: #6b7280;
    line-height: 1.45;
  }
  .checkout-subheading {
    font-size: 0.9rem;
    font-weight: 600;
    color: #4b5563;
    margin: 0 0 12px;
  }
  /* Form Fields & Labels */
  .checkout-fields-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 14px;
  }
  @media (min-width: 600px) {
    .checkout-fields-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
    }
    .checkout-field-full {
      grid-column: 1 / -1;
    }
  }
  .checkout-address-subgrid {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 12px;
  }
  @media (min-width: 360px) {
    .checkout-address-subgrid {
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 10px;
    }
  }
  .line-input-wrap {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }
  .checkout-field-label {
    font-size: 0.84rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 6px;
    line-height: 1.35;
  }
  .required-star {
    color: #dc2626;
    margin-inline-start: 2px;
  }
  .optional-tag {
    font-weight: 400;
    color: #6b7280;
    font-size: 0.78rem;
  }
  .line-input {
    width: 100%;
    min-height: 48px;
    font-size: max(16px, 1rem);
    line-height: 1.4;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid #d1d5db;
    background-color: #f9fafb;
    color: #111827;
    box-sizing: border-box;
    transition: border-color 0.15s ease, background-color 0.15s ease, box-shadow 0.15s ease;
  }
  .line-input:focus {
    outline: none;
    background-color: #ffffff;
    border-color: #111827;
    box-shadow: 0 0 0 3px rgba(17, 24, 39, 0.08);
  }
  .line-input[aria-invalid="true"] {
    border-color: #dc2626;
    background-color: #fef2f2;
  }
  .luxury-field-error {
    color: #b91c1c;
    font-size: 0.82rem;
    font-weight: 500;
    margin: 6px 0 0;
    line-height: 1.4;
  }
  /* Saved addresses bar */
  .saved-addresses-bar {
    margin-bottom: 16px;
    padding: 12px;
    background: #f8fafc;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
  }
  .saved-addresses-label {
    display: block;
    font-size: 0.82rem;
    font-weight: 600;
    color: #475569;
    margin-bottom: 8px;
  }
  .saved-addresses-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }
  .saved-address-pill {
    min-height: 40px;
    padding: 6px 12px;
    border-radius: 999px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #1e293b;
    font-size: 0.85rem;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    touch-action: manipulation;
  }
  .saved-address-pill.is-active {
    background: #111827;
    color: #ffffff;
    border-color: #111827;
  }
  /* Radio & Checkbox Touch Cards */
  .radio-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .radio-option-row {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    background: #f9fafb;
    transition: border-color 0.15s ease, background-color 0.15s ease;
  }
  .radio-option-row.is-selected {
    border-color: #111827;
    background: #ffffff;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
  }
  .radio-option-label {
    min-height: 56px;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    cursor: pointer;
    box-sizing: border-box;
    touch-action: manipulation;
  }
  .radio-left {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
  }
  .radio-left input[type="radio"] {
    width: 20px;
    height: 20px;
    margin: 0;
    accent-color: #111827;
    flex-shrink: 0;
  }
  .radio-text-stack {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }
  .radio-title {
    font-size: 0.95rem;
    font-weight: 600;
    color: #111827;
    display: inline-flex;
    align-items: center;
    gap: 8px;
  }
  .payment-method-icon {
    color: #4b5563;
  }
  .radio-desc {
    font-size: 0.82rem;
    color: #6b7280;
    line-height: 1.4;
  }
  .radio-price {
    font-weight: 700;
    font-size: 0.95rem;
    color: #111827;
    flex-shrink: 0;
  }
  .radio-price.is-free {
    color: #047857;
  }
  .checkout-checkbox-row {
    min-height: 52px;
    padding: 12px 14px;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
    background: #f9fafb;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 0.92rem;
    font-weight: 500;
    color: #1f2937;
    cursor: pointer;
    box-sizing: border-box;
    touch-action: manipulation;
  }
  .checkout-checkbox-row.is-checked {
    border-color: #111827;
    background: #ffffff;
  }
  .checkout-checkbox-row.has-error {
    border-color: #dc2626;
    background: #fef2f2;
  }
  .checkout-checkbox-row input[type="checkbox"] {
    width: 20px;
    height: 20px;
    margin: 0;
    accent-color: #111827;
    flex-shrink: 0;
  }
  /* Order Summary & Bag Items */
  .bag-items-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
    margin: 14px 0 18px;
  }
  .bag-item-row {
    display: grid;
    grid-template-columns: 68px minmax(0, 1fr) auto;
    gap: 12px;
    align-items: start;
    padding-bottom: 14px;
    border-bottom: 1px solid #f1f5f9;
  }
  @media (max-width: 374px) {
    .bag-item-row {
      grid-template-columns: 56px minmax(0, 1fr);
    }
    .bag-item-right {
      grid-column: 2;
      flex-direction: row !important;
      justify-content: space-between;
      width: 100%;
    }
  }
  .bag-item-thumb {
    width: 68px;
    height: 84px;
    border-radius: 10px;
    overflow: hidden;
    background: #f3f4f6;
    flex-shrink: 0;
  }
  @media (max-width: 374px) {
    .bag-item-thumb {
      width: 56px;
      height: 70px;
    }
  }
  .bag-item-info {
    min-width: 0;
  }
  .bag-item-title {
    font-size: 0.95rem;
    font-weight: 600;
    margin: 0 0 6px;
    line-height: 1.35;
    word-break: break-word;
  }
  .bag-item-title a {
    color: #111827;
    text-decoration: none;
  }
  .bag-item-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 12px;
    font-size: 0.82rem;
    color: #6b7280;
  }
  .bag-qty-control-row {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 4px;
  }
  .qty-stepper {
    display: inline-flex;
    align-items: center;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    background: #ffffff;
    overflow: hidden;
  }
  .qty-btn {
    min-width: 44px;
    min-height: 44px;
    border: none;
    background: #f8fafc;
    color: #111827;
    font-size: 1rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    touch-action: manipulation;
  }
  .qty-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }
  .qty-count-display {
    min-width: 28px;
    text-align: center;
    font-weight: 600;
    font-size: 0.9rem;
    color: #111827;
  }
  .bag-item-right {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
  }
  .bag-item-price {
    font-weight: 700;
    font-size: 0.95rem;
    color: #111827;
    white-space: nowrap;
  }
  .bag-remove-btn {
    min-height: 44px;
    padding: 4px 10px;
    border-radius: 8px;
    border: 1px solid transparent;
    background: transparent;
    color: #dc2626;
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    cursor: pointer;
    touch-action: manipulation;
  }
  .bag-remove-btn:hover {
    background: #fef2f2;
  }
  /* Promo Row */
  .promo-row {
    display: flex;
    align-items: stretch;
    gap: 8px;
    margin: 14px 0 18px;
  }
  .btn-apply-promo {
    min-height: 48px;
    padding: 0 18px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 0.88rem;
    cursor: pointer;
    touch-action: manipulation;
    flex-shrink: 0;
  }
  .promo-chip-row {
    margin: 12px 0 16px;
  }
  .promo-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    border-radius: 999px;
    background: #ecfdf5;
    color: #047857;
    font-weight: 600;
    font-size: 0.88rem;
  }
  .promo-chip button {
    min-width: 44px;
    min-height: 44px;
    border: none;
    background: transparent;
    color: inherit;
    cursor: pointer;
    border-radius: 50%;
  }
  .bag-totals-breakdown {
    border-top: 1px solid #e5e7eb;
    padding-top: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .bag-totals-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    font-size: 0.92rem;
    color: #4b5563;
  }
  .bag-totals-row.is-discount strong {
    color: #047857;
  }
  .bag-grand-total {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 2px solid #111827;
    font-size: 1.15rem;
    font-weight: 700;
    color: #111827;
  }
  /* Readiness helper & Desktop Submit */
  .checkout-readiness-helper {
    margin: 14px 0 10px;
    padding: 12px 14px;
    border-radius: 12px;
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
    font-size: 0.88rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
  }
  .checkout-readiness-btn {
    min-height: 44px;
    padding: 4px 12px;
    border-radius: 8px;
    border: 1px solid #d97706;
    background: #ffffff;
    color: #92400e;
    font-weight: 600;
    font-size: 0.82rem;
    cursor: pointer;
    touch-action: manipulation;
  }
  .checkout-submit-btn {
    width: 100%;
    min-height: 54px;
    margin-top: 14px;
    border-radius: 12px;
    font-size: 1.05rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    cursor: pointer;
    touch-action: manipulation;
  }
  /* Sticky One-Handed Mobile Bar */
  .checkout-mobile-bar {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 30;
    background: rgba(255, 255, 255, 0.97);
    backdrop-filter: blur(12px);
    border-top: 1px solid rgba(15, 23, 42, 0.1);
    padding: 10px 14px calc(10px + env(safe-area-inset-bottom, 0px));
    box-shadow: 0 -6px 24px rgba(15, 23, 42, 0.08);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    box-sizing: border-box;
  }
  .checkout-mobile-total {
    display: flex;
    flex-direction: column;
    min-width: 0;
  }
  .checkout-mobile-total span {
    font-size: 0.76rem;
    color: #6b7280;
  }
  .checkout-mobile-total strong {
    font-size: 1.05rem;
    color: #111827;
    white-space: nowrap;
  }
  .checkout-mobile-bar .btn-place-order {
    flex: 1;
    max-width: 240px;
    min-height: 48px;
    border-radius: 12px;
    font-size: 0.98rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    touch-action: manipulation;
  }
  @media (min-width: 992px) {
    .checkout-mobile-bar {
      display: none;
    }
    .checkout-wrapper {
      padding-bottom: 48px;
    }
  }
  /* Empty bag suggestions grid */
  .checkout-suggestions-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-top: 12px;
  }
  @media (max-width: 340px) {
    .checkout-suggestions-grid {
      grid-template-columns: minmax(0, 1fr);
    }
  }
  /* Respect reduced motion preferences */
  @media (prefers-reduced-motion: reduce) {
    #checkout-view .mega-btn-spinner {
      animation: none;
    }
    #checkout-view :is(button, input, .checkout-step-dot, .checkout-mobile-bar) {
      transition: none;
    }
  }
`;

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
      <style dangerouslySetInnerHTML={{ __html: CHECKOUT_RESPONSIVE_STYLES }} />
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
