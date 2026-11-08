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
import { posMockService } from '../services/pos-mock.service';
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

  // Cart and sale state
  const [cart, setCart] = useState<PosCartLine[]>([
    { id: 101, qty: 1, price: 450, listPrice: 450 },
    { id: 103, qty: 2, price: 285, listPrice: 285 },
  ]);
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [discount, setDiscount] = useState<number>(0);
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
      return [...prev, { id: p.id, qty: 1, price: unitPrice, listPrice: p.price }];
    });
    setLastAdded({ id: p.id, k: Date.now() });
  };

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
    setIsHoldOpen(false);
    toast('تم تعليق الفاتورة بنجاح');
  };

  // Handle resuming held sale
  const handleResumeHeld = (heldItem: PosHeldSale) => {
    const newLines = heldItem.items.map((i) => {
      const p = posMockService.getProductById(i.id);
      const price = p ? p.price : 100;
      return { id: i.id, qty: i.qty, price, listPrice: price };
    });

    setCart((prev) => [...prev, ...newLines]);
    if (heldItem.customer) {
      posMockService.getCustomers(heldItem.customer).then((matches) => {
        if (matches.length > 0) setCustomer(matches[0]);
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
                setCustomer={setCustomer}
                discount={discount}
                setDiscount={setDiscount}
                onHold={() => setIsHoldOpen(true)}
                onHeld={() => setIsHeldOpen(true)}
                heldCount={heldSales.length}
                onPay={() => setIsCheckoutOpen(true)}
                onClear={() => setCart([])}
                lastAdded={lastAdded}
                toast={toast}
                cashier={cashier}
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
