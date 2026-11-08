import React, { useState, useEffect, useMemo } from 'react';
import { Icon } from '../ui/Icons';
import { egp, fmt, PImg, Api, Kbd, cx } from '../ui/SharedUI';
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

  const results = useMemo(() => {
    const s = q.trim().toLowerCase();
    if (!s) return [];
    return products
      .filter((p) => p.name.includes(s) || p.sku.toLowerCase().includes(s) || p.barcode.includes(s))
      .slice(0, 7);
  }, [q, products]);

  useEffect(() => setHl(0), [q]);

  const pick = (p: PosProduct) => {
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
      const exact = products.find(
        (p) => p.barcode === q.trim() || p.sku.toLowerCase() === q.trim().toLowerCase()
      );
      if (exact) {
        pick(exact);
      } else if (results[hl]) {
        pick(results[hl]);
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
        value={q}
        onChange={(e) => {
          setQ(e.target.value);
          setOpen(true);
        }}
        onFocus={() => setOpen(true)}
        onBlur={() => setTimeout(() => setOpen(false), 150)}
        onKeyDown={onKey}
        placeholder="امسح الباركود أو ابحث بالاسم / الكود (SKU)…"
        aria-label="بحث الأصناف"
      />
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
                className={cx('scan-row', i === hl && 'hl')}
                onMouseDown={() => pick(p)}
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
                  {egp(customer?.tier === 'wholesale' ? p.wholesale : p.price)}
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
