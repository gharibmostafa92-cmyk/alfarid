'use client';

import React from 'react';
import Link from 'next/link';
import type { CheckoutVM } from './useCheckout';

export function ContactSection({ vm }: { vm: CheckoutVM }) {
  const { form, set, blur, visibleErrors } = vm;
  return (
    <div className="checkout-block" id="checkout-section-contact">
      <div className="checkout-block-header">
        <div>
          <h2 className="checkout-section-heading">Information</h2>
          <p className="checkout-section-subtitle">بيانات التواصل لاستلام وتأكيد الطلب</p>
        </div>
        {!vm.token && (
          <p className="checkout-login-prompt">
            عندك حساب؟{' '}
            <Link href={`/auth/login?redirect=${encodeURIComponent('/checkout')}`}>
              سجّل دخول عشان نملالك البيانات
            </Link>
          </p>
        )}
      </div>

      <h3 className="checkout-subheading">Personal Information</h3>
      <div className="checkout-fields-grid">
        <div className="line-input-wrap checkout-field-full">
          <label htmlFor="chk-fullname" className="checkout-field-label">
            الاسم بالكامل <span className="required-star" aria-hidden="true">*</span>
          </label>
          <input
            type="text"
            id="chk-fullname"
            aria-label="الاسم بالكامل"
            aria-required="true"
            aria-describedby={visibleErrors.fullName ? 'chk-fullname-error' : undefined}
            maxLength={255}
            className="line-input"
            placeholder="الاسم بالكامل (رباعي أو ثنائي)"
            autoComplete="name"
            enterKeyHint="next"
            value={form.fullName}
            onChange={(e) => set('fullName', e.target.value)}
            onBlur={() => blur('fullName')}
            aria-invalid={!!visibleErrors.fullName}
            data-checkout-error={visibleErrors.fullName ? 'true' : undefined}
          />
          {visibleErrors.fullName && (
            <p id="chk-fullname-error" className="luxury-field-error" role="alert">
              {visibleErrors.fullName}
            </p>
          )}
        </div>

        <div className="line-input-wrap">
          <label htmlFor="chk-phone" className="checkout-field-label">
            رقم الموبايل <span className="required-star" aria-hidden="true">*</span>
          </label>
          <input
            type="tel"
            id="chk-phone"
            aria-label="رقم الموبايل"
            aria-required="true"
            aria-describedby={visibleErrors.phone ? 'chk-phone-error' : undefined}
            maxLength={50}
            className="line-input"
            placeholder="01XXXXXXXXX"
            inputMode="tel"
            autoComplete="tel"
            enterKeyHint="next"
            dir="ltr"
            value={form.phone}
            onChange={(e) => set('phone', e.target.value)}
            onBlur={() => blur('phone')}
            aria-invalid={!!visibleErrors.phone}
            data-checkout-error={visibleErrors.phone ? 'true' : undefined}
          />
          {visibleErrors.phone && (
            <p id="chk-phone-error" className="luxury-field-error" role="alert">
              {visibleErrors.phone}
            </p>
          )}
        </div>

        <div className="line-input-wrap">
          <label htmlFor="chk-email" className="checkout-field-label">
            البريد الإلكتروني <span className="optional-tag">(اختياري)</span>
          </label>
          <input
            type="email"
            id="chk-email"
            aria-label="البريد الإلكتروني"
            aria-describedby={visibleErrors.email ? 'chk-email-error' : undefined}
            maxLength={255}
            className="line-input"
            placeholder="البريد الإلكتروني (اختياري)"
            inputMode="email"
            autoComplete="email"
            enterKeyHint="next"
            dir="ltr"
            value={form.email}
            onChange={(e) => set('email', e.target.value)}
            onBlur={() => blur('email')}
            aria-invalid={!!visibleErrors.email}
            data-checkout-error={visibleErrors.email ? 'true' : undefined}
          />
          {visibleErrors.email && (
            <p id="chk-email-error" className="luxury-field-error" role="alert">
              {visibleErrors.email}
            </p>
          )}
        </div>
      </div>
    </div>
  );
}