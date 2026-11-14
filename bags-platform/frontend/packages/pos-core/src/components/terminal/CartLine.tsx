import React, { useState, useEffect, useRef } from 'react';
import { Icon } from '../ui/Icons';
import { egp, fmt, PImg, cx, toAsciiNum } from '../ui/SharedUI';
import { fromCents, parsePositivePrice, toCents } from '../../lib/money';
import { pos } from '../../services/pos-data-source';
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
  const p = pos.getProductById(l.id);
  const name = l.name ?? p?.name ?? `#${l.id}`;
  const stock = l.stock ?? p?.stock;
  const visual = p ?? { cat: l.cat ?? 'hand', h: l.h ?? 240 };
  const [edit, setEdit] = useState(false);
  const invalidPrice = useRef(false);
  const [val, setVal] = useState(String(l.price));
  // F-16: keep the edit field in sync when the line reprices (resume/quote),
  // otherwise the field shows a stale price after the cart updates.
  useEffect(() => {
    if (!edit) setVal(String(l.price));
  }, [l.price, edit]);
  const overridden = Boolean(l.manualPrice);

  return (
    <div className={cx('line', flash ? 'flash' : '', l.error && 'line-bad')}>
      <PImg p={visual} size="sm" />
      <div style={{ minWidth: 0 }}>
        <div className="line-name truncate">{name}</div>
        <div className="line-sub">
          {edit ? (
            <input
              className="price-input"
              autoFocus
              value={val}
              onChange={(e) => {
                const clean = toAsciiNum(e.target.value);
                invalidPrice.current = clean.replace('.', '').length > 9 || Number(clean) > 9999999.99;
                if (!invalidPrice.current) setVal(clean);
              }}
              onBlur={() => {
                const parsed = parsePositivePrice(val, l.price);
                if (!invalidPrice.current) onPrice(parsed);
                invalidPrice.current = false;
                setEdit(false);
              }}
              onKeyDown={(e) => {
                if (e.key === 'Enter') (e.target as HTMLInputElement).blur();
                if (e.key === 'Escape') {
                  invalidPrice.current = true;
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
                  invalidPrice.current = true;
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
          {stock !== undefined && l.qty > stock && (
            <span
              className="badge b-bad"
              style={{ height: 18, fontSize: 10.5, padding: '0 6px' }}
            >
              الرصيد {fmt(stock)}
            </span>
          )}
        </div>
        {l.error && <div className="line-err">{l.error}</div>}
      </div>
      <div className="col" style={{ alignItems: 'flex-end', gap: 6 }}>
        <div className="row gap-1">
          <div className="stepper-q">
            <button
              onClick={() => (stock === undefined || l.qty < stock) && onQty(l.qty + 1)}
              disabled={stock !== undefined && l.qty >= stock}
              aria-label="زيادة"
            >
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
        <span className="price fs-14">{egp(fromCents(toCents(l.price) * l.qty))}</span>
      </div>
    </div>
  );
}
