import React, { useState, useEffect, useMemo } from 'react';
import { Icon } from '../ui/Icons';
import { egp, fmt, PImg, Api, Kbd, cx } from '../ui/SharedUI';
import { pos } from '../../services/pos-data-source';
import { normAr } from '../../lib/arabic';
import { beepError } from '../../lib/sound';
import { isWholesaleEligible } from '../../lib/wholesale';
import type { PosProduct, Customer } from '../../types/pos.types';

interface ScanBarProps {
  products: PosProduct[];
  customer: Customer | null;
  onAdd: (p: PosProduct) => void;
  inputRef?: React.RefObject<HTMLInputElement | null>;
}

export function ScanBar({ products, customer, onAdd, inputRef }: ScanBarProps) {
  const [q, setQ] = useState('');
  const [hl, setHl] = useState(0);
  const [open, setOpen] = useState(false);
  const [unknownBarcode, setUnknownBarcode] = useState<string | null>(null);

  const results = useMemo(() => {
    const s = normAr(q);
    if (!s) return [];
    return products
      .filter((p) => normAr(p.name).includes(s) || normAr(p.sku).includes(s) || normAr(p.barcode).includes(s))
      .slice(0, 7);
  }, [q, products]);

  useEffect(() => setHl(0), [q]);

  const pick = (p: PosProduct) => {
    if (p.stock <= 0) {
      beepError();
      return;
    }
    onAdd(p);
    setQ('');
    setOpen(false);
  };

  const onKey = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setHl((h) => Math.min(h + 1, results.length - 1));
    }
    if (e.key === 'ArrowUp') {
      e.preventDefault();
      setHl((h) => Math.max(h - 1, 0));
    }
    if (e.key === 'Enter') {
      const code = normAr(q);
      // F-12: clear the field before the async lookup resolves so a second
      // scan landing within ~100ms cannot glue onto this barcode.
      setQ('');
      setOpen(false);
      const isBarcode = /^\d{8,}$/.test(code);
      const exact = products.find((p) => normAr(p.barcode) === code || normAr(p.sku) === code);
      if (exact) {
        pick(exact);
      } else if (isBarcode) {
        // A scanner emitted a full barcode: never fall back to a fuzzy match,
        // or a dropped digit can silently add the wrong product.
        pos
          .lookupByBarcode(code)
          .then((p) => {
            if (p) {
              pick(p);
            } else {
              setUnknownBarcode(code);
              beepError();
            }
          })
          .catch(() => {
            setUnknownBarcode(code);
            beepError();
          });
      } else if (results[hl]) {
        pick(results[hl]);
      } else if (code) {
        pos
          .lookupByBarcode(code)
          .then((p) => (p ? pick(p) : beepError()))
          .catch(() => beepError());
      }
    }
    if (e.key === 'Escape') {
      setQ('');
      setOpen(false);
    }
  };

  return (
    <div className="scanbar">
      <span className="scan-ic">
        <Icon n="barcode" s={18} w={2} />
      </span>
      <input
        ref={inputRef}
        data-scan-input
        value={q}
        onChange={(e) => {
          setUnknownBarcode(null);
          setQ(e.target.value);
          setOpen(true);
        }}
        onFocus={() => setOpen(true)}
        onBlur={() => setTimeout(() => setOpen(false), 150)}
        onKeyDown={onKey}
        placeholder="امسح الباركود أو ابحث بالاسم / الكود (SKU)…"
        aria-label="بحث الأصناف"
      />
      {unknownBarcode && (
        <span className="badge b-bad fs-12" role="alert">
          {'\u0628\u0627\u0631\u0643\u0648\u062f \u063a\u064a\u0631 \u0645\u0639\u0631\u0648\u0641: '}
          <b className="mono ltr">{unknownBarcode}</b>
        </span>
      )}
      <span className="row gap-1 fs-12 t-3">
        <Kbd>F2</Kbd>
      </span>
      {open && q && (
        <div className="scan-results">
          {results.length === 0 ? (
            <div className="empty" style={{ padding: 24 }}>
              <h4 className="fs-14">لا يوجد صنف مطابق</h4>
              <p>جرّب الباركود الكامل أو جزء من الاسم</p>
            </div>
          ) : (
            results.map((p, i) => (
              <button
                key={p.id}
                className={cx('scan-row', i === hl && 'hl', p.stock === 0 && 'out')}
                disabled={p.stock === 0}
                onMouseDown={() => p.stock > 0 && pick(p)}
                onMouseEnter={() => setHl(i)}
              >
                <PImg p={p} size="xs" />
                <div className="grow">
                  <div className="fs-13 fw-5 truncate">{p.name}</div>
                  <div className="fs-12 t-3 ltr" style={{ textAlign: 'right' }}>
                    <span className="mono">{p.sku}</span> · <span className="mono">{p.barcode}</span>
                  </div>
                </div>
                <span
                  className={cx(
                    'badge',
                    p.stock === 0 ? 'b-bad' : p.stock <= p.reorder ? 'b-warn' : 'b-neutral'
                  )}
                >
                  {p.stock === 0 ? (
                    'نفد'
                  ) : (
                    <>
                      رصيد <b className="num">{fmt(p.stock)}</b>
                    </>
                  )}
                </span>
                <span className="price fs-14">
                  {egp(isWholesaleEligible(customer) ? p.wholesale : p.price)}
                </span>
              </button>
            ))
          )}
          <div
            className="row between fs-12 t-3"
            style={{ padding: '8px 10px 4px', borderTop: '1px solid var(--line)', marginTop: 4 }}
          >
            <span className="row gap-1">
              <Kbd>↑</Kbd>
              <Kbd>↓</Kbd> تنقّل <Kbd>Enter</Kbd> إضافة
            </span>
            <Api
              path={`/pos/products/lookup?q=…${customer ? '&customer_id=' + customer.id : ''}`}
            />
          </div>
        </div>
      )}
    </div>
  );
}
