'use client';

import React from 'react';
import type { CheckoutVM } from './useCheckout';

export function ContactSection({ vm }: { vm: CheckoutVM }) {
  const { form, set, blur, visibleErrors } = vm;
  return (
    <div className="checkout-block">
      <div className="checkout-block-header">
        <h2 className="checkout-section-heading">Information</h2>
        {!vm.token && (
          <p className="checkout-login-prompt">
            عندك حساب؟{' '}
            <a href={`/auth/login?redirect=${encodeURIComponent('/checkout')}`}>
              سجّل دخول عشان نملالك البيانات
            </a>
          </p>
        )}
      </div>

      <h3 className="checkout-subheading">Personal Information</h3>
      <div className="checkout-fields-grid">
        <div className="line-input-wrap">
          <input
            type="text"
            id="chk-fullname"
            aria-label="الاسم بالكامل"
            maxLength={255}
            className="line-input"
            placeholder="الاسم بالكامل"
            autoComplete="name"
            value={form.fullName}
            onChange={(e) => set('fullName', e.target.value)}
            onBlur={() => blur('fullName')}
            aria-invalid={!!visibleErrors.fullName}
            data-checkout-error={visibleErrors.fullName ? 'true' : undefined}
          />
          {visibleErrors.fullName && <p className="luxury-field-error" role="alert">{visibleErrors.fullName}</p>}
        </div>
        <div className="line-input-wrap">
          <input
            type="tel"
            id="chk-phone"
            aria-label="رقم الموبايل"
            maxLength={50}
            className="line-input"
            placeholder="رقم الموبايل"
            inputMode="tel"
            autoComplete="tel"
            dir="ltr"
            value={form.phone}
            onChange={(e) => set('phone', e.target.value)}
            onBlur={() => blur('phone')}
            aria-invalid={!!visibleErrors.phone}
            data-checkout-error={visibleErrors.phone ? 'true' : undefined}
          />
          {visibleErrors.phone && <p className="luxury-field-error" role="alert">{visibleErrors.phone}</p>}
        </div>
        <div className="line-input-wrap">
          <input
            type="email"
            id="chk-email"
            aria-label="البريد الإلكتروني"
            maxLength={255}
            className="line-input"
            placeholder="البريد الإلكتروني (اختياري)"
            inputMode="email"
            autoComplete="email"
            dir="ltr"
            value={form.email}
            onChange={(e) => set('email', e.target.value)}
            onBlur={() => blur('email')}
            aria-invalid={!!visibleErrors.email}
            data-checkout-error={visibleErrors.email ? 'true' : undefined}
          />
          {visibleErrors.email && <p className="luxury-field-error" role="alert">{visibleErrors.email}</p>}
        </div>
      </div>
    </div>
  );
}
