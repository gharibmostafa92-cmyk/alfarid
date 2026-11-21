'use client';

import React, { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { formatStorePrice } from '@alfarid/utils';
import { useCartStore } from '@/stores/cart-store';
import { SophieBagPlaceholder } from './SophieBagPlaceholder';
import { StoreImage } from '../../StoreImage';
import { useStoreLang } from '@/providers/store-lang-provider';

export interface SophieProduct {
  id: number;
  variantId?: number;
  slug?: string;
  name: string;
  price: number;
  oldPrice?: number;
  badge?: string;
  imgUrl: string | null;
  badgeTone?: 'default' | 'sale' | 'muted';
  href?: string;
  inStock?: boolean;
  tintClass?: string;
  category?: string;
}

export function SophieProductCard({ product }: { product: SophieProduct }) {
  const { isAr } = useStoreLang();
  const addItem = useCartStore((s) => s.addItem);
  const [added, setAdded] = useState(false);
  const addedTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const alive = useRef(true);
  useEffect(() => {
    alive.current = true;
    return () => { alive.current = false; if (addedTimer.current) clearTimeout(addedTimer.current); };
  }, []);
  const [adding, setAdding] = useState(false);
  const cartError = useCartStore((s) => s.error);
  const [addFailed, setAddFailed] = useState(false);
  const [failedImage, setFailedImage] = useState<string | null>(null);
  const image = product.imgUrl && failedImage !== product.imgUrl ? (
    <StoreImage
      src={product.imgUrl}
      alt={product.name}
      onError={() => setFailedImage(product.imgUrl)}
      className={`${product.tintClass || ''} max-w-full sm:max-w-[175px] max-h-[155px] sm:max-h-[200px] object-contain transition-transform duration-300 group-hover:scale-105 ${product.inStock === false ? 'opacity-60' : ''}`}
      loading="lazy"
    />
  ) : <SophieBagPlaceholder />;

  const handleAddToCart = async () => {
    if (!product.variantId || adding) return;
    setAdding(true);
    setAddFailed(false);
    const saved = await addItem(product.variantId);
    if (!alive.current) return;
    setAdding(false);
    setAdded(saved);
    setAddFailed(!saved && useCartStore.getState().error != null);
    if (addedTimer.current) clearTimeout(addedTimer.current);
    if (saved) addedTimer.current = setTimeout(() => setAdded(false), 1600);
  };

  const addToCartBtn = (mobile: boolean) => (
    <button
      type="button"
      onClick={handleAddToCart}
      disabled={adding}
      aria-live="polite"
      aria-label={`Add ${product.name} to cart`}
      className={`${
        mobile
          ? 'lg:hidden mt-2.5 w-full min-h-[40px] rounded-xl text-[12px] font-medium'
          : 'font-[var(--font-oswald)] text-[11px] uppercase tracking-wider min-h-[44px] py-1.5 px-4 rounded-full shadow-sm'
      } transition-all duration-200 active:scale-[0.97] ${
        added ? 'bg-emerald-600 text-white' : mobile ? 'bg-[#171717] text-white hover:bg-[#2c2c2c]' : 'bg-black text-white hover:bg-neutral-800'
      }`}
    >
      {adding ? (isAr ? 'جارٍ الإضافة…' : 'Adding…') : added ? (isAr ? '✓ تمت الإضافة' : '✓ Added') : (isAr ? '+ أضف للسلة' : '+ Add to Cart')}
    </button>
  );

  return (
    <div className="product-card text-center group flex flex-col h-full">
      {/* Image Box */}
      <div className="bg-[#f3f4f6] rounded-2xl relative h-[180px] min-[380px]:h-[210px] sm:h-[260px] flex items-center justify-center p-4 sm:p-6 mb-3 overflow-hidden transition-all duration-300 group-hover:bg-[#ebeef2]">
        {/* Badge */}
        {product.badge && (
          <span
            className={`absolute top-2.5 left-2.5 rounded-full ${
              product.badgeTone === 'sale' ? 'bg-[#111]' : product.badgeTone === 'muted' ? 'bg-[#6b7280]' : 'bg-[#4f7d86]'
            } text-white font-[var(--font-oswald)] text-[10px] font-medium uppercase px-2.5 py-0.5 tracking-[0.5px] z-10`}
          >
            {product.badge}
          </span>
        )}

        {/* Product Image */}
        {product.href ? (
          <Link href={product.href} aria-label={product.name} className="flex items-center justify-center w-full h-full">
            {image}
          </Link>
        ) : (
          image
        )}

        {/* Quick Add Overlay on Desktop Hover */}
        {product.inStock !== false && product.variantId && (
          <div className="hidden lg:flex absolute inset-x-0 bottom-0 p-3 bg-gradient-to-t from-black/25 via-black/10 to-transparent opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity items-center justify-center">
            {addToCartBtn(false)}
          </div>
        )}
      </div>

      {/* Product Name */}
      <h3
        dir="auto"
        className="font-[var(--font-oswald)] text-[14.5px] sm:text-[13.5px] font-medium text-[#222] mb-1 tracking-[0.2px] hover:text-[#4f7d88] transition-colors line-clamp-2 leading-snug"
      >
        {product.href ? (
          <Link href={product.href} className="py-0.5 inline-block">
            {product.name}
          </Link>
        ) : (
          product.name
        )}
      </h3>

      {/* Product Price */}
      <p className="font-[var(--font-oswald)] text-[15.5px] sm:text-[14px] font-semibold text-[#111] mt-auto flex flex-wrap items-baseline justify-center gap-x-1.5">
        {product.oldPrice && (
          <span className="text-[#767676] line-through font-normal text-[12.5px]">
            {formatStorePrice(product.oldPrice)}
          </span>
        )}
        <span>{formatStorePrice(product.price)}</span>
      </p>

      {/* Mobile Add to Cart Button (keeps product photo unobstructed) */}
      {product.inStock !== false && product.variantId && addToCartBtn(true)}
      {addFailed && cartError && (
        <p role="alert" className="text-xs text-red-700 mt-1.5">
          {cartError}
        </p>
      )}
    </div>
  );
}
