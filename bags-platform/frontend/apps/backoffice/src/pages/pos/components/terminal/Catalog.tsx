import React, { useState, useEffect } from 'react';
import { ScanBar } from './ScanBar';
import { egp, fmt, PImg, cx } from '../ui/SharedUI';
import { pos as posMockService } from '../../services/pos-data-source';
import type { PosProduct, ProductCategory, Customer, PosCartLine } from '../../types/pos.types';

interface CatalogProps {
  cart: PosCartLine[];
  customer: Customer | null;
  onAdd: (p: PosProduct) => void;
  scanRef?: React.RefObject<HTMLInputElement | null>;
}

export function Catalog({ cart, customer, onAdd, scanRef }: CatalogProps) {
  const [cat, setCat] = useState<string>('all');
  const [categories, setCategories] = useState<ProductCategory[]>([]);
  const [products, setProducts] = useState<PosProduct[]>([]);

  const loadData = () => {
    posMockService.getCategories().then(setCategories);
    posMockService.getProducts().then(setProducts);
  };

  useEffect(() => {
    loadData();
    const unsub = posMockService.subscribe(loadData);
    return unsub;
  }, []);

  const list = products.filter((p) => cat === 'all' || p.cat === cat);
  const qtyOf = (id: number) => cart.find((l) => l.id === id)?.qty || 0;

  return (
    <div className="catalog">
      <ScanBar customer={customer} onAdd={onAdd} inputRef={scanRef} products={products} />
      <div className="cats">
        {categories.map((c) => (
          <button
            key={c.id}
            className="cat"
            aria-pressed={cat === c.id}
            onClick={() => setCat(c.id)}
          >
            {c.name}
            <span className="count">
              {fmt(
                c.id === 'all'
                  ? products.length
                  : products.filter((p) => p.cat === c.id).length
              )}
            </span>
          </button>
        ))}
      </div>
      <div className="grid-scroll">
        <div className="pgrid">
          {list.map((p) => {
            const q = qtyOf(p.id);
            const price = customer?.tier === 'wholesale' ? p.wholesale : p.price;
            return (
              <button
                key={p.id}
                className={cx('pcard', p.stock === 0 && 'out')}
                onClick={() => p.stock > 0 && onAdd(p)}
                disabled={p.stock === 0}
              >
                {q > 0 && <span className="qty-tag">{fmt(q)}</span>}
                {p.stock === 0 ? (
                  <span className="stock-tag badge b-bad" style={{ height: 22 }}>
                    نفد
                  </span>
                ) : p.stock <= p.reorder ? (
                  <span className="stock-tag badge b-warn" style={{ height: 22 }}>
                    باقي {fmt(p.stock)}
                  </span>
                ) : null}
                <PImg p={p} />
                <div style={{ padding: '0 4px' }}>
                  <div className="pcard-name">{p.name}</div>
                  <div className="mono t-3" style={{ fontSize: 11 }}>
                    {p.sku}
                  </div>
                </div>
                <div className="pcard-foot">
                  <span className="price fs-16">
                    {egp(price)}
                    <small>ج</small>
                  </span>
                  {customer?.tier === 'wholesale' && (
                    <span className="badge b-info" style={{ height: 20, fontSize: 11 }}>
                      جملة
                    </span>
                  )}
                </div>
              </button>
            );
          })}
        </div>
      </div>
    </div>
  );
}
