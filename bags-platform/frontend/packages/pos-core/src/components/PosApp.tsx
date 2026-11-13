import React, { useState, useEffect, useRef, useCallback } from 'react';
import { createPortal } from 'react-dom';
import { hasPermission } from '@alfarid/utils';
import { PosHeader } from './layout/PosHeader';
import { Catalog } from './terminal/Catalog';
import { Cart } from './terminal/Cart';
import { OpenShiftGate } from './shift/OpenShiftGate';
import { CloseShiftModal } from './shift/CloseShiftModal';
import { CheckoutModal } from './checkout/CheckoutModal';
import { ThermalReceipt } from './checkout/ReceiptDone';
import { HoldModal } from './checkout/HoldModal';
import { HeldDrawer } from './checkout/HeldDrawer';
import { ReturnModal } from './returns/ReturnModal';
import { StockPage } from './inventory/StockPage';
import { TransfersPage } from './inventory/TransfersPage';
import { StocktakePage } from './inventory/StocktakePage';
import { Icon } from './ui/Icons';
import { useToast } from './ui/SharedUI';
import { pos } from '../services/pos-data-source';
import { usePosCart, posCartStore } from '../stores/pos-cart.store';
import { createScanDetector, isRapidScannerKey } from '../lib/barcode-scanner';
import { beepError, beepSuccess } from '../lib/sound';
import { createPendingSaleManager, type PendingSaleDraft } from '../lib/pending-sale';
import { createTabCoordinator } from '../lib/tab-coordinator';
import { getLastReceipt, clearReceiptArchive, type ArchivedReceipt } from '../lib/receipt-archive';
import { isCancel, normalizeError } from '../lib/pos-error';
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
  const [starting, setStarting] = useState(true);
  const [startupError, setStartupError] = useState<string | null>(null);
  const [startupAttempt, setStartupAttempt] = useState(0);
  const canRefund = hasPermission(cashier?.perms, 'pos.sales.refund');
  const [isDark, setIsDark] = useState<boolean>(false);

  // Network & multi-tab coordination
  const [isOnline, setIsOnline] = useState<boolean>(typeof navigator !== 'undefined' ? navigator.onLine : true);
  const [hasTabConflict, setHasTabConflict] = useState<boolean>(false);
  const coordinatorRef = useRef<ReturnType<typeof createTabCoordinator> | null>(null);
  const [pendingSale, setPendingSale] = useState<PendingSaleDraft | null>(null);
  const pendingOwner = cashier && currentShift ? `${cashier.id}:${currentShift.id}` : undefined;
  const canRecoverPending = Boolean(pendingOwner && pendingSale?.owner === pendingOwner);
  const [confirmingPending, setConfirmingPending] = useState<boolean>(false);
  const confirmingPendingRef = useRef(false);

  // Cart, customer, discount and the live server quote live in the persisted cart store.
  const cart = usePosCart((s) => s.cart);
  const setCart = usePosCart((s) => s.setCart);
  const customer = usePosCart((s) => s.customer);
  const setCustomer = usePosCart((s) => s.setCustomer);
  const discount = usePosCart((s) => s.discount);
  const setDiscount = usePosCart((s) => s.setDiscount);
  const quote = usePosCart((s) => s.quote);
  const isQuoting = usePosCart((s) => s.isQuoting);
  const heldSaleIds = usePosCart((s) => s.heldSaleIds);
  const lastDeleted = usePosCart((s) => s.lastDeleted);
  const [heldSales, setHeldSales] = useState<PosHeldSale[]>([]);
  const [lastAdded, setLastAdded] = useState<{ id: number; k: number } | null>(null);
  const [maxDiscountPercent, setMaxDiscountPercent] = useState<number>(100);
  // Server id of the resumed held sale: sent as held_sale_id at checkout so the
  // server consumes it atomically. Cleared whenever the cart session ends.
  const [resumedHeldId, setResumedHeldId] = useState<number | null>(null);

  // Modals state
  const [closingShift, setClosingShift] = useState<PosShift | null>(null);
  const [isCheckoutOpen, setIsCheckoutOpen] = useState<boolean>(false);
  const [isHoldOpen, setIsHoldOpen] = useState<boolean>(false);
  const [isHeldOpen, setIsHeldOpen] = useState<boolean>(false);
  const [isReturnOpen, setIsReturnOpen] = useState<boolean>(false);
  const [printReceipt, setPrintReceipt] = useState<ArchivedReceipt | null>(null);
  const holdingRef = useRef(false);
  const deletingHeldRef = useRef(false);

  const scanInputRef = useRef<HTMLInputElement>(null);

  const [heldCount, setHeldCount] = useState(0);

  const [staleDrafts, setStaleDrafts] = useState<PendingSaleDraft[]>([]);
  const [discardArmed, setDiscardArmed] = useState<string | null>(null);

  // F-05: owner-scoped lookup (a previous shift's draft never blocks this
  // one) plus the stale-draft list for the supervised-discard banner.
  const checkPendingSale = useCallback(() => {
    const mgr = createPendingSaleManager({ owner: pendingOwner });
    setPendingSale(mgr.getSaved());
    setStaleDrafts(pendingOwner ? mgr.listDrafts().filter((d) => d.owner !== pendingOwner) : mgr.listDrafts());
    setDiscardArmed(null);
  }, [pendingOwner]);

  const handleDiscardDraft = (owner: string) => {
    if (discardArmed !== owner) {
      setDiscardArmed(owner);
      return;
    }
    createPendingSaleManager({ owner: pendingOwner }).discardDraft(owner);
    toast('تم تجاهل المسودة بعد المراجعة');
    checkPendingSale();
  };

  useEffect(() => {
    checkPendingSale();
  }, [checkPendingSale]);

  // Multi-tab broadcast channel coordination
  useEffect(() => {
    const coordinator = createTabCoordinator({
      onDuplicate: () => setHasTabConflict(true),
    });
    coordinatorRef.current = coordinator;
    coordinator.start();
    return () => {
      coordinator.destroy();
      coordinatorRef.current = null;
    };
  }, []);

  // Window online/offline listener
  useEffect(() => {
    const handleOnline = () => setIsOnline(true);
    const handleOffline = () => setIsOnline(false);
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  }, []);

  useEffect(() => {
    let active = true;
    let unsubscribe: (() => void) | undefined;
    const refresh = async () => {
      const [shift, loadedBranch, loadedCashier] = await Promise.all([
        pos.getCurrentShift(), pos.getBranch(), pos.getCashier(),
      ]);
      if (!active) return;
      setCurrentShift(shift);
      setBranch(loadedBranch);
      setCashier(loadedCashier);
      setHeldCount(pos.getHeldCount());
      setMaxDiscountPercent(pos.getSettings().max_discount_percent);
    };

    setStarting(true);
    setStartupError(null);
    void pos.bootstrap().then(async () => {
      if (!active) return;
      unsubscribe = pos.subscribe(() => void refresh().catch(() => undefined));
      await refresh();
      if (active) setStarting(false);
    }).catch((error: Error) => {
      if (!active) return;
      setStartupError(error.message);
      setStarting(false);
    });
    return () => {
      active = false;
      unsubscribe?.();
    };
  }, [startupAttempt]);

  const loadHeldSales = useCallback(async () => {
    const list = await pos.getHeldSales();
    setHeldSales(list);
    setHeldCount(list.length);
  }, []);

  useEffect(() => {
    if (isHeldOpen && currentShift) {
      void loadHeldSales().catch((error: Error) => toast(error.message, 'bad'));
    }
  }, [isHeldOpen, currentShift?.id, loadHeldSales, toast]);

  // One cart per cashier+shift: survives refresh / power loss (key pos-cart:{userId}:{shiftId}).
  const cartShiftId = (closingShift ?? currentShift)?.id ?? null;
  useEffect(() => {
    if (cashier) posCartStore.getState().bindContext(cashier.id, cartShiftId);
  }, [cashier?.id, cartShiftId]);

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
    if (!currentShift || currentScreen !== 'terminal' || isCheckoutOpen || isReturnOpen || isHoldOpen || isHeldOpen || closingShift || hasTabConflict) return;
    const detect = createScanDetector({
      onScan: async (code) => {
        try {
          const p = await pos.lookupByBarcode(code);
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
  }, [currentShift?.id, currentScreen, isCheckoutOpen, isReturnOpen, isHoldOpen, isHeldOpen, closingShift, hasTabConflict, handleAddToCart, toast]);

  // Pay waits for the current quote so the payment screen opens with a confirmed total.
  const handlePay = useCallback(async () => {
    // F-05: only this shift's own draft gates payment (owner-scoped).
    if (createPendingSaleManager({ owner: pendingOwner }).getSaved()) {
      toast('يجب تأكيد الفاتورة المعلقة أولًا', 'bad');
      return;
    }
    if (isCheckoutOpen) return;
    const result = await posCartStore.getState().flushQuote();
    if (!result || posCartStore.getState().isQuoting) {
      toast(posCartStore.getState().quoteError ?? 'تعذر تأكيد أسعار الفاتورة', 'bad');
      return;
    }
    if (result.lines.some((line) => line.error)) return;
    setPrintReceipt(null);
    setIsCheckoutOpen(true);
  }, [isCheckoutOpen, toast, pendingOwner]);

  useEffect(() => {
    const clearPrint = () => setPrintReceipt(null);
    window.addEventListener('afterprint', clearPrint);
    return () => window.removeEventListener('afterprint', clearPrint);
  }, []);

  // Last physical key time for the F-12 scanner guard (updated first, read for the previous gap).
  const lastKeyAt = useRef<number | null>(null);

  // Keyboard shortcuts (F2, F6, F8, Space, Ctrl+Z, +/-, Ctrl+P)
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      // F-12: rapid key runs are HID-scanner spew, not human typing.
      const rapidScannerKeys = isRapidScannerKey(Date.now(), lastKeyAt.current);
      lastKeyAt.current = Date.now();
      if (!currentShift || isCheckoutOpen || closingShift || hasTabConflict) return;
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
      if (e.key === 'F8' && canRefund) {
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
      // F-12: a Code-128 barcode containing +/- must not nudge the cart.
      if (!isInput && (e.key === '+' || e.key === '=') && !rapidScannerKeys) {
        if (lastAdded) {
          e.preventDefault();
          const currentItem = cart.find((l) => l.id === lastAdded.id);
          if (currentItem) {
            const p = pos.getProductById(currentItem.id);
            const stock = currentItem.stock ?? p?.stock;
            if (stock === undefined || currentItem.qty + 1 > stock) {
              beepError();
              toast(stock === undefined ? 'تعذر التحقق من المخزون' : `الرصيد المتاح ${stock} فقط`, 'bad');
              return;
            }
            setCart((c) => c.map((x) => (x.id === lastAdded.id ? { ...x, qty: x.qty + 1 } : x)));
          }
        }
      }
      // F-12: same guard for '-'/'_' inside barcodes such as SKU-1042.
      if (!isInput && (e.key === '-' || e.key === '_') && !rapidScannerKeys) {
        if (lastAdded) {
          e.preventDefault();
          const currentItem = cart.find((l) => l.id === lastAdded.id);
          if (currentItem) {
            setCart((c) =>
              c
                .map((x) => (x.id === lastAdded.id ? { ...x, qty: x.qty - 1 } : x))
                .filter((x) => x.qty > 0),
            );
          }
        }
      }
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p') {
        e.preventDefault();
        // F-17: reprints are scoped to the current cashier's own receipts.
        const lastRc = getLastReceipt(cashier?.id);
        if (lastRc) {
          setPrintReceipt(lastRc);
          requestAnimationFrame(() => {
            requestAnimationFrame(() => window.print());
          });
          toast(`طباعة آخر فاتورة (${lastRc.invoiceNumber})`);
        } else {
          toast('لا توجد فواتير سابقة للطباعة', 'bad');
        }
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [cart, currentShift, currentScreen, isCheckoutOpen, closingShift, hasTabConflict, canRefund, handlePay, lastAdded, toast, cashier?.id]);

  // Offer undo for 5s after a line is removed.
  const undoToastFor = useRef<number | null>(null);
  useEffect(() => {
    if (lastDeleted && undoToastFor.current !== lastDeleted.id) {
      undoToastFor.current = lastDeleted.id;
      toast('تم حذف الصنف — اضغط Ctrl+Z للتراجع');
    }
    if (!lastDeleted) undoToastFor.current = null;
  }, [lastDeleted, toast]);

  // Confirm pending sale
  const handleConfirmPendingSale = async () => {
    if (!pendingSale || !canRecoverPending || isCheckoutOpen || confirmingPendingRef.current) return;
    confirmingPendingRef.current = true;
    setConfirmingPending(true);
    try {
      const mgr = createPendingSaleManager({ owner: pendingOwner });
      await mgr.executeWithRetry(
        pendingSale.payload,
        pendingSale.key,
        (p, k) => pos.createSale(p, k)
      );
      posCartStore.getState().clearCart();
      setPendingSale(null);
      toast('تم تأكيد تسجيل الفاتورة المعلقة بنجاح');
    } catch (err: any) {
      toast(err?.message || 'تعذر تأكيد الفاتورة، حاول مرة أخرى', 'bad');
    } finally {
      confirmingPendingRef.current = false;
      setConfirmingPending(false);
      checkPendingSale();
    }
  };

  // Handle holding sale
  const handleHoldSale = async (note: string) => {
    if (!cart.length || holdingRef.current) return;
    holdingRef.current = true;
    try {
      await pos.holdSale(cart, customer, note, discount);
      posCartStore.getState().clearCart();
      setResumedHeldId(null);
      setIsHoldOpen(false);
      toast('تم تعليق الفاتورة بنجاح');
    } catch (err) {
      if (!isCancel(err)) {
        toast(normalizeError(err).message, 'bad');
      }
    } finally {
      holdingRef.current = false;
    }
  };

  // Handle resuming held sale: merge with quantity-dedupe so the same variant
  // never appears twice (no duplicate React keys, no distinct violations).
  const handleResumeHeld = async (heldItem: PosHeldSale) => {
    try {
      const resumed = await pos.resumeHeldSale(heldItem.ref);
      // F-03: catalog first so system prices resolve; the stored manual flag
      // (never a price comparison) decides overrides; no magic-100 fallback.
      await pos.loadCatalog().catch(() => undefined);
      const newLines = resumed.items.map((i) => {
        const p = pos.getProductById(i.id);
        const unit = i.unit_price ?? p?.price ?? 0;
        return {
          id: i.id,
          qty: i.qty,
          price: unit,
          listPrice: p?.price ?? unit,
          manualPrice: i.manual_price ?? false,
          name: p?.name,
          stock: p?.stock,
          wholesale: p?.wholesale,
          cat: p?.cat,
          h: p?.h,
        };
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
      if (resumed.serverId) {
        posCartStore.getState().addHeldSaleId(resumed.serverId);
      }
      setResumedHeldId(resumed.serverId ?? null);
      const resumedDiscount = (resumed.items ?? []).reduce((sum, item) => sum + (Number(item.discount_amount) || 0), 0);
      if (resumedDiscount > 0) {
        setDiscount((prev) => prev + resumedDiscount);
      }
      // F-04: only an id-keyed customer object is ever adopted. Name-based
      // fallback matching was removed: duplicate names rebound wholesale
      // pricing to the wrong customer.
      if (resumed.customerObj) {
        setCustomer(resumed.customerObj);
      }
      setIsHeldOpen(false);
      // F-04: the drawer keeps the leased row (the service already refreshed
      // it); hiding it locally created ghost invoices. It leaves the drawer
      // on checkout (handleSalePaid) or explicit delete.
      setCurrentScreen('terminal');
      toast(`تم استرجاع الفاتورة ${resumed.ref}`);
    } catch (err) {
      if (!isCancel(err)) {
        toast(normalizeError(err).message, 'bad');
      }
    }
  };

  const handleDeleteHeld = async (heldItem: PosHeldSale) => {
    if (deletingHeldRef.current) return;
    deletingHeldRef.current = true;
    try {
      await pos.deleteHeldSale(heldItem.ref);
      setHeldSales((list) => list.filter((item) => item.ref !== heldItem.ref));
      toast(`تم حذف الفاتورة المعلقة ${heldItem.ref}`);
    } catch (err) {
      if (!isCancel(err)) {
        toast(normalizeError(err).message, 'bad');
      }
    } finally {
      deletingHeldRef.current = false;
    }
  };

  const handleSalePaid = () => {
    // F-04: the server consumed held_sale_ids atomically — drop those drawer
    // rows now that they can never ghost back.
    const consumed = posCartStore.getState().heldSaleIds;
    if (consumed.length > 0) {
      pos.forgetHeldSaleRefs(consumed);
      setHeldSales((list) => list.filter((h) => !(h.serverId != null && consumed.includes(h.serverId))));
    }
    posCartStore.getState().clearCart();
    setResumedHeldId(null);
    checkPendingSale();
    toast('تم تسجيل الفاتورة بنجاح');
  };

  const handleClearCart = useCallback(() => {
    posCartStore.getState().clearCart();
    setResumedHeldId(null);
  }, []);

  // F-17: logging out ends the trust boundary — drop every cached receipt
  // so the next cashier on this terminal cannot reprint them.
  const handleLogout = useCallback(() => {
    clearReceiptArchive();
    onLogout?.();
  }, [onLogout]);

  const toggleTheme = () => {
    setIsDark((d) => !d);
    document.documentElement.classList.toggle('theme-dark');
  };

  return (
    <div className={`app ${isDark ? 'theme-dark' : 'theme-light'}`} dir="rtl">
      {/* Multi-tab conflict overlay */}
      {hasTabConflict && (
        <div
          className="tab-conflict-overlay"
          style={{
            position: 'fixed',
            inset: 0,
            zIndex: 99999,
            background: 'rgba(0,0,0,0.85)',
            color: '#fff',
            display: 'grid',
            placeItems: 'center',
            backdropFilter: 'blur(6px)',
          }}
        >
          <div style={{ maxWidth: 460, textAlign: 'center', padding: 32 }}>
            <Icon n="alert" s={48} style={{ color: 'var(--bad)', marginBottom: 16 }} />
            <h2 className="fs-22">مفتوح في نافذة أخرى</h2>
            <p className="t-2" style={{ color: 'rgba(255,255,255,0.75)', marginTop: 8 }}>
              نظام نقاط البيع مفتوح حاليًا في نافذة أو تبويب آخر. لتجنب تكرار العمليات ومشاكل التزامن، يرجى العمل من النافذة المفتوحة أو إغلاقها ثم تحديث هذه الصفحة.
            </p>
            <button
              className="btn btn-primary btn-lg"
              style={{ marginTop: 20 }}
              onClick={() => {
                coordinatorRef.current?.claim();
                setHasTabConflict(false);
              }}
            >
              الاستمرار في هذه النافذة
            </button>
          </div>
        </div>
      )}

      {/* Offline banner */}
      {!isOnline && (
        <div
          className="banner b-warn"
          style={{
            borderRadius: 0,
            justifyContent: 'center',
            padding: '8px 16px',
            fontSize: 13,
            fontWeight: 500,
          }}
        >
          <Icon n="wifi" s={16} />
          {/* F-15: no offline sale queue exists — checkout needs the connection back. */}
          <span>غير متصل بالإنترنت — يلزم استعادة الاتصال لإتمام عمليات البيع</span>
        </div>
      )}

      {/* Unconfirmed pending sale banner */}
      {pendingSale && (
        <div
          className="banner b-bad row between"
          style={{
            borderRadius: 0,
            padding: '8px 16px',
            fontSize: 13,
            fontWeight: 500,
          }}
        >
          <div className="row gap-2">
            <Icon n="alert" s={16} />
            <span>فيه فاتورة لم يتأكد تسجيلها</span>
            {!canRecoverPending && <span>يلزم الرجوع للمسؤول إذا كانت الوردية الأصلية غير متاحة.</span>}
          </div>
          <button
            className="btn btn-sm"
            style={{
              background: '#fff',
              color: 'var(--bad)',
              fontWeight: 600,
              padding: '4px 12px',
            }}
            disabled={confirmingPending || !canRecoverPending || isCheckoutOpen}
            onClick={handleConfirmPendingSale}
          >
            {confirmingPending ? 'جارِ التأكيد…' : 'تأكيد الآن'}
          </button>
        </div>
      )}
      {/* F-05: stale drafts from previous shifts — warn, never block; supervised discard only. */}
      {!pendingSale && staleDrafts.length > 0 && (
        <div
          className="banner b-warn row between"
          style={{
            borderRadius: 0,
            padding: '8px 16px',
            fontSize: 13,
            fontWeight: 500,
          }}
        >
          <div className="row gap-2">
            <Icon n="alert" s={16} />
            <span>
              فيه {staleDrafts.length > 1 ? `${staleDrafts.length} مسودات` : 'مسودة'} لم يتأكد تسجيلها من
              وردية سابقة — راجع فواتير الوردية قبل التجاهل.
            </span>
          </div>
          <div className="row gap-2">
            {staleDrafts.slice(0, 2).map((d) => (
              <button
                key={d.owner ?? 'legacy'}
                className="btn btn-sm"
                style={{
                  background: '#fff',
                  color: 'var(--warn)',
                  fontWeight: 600,
                  padding: '4px 12px',
                }}
                onClick={() => d.owner && handleDiscardDraft(d.owner)}
              >
                {discardArmed === d.owner ? 'تأكيد التجاهل؟ اضغط مجددًا' : 'تجاهل بعد المراجعة'}
              </button>
            ))}
          </div>
        </div>
      )}

      {starting ? (
        <div className="gate" role="status">جارِ تحميل نقطة البيع…</div>
      ) : startupError ? (
        <div className="gate">
          <div className="gate-card" role="alert">
            <p>{startupError}</p>
            <button className="btn btn-primary" onClick={() => setStartupAttempt((attempt) => attempt + 1)}>
              إعادة المحاولة
            </button>
          </div>
        </div>
      ) : !currentShift ? (
        <OpenShiftGate onOpened={(shift) => setCurrentShift(shift)} />
      ) : (
        <>
          <PosHeader
            currentScreen={currentScreen}
            onScreenChange={setCurrentScreen}
            branch={branch}
            cashier={cashier}
            shift={currentShift}
            isOnline={isOnline}
            onOpenCloseShift={() => {
              // F-05: only this shift's own draft gates closing (owner-scoped).
              if (createPendingSaleManager({ owner: pendingOwner }).getSaved()) {
                toast('يجب تأكيد الفاتورة المعلقة قبل إغلاق الوردية', 'bad');
                return;
              }
              setClosingShift(currentShift);
            }}
            onOpenReturns={canRefund ? () => setIsReturnOpen(true) : undefined}
            isDark={isDark}
            onToggleDark={toggleTheme}
            onLogout={handleLogout}
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
                heldCount={heldCount}
                onPay={() => void handlePay()}
                onClear={handleClearCart}
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
          <CheckoutModal
            open={isCheckoutOpen}
            onClose={() => {
              setIsCheckoutOpen(false);
              requestAnimationFrame(() => scanInputRef.current?.focus());
            }}
            cart={cart}
            customer={customer}
            quote={quote}
            isQuoting={isQuoting}
            heldSaleId={resumedHeldId}
            heldSaleIds={heldSaleIds}
            onPaid={handleSalePaid}
            onAttemptSettled={checkPendingSale}
            pendingOwner={pendingOwner}
            branch={branch}
            cashier={cashier}
            isDark={isDark}
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
            open={canRefund && isReturnOpen}
            onClose={() => setIsReturnOpen(false)}
            toast={toast}
          />
        </>
      )}
      {closingShift && (
        <CloseShiftModal
          open
          shift={closingShift}
          onClose={() => setClosingShift(null)}
          onClosed={() => setClosingShift(null)}
          onCommitted={(closed) => {
            posCartStore.getState().clearCart();
            setCurrentShift(null);
            toast(`تم إغلاق الوردية #${closed.shift_number} بنجاح`);
          }}
          isDark={isDark}
        />
      )}
      {printReceipt && createPortal(
        <div className="print-receipt-host" dir="rtl" aria-hidden="true">
          <ThermalReceipt
            invoiceNumber={printReceipt.invoiceNumber}
            total={printReceipt.total}
            method={printReceipt.method}
            received={printReceipt.received}
            change={printReceipt.change}
            cashA={printReceipt.cashA}
            cardA={printReceipt.cardA}
            cart={printReceipt.cart}
            customer={printReceipt.customer}
            discount={printReceipt.discount}
            notes={printReceipt.notes}
            branch={printReceipt.branch ?? branch}
            cashier={printReceipt.cashier ?? cashier}
            timestamp={printReceipt.timestamp}
          />
        </div>,
        document.body,
      )}
    </div>
  );
}
