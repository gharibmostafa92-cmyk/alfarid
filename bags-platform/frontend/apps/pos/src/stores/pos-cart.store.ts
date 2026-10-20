import { create } from 'zustand';

export interface PosCartItem {
  id: number;
  barcode: string;
  name: string;
  price: number;
  quantity: number;
  discount: number;
}

interface PosCartState {
  items: PosCartItem[];
  customerId: number | null;
  paymentMethod: 'cash' | 'card' | 'mixed';
  addItem: (item: Omit<PosCartItem, 'quantity' | 'discount'>) => void;
  updateQuantity: (id: number, delta: number) => void;
  setQuantity: (id: number, quantity: number) => void;
  removeItem: (id: number) => void;
  clearCart: () => void;
  setPaymentMethod: (method: 'cash' | 'card' | 'mixed') => void;
  subtotal: () => number;
  totalDiscount: () => number;
  total: () => number;
}

export const usePosCartStore = create<PosCartState>((set, get) => ({
  items: [],
  customerId: null,
  paymentMethod: 'cash',
  addItem: (product) => {
    set((state) => {
      const existing = state.items.find((item) => item.id === product.id);
      if (existing) {
        return {
          items: state.items.map((item) =>
            item.id === product.id ? { ...item, quantity: item.quantity + 1 } : item
          ),
        };
      }
      return {
        items: [...state.items, { ...product, quantity: 1, discount: 0 }],
      };
    });
  },
  updateQuantity: (id, delta) => {
    set((state) => ({
      items: state.items
        .map((item) => {
          if (item.id === id) {
            const newQty = item.quantity + delta;
            return newQty > 0 ? { ...item, quantity: newQty } : null;
          }
          return item;
        })
        .filter(Boolean) as PosCartItem[],
    }));
  },
  setQuantity: (id, quantity) => {
    set((state) => ({
      items:
        quantity > 0
          ? state.items.map((item) => (item.id === id ? { ...item, quantity } : item))
          : state.items.filter((item) => item.id !== id),
    }));
  },
  removeItem: (id) => {
    set((state) => ({ items: state.items.filter((item) => item.id !== id) }));
  },
  clearCart: () => set({ items: [] }),
  setPaymentMethod: (paymentMethod) => set({ paymentMethod }),
  subtotal: () => {
    return get().items.reduce((sum, item) => sum + item.price * item.quantity, 0);
  },
  totalDiscount: () => {
    return get().items.reduce((sum, item) => sum + item.discount, 0);
  },
  total: () => {
    return get().subtotal() - get().totalDiscount();
  },
}));
