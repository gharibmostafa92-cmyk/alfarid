'use client';

import React, { useRef, useState } from 'react';
import { useCartStore } from '@/stores/cart-store';
import Link from 'next/link';
import { formatStorePrice } from '@alfarid/utils';
import type { CheckoutVM } from './useCheckout';
import { StoreProductImage } from '../StoreProductImage';
import { StoreImage } from '@/components/StoreImage';

export interface SuggestionCard {
  slug: string;
  name: string;
  img: string | null;
  priceLabel: string;
}

export function OrderSummary({
  vm,
  suggestions,
}: {
  vm: CheckoutVM;
  suggestions: SuggestionCard[];
}) {
  const {
    items,
    cartLoading,
    totals,
    couponCode,
    quote,
    updateQuantity,
    removeItem,
    applyCoupon,
    removeCoupon,
    promoInput,
    setPromoInput,
    setBanner,
  } = vm;
  const [overrides, setOverrides] = useState<Record<number, number>>({});
  const [promoBusy, setPromoBusy] = useState(false);
  const changing = useRef(false);

  const preview = quote?.preview ?? null;
  const shownItemsTotal = preview?.items_total ?? totals?.items_total ?? 0;
  const shownCoupon = preview?.coupon_discount ?? totals?.coupon_discount ?? 0;
  const shownShipping = preview?.shipping ?? 0;
  const shownCod = preview?.cod_fee ?? 0;
  const shownTax = preview?.tax ?? 0;
  const shownGrand = preview?.grand_total;

  const totalCount = items.reduce((n, i) => n + i.quantity, 0);

  const changeQty = async (id: number, current: number, delta: number) => {
    const item = items.find((i) => i.id === id);
    if (!item || changing.current || vm.cartBusy || !item.priced) return;
    const next = current + delta;
    if (next < 1 || next > item.available) return;
    changing.current = true;
    // Optimistic update — revert to the server value on failure.
    setOverrides((o) => ({ ...o, [id]: next }));
    const ok = await updateQuantity(id, next);
    changing.current = false;
    setOverrides((o) => {
      const copy = { ...o };
      delete copy[id];
      return copy;
    });
    if (!ok) setBanner({ kind: 'error', text: 'مقدرناش نحدّث الكمية، حاول تاني.' });
  };

  const submitPromo = async () => {
    const code = promoInput.trim();
    if (!code || promoBusy) return;
    setPromoBusy(true);
    const ok = await applyCoupon(code);
    setPromoBusy(false);
    if (!ok) {
      setBanner({
        kind: 'error',
        text: useCartStore.getState().error || 'كود الخصم غير صالح أو منتهي.',
      });
    } else {
      setPromoInput('');
    }
  };

  if (!cartLoading && items.length === 0) {
    return (
      <div className="bag-summary-panel" id="checkout-order-summary">
        <h2 className="checkout-section-heading">Shopping Bag (0)</h2>
        <div className="checkout-empty-bag">
          <i className="fa-solid fa-bag-shopping" aria-hidden="true" />
          <p>سلتك فاضية. اكتشف المجموعة واختار اللي يعجبك.</p>
          <Link href="/shop" className="btn-place-order">
            تسوّق الآن
          </Link>
        </div>
        {suggestions.length > 0 && (
          <div className="checkout-suggestions">
            <h3 className="checkout-subheading">مقترحات ليك</h3>
            <div className="checkout-suggestions-grid">
              {suggestions.slice(0, 4).map((s) => (
                <Link
                  key={s.slug}
                  href={`/shop?product=${encodeURIComponent(s.slug)}`}
                  className="checkout-suggestion-card"
                >
                  {s.img ? (
                    <StoreImage src={s.img} alt={s.name} loading="lazy" />
                  ) : (
                    <span className="checkout-suggestion-ph" aria-hidden="true" />
                  )}
                  <span className="checkout-suggestion-name">{s.name}</span>
                  <span className="checkout-suggestion-price">{s.priceLabel}</span>
                </Link>
              ))}
            </div>
          </div>
        )}
      </div>
    );
  }

  return (
    <div className="bag-summary-panel" id="checkout-order-summary">
      <h2 className="checkout-section-heading" id="shopping-bag-heading">
        Shopping Bag ({totalCount})
      </h2>

      {cartLoading && items.length === 0 ? (
        <div className="bag-items-list" aria-hidden="true">
          {[1, 2, 3].map((i) => (
            <div key={i} className="bag-item-row">
              <div className="mega-spotlight-skel-img" style={{ width: 72, height: 90, borderRadius: 10 }} />
              <div style={{ flex: 1 }}>
                <div className="mega-skel-title" />
                <div className="mega-skel-link" />
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div className="bag-items-list" id="bag-items-container">
          {items.map((item) => {
            const qty = overrides[item.id] ?? item.quantity;
            const atCap = qty >= item.available;
            return (
              <div
                key={item.id}
                className={`bag-item-row${item.priced ? '' : ' is-unavailable'}`}
                data-bag-id={item.id}
              >
                <div className="bag-item-thumb">
                  <StoreProductImage src={item.image} alt={item.name} />
                </div>

                <div className="bag-item-info">
                  <h4 className="bag-item-title">
                    {item.slug ? (
                      <Link href={`/shop?product=${encodeURIComponent(item.slug)}`}>
                        {item.name}
                      </Link>
                    ) : (
                      item.name
                    )}
                  </h4>
                  <div className="bag-item-meta">
                    {item.color && (
                      <span className="bag-meta-pair">
                        <span className="meta-label">Color:</span>
                        <span className="meta-val">{item.color}</span>
                      </span>
                    )}
                    {item.size && (
                      <span className="bag-meta-pair">
                        <span className="meta-label">Size:</span>
                        <span className="meta-val">{item.size}</span>
                      </span>
                    )}
                    <div className="bag-qty-control-row">
                      <span className="meta-label">Quantity:</span>
                      <span className="meta-val qty-stepper">
                        <button
                          type="button"
                          className="qty-btn"
                          onClick={() => changeQty(item.id, qty, -1)}
                          aria-label="Decrease quantity"
                          disabled={
                            qty <= 1 ||
                            vm.cartBusy ||
                            overrides[item.id] !== undefined ||
                            !item.priced
                          }
                        >
                          -
                        </button>
                        <span className="qty-count-display" aria-live="polite">
                          {qty}
                        </span>
                        <button
                          type="button"
                          className="qty-btn"
                          onClick={() => changeQty(item.id, qty, 1)}
                          aria-label="Increase quantity"
                          disabled={
                            atCap ||
                            vm.cartBusy ||
                            overrides[item.id] !== undefined ||
                            !item.priced
                          }
                          title={atCap ? 'أقصى كمية متاحة' : undefined}
                        >
                          +
                        </button>
                      </span>
                    </div>
                  </div>
                  {!item.priced && (
                    <p className="bag-item-unavailable" role="alert">
                      غير متاح{' '}
                      <button
                        type="button"
                        className="bag-remove-btn"
                        onClick={() => removeItem(item.id)}
                      >
                        حذف
                      </button>
                    </p>
                  )}
                </div>

                <div className="bag-item-right">
                  <span className="bag-item-price" aria-live="polite">
                    {overrides[item.id] !== undefined
                      ? 'جاري التحديث...'
                      : item.priced
                        ? formatStorePrice(item.lineTotal)
                        : 'غير متاح'}
                  </span>
                  {item.priced && (
                    <button
                      type="button"
                      className="bag-remove-btn"
                      onClick={() => removeItem(item.id)}
                      title="Remove item"
                      aria-label={`حذف ${item.name}`}
                    >
                      <i className="fa-regular fa-trash-can" aria-hidden="true" /> Remove
                    </button>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      )}

      {couponCode ? (
        <div className="promo-chip-row">
          <span className="promo-chip">
            <i className="fa-solid fa-tag" aria-hidden="true" />
            <span>{couponCode}</span>
            <button
              type="button"
              onClick={() => removeCoupon()}
              aria-label="إزالة الكوبون"
            >
              ✕
            </button>
          </span>
        </div>
      ) : (
        <div className="promo-row">
          <div className="line-input-wrap" style={{ flex: 1 }}>
            <label htmlFor="promo-code-input" className="sr-only">
              كود الخصم
            </label>
            <input
              type="text"
              className="line-input"
              id="promo-code-input"
              placeholder="كود الخصم"
              dir="ltr"
              enterKeyHint="send"
              value={promoInput}
              onChange={(e) => setPromoInput(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault();
                  submitPromo();
                }
              }}
            />
          </div>
          <button
            type="button"
            className="btn-apply-promo"
            id="apply-promo-btn"
            onClick={submitPromo}
            disabled={promoBusy || !promoInput.trim()}
          >
            {promoBusy ? '...' : 'APPLY'}
          </button>
        </div>
      )}

      <div className="bag-totals-breakdown">
        <div className="bag-totals-row">
          <span>Subtotal</span>
          <strong>{formatStorePrice(shownItemsTotal)}</strong>
        </div>
        {shownCoupon > 0 && (
          <div className="bag-totals-row is-discount">
            <span>Discount{couponCode ? ` (${couponCode})` : ''}</span>
            <strong>-{formatStorePrice(shownCoupon)}</strong>
          </div>
        )}
        <div className="bag-totals-row">
          <span>Shipping</span>
          <strong id="summary-shipping" aria-live="polite">
            {!preview
              ? vm.quoteLoading
                ? 'جاري الحساب...'
                : 'اختار المدينة'
              : shownShipping === 0
                ? 'Free'
                : formatStorePrice(shownShipping)}
          </strong>
        </div>
        {shownCod > 0 && (
          <div className="bag-totals-row">
            <span>COD fee</span>
            <strong>{formatStorePrice(shownCod)}</strong>
          </div>
        )}
        {shownTax > 0 && (
          <div className="bag-totals-row">
            <span>Tax</span>
            <strong>{formatStorePrice(shownTax)}</strong>
          </div>
        )}
      </div>

      <div className="bag-grand-total">
        <span>Total:</span>
        <span className="total-amount" id="summary-total" aria-live="polite">
          {shownGrand === undefined ? '—' : formatStorePrice(shownGrand)}
        </span>
      </div>
    </div>
  );
}