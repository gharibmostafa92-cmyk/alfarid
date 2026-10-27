'use client';

import React from 'react';
import type { CheckoutVM } from './useCheckout';

const methodLabels: Record<string, { title: string; desc: string }> = {
  cod: { title: 'الدفع عند الاستلام', desc: 'ادفع كاش لما الطلب يوصلك' },
  wallet: { title: 'المحفظة', desc: 'ادفع من رصيد محفظتك' },
  credit: { title: 'آجل', desc: 'متاح لعملاء الجملة المعتمدين' },
};

export function PaymentSection({ vm }: { vm: CheckoutVM }) {
  const { form, set, blur, visibleErrors, quote } = vm;
  const methods = (quote?.payment_methods ?? []).filter((m) => m in methodLabels);
  return (
    <div className="checkout-block" style={{ marginBottom: 0 }}>
      <div className="checkout-block-header">
        <h2 className="checkout-section-heading">Payment</h2>
      </div>

      <div className="radio-list">
        {!quote && <p className="checkout-login-prompt">طرق الدفع هتظهر بعد حساب الشحن.</p>}
        {quote && methods.length === 0 && <p role="alert">لا توجد طريقة دفع متاحة حاليًا لهذا الحساب.</p>}
        {methods.map((m) => (
          <div key={m} className="radio-option-row">
            <label className="radio-option-label">
              <span className="radio-left">
                <input
                  type="radio"
                  name="paymentMethod"
                  value={m}
                  checked={form.paymentMethod === m}
                  onChange={() => set('paymentMethod', m as typeof form.paymentMethod)}
                />
                <span>
                  <span className="radio-title">{methodLabels[m]?.title ?? m}</span>
                  {methodLabels[m]?.desc && <span className="radio-desc">{methodLabels[m].desc}</span>}
                </span>
              </span>
            </label>
          </div>
        ))}
      </div>

      <label className="checkout-checkbox-row" style={{ marginTop: '16px' }}>
        <input
          type="checkbox"
          name="agreeTerms"
          checked={form.agreeTerms}
          onChange={(e) => set('agreeTerms', e.target.checked)}
          onBlur={() => blur('agreeTerms')}
          aria-invalid={!!visibleErrors.agreeTerms}
          data-checkout-error={visibleErrors.agreeTerms ? 'true' : undefined}
        />
        <span>موافق على الشروط وسياسة الاستبدال والاسترجاع</span>
      </label>
      {visibleErrors.agreeTerms && <p className="luxury-field-error" role="alert">{visibleErrors.agreeTerms}</p>}
    </div>
  );
}
