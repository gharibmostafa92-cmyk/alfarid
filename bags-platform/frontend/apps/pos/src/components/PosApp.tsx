import React, { useState, useEffect, useRef, useCallback } from 'react';
import { PosHeader } from './layout/PosHeader';
import { Catalog } from './terminal/Catalog';
import { Cart } from './terminal/Cart';
import { OpenShiftGate } from './shift/OpenShiftGate';
import { CloseShiftModal } from './shift/CloseShiftModal';
import { CheckoutModal } from './checkout/CheckoutModal';
import { HoldModal } from './checkout/HoldModal';
import { HeldDrawer } from './checkout/HeldDrawer';
import { ReturnModal } from './returns/ReturnModal';
import { StockPage } from './inventory/StockPage';
import { TransfersPage } from './inventory/TransfersPage';
import { StocktakePage } from './inventory/StocktakePage';
import { useToast } from './ui/SharedUI';
import { pos as posMockService } from '../services/pos-data-source';
import type {
  AppScreen,
  PosShift,
  Branch,
  CashierUser,
  PosProduct,
  PosCartLine,
  Customer,
  PosHeldSale,
} from '../types/pos.types';

export function PosApp() {
  const toast = useToast();

  const [currentScreen, setCurrentScreen] = useState<AppScreen>('terminal');
  const [currentShift, setCurrentShift] = useState<PosShift | null>(null);
  const [branch, setBranch] = useState<Branch | null>(null);
  const [cashier, setCashier] = useState<CashierUser | null>(null);
  const [isDark, setIsDark] = useState<boolean>(false);

  // Cart and sale state: the cart always starts empty, never with demo lines.
  const [cart, setCart] = useState<PosCartLine[]>([]);
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [discount, setDiscount] = useState<number>(0);
  const [maxDiscountPercent, setMaxDiscountPercent] = useState<number>(100);
  // Cart-session idempotency key: stable across CheckoutModal re-opens for the
  // same cart, renewed only when the cart session ends (paid / held / cleared).
  const [saleSessionKey, setSaleSessionKey] = useState<string>(() => globalThis.crypto.randomUUID());
  const renewSaleSessionKey = useCallback(() => setSaleSessionKey(globalThis.crypto.randomUUID()), []);
  const [heldSales, setHeldSales] = useState<PosHeldSale[]>([]);
  const [lastAdded, setLastAdded] = useState<{ id: number; k: number } | null>(null);

  // Modals state
  const [isCloseShiftOpen, setIsCloseShiftOpen] = useState<boolean>(false);
  const [isCheckoutOpen, setIsCheckoutOpen] = useState<boolean>(false);
  const [isHoldOpen, setIsHoldOpen] = useState<boolean>(false);
  const [isHeldOpen, setIsHeldOpen] = useState<boolean>(false);
  const [isReturnOpen, setIsReturnOpen] = useState<boolean>(false);

  const scanInputRef = useRef<HTMLInputElement>(null);

  const loadInitialData = useCallback(() => {
    posMockService.getCurrentShift().then(setCurrentShift);
    posMockService.getBranch().then(setBranch);
    posMockService.getCashier().then(setCashier);
    posMockService.getHeldSales().then(setHeldSales);
    posMockService.getSettings().then((s) => setMaxDiscountPercent(s.max_discount_percent));
  }, []);

  useEffect(() => {
    loadInitialData();
    const unsub = posMockService.subscribe(loadInitialData);
    return unsub;
  }, [loadInitialData]);

  // Handle Add Product to Cart
  const handleAddToCart = (p: PosProduct) => {
    setCart((prev) => {
      const existing = prev.find((l) => l.id === p.id);
      const unitPrice = customer?.tier === 'wholesale' ? p.wholesale : p.price;
      if (existing) {
        return prev.map((l) => (l.id === p.id ? { ...l, qty: l.qty + 1 } : l));
      }
      return [...prev, { id: p.id, qty: 1, price: unitPrice, listPrice: p.price, manualPrice: false }];
    });
    setLastAdded({ id: p.id, k: Date.now() });
  };

  // Reprice non-manual lines whenever the customer changes so the cart always
  // matches the current customer's tier, regardless of scan/select order.
  const handleCustomerChange = useCallback((c: Customer | null) => {
    setCustomer(c);
    setCart((prev) =>
      prev.map((l) => {
        if (l.manualPrice) return l;
        if (c?.tier === 'wholesale') {
          const p = posMockService.getProductById(l.id);
          if (!p) return l;
          return { ...l, price: p.wholesale };
        }
        return { ...l, price: l.listPrice };
      }),
    );
  }, []);

  const handleClearCart = useCallback(() => {
    setCart([]);
    setDiscount(0);
    renewSaleSessionKey();
  }, [renewSaleSessionKey]);

  // Keyboard shortcuts (F2, F6, F8, Space)
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      const activeTag = (e.target as HTMLElement)?.tagName;
      const isInput = activeTag === 'INPUT' || activeTag === 'TEXTAREA';

      if (e.key === 'F2') {
        e.preventDefault();
        setCurrentScreen('terminal');
        setTimeout(() => scanInputRef.current?.focus(), 60);
      }
      if (e.key === 'F6') {
        e.preventDefault();
        setIsHeldOpen((o) => !o);
      }
      if (e.key === 'F8') {
        e.preventDefault();
        setIsReturnOpen((o) => !o);
      }
      if (e.key === ' ' && !isInput && cart.length > 0 && currentScreen === 'terminal') {
        e.preventDefault();
        setIsCheckoutOpen(true);
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [cart.length, currentScreen]);

  // Handle holding sale
  const handleHoldSale = async (note: string) => {
    if (!cart.length) return;
    await posMockService.holdSale(cart, customer, note);
    setCart([]);
    setCustomer(null);
    setDiscount(0);
    renewSaleSessionKey();
    setIsHoldOpen(false);
    toast('تم تعليق الفاتورة بنجاح');
  };

  // Handle resuming held sale: merge with quantity-dedupe so the same variant
  // never appears twice (no duplicate React keys, no distinct violations).
  const handleResumeHeld = (heldItem: PosHeldSale) => {
    const newLines = heldItem.items.map((i) => {
      const p = posMockService.getProductById(i.id);
      const price = p ? p.price : 100;
      return { id: i.id, qty: i.qty, price, listPrice: price, manualPrice: false };
    });

    setCart((prev) => {
      const next = prev.map((l) => ({ ...l }));
      for (const nl of newLines) {
        const existing = next.find((l) => l.id === nl.id);
        if (existing) existing.qty += nl.qty;
        else next.push({ ...nl });
      }
      return next;
    });
    if (heldItem.customer) {
      posMockService.getCustomers(heldItem.customer).then((matches) => {
        if (matches.length > 0) handleCustomerChange(matches[0]);
      });
    }
    posMockService.deleteHeldSale(heldItem.ref);
    setIsHeldOpen(false);
    setCurrentScreen('terminal');
    toast(`تم استرجاع الفاتورة ${heldItem.ref}`);
  };

  const handleDeleteHeld = async (heldItem: PosHeldSale) => {
    await posMockService.deleteHeldSale(heldItem.ref);
    toast(`تم حذف الفاتورة المعلقة ${heldItem.ref}`);
  };

  const handleSalePaid = () => {
    setCart([]);
    setCustomer(null);
    setDiscount(0);
    renewSaleSessionKey();
    toast('تم تسجيل الفاتورة بنجاح');
  };

  const toggleTheme = () => {
    setIsDark((d) => !d);
    document.documentElement.classList.toggle('theme-dark');
  };

  return (
    <div className={`app ${isDark ? 'theme-dark' : 'theme-light'}`} dir="rtl">
      {!currentShift ? (
        <OpenShiftGate onOpened={(shift) => setCurrentShift(shift)} />
      ) : (
        <>
          <PosHeader
            currentScreen={currentScreen}
            onScreenChange={setCurrentScreen}
            branch={branch}
            cashier={cashier}
            shift={currentShift}
            onOpenCloseShift={() => setIsCloseShiftOpen(true)}
            onOpenReturns={() => setIsReturnOpen(true)}
            isDark={isDark}
            onToggleDark={toggleTheme}
          />

          {currentScreen === 'terminal' && (
            <div className="sale">
              <Catalog
                cart={cart}
                customer={customer}
                onAdd={handleAddToCart}
                scanRef={scanInputRef}
              />
              <Cart
                cart={cart}
                setCart={setCart}
                customer={customer}
                setCustomer={handleCustomerChange}
                discount={discount}
                setDiscount={setDiscount}
                onHold={() => setIsHoldOpen(true)}
                onHeld={() => setIsHeldOpen(true)}
                heldCount={heldSales.length}
                onPay={() => setIsCheckoutOpen(true)}
                onClear={handleClearCart}
                lastAdded={lastAdded}
                toast={toast}
                cashier={cashier}
                maxDiscountPercent={maxDiscountPercent}
              />
            </div>
          )}

          {currentScreen === 'stock' && <StockPage branch={branch} />}

          {currentScreen === 'transfers' && (
            <TransfersPage toast={toast} branch={branch} />
          )}

          {currentScreen === 'stocktake' && <StocktakePage toast={toast} />}

          {/* Modals & Overlays */}
          <CloseShiftModal
            open={isCloseShiftOpen}
            onClose={() => setIsCloseShiftOpen(false)}
            shift={currentShift}
            onClosed={(closed) => {
              setCurrentShift(null);
              setIsCloseShiftOpen(false);
              toast(`تم إغلاق الوردية #${closed.shift_number} بنجاح`);
            }}
          />

          <CheckoutModal
            open={isCheckoutOpen}
            onClose={() => setIsCheckoutOpen(false)}
            cart={cart}
            customer={customer}
            discount={discount}
            sessionKey={saleSessionKey}
            onPaid={handleSalePaid}
            branch={branch}
            cashier={cashier}
          />

          <HoldModal
            open={isHoldOpen}
            onClose={() => setIsHoldOpen(false)}
            onHold={handleHoldSale}
            cart={cart}
            customer={customer}
          />

          <HeldDrawer
            open={isHeldOpen}
            onClose={() => setIsHeldOpen(false)}
            held={heldSales}
            onResume={handleResumeHeld}
            onDelete={handleDeleteHeld}
            cartBusy={cart.length > 0}
            branch={branch}
          />

          <ReturnModal
            open={isReturnOpen}
            onClose={() => setIsReturnOpen(false)}
            toast={toast}
          />
        </>
      )}
    </div>
  );
}
