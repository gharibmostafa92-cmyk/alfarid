import React, { useState, useEffect, useRef, useCallback } from 'react';
import { ScanBar } from './ScanBar';
import { Icon } from '../ui/Icons';
import { egp, fmt, PImg, cx } from '../ui/SharedUI';
import { pos } from '../../services/pos-data-source';
import { isWholesaleEligible } from '../../lib/wholesale';
import type { PosProduct, ProductCategory, Customer, PosCartLine } from '../../types/pos.types';

interface CatalogProps {
  cart: PosCartLine[];
  customer: Customer | null;
  onAdd: (p: PosProduct) => void;
  onDecrement?: (p: PosProduct) => void;
  scanRef?: React.RefObject<HTMLInputElement | null>;
  shiftActions?: React.ReactNode;
  allowOutOfStockIds?: Set<number>;
  onRawScan?: (code: string) => boolean;
}

export function Catalog({ cart, customer, onAdd, onDecrement, scanRef, shiftActions, allowOutOfStockIds, onRawScan }: CatalogProps) {
  const [cat, setCat] = useState<string>('all');
  const [categories, setCategories] = useState<ProductCategory[]>([]);
  const [products, setProducts] = useState<PosProduct[]>([]);
  const [allCatsOpen, setAllCatsOpen] = useState(false);
  const [scrollState, setScrollState] = useState({ canPrev: false, canNext: false, overflowing: false });

  const catsRef = useRef<HTMLDivElement>(null);
  const catsBarRef = useRef<HTMLDivElement>(null);
  const catBtnRefs = useRef<Record<string, HTMLButtonElement | null>>({});

  const loadData = () => {
    pos.getCategories().then(setCategories);
    pos.getProducts().then(setProducts);
  };

  useEffect(() => {
    loadData();
    const unsub = pos.subscribe(loadData);
    return unsub;
  }, []);

  const updateScrollState = useCallback(() => {
    const el = catsRef.current;
    if (!el) return;
    const maxScroll = el.scrollWidth - el.clientWidth;
    const overflowing = maxScroll > 4;
    const offset = Math.abs(el.scrollLeft);
    const canPrev = overflowing && offset > 4;
    const canNext = overflowing && offset < maxScroll - 4;
    setScrollState((prev) =>
      prev.canPrev === canPrev && prev.canNext === canNext && prev.overflowing === overflowing
        ? prev
        : { canPrev, canNext, overflowing }
    );
  }, []);

  useEffect(() => {
    const el = catsRef.current;
    if (!el) return;
    updateScrollState();

    const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(updateScrollState) : null;
    ro?.observe(el);

    const onWheel = (e: WheelEvent) => {
      if (e.deltaY !== 0 && el.scrollWidth > el.clientWidth) {
        e.preventDefault();
        const isRtl = getComputedStyle(el).direction === 'rtl';
        el.scrollLeft += isRtl ? -e.deltaY : e.deltaY;
      }
    };
    el.addEventListener('wheel', onWheel, { passive: false });

    return () => {
      ro?.disconnect();
      el.removeEventListener('wheel', onWheel);
    };
  }, [categories.length, updateScrollState]);

  useEffect(() => {
    if (!allCatsOpen) return;
    const onPointerDown = (e: PointerEvent) => {
      if (catsBarRef.current && !catsBarRef.current.contains(e.target as Node)) {
        setAllCatsOpen(false);
      }
    };
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setAllCatsOpen(false);
    };
    window.addEventListener('pointerdown', onPointerDown);
    window.addEventListener('keydown', onKeyDown);
    return () => {
      window.removeEventListener('pointerdown', onPointerDown);
      window.removeEventListener('keydown', onKeyDown);
    };
  }, [allCatsOpen]);

  const scrollCats = (dir: 'prev' | 'next') => {
    const el = catsRef.current;
    if (!el) return;
    const isRtl = getComputedStyle(el).direction === 'rtl';
    const step = Math.max(180, Math.round(el.clientWidth * 0.65));
    const delta = isRtl ? (dir === 'next' ? -step : step) : dir === 'next' ? step : -step;
    el.scrollBy({ left: delta, behavior: 'smooth' });
  };

  const selectCategory = (id: string) => {
    setCat(id);
    setAllCatsOpen(false);
    requestAnimationFrame(() => {
      catBtnRefs.current[id]?.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
    });
  };

  const countFor = (c: ProductCategory) =>
    c.id === 'all' ? products.length : products.filter((p) => p.cat === c.id).length;

  const list = products.filter((p) => cat === 'all' || p.cat === cat);
  const activeCategory = categories.find((c) => c.id === cat);
  const qtyOf = (id: number) => cart.find((l) => l.id === id)?.qty || 0;
  const showAllBtn = scrollState.overflowing || categories.length > 5;

  return (
    <div className="catalog">
      <div className="catalog-bar">
        <ScanBar customer={customer} onAdd={onAdd} inputRef={scanRef} products={products} allowOutOfStockIds={allowOutOfStockIds} onRawScan={onRawScan} />
        {shiftActions}
      </div>
      <div className="cats-bar" ref={catsBarRef}>
        <div
          className="cats-scroll-wrap"
          data-fade-prev={scrollState.canPrev || undefined}
          data-fade-next={scrollState.canNext || undefined}
        >
          {scrollState.canPrev && (
            <button
              type="button"
              className="cats-nav prev"
              onClick={() => scrollCats('prev')}
              aria-label="الفئات السابقة"
            >
              <Icon n="chevRight" s={15} w={2} />
            </button>
          )}
          <div className="cats" ref={catsRef} onScroll={updateScrollState}>
            {categories.map((c) => (
              <button
                key={c.id}
                ref={(el) => {
                  catBtnRefs.current[c.id] = el;
                }}
                type="button"
                className="cat"
                aria-pressed={cat === c.id}
                onClick={() => selectCategory(c.id)}
              >
                {c.name}
                <span className="count">{fmt(countFor(c))}</span>
              </button>
            ))}
          </div>
          {scrollState.canNext && (
            <button
              type="button"
              className="cats-nav next"
              onClick={() => scrollCats('next')}
              aria-label="المزيد من الفئات"
            >
              <Icon n="chevLeft" s={15} w={2} />
            </button>
          )}
        </div>
        {showAllBtn && (
          <button
            type="button"
            className="cats-all-btn"
            aria-expanded={allCatsOpen}
            aria-haspopup="true"
            onClick={() => setAllCatsOpen((v) => !v)}
          >
            <Icon n="filter" s={14} />
            <span>الفئات</span>
            <span className="count">{fmt(categories.length)}</span>
            <Icon n="chevDown" s={13} className={cx('cats-all-chev', allCatsOpen && 'open')} />
          </button>
        )}
        {allCatsOpen && (
          <div className="cats-popover" role="dialog" aria-label="كل الفئات">
            <div className="cats-popover-head">
              <span className="fs-13 fw-6">كل الفئات ({fmt(categories.length)})</span>
              <button
                type="button"
                className="icon-btn sm"
                onClick={() => setAllCatsOpen(false)}
                aria-label="إغلاق"
              >
                <Icon n="x" s={14} />
              </button>
            </div>
            <div className="cats-popover-grid">
              {categories.map((c) => (
                <button
                  key={c.id}
                  type="button"
                  className="cats-popover-item"
                  aria-pressed={cat === c.id}
                  onClick={() => selectCategory(c.id)}
                >
                  <span className="truncate">{c.name}</span>
                  <span className="count">{fmt(countFor(c))}</span>
                </button>
              ))}
            </div>
          </div>
        )}
      </div>
      <div className="grid-scroll">
        {list.length === 0 ? (
          <div className="empty catalog-empty" role="status" aria-live="polite">
            <div className="empty-ic" aria-hidden="true">
              <Icon n={cat !== 'all' && products.length > 0 ? 'box' : 'bag'} s={24} w={1.6} />
            </div>
            {cat !== 'all' && products.length > 0 ? (
              <>
                <h4>
                  لا توجد أصناف في{' '}
                  <span className="catalog-empty-cat" dir="auto">
                    {activeCategory?.name || 'هذه الفئة'}
                  </span>
                </h4>
                <p>اختر فئة أخرى من الأعلى أو اعرض كل الأصناف المتاحة للبيع.</p>
                <div className="catalog-empty-actions">
                  <button
                    type="button"
                    className="btn btn-primary btn-sm"
                    onClick={() => selectCategory('all')}
                  >
                    <span>عرض كل الأصناف</span>
                    <span className="catalog-empty-count num">{fmt(products.length)}</span>
                  </button>
                </div>
              </>
            ) : (
              <>
                <h4>لا توجد أصناف متاحة حالياً</h4>
                <p>لم تتم إضافة أي أصناف إلى كتالوج نقطة البيع بعد.</p>
              </>
            )}
          </div>
        ) : (
          <div className="pgrid">
            {list.map((p) => {
            const q = qtyOf(p.id);
            const wholesaleActive = isWholesaleEligible(customer);
            const price = wholesaleActive ? p.wholesale : p.price;
            const inPrep = Boolean(allowOutOfStockIds?.has(p.id));
            const canPick = p.stock > 0 || inPrep;
            return (
              <div key={p.id} className={cx('pcard-wrap', q > 0 && 'in-cart', !canPick && 'out', inPrep && 'in-prep')}>
                <button
                  type="button"
                  className={cx('pcard', !canPick && 'out', q > 0 && 'in-cart')}
                  onClick={() => canPick && onAdd(p)}
                  disabled={!canPick}
                >
                  {inPrep && p.stock === 0 ? (
                    <span className="stock-tag badge b-acc" style={{ height: 22 }}>
                      في الطلب
                    </span>
                  ) : p.stock === 0 ? (
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
                    {wholesaleActive && (
                      <span className="badge b-info" style={{ height: 20, fontSize: 11 }}>
                        جملة
                      </span>
                    )}
                  </div>
                </button>
                {q > 0 && (
                  <div className="pcard-qty-bar">
                    <span className="qty-tag">{fmt(q)}</span>
                    {onDecrement && (
                      <button
                        type="button"
                        className="pcard-dec"
                        onClick={() => onDecrement(p)}
                        aria-label={`إنقاص ${p.name}`}
                      >
                        <Icon n={q === 1 ? 'trash' : 'minus'} s={13} />
                      </button>
                    )}
                  </div>
                )}
              </div>
            );
          })}
          </div>
        )}
      </div>
    </div>
  );
}
