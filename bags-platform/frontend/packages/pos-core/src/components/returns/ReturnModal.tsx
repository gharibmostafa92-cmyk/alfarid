import React, { useState, useEffect, useRef } from 'react';
import ReactDOM from 'react-dom';
import { Icon } from '../ui/Icons';
import { egp, fmt, PImg, Empty, Seg, Check, Search, MoneyInput, cx, useEsc } from '../ui/SharedUI';
import { pos } from '../../services/pos-data-source';
import { isCancel, normalizeError } from '../../lib/pos-error';
import { normAr } from '../../lib/arabic';
import { beepError, beepSuccess } from '../../lib/sound';
import { proportionalRefundCents, toCents, fromCents } from '../../lib/money';
import type { PastSale, PosProduct } from '../../types/pos.types';

interface ReturnModalProps {
  open: boolean;
  onClose: () => void;
  toast: (msg: string, tone?: 'ok' | 'bad') => void;
  initialOrderNumber?: string | null;
}

export function ReturnModal({ open, onClose, toast, initialOrderNumber }: ReturnModalProps) {
  const [num, setNum] = useState<string>('');
  const [sale, setSale] = useState<PastSale | null>(null);
  const [recentSales, setRecentSales] = useState<PastSale[]>([]);
  const [loadingRecent, setLoadingRecent] = useState<boolean>(false);
  const [searching, setSearching] = useState<boolean>(false);
  const [err, setErr] = useState<string>('');
  const [mode, setMode] = useState<'return' | 'exchange'>('return');
  const [sel, setSel] = useState<Record<number, { qty: number; to: 'on_hand' | 'damaged' }>>({});
  const [repl, setRepl] = useState<{ id: number; qty: number }[]>([]);
  const [rq, setRq] = useState<string>('');
  const [busy, setBusy] = useState<boolean>(false);
  const submitting = useRef(false);
  const [allProducts, setAllProducts] = useState<PosProduct[]>([]);
  const [replQuoteTotal, setReplQuoteTotal] = useState<number | null>(null);
  const [replQuoteLines, setReplQuoteLines] = useState<
    Array<{ id: number; qty: number; unit_price: number; line_total: number; error?: string | null }>
  >([]);
  const [payMethod, setPayMethod] = useState<'cash' | 'card' | 'mixed' | 'credit'>('cash');
  const [exchangeCashPart, setExchangeCashPart] = useState<string>('');
  const exchangeCardRef = useRef<HTMLDivElement>(null);
  const replInputRef = useRef<HTMLInputElement>(null);

  const selectSale = (s: PastSale) => {
    setSale(s);
    setSel({});
    setRepl([]);
    setRq('');
    setErr('');
    if (s.payment_method !== 'credit' && payMethod === 'credit') {
      setPayMethod('cash');
    }
  };

  useEffect(() => {
    if (open && mode === 'exchange' && sale) {
      requestAnimationFrame(() => {
        exchangeCardRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        replInputRef.current?.focus();
      });
    }
  }, [open, mode, sale?.id]);

  useEffect(() => {
    if (open) {
      setNum(initialOrderNumber ?? '');
      setSale(null);
      setRecentSales([]);
      setSearching(false);
      setErr('');
      setMode('return');
      setSel({});
      setRepl([]);
      setRq('');
      setBusy(false);
      setReplQuoteTotal(null);
      setReplQuoteLines([]);
      setPayMethod('cash');
      setExchangeCashPart('');
      pos.getProducts().then(setAllProducts).catch((error) => {
        if (!isCancel(error)) toast(normalizeError(error).message, 'bad');
      });
      if (initialOrderNumber?.trim()) {
        setSearching(true);
        pos.getPastSale(initialOrderNumber.trim())
          .then((s) => {
            if (s) selectSale(s);
            else setErr('لم يتم العثور على فاتورة بهذا الرقم في الفرع');
          })
          .catch((error) => {
            if (!isCancel(error)) setErr(normalizeError(error).message);
          })
          .finally(() => setSearching(false));
      }
    }
  }, [open, initialOrderNumber, toast]);

  useEffect(() => {
    if (!open || sale) return;
    let cancelled = false;
    const timer = setTimeout(() => {
      setLoadingRecent(true);
      pos.getSalesHistory({ q: num.trim() || undefined, limit: 15 })
        .then((res) => {
          if (!cancelled) setRecentSales(res.data);
        })
        .catch(() => {
          if (!cancelled) setRecentSales([]);
        })
        .finally(() => {
          if (!cancelled) setLoadingRecent(false);
        });
    }, num.trim() ? 220 : 0);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [open, sale, num]);

  useEffect(() => {
    setReplQuoteTotal(null);
    setReplQuoteLines([]);
    if (!open || mode !== 'exchange' || !sale || !repl.length) return;
    const controller = new AbortController();
    const items = repl.map((r) => ({ product_variant_id: r.id, qty: r.qty }));
    void pos.quoteSale({ items, customer_id: sale.customer_id ?? null }, controller.signal)
      .then((q) => {
        if (controller.signal.aborted) return;
        setReplQuoteTotal(q.grand_total);
        // F-06: surface server line errors (e.g. insufficient stock) instead
        // of dropping them — they gate the submit button below.
        setReplQuoteLines(
          q.lines.map((l) => ({
            id: l.product_variant_id,
            qty: l.qty,
            unit_price: l.unit_price,
            line_total: l.line_total,
            error: l.error ?? null,
          })),
        );
      })
      .catch((error: unknown) => {
        if (!controller.signal.aborted && !isCancel(error)) {
          toast(normalizeError(error).message, 'bad');
        }
      });
    return () => controller.abort();
  }, [open, mode, repl, sale, toast]);

  useEsc(onClose, open);
  if (!open) return null;

  const lookupNumber = async (rawNumber: string) => {
    const clean = rawNumber.trim();
    if (!clean || searching) return;
    setSearching(true);
    try {
      const s = await pos.getPastSale(clean);
      if (s) {
        setNum(s.number);
        selectSale(s);
      } else {
        setSale(null);
        setErr('لم يتم العثور على فاتورة بهذا الرقم في الفرع');
      }
    } catch (error) {
      if (!isCancel(error)) {
        setErr(normalizeError(error).message);
      }
    } finally {
      setSearching(false);
    }
  };

  const find = () => lookupNumber(num);

  const loadExample = async () => {
    setSearching(true);
    try {
      const s = await pos.getPastSale('POS-NSR-10233');
      if (s) {
        setNum('POS-NSR-10233');
        setSale(s);
        setSel({});
        setErr('');
      }
    } finally {
      setSearching(false);
    }
  };

  // F-07: server-compatible proportional cents (never unit-price drift).
  const lineRefundCents = (i: PastSale['items'][number], selQty: number): number => {
    const soldNetCents = toCents(i.line_total ?? i.price * i.qty);
    const alreadyReturned = Math.max(0, i.qty - (i.returnable_qty ?? i.qty));
    return proportionalRefundCents(
      soldNetCents,
      i.qty,
      alreadyReturned,
      selQty,
      toCents(i.previous_refunded_amount ?? 0),
    );
  };

  const lineRefund = (i: PastSale['items'][number], selQty: number): number => fromCents(lineRefundCents(i, selQty));

  const retTotalCents = sale ? sale.items.reduce((s, i) => s + lineRefundCents(i, sel[i.id]?.qty || 0), 0) : 0;
  const retTotal = fromCents(retTotalCents);

  const fallbackReplTotalCents = repl.reduce((s, r) => {
    const p = pos.getProductById(r.id);
    return s + toCents(p?.price || 0) * r.qty;
  }, 0);
  const replTotalCents = replQuoteTotal != null ? toCents(replQuoteTotal) : fallbackReplTotalCents;
  const replTotal = fromCents(replTotalCents);

  const netCents = replTotalCents - retTotalCents;
  const net = fromCents(netCents);
  const selectedCount = Object.values(sel).filter((v) => v.qty > 0).length;
  const anySel = selectedCount > 0;
  const isBranchOrderReturn = sale?.channel === 'store' || sale?.channel === 'wholesale';
  const isSeparateExchangeFlow = Boolean(sale && (isBranchOrderReturn || sale.payment_method === 'credit'));
  const dueCents = isSeparateExchangeFlow ? replTotalCents : Math.max(0, netCents);
  const effectivePayMethod: 'cash' | 'card' | 'mixed' | 'credit' =
    payMethod === 'credit' && !(sale?.payment_method === 'credit' && sale?.customer_id) ? 'cash' : payMethod;
  // F-06: a quoted line error (e.g. replacement out of stock) blocks submit.
  const quoteBlocked = mode === 'exchange' && replQuoteLines.some((l) => l.error);
  const mixedCents = toCents(exchangeCashPart);
  const mixedValid =
    effectivePayMethod !== 'mixed' || (mode === 'exchange' && dueCents > 0 && mixedCents > 0 && mixedCents < dueCents);
  const canSubmit =
    anySel &&
    (mode === 'return' || (repl.length > 0 && replQuoteTotal != null)) &&
    !quoteBlocked &&
    !busy &&
    (mode === 'return' || dueCents <= 0 || effectivePayMethod === 'credit' || mixedValid);

  const returnableItems = sale ? sale.items.filter((i) => (i.returnable_qty ?? i.qty) > 0) : [];
  const allReturnableSelected =
    returnableItems.length > 0 &&
    returnableItems.every((i) => (sel[i.id]?.qty || 0) >= (i.returnable_qty ?? i.qty));

  const saleOriginalTotal = sale
    ? (sale.grand_total ?? sale.items.reduce((acc, i) => acc + (i.line_total ?? i.price * i.qty), 0))
    : 0;
  const saleChannelLabel =
    sale?.channel === 'wholesale' ? 'تاجر' : sale?.channel === 'store' ? 'متجر' : 'كاشير';

  const submitHint = !anySel
    ? 'حدد صنفاً واحداً على الأقل للإرجاع'
    : mode === 'exchange' && repl.length === 0
    ? 'أضف صنفاً بديلاً لإتمام الاستبدال'
    : quoteBlocked
    ? 'راجع تنبيهات المخزون في الأصناف البديلة'
    : mode === 'exchange' && dueCents > 0 && effectivePayMethod === 'mixed' && !mixedValid
    ? 'أدخل المبلغ النقدي (أكبر من 0 وأقل من الصافي)'
    : null;

  const toggleSelectAll = () => {
    if (!sale) return;
    if (allReturnableSelected) {
      setSel({});
    } else {
      const next: Record<number, { qty: number; to: 'on_hand' | 'damaged' }> = {};
      for (const i of returnableItems) {
        const maxQ = i.returnable_qty ?? i.qty;
        next[i.id] = { qty: maxQ, to: sel[i.id]?.to ?? 'on_hand' };
      }
      setSel(next);
    }
  };

  const normRq = normAr(rq);
  const results = normRq
    ? allProducts
        .filter(
          (p) =>
            normAr(p.name).includes(normRq) ||
            normAr(p.sku).includes(normRq) ||
            normAr(p.barcode).includes(normRq),
        )
        .slice(0, 8)
    : [];

  const addReplacementProduct = (p: PosProduct) => {
    if (p.stock <= 0) {
      beepError();
      toast('الصنف البديل غير متوفر في المخزون', 'bad');
      return;
    }
    const curQty = repl.find((x) => x.id === p.id)?.qty ?? 0;
    if (curQty >= p.stock) {
      beepError();
      toast(`الرصيد المتاح ${p.stock} فقط`, 'bad');
      return;
    }
    setRepl((r) =>
      r.find((x) => x.id === p.id)
        ? r.map((x) => (x.id === p.id ? { ...x, qty: Math.min(p.stock, x.qty + 1) } : x))
        : [...r, { id: p.id, qty: 1 }],
    );
    setRq('');
    beepSuccess();
  };

  const handleReplacementEnter = async () => {
    if (!normRq) return;
    const exact = allProducts.find((p) => normAr(p.barcode) === normRq || normAr(p.sku) === normRq);
    if (exact) {
      addReplacementProduct(exact);
      return;
    }
    const firstAvailable = results.find((p) => p.stock > 0);
    if (firstAvailable && !/^\d{8,}$/.test(normRq)) {
      addReplacementProduct(firstAvailable);
      return;
    }
    try {
      const found = await pos.lookupByBarcode(normRq);
      if (found) addReplacementProduct(found);
      else {
        beepError();
        toast('لا يوجد صنف مطابق للباركود أو الكود', 'bad');
      }
    } catch {
      beepError();
      toast('تعذر البحث عن الصنف البديل', 'bad');
    }
  };

  const handleSubmit = async () => {
    if (!canSubmit || !sale || submitting.current) return;
    submitting.current = true;
    setBusy(true);
    try {
      const returnPayload = Object.entries(sel)
        .filter(([, v]) => v.qty > 0)
        .map(([id, v]) => ({
          id: Number(id),
          qty: v.qty,
          return_to: v.to,
        }));

      const orderItemsPayload = Object.entries(sel)
        .filter(([, v]) => v.qty > 0)
        .map(([variantId, v]) => {
          const line = sale.items.find((i) => i.id === Number(variantId));
          return line?.order_item_id
            ? { order_item_id: line.order_item_id, qty: v.qty, return_to: v.to }
            : null;
        })
        .filter((x): x is { order_item_id: number; qty: number; return_to: 'on_hand' | 'damaged' } => x !== null);

      if (mode === 'return') {
        if (isBranchOrderReturn) {
          if (orderItemsPayload.length === 0) throw new Error('تعذر تحديد بنود الطلب للمرتجع.');
          const res = await pos.receiveBranchOrderReturn(sale.id, {
            reason_type: 'change_of_mind',
            items: orderItemsPayload,
          });
          toast(`تم استلام مرتجع الطلب #${res.return_number} في مخزون الفرع دون خصم من الدرج`, 'ok');
        } else {
          const res = await pos.processReturn(sale.id, returnPayload);
          const cardRefund = res?.card_refund_amount ?? 0;
          const cashRefund = res?.cash_refund_amount ?? 0;
          if ((res.account_credit_amount ?? 0) > 0) {
            toast(`تم تسجيل إشعار دائن بقيمة ${egp(res.account_credit_amount!)} ج.م على حساب التاجر`, 'ok');
          } else if (cardRefund > 0 && cashRefund > 0) {
            toast(`تم تسجيل المرتجع. رد ${cashRefund} ج نقداً و${cardRefund} ج على الكارت`, 'ok');
          } else if (cardRefund > 0) {
            toast(`تم تسجيل المرتجع. يجب رد ${cardRefund} جنيه على الكارت يدويًا`, 'ok');
          } else if (cashRefund > 0) {
            toast(`تم تسجيل المرتجع. رد ${cashRefund} جنيه نقداً من الدرج`, 'ok');
          } else {
            toast('تم تسجيل المرتجع وتحديث المخزون', 'ok');
          }
        }
        onClose();
        return;
      }

      // Exchange mode
      if (isSeparateExchangeFlow) {
        if (isBranchOrderReturn) {
          if (orderItemsPayload.length === 0) throw new Error('تعذر تحديد بنود الطلب للاستبدال.');
          await pos.receiveBranchOrderReturn(sale.id, {
            reason: `استبدال من نقطة البيع للطلب ${sale.number}`,
            reason_type: 'change_of_mind',
            items: orderItemsPayload,
          });
        } else {
          await pos.processReturn(sale.id, returnPayload);
        }

        const totalDue = fromCents(dueCents);
        const cashAmount =
          effectivePayMethod === 'cash'
            ? totalDue
            : effectivePayMethod === 'mixed'
            ? fromCents(mixedCents)
            : 0;
        const cardAmount =
          effectivePayMethod === 'card'
            ? totalDue
            : effectivePayMethod === 'mixed'
            ? fromCents(dueCents - mixedCents)
            : 0;

        try {
          const created = await pos.createSale({
            items: repl.map((r) => ({ product_variant_id: r.id, qty: r.qty })),
            customer_id: sale.customer_id ?? null,
            payment_method: effectivePayMethod,
            cash_amount: cashAmount,
            card_amount: cardAmount,
            notes: `فاتورة استبدال للطلب ${sale.number}`,
          });
          toast(`تم الاستبدال: تسجيل مرتجع ${sale.number} وإصدار فاتورة بديلة #${created.invoiceNumber}`, 'ok');
          onClose();
        } catch (saleErr) {
          toast(`تم تسجيل المرتجع بنجاح، لكن تعذر إصدار الفاتورة البديلة: ${normalizeError(saleErr).message}`, 'bad');
        }
        return;
      }

      const paymentOptions = {
        method: effectivePayMethod === 'credit' ? 'cash' : effectivePayMethod,
        ...(netCents > 0 && effectivePayMethod === 'mixed'
          ? { cashAmount: fromCents(mixedCents), cardAmount: fromCents(netCents - mixedCents) }
          : {}),
      };
      const exchangePayload = repl.map((r) => ({
        id: r.id,
        qty: r.qty,
      }));
      const res = await pos.processExchange(
        sale.id,
        returnPayload,
        exchangePayload,
        net,
        sale.customer_id,
        paymentOptions,
      );
      const cardRefund = res?.card_refund_amount ?? 0;
      const cashRefund = res?.cash_refund_amount ?? 0;
      if (cardRefund > 0 && cashRefund > 0) {
        toast(`تم الاستبدال. رد ${cashRefund} ج نقداً و${cardRefund} ج على الكارت`, 'ok');
      } else if (cardRefund > 0) {
        toast(`تم الاستبدال. يجب رد ${cardRefund} جنيه على الكارت يدويًا`, 'ok');
      } else if (cashRefund > 0) {
        toast(`تم الاستبدال. رد ${cashRefund} جنيه نقداً من الدرج`, 'ok');
      } else {
        toast('تم الاستبدال وإصدار فاتورة جديدة', 'ok');
      }
      onClose();
    } catch (error) {
      if (!isCancel(error)) {
        toast(normalizeError(error).message, 'bad');
      }
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  };

  return ReactDOM.createPortal(
    <div
      dir="rtl"
      className={document.querySelector('.app')?.classList.contains('theme-dark') ? 'theme-dark' : ''}
    >
      <div className="pos-overlay" onClick={onClose} />
      <div
        className={cx('pos-modal pos-sheet-mobile ret-modal', sale && mode === 'exchange' && 'is-exchange')}
        style={{ width: sale && mode === 'exchange' ? 'min(1080px, calc(100vw - 32px))' : 'min(920px, calc(100vw - 32px))' }}
        data-screen-label="07 Return & Exchange"
        role="dialog"
        aria-modal="true"
        aria-label="مرتجع أو استبدال"
      >
        <div className="pop ret-pop">
          <div className="pos-sheet-handle" aria-hidden="true" />
          <header className="ret-header">
            <div className="ret-header-title">
              <span className="ret-header-ic" aria-hidden="true">
                <Icon n={mode === 'return' ? 'ret' : 'swap'} s={18} />
              </span>
              <div className="grow col" style={{ gap: 2 }}>
                <h3 className="fs-16 fw-6">مرتجع / استبدال</h3>
                <span className="fs-12 t-2">
                  {!sale
                    ? 'اختر الفاتورة من القائمة أو امسح باركود الإيصال'
                    : mode === 'return'
                    ? 'اختر الأصناف المرتجعة وحالتها لحساب المبلغ المسترد'
                    : 'حدد المرتجع وأضف الصنف البديل لحساب الفارق فوراً'}
                </span>
              </div>
            </div>
            <div className="ret-header-actions">
              {sale && (
                <Seg
                  value={mode}
                  onChange={setMode}
                  options={[
                    { value: 'return', label: 'إرجاع فقط' },
                    { value: 'exchange', label: 'استبدال بصنف آخر' },
                  ]}
                />
              )}
              <button className="icon-btn sm" onClick={onClose} aria-label="إغلاق">
                <Icon n="x" s={16} />
              </button>
            </div>
          </header>

          <div className="ret-body">
            {!sale && (
              <div className="ret-head">
                <div className="scanbar grow" style={{ height: 46 }}>
                  <span className="scan-ic" style={{ width: 28, height: 28 }}>
                    <Icon n="barcode" s={16} w={2} />
                  </span>
                  <input
                    autoFocus
                    value={num}
                    onChange={(e) => setNum(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && find()}
                    placeholder="امسح باركود الإيصال أو ابحث برقم الفاتورة أو اسم العميل…"
                    className="ltr"
                    style={{ textAlign: 'right' }}
                  />
                  {num && (
                    <button
                      type="button"
                      className="icon-btn sm"
                      onClick={() => {
                        setNum('');
                        setErr('');
                      }}
                      aria-label="مسح البحث"
                    >
                      <Icon n="x" s={14} />
                    </button>
                  )}
                </div>
                <button
                  className="btn btn-primary ret-search-btn"
                  disabled={!num.trim() || searching}
                  onClick={find}
                >
                  <Icon n="search" s={15} />
                  {searching ? 'جارِ البحث…' : 'بحث'}
                </button>
                {import.meta.env.DEV && (
                  <button className="btn btn-ghost ret-example-btn" onClick={loadExample} disabled={searching}>
                    مثال
                  </button>
                )}
              </div>
            )}

            {err && (
              <div className="banner b-bad" role="alert">
                <Icon n="alert" s={16} />
                <span>{err}</span>
              </div>
            )}

            {!sale ? (
              <div className="ret-empty-wrap">
                {loadingRecent && recentSales.length === 0 ? (
                  <div className="t-3 fs-13" style={{ padding: '32px 0', textAlign: 'center' }}>
                    جارٍ تحميل فواتير الفرع الأخيرة…
                  </div>
                ) : recentSales.length > 0 ? (
                  <div className="ret-recent-box">
                    <div className="ret-recent-head">
                      <span className="section-label">
                        {num.trim() ? 'فواتير مطابقة للبحث' : 'أحدث فواتير الفرع'}
                      </span>
                      <span className="badge b-neutral num">{fmt(recentSales.length)}</span>
                    </div>
                    <div className="ret-recent-list">
                      {recentSales.map((s) => {
                        const returnable =
                          s.returnable_qty ?? s.items.reduce((acc, i) => acc + (i.returnable_qty ?? i.qty), 0);
                        const totalVal =
                          s.grand_total ?? s.items.reduce((acc, i) => acc + (i.line_total ?? i.price * i.qty), 0);
                        const channelLabel =
                          s.channel === 'wholesale' ? 'تاجر' : s.channel === 'store' ? 'متجر' : 'كاشير';
                        return (
                          <button
                            key={`${s.channel ?? 'pos'}-${s.id}`}
                            type="button"
                            className="ret-recent-row"
                            onClick={() => void lookupNumber(s.number)}
                          >
                            <div className="col" style={{ gap: 2, textAlign: 'right' }}>
                              <div className="row gap-2">
                                <bdi className="ltr mono fw-6">{s.number}</bdi>
                                <span className="badge b-info">{channelLabel}</span>
                                <span className="badge b-neutral">{s.method}</span>
                              </div>
                              <span className="fs-12 t-2 truncate">
                                {s.customer} · {s.date}
                              </span>
                            </div>
                            <div className="col" style={{ gap: 2, textAlign: 'left', flexShrink: 0 }}>
                              <b className="num fs-13">{egp(totalVal)} ج</b>
                              <span className={cx('fs-11', returnable > 0 ? 't-ok' : 't-3')}>
                                {returnable > 0 ? `متاح للإرجاع: ${fmt(returnable)}` : 'مرتجع بالكامل'}
                              </span>
                            </div>
                          </button>
                        );
                      })}
                    </div>
                  </div>
                ) : (
                  <Empty
                    icon="ret"
                    title="ابدأ بمسح إيصال البيع الأصلي أو البحث باسم العميل"
                    body="يمكنك مسح الإيصال أو البحث برقم الفاتورة أو اسم/هاتف العميل لعرض الفواتير المتاحة للإرجاع."
                  />
                )}
              </div>
            ) : (
              <>
                <div className="ret-sale-summary">
                  <div className="order-meta grow">
                    <div>
                      <span>رقم الفاتورة</span>
                      <div className="row gap-1 wrap">
                        <b className="mono">{sale.number}</b>
                        <span className="badge b-info" style={{ height: 20, fontSize: 10.5, padding: '0 7px' }}>
                          {saleChannelLabel}
                        </span>
                      </div>
                    </div>
                    <div>
                      <span>التاريخ</span>
                      <b>{sale.date}</b>
                    </div>
                    <div>
                      <span>العميل / الدفع</span>
                      <b className="truncate">
                        {sale.customer} · {sale.method}
                      </b>
                    </div>
                    <div>
                      <span>إجمالي الفاتورة</span>
                      <b className="num">{egp(saleOriginalTotal)} ج.م</b>
                    </div>
                  </div>
                  {!initialOrderNumber && (
                    <button
                      type="button"
                      className="btn btn-secondary btn-sm ret-change-sale-btn"
                      onClick={() => {
                        setSale(null);
                        setSel({});
                        setRepl([]);
                        setRq('');
                        setErr('');
                      }}
                    >
                      <Icon n="chevRight" s={14} />
                      تغيير الفاتورة
                    </button>
                  )}
                </div>

                {isBranchOrderReturn && mode === 'return' && (
                  <div className="banner b-info">
                    <Icon n="info" s={15} />
                    <span>سيتم استلام القطع في مخزون الفرع وتسجيل طلب المرتجع للمراجعة المالية دون خصم نقدية من الدرج.</span>
                  </div>
                )}

                {mode === 'exchange' && isSeparateExchangeFlow && (
                  <div className="banner b-info">
                    <Icon n="info" s={15} />
                    <span>
                      {isBranchOrderReturn
                        ? 'سيتم استلام المرتجع لمخزون الفرع للمراجعة المالية، وإصدار فاتورة POS جديدة بالأصناف البديلة.'
                        : 'سيتم تسجيل مرتجع الفاتورة الآجلة كإشعار دائن على حساب العميل، وإصدار فاتورة جديدة بالأصناف البديلة.'}
                    </span>
                  </div>
                )}

                <div className={cx(mode === 'exchange' && 'ret-exchange-grid')}>
                  <div className="card ret-items-card">
                    <div className="ret-items-head">
                      <div className="row gap-2">
                        <span className="section-label">بنود الفاتورة</span>
                        <span className="badge b-neutral">{fmt(sale.items.length)} أصناف</span>
                      </div>
                      {returnableItems.length > 0 && (
                        <button
                          type="button"
                          className="btn btn-ghost btn-sm"
                          onClick={toggleSelectAll}
                        >
                          <Icon n={allReturnableSelected ? 'x' : 'check'} s={13} />
                          {allReturnableSelected ? 'إلغاء التحديد' : 'تحديد الكل'}
                        </button>
                      )}
                    </div>

                    <div className="table-wrap">
                      <table className="tbl ret-tbl">
                        <thead>
                          <tr>
                            <th style={{ width: 40 }}></th>
                            <th>الصنف</th>
                            <th className="n">السعر</th>
                            <th className="n">المباع</th>
                            <th style={{ textAlign: 'center' }}>كمية الإرجاع</th>
                            <th>حالة الصنف الراجع</th>
                            <th className="n">المسترد</th>
                          </tr>
                        </thead>
                        <tbody>
                          {sale.items.map((i) => {
                            const p = pos.getProductById(i.id);
                            const s = sel[i.id] || { qty: 0, to: 'on_hand' };
                            const retMax = i.returnable_qty != null ? i.returnable_qty : i.qty;
                            const fullyReturned = retMax <= 0;
                            const on = !fullyReturned && s.qty > 0;
                            const upd = (v: Partial<{ qty: number; to: 'on_hand' | 'damaged' }>) =>
                              setSel((x) => ({ ...x, [i.id]: { ...s, ...v } }));
                            const netUnit = i.net_unit_price ?? (i.line_total != null && i.qty > 0 ? i.line_total / i.qty : i.price);
                            const hasDiscount = Math.abs(netUnit - i.price) > 0.01;
                            const prevReturned = Math.max(0, i.qty - retMax);

                            return (
                              <tr key={i.id} className={cx(on && 'selected', fullyReturned && 'ret-row-done')}>
                                <td className="ret-col-check">
                                  {fullyReturned ? (
                                    <span className="badge b-neutral" style={{ fontSize: 10, padding: '2px 6px' }}>
                                      —
                                    </span>
                                  ) : (
                                    <Check
                                      on={on}
                                      onChange={(v) => upd({ qty: v ? 1 : 0 })}
                                    />
                                  )}
                                </td>
                                <td
                                  className="ret-col-item"
                                  onClick={() => !fullyReturned && upd({ qty: on ? 0 : 1 })}
                                  style={{ cursor: fullyReturned ? 'default' : 'pointer' }}
                                >
                                  <div className="row gap-2">
                                    <PImg p={p} size="xs" />
                                    <div className="grow" style={{ minWidth: 0 }}>
                                      <div className="fs-13 fw-5 truncate">{i.name || p?.name || `صنف #${i.id}`}</div>
                                      <div className="mono t-3" style={{ fontSize: 11 }}>
                                        {i.sku || p?.sku || ''}
                                      </div>
                                    </div>
                                  </div>
                                </td>
                                <td className="n ret-col-price" data-label="السعر">
                                  <div>{egp(netUnit)}</div>
                                  {hasDiscount && (
                                    <div className="fs-11 t-3" style={{ textDecoration: 'line-through' }}>
                                      {egp(i.price)}
                                    </div>
                                  )}
                                </td>
                                <td className="n ret-col-sold" data-label="المباع">
                                  <div>{fmt(i.qty)}</div>
                                  {prevReturned > 0 && !fullyReturned && (
                                    <div className="fs-11 t-warn">متبقي {fmt(retMax)}</div>
                                  )}
                                </td>
                                <td className="ret-col-qty" style={{ textAlign: 'center' }}>
                                  {fullyReturned ? (
                                    <span className="badge b-neutral" style={{ fontSize: 11 }}>
                                      مرتجع بالكامل
                                    </span>
                                  ) : (
                                    <div className="stepper-q" style={{ opacity: on ? 1 : 0.6 }}>
                                      <button
                                        type="button"
                                        disabled={s.qty >= retMax}
                                        aria-label="زيادة كمية الإرجاع"
                                        onClick={() => upd({ qty: Math.min(retMax, s.qty + 1) })}
                                      >
                                        <Icon n="plus" s={13} />
                                      </button>
                                      <span>{fmt(s.qty)}</span>
                                      <button
                                        type="button"
                                        disabled={s.qty <= 0}
                                        aria-label="تقليل كمية الإرجاع"
                                        onClick={() => upd({ qty: Math.max(0, s.qty - 1) })}
                                      >
                                        <Icon n="minus" s={13} />
                                      </button>
                                    </div>
                                  )}
                                </td>
                                <td className="ret-col-cond">
                                  <div
                                    className="seg"
                                    style={{
                                      opacity: on ? 1 : 0.45,
                                      pointerEvents: on ? 'auto' : 'none',
                                    }}
                                  >
                                    <button
                                      type="button"
                                      aria-pressed={s.to === 'on_hand'}
                                      onClick={() => upd({ to: 'on_hand' })}
                                    >
                                      <span className="row gap-1">
                                        <i className="ret-dot ok" />
                                        سليم
                                      </span>
                                    </button>
                                    <button
                                      type="button"
                                      aria-pressed={s.to === 'damaged'}
                                      onClick={() => upd({ to: 'damaged' })}
                                    >
                                      <span className="row gap-1">
                                        <i className="ret-dot bad" />
                                        تالف
                                      </span>
                                    </button>
                                  </div>
                                </td>
                                {/* F-07: proportional cents, matching what the server will refund. */}
                                <td className="n fw-6 ret-col-refund" data-label="المسترد">
                                  {on ? egp(lineRefund(i, s.qty)) : '—'}
                                </td>
                              </tr>
                            );
                          })}
                        </tbody>
                      </table>
                    </div>
                  </div>

                  {mode === 'exchange' && (
                    <div ref={exchangeCardRef} className="card card-pad col gap-3 ret-exchange-card">
                      <div className="row between wrap gap-2">
                        <div className="row gap-2">
                          <span className="section-label">الأصناف البديلة</span>
                          {repl.length > 0 && (
                            <span className="badge b-acc">{fmt(repl.reduce((a, r) => a + r.qty, 0))} قطعة</span>
                          )}
                        </div>
                        <span className="fs-11 t-3">
                          {isSeparateExchangeFlow ? 'تُصدر فاتورة بديلة جديدة' : 'عملية استبدال واحدة'}
                        </span>
                      </div>
                      <div className="col gap-2">
                        <div className="scanbar" style={{ height: 42 }}>
                          <span className="scan-ic" style={{ width: 26, height: 26 }}>
                            <Icon n="barcode" s={15} w={2} />
                          </span>
                          <input
                            ref={replInputRef}
                            value={rq}
                            onChange={(e) => setRq(e.target.value)}
                            onKeyDown={(e) => {
                              if (e.key === 'Enter') {
                                e.preventDefault();
                                void handleReplacementEnter();
                              }
                            }}
                            placeholder="امسح الباركود أو ابحث عن الصنف البديل…"
                            aria-label="بحث عن الصنف البديل"
                          />
                          {rq && (
                            <button
                              type="button"
                              className="icon-btn sm"
                              onClick={() => {
                                setRq('');
                                replInputRef.current?.focus();
                              }}
                              aria-label="مسح بحث الصنف البديل"
                            >
                              <Icon n="x" s={13} />
                            </button>
                          )}
                        </div>
                        {normRq && (
                          results.length > 0 ? (
                            <div className="ret-search-results">
                              {results.map((p) => {
                                const stockTone = p.stock <= 0 ? 'b-bad' : p.stock <= p.reorder ? 'b-warn' : 'b-ok';
                                return (
                                  <button
                                    key={p.id}
                                    type="button"
                                    className="scan-row"
                                    // F-06: never offer a replacement that is known to be out of stock.
                                    disabled={p.stock <= 0}
                                    title={p.stock <= 0 ? 'نفد من المخزون' : undefined}
                                    onClick={() => addReplacementProduct(p)}
                                  >
                                    <PImg p={p} size="xs" />
                                    <div className="grow" style={{ minWidth: 0 }}>
                                      <div className="fs-13 fw-5 truncate">{p.name}</div>
                                      <div className="mono t-3" style={{ fontSize: 11 }}>{p.sku}</div>
                                    </div>
                                    <span className={cx('badge', stockTone)}>رصيد {fmt(p.stock)}</span>
                                    <span className="price">{egp(p.price)}</span>
                                  </button>
                                );
                              })}
                            </div>
                          ) : (
                            <div className="ret-repl-empty" style={{ padding: '10px 12px' }}>
                              <Icon n="search" s={14} />
                              <span>لا توجد أصناف مطابقة لـ «{rq.trim()}»</span>
                            </div>
                          )
                        )}
                      </div>

                    {repl.length > 0 ? (
                      <div className="col gap-2">
                        {repl.map((r) => {
                          const p = pos.getProductById(r.id);
                          if (!p) return null;
                          const quotedLine = replQuoteLines.find((ql) => ql.id === r.id);
                          const lineTotal = quotedLine ? quotedLine.line_total : p.price * r.qty;
                          const wholesaleApplied = Boolean(
                            quotedLine && p.wholesale < p.price && Math.abs(quotedLine.unit_price - p.wholesale) < 0.01,
                          );
                          return (
                            <div key={r.id} className={cx('ret-repl-row', quotedLine?.error && 'is-error')}>
                              <PImg p={p} size="xs" />
                              <div className="grow" style={{ minWidth: 0 }}>
                                <div className="row gap-2">
                                  <span className="fs-13 fw-5 truncate">{p.name}</span>
                                  {wholesaleApplied && (
                                    <span className="badge b-acc" style={{ height: 18, fontSize: 10 }}>
                                      جملة
                                    </span>
                                  )}
                                </div>
                                <div className="fs-11 t-3 num">{egp(quotedLine?.unit_price ?? p.price)} ج / قطعة</div>
                              </div>
                              <div className="stepper-q">
                                {/* F-06: the + stepper never exceeds known on-hand stock. */}
                                <button
                                  type="button"
                                  disabled={p.stock <= r.qty}
                                  title={p.stock <= r.qty ? 'الرصيد المتاح ' + p.stock + ' فقط' : undefined}
                                  aria-label="زيادة كمية الصنف البديل"
                                  onClick={() =>
                                    setRepl((x) =>
                                      x.map((y) => (y.id === r.id ? { ...y, qty: Math.min(p.stock, y.qty + 1) } : y))
                                    )
                                  }
                                >
                                  <Icon n="plus" s={13} />
                                </button>
                                <span>{fmt(r.qty)}</span>
                                <button
                                  type="button"
                                  aria-label="تقليل كمية الصنف البديل"
                                  onClick={() =>
                                    setRepl((x) =>
                                      r.qty > 1
                                        ? x.map((y) =>
                                            y.id === r.id ? { ...y, qty: y.qty - 1 } : y
                                          )
                                        : x.filter((y) => y.id !== r.id)
                                    )
                                  }
                                >
                                  <Icon n="minus" s={13} />
                                </button>
                              </div>
                              <span className="price ret-repl-price">
                                {egp(lineTotal)}
                              </span>
                              <button
                                type="button"
                                className="icon-btn sm"
                                aria-label="حذف الصنف البديل"
                                onClick={() => setRepl((x) => x.filter((y) => y.id !== r.id))}
                              >
                                <Icon n="trash" s={14} />
                              </button>
                              {quotedLine?.error && (
                                <div className="fs-12 t-bad ret-repl-err" role="alert">
                                  {quotedLine.error}
                                </div>
                              )}
                            </div>
                          );
                        })}
                      </div>
                    ) : (
                      <div className="ret-repl-empty">
                        <Icon n="search" s={15} />
                        <span>ابحث وأضف الصنف البديل لحساب الفارق تلقائياً.</span>
                      </div>
                    )}

                    {mode === 'exchange' && dueCents > 0 && (
                      <div className="col gap-2 ret-pay-box">
                        <div className="row between wrap gap-2">
                          <span className="fs-13 fw-5 t-2">
                            {isSeparateExchangeFlow ? 'طريقة دفع الفاتورة البديلة:' : 'طريقة تحصيل الفرق:'}
                          </span>
                          <Seg
                            value={effectivePayMethod}
                            onChange={setPayMethod}
                            options={[
                              { value: 'cash', label: 'نقدي' },
                              { value: 'card', label: 'بطاقة' },
                              { value: 'mixed', label: 'مختلط' },
                              ...(sale?.payment_method === 'credit' && sale?.customer_id
                                ? [{ value: 'credit' as const, label: 'على الحساب' }]
                                : []),
                            ]}
                          />
                        </div>
                        {effectivePayMethod === 'mixed' && (
                          <div className="ret-mixed-grid">
                            <div className="field">
                              <span className="label fs-12 t-2">المبلغ نقداً</span>
                              <MoneyInput
                                value={exchangeCashPart}
                                onChange={setExchangeCashPart}
                                placeholder="0"
                              />
                            </div>
                            <div className="field">
                              <span className="label fs-12 t-2">المتبقي على البطاقة</span>
                              <div className="ret-card-remainder num">
                                {egp(Math.max(0, fromCents(dueCents - mixedCents)))} ج.م
                              </div>
                            </div>
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                )}
                </div>
              </>
            )}
          </div>

          {sale && (
            <footer className="ret-footer">
              {mode === 'return' ? (
                <div
                  className="net grow"
                  style={{
                    background: anySel ? 'var(--bad-bg)' : 'var(--bg-sunken)',
                    color: anySel ? 'var(--bad)' : 'var(--text-2)',
                  }}
                >
                  <Icon n="cash" s={18} />
                  <div className="col" style={{ gap: 1 }}>
                    <span className="fs-13 fw-6">
                      {sale.payment_method === 'credit' ? 'إشعار دائن على حساب التاجر' : 'قيمة المرتجع'}
                    </span>
                    <span className="fs-11" style={{ opacity: 0.85 }}>
                      {submitHint ?? `${fmt(selectedCount)} أصناف محددة للإرجاع`}
                    </span>
                  </div>
                  <span className="ms-auto display fs-20 fw-7 num">{egp(retTotal)} ج.م</span>
                </div>
              ) : (
                <div
                  className="net grow"
                  style={{
                    background:
                      !anySel || !repl.length
                        ? 'var(--bg-sunken)'
                        : net > 0
                        ? 'var(--ok-bg)'
                        : net < 0
                        ? 'var(--bad-bg)'
                        : 'var(--info-bg)',
                    color:
                      !anySel || !repl.length
                        ? 'var(--text-2)'
                        : net > 0
                        ? 'var(--ok)'
                        : net < 0
                        ? 'var(--bad)'
                        : 'var(--info)',
                  }}
                >
                  <Icon n="swap" s={18} />
                  <div className="col" style={{ gap: 1 }}>
                    <span className="fs-13 fw-6">
                      {isSeparateExchangeFlow
                        ? 'فاتورة بديلة جديدة'
                        : net > 0
                        ? 'تحصيل فرق من العميل'
                        : net < 0
                        ? 'رد فرق للعميل'
                        : 'بدون فروق'}
                    </span>
                    <span className="fs-11 num" style={{ opacity: 0.85 }}>
                      {submitHint
                        ? `${submitHint} · مرتجع ${egp(retTotal)} ← بديل ${egp(replTotal)}`
                        : `مرتجع ${egp(retTotal)} ← بديل ${egp(replTotal)}`}
                    </span>
                  </div>
                  <span className="ms-auto display fs-20 fw-7 num">
                    {egp(isSeparateExchangeFlow ? replTotal : Math.abs(net))} ج.م
                  </span>
                </div>
              )}
              <button
                className="btn btn-primary btn-lg ret-submit-btn"
                disabled={!canSubmit}
                onClick={handleSubmit}
              >
                <Icon n="check" s={16} w={2.4} />
                {busy
                  ? 'جارِ التنفيذ…'
                  : mode === 'return'
                  ? 'تأكيد المرتجع'
                  : 'تأكيد الاستبدال'}
              </button>
            </footer>
          )}
        </div>
      </div>
    </div>,
    document.body
  );
}
