import React, { useEffect, useState } from 'react';
import { CustomerBar } from './CustomerBar';
import { CartLine } from './CartLine';
import { Icon } from '../ui/Icons';
import { cx, egp, fmt, Kbd, toAsciiNum } from '../ui/SharedUI';
import type { PosCartLine, Customer, CashierUser, QuoteResult, PosPrepSession } from '../../types/pos.types';
import { computeCartTotals, parseNonNegativeAmount } from '../../lib/money';

interface CartProps {
  cart: PosCartLine[];
  setCart: React.Dispatch<React.SetStateAction<PosCartLine[]>>;
  customer: Customer | null;
  setCustomer: (c: Customer | null) => void;
  discount: number;
  setDiscount: (d: number) => void;
  onHold: () => void;
  onHeld: () => void;
  heldCount: number;
  onPay: () => void;
  onClear: () => void;
  lastAdded: { id: number; k: number } | null;
  toast: (msg: string, tone?: 'ok' | 'bad') => void;
  cashier?: CashierUser | null;
  /** Latest server quote: the source of truth for totals once available. */
  quote?: QuoteResult | null;
  isQuoting?: boolean;
  maxDiscountPercent?: number;
  prepSession?: PosPrepSession | null;
  cartMode?: 'cart' | 'prep';
  onCartModeChange?: (mode: 'cart' | 'prep') => void;
  onPrepQty?: (productVariantId: number, qty: number) => void;
  onPrepMarkAll?: () => void;
  onPrepConfirm?: () => Promise<void>;
  onPrepExit?: () => void;
  isPrepSubmitting?: boolean;
}

export function Cart({
  cart,
  setCart,
  customer,
  setCustomer,
  discount,
  setDiscount,
  onHold,
  onHeld,
  heldCount,
  onPay,
  onClear,
  lastAdded,
  toast,
  cashier,
  quote,
  isQuoting,
  maxDiscountPercent = 100,
  prepSession,
  cartMode = 'cart',
  onCartModeChange,
  onPrepQty,
  onPrepMarkAll,
  onPrepConfirm,
  onPrepExit,
  isPrepSubmitting = false,
}: CartProps) {
  const canOverride = cashier?.perms?.includes('pos.override_price') ?? false;
  const canDiscount = cashier?.perms?.includes('pos.discount') ?? false;
  const totals = computeCartTotals(cart, discount, maxDiscountPercent);
  const { subtotal, pricedTotal, lineAdj, discount: effectiveDiscount, total: localTotal } = totals;
  // The screen total is the server's; the local figure only shows until the first quote lands.
  const total = quote ? quote.grand_total : localTotal;
  const items = cart.reduce((s, l) => s + l.qty, 0);
  const [discOpen, setDiscOpen] = useState(false);
  const [discVal, setDiscVal] = useState('');

  useEffect(() => {
    if (discount !== effectiveDiscount) {
      setDiscount(effectiveDiscount);
    }
  }, [discount, effectiveDiscount, setDiscount]);

  const applyDiscount = () => {
    const wanted = parseNonNegativeAmount(discVal, 0);
    const clamped = Math.min(wanted, pricedTotal, totals.cap);
    if (wanted > totals.cap) {
      toast(`الحد الأقصى للخصم ${maxDiscountPercent}% (${egp(totals.cap)} ج)`, 'bad');
    } else if (wanted > pricedTotal) {
      toast(`لا يمكن أن يتجاوز الخصم إجمالي الفاتورة (${egp(pricedTotal)} ج)`, 'bad');
    }
    setDiscount(clamped);
    setDiscOpen(false);
  };

  const showPrep = Boolean(prepSession && cartMode === 'prep');
  const pickedTotal = prepSession ? prepSession.items.reduce((sum, i) => sum + i.pickedQty, 0) : 0;
  const expectedTotal = prepSession ? prepSession.items.reduce((sum, i) => sum + i.expectedQty, 0) : 0;
  const allPrepPicked = Boolean(prepSession && prepSession.items.length > 0 && prepSession.items.every((i) => i.pickedQty === i.expectedQty));
  const prepPct = expectedTotal > 0 ? Math.min(100, Math.round((pickedTotal / expectedTotal) * 100)) : 0;

  return (
    <aside className="cart" aria-hidden={cart.length === 0 && !prepSession ? true : undefined}>
      {prepSession && (
        <div className="pos-prep-switcher" role="tablist" aria-label="وضع السلة">
          <button
            type="button"
            role="tab"
            aria-selected={cartMode === 'prep'}
            className="pos-prep-tab"
            onClick={() => onCartModeChange?.('prep')}
          >
            <Icon n="box" s={14} />
            <span>تجهيز #{prepSession.orderNumber}</span>
            <span className="badge-count num">{fmt(pickedTotal)}/{fmt(expectedTotal)}</span>
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={cartMode === 'cart'}
            className="pos-prep-tab"
            onClick={() => onCartModeChange?.('cart')}
          >
            <Icon n="pos" s={14} />
            <span>الفاتورة</span>
            {items > 0 && <span className="badge-count num">{fmt(items)}</span>}
          </button>
        </div>
      )}

      {showPrep && prepSession ? (
        <div className="pos-prep-panel">
          <div className="pos-prep-head">
            <div className="col" style={{ gap: 2 }}>
              <div className="row gap-2">
                <h3 className="fs-15 fw-7">تجهيز طلب الفرع</h3>
                <bdi className="ltr mono badge b-acc">{prepSession.orderNumber}</bdi>
              </div>
              {prepSession.customerName && (
                <span className="fs-12 t-2">{prepSession.customerName}</span>
              )}
            </div>
            <span className={cx('badge', allPrepPicked ? 'b-ok' : 'b-info', 'num')}>
              {fmt(pickedTotal)} / {fmt(expectedTotal)} قطعة
            </span>
          </div>

          <div className="pos-prep-progress" aria-hidden="true">
            <div className={cx('pos-prep-progress-bar', allPrepPicked && 'is-complete')} style={{ width: `${prepPct}%` }} />
          </div>

          <p className="fs-12 t-3 pos-prep-hint">
            امسح باركود الصنف أو اضغط ✓ لتأكيد الكمية المطلوبة من المخزون المحجوز.
          </p>

          <div className="cart-list pos-prep-list">
            {prepSession.items.map((item) => {
              const done = item.pickedQty === item.expectedQty;
              return (
                <div key={item.productVariantId} className={cx('pos-prep-row', done && 'is-picked')}>
                  <div className="grow col" style={{ gap: 2, minWidth: 0 }}>
                    <div className="fs-13 fw-6 truncate">{item.name}</div>
                    <div className="fs-11 t-3 ltr" style={{ textAlign: 'right' }}>
                      <span className="mono">{item.sku}</span>
                      {item.barcode ? <> · <span className="mono">{item.barcode}</span></> : null}
                    </div>
                  </div>
                  <div className="pos-prep-controls">
                    <button
                      type="button"
                      className="icon-btn sm"
                      disabled={item.pickedQty <= 0 || isPrepSubmitting}
                      onClick={() => onPrepQty?.(item.productVariantId, item.pickedQty - 1)}
                      aria-label={`إنقاص ${item.name}`}
                    >
                      <Icon n="minus" s={13} />
                    </button>
                    <span className={cx('pos-prep-qty num', done && 't-ok')}>
                      {fmt(item.pickedQty)} / {fmt(item.expectedQty)}
                    </span>
                    <button
                      type="button"
                      className="icon-btn sm"
                      disabled={item.pickedQty >= item.expectedQty || isPrepSubmitting}
                      onClick={() => onPrepQty?.(item.productVariantId, item.pickedQty + 1)}
                      aria-label={`زيادة ${item.name}`}
                    >
                      <Icon n="plus" s={13} />
                    </button>
                    <button
                      type="button"
                      className={cx('btn btn-sm', done ? 'btn-primary' : 'btn-secondary')}
                      disabled={isPrepSubmitting}
                      onClick={() => onPrepQty?.(item.productVariantId, done ? 0 : item.expectedQty)}
                      title={done ? 'تصفير البند' : 'تجهيز كامل الكمية'}
                      aria-label={`تأكيد ${item.name}`}
                    >
                      <Icon n="check" s={13} />
                    </button>
                  </div>
                </div>
              );
            })}
          </div>

          <div className="pos-prep-foot">
            {!allPrepPicked && onPrepMarkAll && (
              <button
                type="button"
                className="btn btn-secondary btn-sm"
                disabled={isPrepSubmitting}
                onClick={onPrepMarkAll}
              >
                <Icon n="check" s={14} />
                تحديد الكل كمجهز
              </button>
            )}
            <button
              type="button"
              className="btn btn-accent pay-btn"
              disabled={!allPrepPicked || isPrepSubmitting}
              onClick={() => void onPrepConfirm?.()}
            >
              <span>{isPrepSubmitting ? 'جارٍ تأكيد التجهيز…' : 'إنهاء وتأكيد التجهيز'}</span>
              <Icon n="check" s={17} />
            </button>
            <button
              type="button"
              className="btn btn-ghost btn-sm"
              disabled={isPrepSubmitting}
              onClick={onPrepExit}
            >
              إغلاق وضع التجهيز
            </button>
          </div>
        </div>
      ) : (
        <>
      <CustomerBar customer={customer} setCustomer={setCustomer} toast={toast} />
      <div className="cart-head">
        <div className="row gap-2">
          <h3 className="fs-16">الفاتورة الحالية</h3>
          {items > 0 && <span className="badge b-neutral num">{fmt(items)} قطعة</span>}
        </div>
        {cart.length > 0 && (
          <button className="btn btn-ghost btn-sm" onClick={onClear}>
            <Icon n="trash" s={14} />
            مسح
          </button>
        )}
      </div>
      <div className="cart-list">
        {cart.length === 0 ? (
          <div className="empty" style={{ paddingTop: 56 }}>
            <div className="empty-ic">
              <Icon n="barcode" s={24} />
            </div>
            <h4>الفاتورة فارغة</h4>
            <p>امسح باركود صنف أو اضغط على أي صنف من الكتالوج لإضافته.</p>
            <div className="row gap-2 fs-12 t-3" style={{ marginTop: 8 }}>
              <Kbd>F2</Kbd> بحث <Kbd>F6</Kbd> المعلقة
              {cashier?.perms?.includes('pos.sales.refund') && <><Kbd>F8</Kbd> مرتجع</>}
            </div>
          </div>
        ) : (
          cart.map((l) => (
            <CartLine
              key={l.id}
              l={l}
              canOverride={canOverride}
              flash={lastAdded?.id === l.id ? lastAdded.k : null}
              onQty={(q) =>
                setCart((c) => c.map((x) => (x.id === l.id ? { ...x, qty: q } : x)))
              }
              onPrice={(pr) =>
                setCart((c) =>
                  c.map((x) =>
                    x.id === l.id ? { ...x, price: pr, manualPrice: true } : x,
                  ),
                )
              }
              onRemove={() => setCart((c) => c.filter((x) => x.id !== l.id))}
            />
          ))
        )}
      </div>
      <div className="totals">
        <div className="r">
          <span>المجموع الفرعي</span>
          <b>{egp(subtotal)}</b>
        </div>
        {/* F-16: egp already carries the sign — never prepend another minus. */}
        {lineAdj !== 0 && (
          <div className="r">
            <span>تعديلات الأسعار</span>
            <b className="t-warn">{lineAdj > 0 ? '−' + egp(lineAdj) : '+' + egp(Math.abs(lineAdj))}</b>
          </div>
        )}
        <div className="r">
          <span className="row gap-2">
            الخصم{' '}
            {discOpen ? (
              <span className="row gap-1">
                <input
                  className="price-input"
                  autoFocus
                  value={discVal}
                  onChange={(e) => setDiscVal(toAsciiNum(e.target.value))}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') applyDiscount();
                  }}
                  onBlur={applyDiscount}
                  placeholder="0"
                />
              </span>
            ) : (
              <button
                className="t-acc fs-12 fw-6"
                onClick={() => {
                  setDiscVal(effectiveDiscount ? String(effectiveDiscount) : '');
                  setDiscOpen(true);
                }}
                disabled={!cart.length || !canDiscount}
                title={canDiscount ? undefined : 'تحتاج صلاحية الخصم (pos.discount)'}
              >
                {effectiveDiscount ? 'تعديل' : '+ إضافة'}
              </button>
            )}
            {!canDiscount && <span className="fs-12 t-3">تحتاج صلاحية</span>}
          </span>
          <b className={effectiveDiscount ? 't-ok' : ''}>{effectiveDiscount ? '−' + egp(effectiveDiscount) : '٠'}</b>
        </div>
        <div className="grand">
          <span className="fs-13 t-2">الإجمالي</span>
          <span className={isQuoting ? 'v quoting' : 'v'}>
            {egp(total)}
            <small>ج.م</small>
          </span>
        </div>
      </div>
      <div className="actions">
        <button className="btn btn-secondary" disabled={!cart.length} onClick={onHold}>
          <Icon n="pause" s={15} />
          تعليق
        </button>
        <button className="btn btn-secondary" onClick={onHeld}>
          <Icon n="inbox" s={16} />
          المعلقة
          {heldCount > 0 && <span className="badge-count">{fmt(heldCount)}</span>}
          <Kbd>F6</Kbd>
        </button>
        <button
          className="btn btn-danger"
          disabled={!cart.length}
          onClick={onClear}
          aria-label="إلغاء ومسح الفاتورة"
          title="إلغاء ومسح الفاتورة"
        >
          <Icon n="x" s={18} />
        </button>
      </div>
      <button className="pay-btn" disabled={!cart.length} onClick={onPay}>
        <span className="row gap-2">
          إتمام الدفع{' '}
          <span
            className="kbd"
            style={{
              background: 'rgba(255,255,255,.15)',
              borderColor: 'rgba(255,255,255,.35)',
              color: '#fff',
            }}
          >
            Space
          </span>
        </span>
        <span className="amt num">{egp(total)} ج</span>
      </button>
        </>
      )}
    </aside>
  );
}
