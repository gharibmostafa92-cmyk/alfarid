import React, { useState } from 'react';
import { Icon } from '../ui/Icons';
import { egp, fmt, PImg, cx, toAsciiNum } from '../ui/SharedUI';
import { posMockService } from '../../services/pos-mock.service';
import type { PosCartLine } from '../../types/pos.types';

interface CartLineProps {
  l: PosCartLine;
  onQty: (q: number) => void;
  onRemove: () => void;
  onPrice: (price: number) => void;
  canOverride?: boolean;
  flash?: boolean | number | null;
}

export function CartLine({ l, onQty, onRemove, onPrice, canOverride, flash }: CartLineProps) {
  const p = posMockService.getProductById(l.id);
  const [edit, setEdit] = useState(false);
  const [val, setVal] = useState(String(l.price));
  const overridden = l.price !== l.listPrice;

  if (!p) return null;

  return (
    <div className={cx('line', flash ? 'flash' : '')}>
      <PImg p={p} size="sm" />
      <div style={{ minWidth: 0 }}>
        <div className="line-name truncate">{p.name}</div>
        <div className="line-sub">
          {edit ? (
            <input
              className="price-input"
              autoFocus
              value={val}
              onChange={(e) => setVal(toAsciiNum(e.target.value))}
              onBlur={() => {
                onPrice(Number(val || l.listPrice));
                setEdit(false);
              }}
              onKeyDown={(e) => {
                if (e.key === 'Enter') (e.target as HTMLInputElement).blur();
                if (e.key === 'Escape') {
                  setVal(String(l.price));
                  setEdit(false);
                }
              }}
            />
          ) : (
            <span
              className={cx('num', canOverride && 'price-edit')}
              onClick={() => {
                if (canOverride) {
                  setVal(String(l.price));
                  setEdit(true);
                }
              }}
              title={canOverride ? 'تعديل السعر (pos.override_price)' : undefined}
            >
              {egp(l.price)} ج
            </span>
          )}
          {overridden && !edit && (
            <>
              <s className="num">{egp(l.listPrice)}</s>
              <span
                className="badge b-warn"
                style={{ height: 18, fontSize: 10.5, padding: '0 6px' }}
              >
                سعر معدّل
              </span>
            </>
          )}
          {l.qty > p.stock && (
            <span
              className="badge b-bad"
              style={{ height: 18, fontSize: 10.5, padding: '0 6px' }}
            >
              الرصيد {fmt(p.stock)}
            </span>
          )}
        </div>
      </div>
      <div className="col" style={{ alignItems: 'flex-end', gap: 6 }}>
        <div className="row gap-1">
          <div className="stepper-q">
            <button onClick={() => onQty(l.qty + 1)} aria-label="زيادة">
              <Icon n="plus" s={14} />
            </button>
            <span>{fmt(l.qty)}</span>
            <button
              onClick={() => (l.qty > 1 ? onQty(l.qty - 1) : onRemove())}
              aria-label="نقص"
            >
              <Icon n="minus" s={14} />
            </button>
          </div>
          <button
            className="icon-btn sm"
            onClick={onRemove}
            aria-label="حذف"
            style={{ color: 'var(--text-3)' }}
          >
            <Icon n="trash" s={15} />
          </button>
        </div>
        <span className="price fs-14">{egp(l.price * l.qty)}</span>
      </div>
    </div>
  );
}
