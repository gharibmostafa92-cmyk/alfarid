'use client';

import React, { useState } from 'react';
import { formatStorePrice } from '@alfarid/utils';
import type { CheckoutVM } from './useCheckout';

export function DeliverySection({ vm }: { vm: CheckoutVM }) {
  const { form, quote, quoteLoading } = vm;
  const [selectedKey, setSelectedKey] = useState<string | null>(null);
  const options = quote?.options ?? [];
  const activeKey =
    selectedKey && options.some((o) => o.key === selectedKey)
      ? selectedKey
      : options[0]?.key ?? null;

  return (
    <div className="checkout-block" id="checkout-section-delivery">
      <div className="checkout-block-header">
        <div>
          <h2 className="checkout-section-heading">Delivery</h2>
          <p className="checkout-section-subtitle">خيارات التوصيل المتاحة لمدينتك</p>
        </div>
      </div>

      {vm.quoteError && (
        <div className="checkout-inline-alert" role="alert">
          <span>تعذّر حساب الشحن.</span>
          <button type="button" className="bag-remove-btn" onClick={vm.retryQuote}>
            إعادة المحاولة
          </button>
        </div>
      )}

      {form.cityId === null ? (
        <p className="checkout-login-prompt checkout-empty-hint">
          <i className="fa-solid fa-location-dot" aria-hidden="true" />{' '}
          اختار المدينة عشان تشوف تكلفة الشحن وموعد التوصيل.
        </p>
      ) : quoteLoading && !quote ? (
        <div className="radio-list" aria-hidden="true">
          <div className="mega-skel-link" style={{ height: 60, borderRadius: 12 }} />
          <div className="mega-skel-link" style={{ height: 60, borderRadius: 12 }} />
        </div>
      ) : (
        <div className="radio-list" role="radiogroup" aria-label="طريقة التوصيل">
          {options.map((opt) => {
            const isChecked = activeKey === opt.key;
            return (
              <div
                key={opt.key}
                className={`radio-option-row${isChecked ? ' is-selected' : ''}`}
              >
                <label className="radio-option-label">
                  <span className="radio-left">
                    <input
                      type="radio"
                      name="deliveryMethod"
                      value={opt.key}
                      checked={isChecked}
                      onChange={() => setSelectedKey(opt.key)}
                    />
                    <span className="radio-text-stack">
                      <span className="radio-title">{opt.label}</span>
                      {opt.estimated_days && (
                        <span className="radio-desc">{opt.estimated_days}</span>
                      )}
                    </span>
                  </span>
                  <span className={`radio-price${opt.cost === 0 ? ' is-free' : ''}`}>
                    {opt.cost === 0 ? 'Free' : formatStorePrice(opt.cost)}
                  </span>
                </label>
              </div>
            );
          })}
          {quote && options.length === 0 && (
            <p className="checkout-login-prompt checkout-empty-hint" role="status">
              لا توجد طرق شحن متاحة للمدينة دي.
            </p>
          )}
        </div>
      )}
    </div>
  );
}