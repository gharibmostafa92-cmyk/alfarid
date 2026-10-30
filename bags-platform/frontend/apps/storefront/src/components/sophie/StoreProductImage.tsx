'use client';

import { StoreImage } from '../StoreImage';
import { useState } from 'react';
import { storeImageSource } from '@/lib/store-image';
import { SophieBagPlaceholder } from './SophieBagPlaceholder';

/** One image rule for the menu, checkout and cart, including absent or failed media. */
export function StoreProductImage({ src, alt, className, id }: {
  src?: string | null; alt: string; className?: string; id?: string;
}) {
  const source = storeImageSource(src);
  const [failed, setFailed] = useState<string | null>(null);
  if (!source || failed === source.src) return <SophieBagPlaceholder />;
  return <StoreImage id={id} src={source.src} alt={alt}
    loading="lazy" className={className}
    style={{ width: '100%', height: '100%', objectFit: 'contain' }}
    data-store-product-image onError={() => setFailed(source.src)} />;
}