'use client';

import React, { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { formatStorePrice } from '@alfarid/utils';
import { useCartStore } from '@/stores/cart-store';
import { SophieBagPlaceholder } from './SophieBagPlaceholder';
import { StoreImage } from '../StoreImage';

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
  const addItem = useCartStore((s) => s.addItem);
  const [added, setAdded] = useState(false);
  const addedTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const alive = useRef(true);
  useEffect(() => {
    alive.current = true;
    return () => { alive.current = false; if (addedTimer.current) clearTimeout(addedTimer.current); };
  }, []);
  const [adding, setAdding] = useState(false);
  const busy = useCartStore((s) => s.busy);
  const cartError = useCartStore((s) => s.error);
  const [addFailed, setAddFailed] = useState(false);
  const [failedImage, setFailedImage] = useState<string | null>(null);
  const image = product.imgUrl && failedImage !== product.imgUrl ? (
    <StoreImage src={product.imgUrl} alt={product.name} onError={() => setFailedImage(product.imgUrl)}
      className={`${product.tintClass || ''} max-w-[175px] max-h-[200px] object-contain transition-transform duration-300 group-hover:scale-105 ${product.inStock === false ? 'opacity-60' : ''}`} loading="lazy" />
  ) : <SophieBagPlaceholder />;

  const handleAddToCart = async () => {
    if (!product.variantId || busy) return;
    setAdding(true);
    setAddFailed(false);
    const saved = await addItem(product.variantId);
    if (!alive.current) return;
    setAdding(false);
    setAdded(saved);
    setAddFailed(!saved);
    if (addedTimer.current) clearTimeout(addedTimer.current);
    if (saved) addedTimer.current = setTimeout(() => setAdded(false), 1600);
  };

  return (
    <div className="product-card text-center group flex flex-col">
      {/* Image Box */}
      <div className="bg-[#f3f4f6] relative h-[260px] flex items-center justify-center p-6 mb-3.5 overflow-hidden transition-all duration-300 group-hover:bg-[#ebeef2]">
        {/* Badge */}
        {product.badge && (
          <span className={`absolute top-0 left-0 ${product.badgeTone === 'sale' ? 'bg-[#111]' : product.badgeTone === 'muted' ? 'bg-[#9ca3af]' : 'bg-[#4f7d86]'} text-white font-['Oswald'] text-[10px] font-medium uppercase px-2.5 py-1 tracking-[0.5px] z-10`}>
            {product.badge}
          </span>
        )}

        {/* Product Image */}
        {product.href ? <Link href={product.href} aria-label={product.name} className="flex items-center justify-center w-full h-full">{image}</Link> : image}

        {/* Quick Add Overlay on Hover */}
        {product.inStock !== false && product.variantId && <div className="absolute inset-x-0 bottom-0 p-3 bg-gradient-to-t from-black/20 to-transparent opacity-100 lg:opacity-0 lg:group-hover:opacity-100 focus-within:opacity-100 transition-opacity flex items-center justify-center">
          <button
            onClick={handleAddToCart}
            disabled={busy}
            className={`font-['Oswald'] text-[11px] uppercase tracking-wider py-1.5 px-4 rounded-xs shadow-sm transition-all duration-200 ${
              added ? 'bg-emerald-600 text-white' : 'bg-black text-white hover:bg-neutral-800'
            }`}
          >
            {adding ? 'Adding…' : added ? '✓ Added' : '+ Add to Cart'}
          </button>
        </div>}
      </div>

      {/* Product Name */}
      <h3 dir="auto" className="font-['Oswald'] text-[13px] font-medium text-[#222] mb-1 tracking-[0.2px] hover:text-[#4f7d88] transition-colors line-clamp-1">
        {product.href ? <Link href={product.href}>{product.name}</Link> : product.name}
      </h3>

      {/* Product Price */}
      <p className="font-['Oswald'] text-[14px] font-semibold text-[#111]">
        {product.oldPrice && (
          <span className="text-[#b0b0b0] line-through font-normal mr-1 text-[13px]">
            {formatStorePrice(product.oldPrice)}
          </span>
        )}
        <span>{formatStorePrice(product.price)}</span>
      </p>
      {addFailed && cartError && <p role="alert" className="text-xs text-red-700 mt-2">{cartError}</p>}
    </div>
  );
}
