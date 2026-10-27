'use client';

/* eslint-disable @next/next/no-img-element */
import type { ComponentProps } from 'react';
import { storeImageUrl } from '@/lib/store-image';

/** Keep each surface's native sizing, loading and error behavior with one URL policy. */
export function StoreImage({ src, alt, ...props }: ComponentProps<'img'>) {
  return <img {...props} alt={alt ?? ''} src={storeImageUrl(typeof src === 'string' ? src : null)} />;
}
