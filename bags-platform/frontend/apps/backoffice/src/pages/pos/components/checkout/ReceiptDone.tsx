import React, { useEffect } from 'react';
import { Icon } from '../ui/Icons';
import { egp, fmt, Kbd } from '../ui/SharedUI';
import { pos as posMockService } from '../../services/pos-data-source';
import type { PosCartLine, Customer, Branch, CashierUser } from '../../types/pos.types';

interface ReceiptDoneProps {
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
  onNew: () => void;
  branch?: Branch | null;
  cashier?: CashierUser | null;
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
  useEffect(() => {
    const h = (e: KeyboardEvent) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        onNew();
      }
    };
    const t = setTimeout(() => window.addEventListener('keydown', h), 300);
    return () => {
      clearTimeout(t);
      window.removeEventListener('keydown', h);
    };
  }, [onNew]);

  return (
    <div className="grid" style={{ gridTemplateColumns: '1fr 360px' }}>
      <div
        className="col center"
        style={{ padding: 36, gap: 18, alignItems: 'flex-start', justifyContent: 'center' }}
      >
        <span
          style={{
            width: 60,
            height: 60,
            borderRadius: 20,
            background: 'var(--ok-bg)',
            color: 'var(--ok)',
            display: 'grid',
            placeItems: 'center',
          }}
        >
          <Icon n="check" s={30} w={2.4} />
        </span>
        <div>
          <h2 className="fs-26">تم تسجيل البيع بنجاح</h2>
          <p className="t-2" style={{ marginTop: 4 }}>
            فاتورة <b className="num ltr t-1">{invoiceNumber}</b> — تم خصم المخزون وإيداع النقدية
            بالدرج.
          </p>
        </div>
        {method !== 'card' && (
          <div className="change" style={{ width: '100%', maxWidth: 380 }}>
            <span className="fs-13" style={{ opacity: 0.8 }}>
              {method === 'cash' ? 'الفكة للعميل' : 'تم تحصيل نقدي'}
            </span>
            <span className="v">
              {egp(method === 'cash' ? Math.max(0, change) : cashA)}{' '}
              <small className="fs-13" style={{ opacity: 0.6 }}>
                ج
              </small>
            </span>
          </div>
        )}
        <div className="row gap-2">
          <button className="btn btn-secondary btn-lg" onClick={() => window.print()}>
            <Icon n="printer" s={17} />
            إعادة الطباعة
          </button>
          <button className="btn btn-primary btn-lg" autoFocus onClick={onNew}>
            فاتورة جديدة <Kbd>Enter</Kbd>
          </button>
        </div>
      </div>
      <div
        style={{
          background: 'var(--bg-sunken)',
          padding: '28px 0 40px',
          borderInlineStart: '1px solid var(--line)',
        }}
      >
        <div className="receipt">
          <div style={{ textAlign: 'center' }}>
            <b className="fs-14 display">الفريد للشنط</b>
            <div style={{ color: '#6f6a76' }}>{branch?.name || 'فرع مدينة نصر'}</div>
            <div className="num" style={{ color: '#6f6a76' }}>
              {invoiceNumber} ·{' '}
              {new Date().toLocaleString('ar-EG', { dateStyle: 'short', timeStyle: 'short' })}
            </div>
          </div>
          <div className="dash" />
          {cart.map((l) => {
            const p = posMockService.getProductById(l.id);
            if (!p) return null;
            return (
              <div key={l.id}>
                <div className="truncate">{p.name}</div>
                <div className="rr" style={{ color: '#6f6a76' }}>
                  <span className="num">
                    {fmt(l.qty)} × {egp(l.price)}
                  </span>
                  <span className="num" style={{ color: '#18151c' }}>
                    {egp(l.qty * l.price)}
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
            الكاشير: {cashier?.name || 'أحمد سمير'} — الاستبدال خلال ١٤ يومًا بالإيصال
          </div>
          <div className="bars" />
        </div>
      </div>
    </div>
  );
}
