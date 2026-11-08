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
import { usePosCart, posCartStore } from '../stores/pos-cart.store';
import { createScanDetector } from '../lib/barcode-scanner';
import { beepError, beepSuccess } from '../lib/sound';
import type {
  AppScreen,
  PosShift,
  Branch,
  CashierUser,
  PosProduct,
  Customer,
  PosHeldSale,
} from '../types/pos.types';

export interface PosAppProps {
  onLogout?: () => void;
}

export function PosApp({ onLogout }: PosAppProps = {}) {
  const toast = useToast();

  const [currentScreen, setCurrentScreen] = useState<AppScreen>('terminal');
  const [currentShift, setCurrentShift] = useState<PosShift | null>(null);
  const [branch, setBranch] = useState<Branch | null>(null);
  const [cashier, setCashier] = useState<CashierUser | null>(null);
  const [isDark, setIsDark] = useState<boolean>(false);

  // Cart, customer, discount and the live server quote live in the persisted cart store.
  const cart = usePosCart((s) => s.cart);
  const setCart = usePosCart((s) => s.setCart);
  const customer = usePosCart((s) => s.customer);
  const setCustomer = usePosCart((s) => s.setCustomer);
  const discount = usePosCart((s) => s.discount);
  const setDiscount = usePosCart((s) => s.setDiscount);
  const quote = usePosCart((s) => s.quote);
  const isQuoting = usePosCart((s) => s.isQuoting);
  const lastDeleted = usePosCart((s) => s.lastDeleted);
  const [heldSales, setHeldSales] = useState<PosHeldSale[]>([]);
  const [lastAdded, setLastAdded] = useState<{ id: number; k: number } | null>(null);
  const [maxDiscountPercent, setMaxDiscountPercent] = useState<number>(100);

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
    setMaxDiscountPercent(posMockService.getSettings().max_discount_percent);
  }, []);

  useEffect(() => {
    loadInitialData();
    const unsub = posMockService.subscribe(loadInitialData);
    return unsub;
  }, [loadInitialData]);

  // One cart per cashier+shift: survives refresh / power loss (key pos-cart:{userId}:{shiftId}).
  useEffect(() => {
    if (cashier) posCartStore.getState().bindContext(cashier.id, currentShift?.id ?? null);
  }, [cashier?.id, currentShift?.id]);

  // Add a product (click, scan bar or HID scanner): beep tells the cashier the outcome.
  const handleAddToCart = useCallback(
    (p: PosProduct) => {
      const inCart = posCartStore.getState().cart.find((l) => l.id === p.id)?.qty ?? 0;
      if (p.stock <= 0 || inCart + 1 > p.stock) {
        beepError();
        toast(p.stock <= 0 ? 'الصنف نفد من المخزون' : `الرصيد المتاح ${p.stock} فقط`, 'bad');
        return;
      }
      posCartStore.getState().addItem(p);
      setLastAdded({ id: p.id, k: Date.now() });
      beepSuccess();
    },
    [toast],
  );

  // Hardware scanner: works even when the scan box is not focused.
  useEffect(() => {
    const detect = createScanDetector({
      onScan: async (code) => {
        try {
          const p = await posMockService.lookupByBarcode(code);
          if (p) handleAddToCart(p);
          else {
            beepError();
            toast(`لا يوجد صنف بالباركود ${code}`, 'bad');
          }
        } catch {
          beepError();
          toast('تعذر البحث عن الباركود', 'bad');
        }
      },
    });
    window.addEventListener('keydown', detect, true);
    return () => window.removeEventListener('keydown', detect, true);
  }, [handleAddToCart, toast]);

  // Pay waits for the current quote so the payment screen opens with a confirmed total.
  const handlePay = useCallback(async () => {
    await posCartStore.getState().flushQuote();
    setIsCheckoutOpen(true);
  }, []);

  // Keyboard shortcuts (F2, F6, F8, Space, Ctrl+Z)
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
        void handlePay();
      }
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z' && !isInput) {
        if (posCartStore.getState().lastDeleted) {
          e.preventDefault();
          posCartStore.getState().undoDelete();
        }
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [cart.length, currentScreen, handlePay]);

  // Offer undo for 5s after a line is removed.
  const undoToastFor = useRef<number | null>(null);
  useEffect(() => {
    if (lastDeleted && undoToastFor.current !== lastDeleted.id) {
      undoToastFor.current = lastDeleted.id;
      toast('تم حذف الصنف — اضغط Ctrl+Z للتراجع');
    }
    if (!lastDeleted) undoToastFor.current = null;
  }, [lastDeleted, toast]);

  // Handle holding sale
  const handleHoldSale = async (note: string) => {
    if (!cart.length) return;
    await posMockService.holdSale(cart, customer, note);
    posCartStore.getState().clearCart();
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
    posCartStore.getState().clearCart();
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
            onLogout={onLogout}
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
                setCustomer={setCustomer as (c: Customer | null) => void}
                discount={discount}
                setDiscount={setDiscount}
                onHold={() => setIsHoldOpen(true)}
                onHeld={() => setIsHeldOpen(true)}
                heldCount={heldSales.length}
                onPay={() => void handlePay()}
                onClear={() => posCartStore.getState().clearCart()}
                lastAdded={lastAdded}
                toast={toast}
                cashier={cashier}
                quote={quote}
                isQuoting={isQuoting}
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
              posCartStore.getState().clearCart();
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
