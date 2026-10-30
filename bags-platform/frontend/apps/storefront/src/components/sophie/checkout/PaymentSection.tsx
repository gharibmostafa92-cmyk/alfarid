'use client';

import React from 'react';
import type { CheckoutVM } from './useCheckout';

const methodLabels: Record<string, { title: string; desc: string; icon: string }> = {
  cod: {
    title: 'الدفع عند الاستلام',
    desc: 'ادفع كاش لما الطلب يوصلك لباب البيت',
    icon: 'fa-solid fa-money-bill-wave',
  },
  wallet: {
    title: 'المحفظة',
    desc: 'ادفع فوراً من رصيد محفظتك',
    icon: 'fa-solid fa-wallet',
  },
  credit: {
    title: 'آجل',
    desc: 'متاح لعملاء الجملة المعتمدين',
    icon: 'fa-solid fa-file-invoice-dollar',
  },
};

export function PaymentSection({ vm }: { vm: CheckoutVM }) {
  const { form, set, blur, visibleErrors, quote } = vm;
  const methods = (quote?.payment_methods ?? []).filter((m) => m in methodLabels);
  return (
    <div className="checkout-block" id="checkout-section-payment" style={{ marginBottom: 0 }}>
      <div className="checkout-block-header">
        <div>
          <h2 className="checkout-section-heading">Payment</h2>
          <p className="checkout-section-subtitle">اختر وسيلة الدفع المناسبة لك</p>
        </div>
      </div>

      <div className="radio-list" role="radiogroup" aria-label="طريقة الدفع">
        {!quote && (
          <p className="checkout-login-prompt checkout-empty-hint">
            <i className="fa-solid fa-shield-halved" aria-hidden="true" />{' '}
            طرق الدفع هتظهر بعد حساب الشحن.
          </p>
        )}
        {quote && methods.length === 0 && (
          <p className="luxury-field-error" role="alert">
            لا توجد طريقة دفع متاحة حاليًا لهذا الحساب.
          </p>
        )}
        {methods.map((m) => {
          const isSelected = form.paymentMethod === m;
          const info = methodLabels[m];
          return (
            <div
              key={m}
              className={`radio-option-row${isSelected ? ' is-selected' : ''}`}
            >
              <label className="radio-option-label">
                <span className="radio-left">
                  <input
                    type="radio"
                    name="paymentMethod"
                    value={m}
                    checked={isSelected}
                    onChange={() => set('paymentMethod', m as typeof form.paymentMethod)}
                  />
                  <span className="radio-text-stack">
                    <span className="radio-title">
                      {info?.icon && (
                        <i className={`${info.icon} payment-method-icon`} aria-hidden="true" />
                      )}
                      {info?.title ?? m}
                    </span>
                    {info?.desc && <span className="radio-desc">{info.desc}</span>}
                  </span>
                </span>
              </label>
            </div>
          );
        })}
      </div>

      <label
        className={`checkout-checkbox-row${form.agreeTerms ? ' is-checked' : ''}${
          visibleErrors.agreeTerms ? ' has-error' : ''
        }`}
        style={{ marginTop: '16px' }}
      >
        <input
          type="checkbox"
          name="agreeTerms"
          checked={form.agreeTerms}
          onChange={(e) => set('agreeTerms', e.target.checked)}
          onBlur={() => blur('agreeTerms')}
          aria-invalid={!!visibleErrors.agreeTerms}
          aria-describedby={visibleErrors.agreeTerms ? 'chk-terms-error' : undefined}
          data-checkout-error={visibleErrors.agreeTerms ? 'true' : undefined}
        />
        <span>موافق على الشروط وسياسة الاستبدال والاسترجاع</span>
      </label>
      {visibleErrors.agreeTerms && (
        <p id="chk-terms-error" className="luxury-field-error" role="alert">
          {visibleErrors.agreeTerms}
        </p>
      )}
    </div>
  );
}