import React, { useState } from 'react';
import { CustomerBar } from './CustomerBar';
import { CartLine } from './CartLine';
import { Icon } from '../ui/Icons';
import { egp, fmt, Kbd, toAsciiNum } from '../ui/SharedUI';
import type { PosCartLine, Customer, CashierUser } from '../../types/pos.types';

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
}: CartProps) {
  const canOverride = cashier?.perms?.includes('pos.override_price') ?? true;
  const subtotal = cart.reduce((s, l) => s + l.listPrice * l.qty, 0);
  const lineAdj = cart.reduce((s, l) => s + (l.listPrice - l.price) * l.qty, 0);
  const total = Math.max(0, subtotal - lineAdj - discount);
  const items = cart.reduce((s, l) => s + l.qty, 0);
  const [discOpen, setDiscOpen] = useState(false);
  const [discVal, setDiscVal] = useState('');

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
                setCart((c) => c.map((x) => (x.id === l.id ? { ...x, price: pr } : x)))
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
                    if (e.key === 'Enter') {
                      setDiscount(Math.min(Number(discVal || 0), subtotal));
                      setDiscOpen(false);
                    }
                  }}
                  onBlur={() => {
                    setDiscount(Math.min(Number(discVal || 0), subtotal));
                    setDiscOpen(false);
                  }}
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
                disabled={!cart.length}
              >
                {discount ? 'تعديل' : '+ إضافة'}
              </button>
            )}
          </span>
          <b className={discount ? 't-ok' : ''}>{discount ? '−' + egp(discount) : '٠'}</b>
        </div>
        <div className="grand">
          <span className="fs-13 t-2">الإجمالي</span>
          <span className="v">
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
