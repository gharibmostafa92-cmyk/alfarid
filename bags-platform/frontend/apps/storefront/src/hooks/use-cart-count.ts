'use client';

import { useEffect, useState } from 'react';
import { useCartStore } from '@/stores/cart-store';

export function useCartCount() {
  const count = useCartStore((s) => s.items.reduce((t, i) => t + i.quantity, 0));
  const [mounted, setMounted] = useState(false);
  useEffect(() => setMounted(true), []);
  return mounted ? count : 0;
}
