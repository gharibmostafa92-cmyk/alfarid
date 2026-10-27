'use client';

import React from 'react';
import { formatStorePrice } from '@alfarid/utils';
import type { CheckoutVM } from './useCheckout';

export function DeliverySection({ vm }: { vm: CheckoutVM }) {
  const { form, quote, quoteLoading } = vm;
  return (
    <div className="checkout-block">
      <div className="checkout-block-header">
        <h2 className="checkout-section-heading">Delivery</h2>
      </div>
      {vm.quoteError && <p role="alert">تعذّر حساب الشحن. <button type="button" className="bag-remove-btn" onClick={vm.retryQuote}>إعادة المحاولة</button></p>}
      {form.cityId === null ? (
        <p className="checkout-login-prompt">اختار المدينة عشان تشوف تكلفة الشحن.</p>
      ) : quoteLoading && !quote ? (
        <div className="radio-list" aria-hidden="true">
          <div className="mega-skel-link" style={{ height: 56 }} />
          <div className="mega-skel-link" style={{ height: 56 }} />
        </div>
      ) : (
        <div className="radio-list">
          {(quote?.options ?? []).map((opt) => (
            <div key={opt.key} className="radio-option-row">
              <label className="radio-option-label">
                <span className="radio-left">
                  <input type="radio" name="deliveryMethod" checked readOnly />
                  <span>
                    <span className="radio-title">{opt.label}</span>
                    <span className="radio-desc">{opt.estimated_days}</span>
                  </span>
                </span>
                <span className="radio-price">{opt.cost === 0 ? 'Free' : formatStorePrice(opt.cost)}</span>
              </label>
            </div>
          ))}
          {quote && quote.options.length === 0 && (
            <p className="checkout-login-prompt">لا توجد طرق شحن متاحة للمدينة دي.</p>
          )}
        </div>
      )}
    </div>
  );
}
