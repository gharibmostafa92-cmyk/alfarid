'use client';

import React from 'react';
import type { CheckoutVM } from './useCheckout';

export function ShippingSection({ vm }: { vm: CheckoutVM }) {
  const { form, set, blur, visibleErrors, cities } = vm;
  return (
    <div className="checkout-block">
      <div className="checkout-block-header">
        <h2 className="checkout-section-heading">Shipping</h2>
      </div>

      <h3 className="checkout-subheading">Shipping Information</h3>
      {vm.citiesError && <p role="alert">تعذّر تحميل المدن. <button type="button" className="bag-remove-btn" onClick={vm.loadCities}>إعادة المحاولة</button></p>}
      <div className="checkout-fields-grid">
        <div className="line-input-wrap">
          {cities === null ? (
            <div className="mega-skel-link" aria-hidden="true" style={{ height: 44 }} />
          ) : (
            <select
              id="chk-city"
              aria-label="المدينة"
              className="line-input"
              value={form.cityId ?? ''}
              onChange={(e) => set('cityId', e.target.value === '' ? null : Number(e.target.value))}
              onBlur={() => blur('cityId')}
              aria-invalid={!!visibleErrors.cityId}
              data-checkout-error={visibleErrors.cityId ? 'true' : undefined}
            >
              <option value="">اختار المدينة</option>
              {cities.map((c) => (
                <option key={c.id} value={c.id}>{c.name_ar}{c.name_en ? ` — ${c.name_en}` : ''}</option>
              ))}
            </select>
          )}
          {visibleErrors.cityId && <p className="luxury-field-error" role="alert">{visibleErrors.cityId}</p>}
        </div>
        <div className="line-input-wrap">
          <input
            type="text"
            id="chk-street"
            aria-label="عنوان الشحن"
            className="line-input"
            placeholder="الشارع"
            autoComplete="street-address"
            value={form.street}
            onChange={(e) => set('street', e.target.value)}
            onBlur={() => blur('street')}
            aria-invalid={!!visibleErrors.street}
            data-checkout-error={visibleErrors.street ? 'true' : undefined}
          />
          {visibleErrors.street && <p className="luxury-field-error" role="alert">{visibleErrors.street}</p>}
        </div>
        <div className="line-input-wrap">
          <input
            type="text"
            id="chk-building"
            className="line-input"
            placeholder="رقم العمارة (اختياري)"
            inputMode="numeric"
            value={form.building}
            onChange={(e) => set('building', e.target.value)}
            onBlur={() => blur('building')}
          />
        </div>
        <div className="line-input-wrap">
          <input
            type="text"
            id="chk-floor"
            className="line-input"
            placeholder="الدور (اختياري)"
            inputMode="numeric"
            value={form.floor}
            onChange={(e) => set('floor', e.target.value)}
            onBlur={() => blur('floor')}
          />
        </div>
        <div className="line-input-wrap">
          <input
            type="text"
            id="chk-apartment"
            className="line-input"
            placeholder="الشقة (اختياري)"
            inputMode="numeric"
            value={form.apartment}
            onChange={(e) => set('apartment', e.target.value)}
            onBlur={() => blur('apartment')}
          />
        </div>
        <div className="line-input-wrap">
          <input
            type="text"
            id="chk-notes"
            className="line-input"
            placeholder="ملاحظات للشحن (اختياري)"
            value={form.notes}
            onChange={(e) => set('notes', e.target.value)}
            onBlur={() => blur('notes')}
          />
        </div>
      </div>
    </div>
  );
}
