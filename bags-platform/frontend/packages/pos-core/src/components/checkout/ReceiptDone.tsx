import React, { useRef } from 'react';
import { Icon } from '../ui/Icons';
import { egp, fmt } from '../ui/SharedUI';
import { pos } from '../../services/pos-data-source';
import { fromCents, toCents } from '../../lib/money';
import { printReceipt } from '../../lib/print-receipt';
import type { PosCartLine, Customer, Branch, CashierUser } from '../../types/pos.types';

export interface ThermalReceiptProps {
  invoiceNumber: string;
  total: number;
  method: 'cash' | 'card' | 'mixed';
  received: number;
  change: number;
  cashA: number;
  cardA: number;
  cart: PosCartLine[];
  customer: Customer | null;
  discount: number;
  notes?: string;
  branch?: Branch | null;
  cashier?: CashierUser | null;
  timestamp?: number;
}

export function ThermalReceipt({
  invoiceNumber,
  total,
  method,
  received,
  change,
  cashA,
  cardA,
  cart,
  customer,
  discount,
  notes,
  branch,
  cashier,
  timestamp,
}: ThermalReceiptProps) {
  const printedAt = new Date(timestamp ?? Date.now()).toLocaleString('ar-EG', {
    dateStyle: 'short',
    timeStyle: 'short',
  });

  return (
    <div className="receipt">
      <div style={{ textAlign: 'center' }}>
        <b className="fs-14 display">VARYA للشنط</b>
        <div style={{ color: '#6f6a76' }}>{branch?.name || 'الفرع'}</div>
        <div className="num" style={{ color: '#6f6a76' }}>
          {invoiceNumber} · {printedAt}
        </div>
      </div>
      <div className="dash" />
      {cart.map((l) => {
        const p = pos.getProductById(l.id);
        const name = l.name ?? p?.name ?? `#${l.id}`;
        return (
          <div key={l.id}>
            <div className="truncate">{name}</div>
            <div className="rr" style={{ color: '#6f6a76' }}>
              <span className="num">
                {fmt(l.qty)} × {egp(l.price)}
              </span>
              <span className="num" style={{ color: '#18151c' }}>
                {egp(fromCents(toCents(l.price) * l.qty))}
              </span>
            </div>
          </div>
        );
      })}
      <div className="dash" />
      {discount > 0 && (
        <div className="rr">
          <span>خصم</span>
          <span className="num">−{egp(discount)}</span>
        </div>
      )}
      <div className="rr" style={{ fontWeight: 700, fontSize: 14 }}>
        <span>الإجمالي</span>
        <span className="num">{egp(total)} ج</span>
      </div>
      {method === 'cash' && (
        <>
          <div className="rr">
            <span>نقدي مستلم</span>
            <span className="num">{egp(received)}</span>
          </div>
          <div className="rr">
            <span>الباقي</span>
            <span className="num">{egp(Math.max(0, change))}</span>
          </div>
        </>
      )}
      {method === 'card' && (
        <div className="rr">
          <span>بطاقة</span>
          <span className="num">{egp(total)}</span>
        </div>
      )}
      {method === 'mixed' && (
        <>
          <div className="rr">
            <span>نقدي</span>
            <span className="num">{egp(cashA)}</span>
          </div>
          <div className="rr">
            <span>بطاقة</span>
            <span className="num">{egp(cardA)}</span>
          </div>
        </>
      )}
      {customer && (
        <>
          <div className="dash" />
          <div className="rr">
            <span>العميل</span>
            <span>{customer.name}</span>
          </div>
        </>
      )}
      {notes && <div style={{ marginTop: 6, color: '#6f6a76' }}>{notes}</div>}
      <div className="dash" />
      <div style={{ textAlign: 'center', color: '#6f6a76' }}>
        الكاشير: {cashier?.name || 'كاشير'} — الاستبدال خلال {fmt(pos.getSettings()?.return_window_days ?? 30)} يومًا بالإيصال
      </div>
      <div className="bars" />
    </div>
  );
}

interface ReceiptDoneProps extends Omit<ThermalReceiptProps, 'timestamp'> {
  onNew: () => void;
}

export function ReceiptDone({
  invoiceNumber,
  total,
  method,
  received,
  change,
  cashA,
  cardA,
  cart,
  customer,
  discount,
  notes,
  onNew,
  branch,
  cashier,
}: ReceiptDoneProps) {
  const receiptPreview = useRef<HTMLDivElement>(null);

  const payment = { cash: 'نقدي', card: 'بطاقة', mixed: 'نقدي وبطاقة' }[method];

  return (
    <div className="pos-sale-done">
      <div className="pos-sale-result">
        <span className="pos-success-icon"><Icon n="check" s={24} w={2.4} /></span>
        <h2>تم تسجيل البيع بنجاح</h2>
        <p className="t-2">فاتورة <bdi className="num">{invoiceNumber}</bdi> · {payment}</p>
        <dl className="pos-sale-amounts">
          <div><dt>المبلغ المحصّل</dt><dd><bdi>{egp(total)}</bdi> ج.م</dd></div>
          {method === 'cash' && <div><dt>الباقي للعميل</dt><dd><bdi>{egp(Math.max(0, change))}</bdi> ج.م</dd></div>}
        </dl>
        <div className="pos-dialog-actions">
          <button className="btn btn-primary" autoFocus onClick={onNew}>فاتورة جديدة</button>
          <button className="btn btn-secondary" onClick={() => printReceipt(receiptPreview.current?.querySelector('.receipt') ?? null)}>
            <Icon n="printer" s={17} /> طباعة الإيصال
          </button>
        </div>
      </div>
      <details className="pos-receipt-details">
        <summary>معاينة الإيصال</summary>
        <div ref={receiptPreview} className="pos-receipt-preview">
          <ThermalReceipt {...{ invoiceNumber, total, method, received, change, cashA, cardA, cart, customer, discount, notes, branch, cashier }} />
        </div>
      </details>
    </div>
  );
}
