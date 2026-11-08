import React, { useState, useEffect } from 'react';
import { Icon } from '../ui/Icons';
import { egp, fmt, PImg, Api, Field, Modal } from '../ui/SharedUI';
import { pos as posMockService } from '../../services/pos-data-source';
import type { PosCartLine, Customer } from '../../types/pos.types';

interface HoldModalProps {
  open: boolean;
  onClose: () => void;
  onHold: (note: string) => void;
  cart: PosCartLine[];
  customer: Customer | null;
}

export function HoldModal({ open, onClose, onHold, cart, customer }: HoldModalProps) {
  const [note, setNote] = useState('');

  useEffect(() => {
    if (open) setNote('');
  }, [open]);

  const total = cart.reduce((s, l) => s + l.price * l.qty, 0);

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="تعليق الفاتورة"
      width={460}
      footer={
        <>
          <span className="grow">
            <Api m="POST" path="/pos/sales/hold" />
          </span>
          <button className="btn btn-secondary" onClick={onClose}>
            إلغاء
          </button>
          <button className="btn btn-primary" onClick={() => onHold(note)}>
            <Icon n="pause" s={15} />
            تعليق وخدمة زبون آخر
          </button>
        </>
      }
    >
      <div className="col gap-4">
        <div className="sunken row gap-3" style={{ padding: 12 }}>
          <div className="stack-thumbs">
            {cart.slice(0, 3).map((l) => (
              <PImg key={l.id} p={posMockService.getProductById(l.id)} size="xs" />
            ))}
          </div>
          <div className="grow">
            <div className="fs-13 fw-6 num">
              {fmt(cart.reduce((s, l) => s + l.qty, 0))} قطعة
            </div>
            <div className="fs-12 t-3">{customer ? customer.name : 'بدون عميل'}</div>
          </div>
          <span className="price fs-16">{egp(total)} ج</span>
        </div>
        <Field
          label={
            <>
              ملاحظة <span className="t-3 fs-12">(اختياري)</span>
            </>
          }
        >
          <input
            className="input"
            autoFocus
            value={note}
            onChange={(e) => setNote(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && onHold(note)}
            placeholder="مثال: زبون بيجيب محفظة إضافية"
          />
        </Field>
        <div className="row gap-2 wrap">
          {['بانتظار العميل', 'بيجيب فلوس', 'بيقيس لون تاني', 'هيرجع كمان شوية'].map((s) => (
            <button key={s} className="note-chip" onClick={() => setNote(s)}>
              {s}
            </button>
          ))}
        </div>
      </div>
    </Modal>
  );
}
