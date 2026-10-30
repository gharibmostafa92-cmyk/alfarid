'use client';

import React from 'react';
import type { CheckoutVM } from './useCheckout';

export function ShippingSection({ vm }: { vm: CheckoutVM }) {
  const { form, set, blur, visibleErrors, cities, savedAddresses, applySavedAddress } = vm;
  return (
    <div className="checkout-block" id="checkout-section-shipping">
      <div className="checkout-block-header">
        <div>
          <h2 className="checkout-section-heading">Shipping</h2>
          <p className="checkout-section-subtitle">عنوان التوصيل بالتفصيل لضمان سرعة الوصول</p>
        </div>
      </div>

      <h3 className="checkout-subheading">Shipping Information</h3>

      {savedAddresses && savedAddresses.length > 0 && (
        <div className="saved-addresses-bar" role="region" aria-label="العناوين المحفوظة">
          <span className="saved-addresses-label">عناوينك المحفوظة:</span>
          <div className="saved-addresses-pills">
            {savedAddresses.map((addr) => {
              const active =
                form.cityId === addr.city_id && form.street === addr.address_line;
              return (
                <button
                  key={addr.id}
                  type="button"
                  className={`saved-address-pill${active ? ' is-active' : ''}`}
                  onClick={() => applySavedAddress(addr)}
                >
                  <i className="fa-solid fa-location-dot" aria-hidden="true" />
                  <span>{addr.label || addr.address_line}</span>
                </button>
              );
            })}
          </div>
        </div>
      )}

      {vm.citiesError && (
        <div className="checkout-inline-alert" role="alert">
          <span>تعذّر تحميل المدن.</span>
          <button type="button" className="bag-remove-btn" onClick={vm.loadCities}>
            إعادة المحاولة
          </button>
        </div>
      )}

      <div className="checkout-fields-grid">
        <div className="line-input-wrap">
          <label htmlFor="chk-city" className="checkout-field-label">
            المدينة / المحافظة <span className="required-star" aria-hidden="true">*</span>
          </label>
          {cities === null ? (
            <div className="mega-skel-link" aria-hidden="true" style={{ height: 48, borderRadius: 10 }} />
          ) : (
            <select
              id="chk-city"
              aria-label="المدينة"
              aria-required="true"
              aria-describedby={visibleErrors.cityId ? 'chk-city-error' : undefined}
              className="line-input"
              value={form.cityId ?? ''}
              onChange={(e) =>
                set('cityId', e.target.value === '' ? null : Number(e.target.value))
              }
              onBlur={() => blur('cityId')}
              aria-invalid={!!visibleErrors.cityId}
              data-checkout-error={visibleErrors.cityId ? 'true' : undefined}
            >
              <option value="">اختار المدينة</option>
              {cities.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name_ar}
                  {c.name_en ? ` — ${c.name_en}` : ''}
                </option>
              ))}
            </select>
          )}
          {visibleErrors.cityId && (
            <p id="chk-city-error" className="luxury-field-error" role="alert">
              {visibleErrors.cityId}
            </p>
          )}
        </div>

        <div className="line-input-wrap">
          <label htmlFor="chk-street" className="checkout-field-label">
            الشارع والمنطقة <span className="required-star" aria-hidden="true">*</span>
          </label>
          <input
            type="text"
            id="chk-street"
            aria-label="عنوان الشحن"
            aria-required="true"
            aria-describedby={visibleErrors.street ? 'chk-street-error' : undefined}
            className="line-input"
            placeholder="الشارع، الحي، أو أقرب علامة مميزة"
            autoComplete="street-address"
            enterKeyHint="next"
            value={form.street}
            onChange={(e) => set('street', e.target.value)}
            onBlur={() => blur('street')}
            aria-invalid={!!visibleErrors.street}
            data-checkout-error={visibleErrors.street ? 'true' : undefined}
          />
          {visibleErrors.street && (
            <p id="chk-street-error" className="luxury-field-error" role="alert">
              {visibleErrors.street}
            </p>
          )}
        </div>

        {/* Compact 3-column sub-grid on mobile/tablet to minimize vertical scrolling */}
        <div className="checkout-address-subgrid checkout-field-full">
          <div className="line-input-wrap">
            <label htmlFor="chk-building" className="checkout-field-label">
              العمارة <span className="optional-tag">(اختياري)</span>
            </label>
            <input
              type="text"
              id="chk-building"
              aria-label="رقم العمارة"
              className="line-input"
              placeholder="رقم العمارة (اختياري)"
              inputMode="numeric"
              enterKeyHint="next"
              value={form.building}
              onChange={(e) => set('building', e.target.value)}
              onBlur={() => blur('building')}
            />
          </div>

          <div className="line-input-wrap">
            <label htmlFor="chk-floor" className="checkout-field-label">
              الدور <span className="optional-tag">(اختياري)</span>
            </label>
            <input
              type="text"
              id="chk-floor"
              aria-label="الدور"
              className="line-input"
              placeholder="الدور (اختياري)"
              inputMode="numeric"
              enterKeyHint="next"
              value={form.floor}
              onChange={(e) => set('floor', e.target.value)}
              onBlur={() => blur('floor')}
            />
          </div>

          <div className="line-input-wrap">
            <label htmlFor="chk-apartment" className="checkout-field-label">
              الشقة <span className="optional-tag">(اختياري)</span>
            </label>
            <input
              type="text"
              id="chk-apartment"
              aria-label="الشقة"
              className="line-input"
              placeholder="الشقة (اختياري)"
              inputMode="numeric"
              enterKeyHint="next"
              value={form.apartment}
              onChange={(e) => set('apartment', e.target.value)}
              onBlur={() => blur('apartment')}
            />
          </div>
        </div>

        <div className="line-input-wrap checkout-field-full">
          <label htmlFor="chk-notes" className="checkout-field-label">
            ملاحظات للمندوب <span className="optional-tag">(اختياري)</span>
          </label>
          <input
            type="text"
            id="chk-notes"
            aria-label="ملاحظات للشحن"
            className="line-input"
            placeholder="ملاحظات للشحن (اختياري) — مثل: بجوار صيدلية، اتصل قبل الوصول"
            enterKeyHint="done"
            value={form.notes}
            onChange={(e) => set('notes', e.target.value)}
            onBlur={() => blur('notes')}
          />
        </div>
      </div>
    </div>
  );
}