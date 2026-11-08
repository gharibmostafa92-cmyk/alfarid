import React, { useState } from 'react';
import { CustomerBar } from './CustomerBar';
import { CartLine } from './CartLine';
import { Icon } from '../ui/Icons';
import { egp, fmt, Kbd, toAsciiNum } from '../ui/SharedUI';
import type { PosCartLine, Customer, CashierUser, QuoteResult } from '../../types/pos.types';
import { maxDiscountAmount, toCents, fromCents } from '../../lib/money';

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
}: CartProps) {
  const canOverride = cashier?.perms?.includes('pos.override_price') ?? true;
  const canDiscount = cashier?.perms?.includes('pos.discount') ?? true;
  const subtotal = cart.reduce((s, l) => s + l.listPrice * l.qty, 0);
  const lineAdj = cart.reduce((s, l) => s + (l.listPrice - l.price) * l.qty, 0);
  const localTotal = Math.max(0, subtotal - lineAdj - discount);
  // The screen total is the server's; the local figure only shows until the first quote lands.
  const total = quote ? quote.grand_total : localTotal;
  const items = cart.reduce((s, l) => s + l.qty, 0);
  const [discOpen, setDiscOpen] = useState(false);
  const [discVal, setDiscVal] = useState('');

  const applyDiscount = () => {
    const cap = fromCents(maxDiscountAmount(toCents(subtotal - lineAdj), maxDiscountPercent));
    const wanted = Math.min(Number(discVal || 0), subtotal);
    if (wanted > cap) {
      toast(`الحد الأقصى للخصم ${maxDiscountPercent}% (${egp(cap)} ج)`, 'bad');
    }
    setDiscount(Math.min(wanted, cap));
    setDiscOpen(false);
  };

  return (
    <aside className="cart">
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
              <Kbd>F2</Kbd> بحث <Kbd>F6</Kbd> المعلقة <Kbd>F8</Kbd> مرتجع
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
                    x.id === l.id ? { ...x, price: pr, manualPrice: pr !== x.listPrice } : x,
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
        {lineAdj !== 0 && (
          <div className="r">
            <span>تعديلات الأسعار</span>
            <b className="t-warn">−{egp(lineAdj)}</b>
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
                  setDiscVal(discount ? String(discount) : '');
                  setDiscOpen(true);
                }}
                disabled={!cart.length || !canDiscount}
                title={canDiscount ? undefined : 'تحتاج صلاحية الخصم (pos.discount)'}
              >
                {discount ? 'تعديل' : '+ إضافة'}
              </button>
            )}
            {!canDiscount && <span className="fs-12 t-3">تحتاج صلاحية</span>}
          </span>
          <b className={discount ? 't-ok' : ''}>{discount ? '−' + egp(discount) : '٠'}</b>
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
    </aside>
  );
}
