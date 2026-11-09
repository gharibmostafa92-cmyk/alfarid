import React, { useState, useEffect, useRef } from 'react';
import ReactDOM from 'react-dom';
import { Icon } from '../ui/Icons';
import { egp, fmt, PImg, Api, Kbd, MoneyInput, Field, cx, useEsc } from '../ui/SharedUI';
import { ReceiptDone } from './ReceiptDone';
import { pos as posMockService } from '../../services/pos-data-source';
import type { PosCartLine, Customer, Branch, CashierUser, QuoteResult } from '../../types/pos.types';
import { toCents, fromCents } from '../../lib/money';
import { buildCheckoutPayload } from '../../lib/checkout';
import { createPendingSaleManager } from '../../lib/pending-sale';
import { pushReceipt, type ArchivedReceipt } from '../../lib/receipt-archive';
import { normalizeError } from '../../lib/pos-error';

interface CheckoutModalProps {
  open: boolean;
  onClose: () => void;
  cart: PosCartLine[];
  customer: Customer | null;
  quote: QuoteResult | null;
  isQuoting: boolean;
  onPaid: () => void;
  onAttemptSettled: () => void;
  pendingOwner: string | undefined;
  branch?: Branch | null;
  cashier?: CashierUser | null;
}

export function CheckoutModal({
  open,
  onClose,
  cart,
  customer,
  quote,
  isQuoting,
  onPaid,
  onAttemptSettled,
  pendingOwner,
  branch,
  cashier,
}: CheckoutModalProps) {
  const [method, setMethod] = useState<'cash' | 'card' | 'mixed'>('cash');
  const [received, setReceived] = useState<string>('');
  const [cashPart, setCashPart] = useState<string>('');
  const [notes, setNotes] = useState<string>('');
  const [cardDigits, setCardDigits] = useState<string>('');
  const [stage, setStage] = useState<'pay' | 'sending' | 'done'>('pay');
  const [retryStatus, setRetryStatus] = useState<string>('');
  const [errorMsg, setErrorMsg] = useState<string>('');
  const [idem, setIdem] = useState<string>('');
  const [receipt, setReceipt] = useState<ArchivedReceipt | null>(null);

  const isSubmittingRef = useRef(false);

  const subtotal = quote?.subtotal ?? 0;
  const discount = quote?.discount_total ?? 0;
  const totalCents = toCents(quote?.grand_total);
  const total = fromCents(totalCents);

  useEffect(() => {
    if (open) {
      setMethod('cash');
      setReceived('');
      setCashPart('');
      setNotes('');
      setCardDigits('');
      setStage('pay');
      setRetryStatus('');
      setErrorMsg('');
      setIdem('');
      setReceipt(null);
    }
  }, [open]);

  useEffect(() => {
    if (!open || stage !== 'pay') return;
    const h = (e: KeyboardEvent) => {
      if ((e.target as HTMLElement).tagName === 'TEXTAREA' || (e.target as HTMLElement).tagName === 'INPUT') return;
      if (e.key === 'F1') {
        e.preventDefault();
        setMethod('cash');
      }
      if (e.key === 'F3') {
        e.preventDefault();
        setMethod('card');
      }
      if (e.key === 'F4') {
        e.preventDefault();
        setMethod('mixed');
      }
    };
    window.addEventListener('keydown', h);
    return () => window.removeEventListener('keydown', h);
  }, [open, stage]);

  useEsc(() => stage !== 'sending' && onClose(), open);
  if (!open) return null;

  const receivedCents = toCents(received);
  const cashCents = Math.min(toCents(cashPart), totalCents);
  const rec = fromCents(receivedCents);
  const change = fromCents(receivedCents - totalCents);
  const cashA = fromCents(cashCents);
  const cardA = fromCents(totalCents - cashCents);

  const valid = quote && !isQuoting && quote.lines.length > 0 && !quote.lines.some((line) => line.error) && (
    method === 'card' ||
    (method === 'cash' && receivedCents >= totalCents) ||
    (method === 'mixed' && cashCents > 0 && cashCents < totalCents)
  );

  const roundUps = [
    ...new Set([
      total,
      Math.ceil(total / 50) * 50,
      Math.ceil(total / 100) * 100,
      Math.ceil(total / 200) * 200,
      Math.ceil(total / 500) * 500,
    ]),
  ]
    .filter((v) => v >= total)
    .slice(0, 4);

  const press = (k: string) => {
    const set = method === 'mixed' ? setCashPart : setReceived;
    const cur = method === 'mixed' ? cashPart : received;
    if (k === '⌫') set(cur.slice(0, -1));
    else if (k === '.') {
      if (!cur.includes('.')) set((cur || '0') + '.');
    } else set(cur + k);
  };

  const confirm = async () => {
    if (!pendingOwner || !quote || !valid || stage !== 'pay' || isSubmittingRef.current) return;
    isSubmittingRef.current = true;
    setStage('sending');
    setRetryStatus('');
    setErrorMsg('');

    const finalNotes = (method === 'card' || method === 'mixed') && cardDigits.trim()
      ? (notes.trim() ? `كارت ****${cardDigits.trim()} · ${notes.trim()}` : `كارت ****${cardDigits.trim()}`)
      : notes.trim();

    const payload = buildCheckoutPayload(quote, {
      customerId: customer?.id ?? null,
      method,
      cashCents,
      overridePrice: cart.some((line) => line.manualPrice),
      notes: finalNotes,
    });

    try {
      const pendingManager = createPendingSaleManager({ owner: pendingOwner });
      const draft = pendingManager.prepare(payload);
      setIdem(draft.key);
      const res = await pendingManager.executeWithRetry(
        draft.payload,
        draft.key,
        (p, k) => posMockService.createSale(p, k),
        (attempt) => {
          setRetryStatus(`جاري التأكد… (محاولة ${attempt})`);
        }
      );

      const completedReceipt = {
        invoiceNumber: res.invoiceNumber,
        total,
        method,
        received: rec,
        change,
        cashA,
        cardA,
        cart,
        customer,
        discount,
        notes: finalNotes,
        branch,
        cashier,
        timestamp: Date.now(),
      };
      pushReceipt(completedReceipt);
      setReceipt(completedReceipt);
      setStage('done');
      onPaid();
    } catch (err: any) {
      const apiErr = normalizeError(err);
      setErrorMsg(apiErr.status === 0 ? 'لم يتأكد تسجيل الفاتورة بعد. أعد المحاولة لتأكيدها.' : apiErr.message);
      setStage('pay');
    } finally {
      isSubmittingRef.current = false;
      onAttemptSettled();
    }
  };

  return ReactDOM.createPortal(
    <div
      dir="rtl"
      className={document.querySelector('.app')?.classList.contains('theme-dark') ? 'theme-dark' : ''}
    >
      <div className="overlay" onClick={() => stage === 'pay' && onClose()} />
      <div
        className="modal"
        style={{ width: 'min(960px, calc(100vw - 32px))' }}
        data-screen-label="05 Checkout"
        role="dialog"
        aria-modal="true"
      >
        <div className="pop">
          {stage !== 'done' ? (
            <div className="co">
              <div className="co-main">
                <div className="row between">
                  <h3 className="fs-16">الدفع وطرق التحصيل</h3>
                  <button className="icon-btn sm" disabled={stage === 'sending'} onClick={onClose} aria-label="إغلاق">
                    <Icon n="x" s={16} />
                  </button>
                </div>
                <div className="due">
                  <div className="col gap-1">
                    <span className="fs-13 t-2">المطلوب سداده</span>
                    <span className="v">
                      {egp(total)}
                      <small>ج.م</small>
                    </span>
                  </div>
                  {customer && (
                    <span className="badge b-neutral">
                      <Icon n="users" s={13} />
                      {customer.name}
                    </span>
                  )}
                </div>
                <div className="methods" role="radiogroup">
                  {(
                    [
                      ['cash', 'نقدي', 'cash', 'F1'],
                      ['card', 'بطاقة / فيزا', 'card', 'F3'],
                      ['mixed', 'دفع مختلط', 'split', 'F4'],
                    ] as const
                  ).map(([v, l, ic, k]) => (
                    <button
                      key={v}
                      role="radio"
                      className="method"
                      aria-checked={method === v}
                      onClick={() => setMethod(v)}
                    >
                      <Kbd>{k}</Kbd>
                      <Icon n={ic} s={24} w={1.6} />
                      {l}
                    </button>
                  ))}
                </div>

                {method === 'cash' && (
                  <div className="grid" style={{ gridTemplateColumns: '1fr 220px', gap: 16 }}>
                    <div className="col gap-3">
                      <span className="fs-13 fw-5">المبلغ المستلم من العميل</span>
                      <MoneyInput
                        value={received}
                        onChange={setReceived}
                        autoFocus
                        placeholder={String(total)}
                        onEnter={confirm}
                      />
                      <div className="denoms">
                        {roundUps.map((v, i) => (
                          <button
                            key={v}
                            className="denom"
                            onClick={() => setReceived(String(v))}
                          >
                            {i === 0 && v === total ? (
                              <span className="fs-13" style={{ fontFamily: 'var(--f-body)' }}>
                                المبلغ بالضبط
                              </span>
                            ) : (
                              <>
                                {fmt(v)}
                                <small>ج</small>
                              </>
                            )}
                          </button>
                        ))}
                      </div>
                      <div className={cx('change', change < 0 && received && 'neg')}>
                        <span className="col">
                          <span className="fs-12" style={{ opacity: 0.75 }}>
                            {change < 0 && received
                              ? 'المبلغ غير كافٍ — متبقٍ'
                              : 'المتبقي للعميل (الفكة)'}
                          </span>
                        </span>
                        <span className="v">
                          {received ? egp(Math.abs(change)) : '٠'}
                          <small className="fs-13" style={{ opacity: 0.6, marginInlineStart: 4 }}>
                            ج
                          </small>
                        </span>
                      </div>
                    </div>
                    <div className="keypad">
                      {['7', '8', '9', '4', '5', '6', '1', '2', '3', '.', '0', '⌫'].map((k) => (
                        <button key={k} onClick={() => press(k)}>
                          {k}
                        </button>
                      ))}
                    </div>
                  </div>
                )}

                {method === 'card' && (
                  <div className="col gap-3">
                    <div
                      className="card card-pad row gap-4"
                      style={{ background: 'var(--bg-sunken)' }}
                    >
                      <div
                        style={{
                          width: 64,
                          height: 88,
                          borderRadius: 12,
                          background: 'var(--bg-ink)',
                          color: 'var(--on-ink)',
                          display: 'grid',
                          placeItems: 'center',
                          flexShrink: 0,
                        }}
                      >
                        <Icon n="card" s={26} w={1.5} />
                      </div>
                      <div className="col gap-1 grow">
                        <b className="fs-14">ادفع على ماكينة البطاقات</b>
                        <p className="fs-13 t-2">
                          أدخل مبلغ <b className="num t-1">{egp(total)} ج</b> على الماكينة، وبعد
                          خروج إيصال الموافقة اضغط «تأكيد الدفع».
                        </p>
                      </div>
                    </div>
                    <Field
                      label="آخر ٤ أرقام / رقم الموافقة"
                      hint="اختياري — يظهر في تقرير الوردية"
                    >
                      <input
                        className="input ltr num"
                        style={{ textAlign: 'right', maxWidth: 240 }}
                        placeholder="•••• 4821"
                        value={cardDigits}
                        onChange={(e) => setCardDigits(e.target.value.replace(/\D/g, '').slice(-4))}
                      />
                    </Field>
                  </div>
                )}

                {method === 'mixed' && (
                  <div className="col gap-3">
                    <div className="grid" style={{ gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                      <div className="col gap-2">
                        <span className="fs-13 fw-5 row gap-2">
                          <Icon n="cash" s={16} />
                          الجزء النقدي
                        </span>
                        <MoneyInput
                          size="md"
                          value={cashPart}
                          onChange={(v) =>
                            setCashPart(String(Math.min(Number(v || 0), total) || ''))
                          }
                          autoFocus
                          placeholder="0"
                        />
                      </div>
                      <div className="col gap-2">
                        <span className="fs-13 fw-5 row gap-2">
                          <Icon n="card" s={16} />
                          الجزء بالبطاقة <span className="t-3 fs-12">(تلقائي)</span>
                        </span>
                        <div
                          className="money-in md"
                          style={{ background: 'var(--bg-sunken)' }}
                        >
                          <span className="cur">ج.م</span>
                          <input
                            readOnly
                            value={cashPart ? String(cardA) : ''}
                            placeholder="—"
                          />
                        </div>
                      </div>
                    </div>
                    <div className="split-bar">
                      <span
                        style={{
                          width: `${total ? (cashA / total) * 100 : 0}%`,
                          background: 'var(--ok)',
                        }}
                      />
                      <span
                        style={{
                          width: `${total && cashPart ? (cardA / total) * 100 : 0}%`,
                          background: 'var(--info)',
                        }}
                      />
                    </div>
                    <div className="row between fs-12">
                      <span className="row gap-3">
                        <span className="row gap-1">
                          <i
                            style={{
                              width: 8,
                              height: 8,
                              borderRadius: 99,
                              background: 'var(--ok)',
                            }}
                          />
                          نقدي <b className="num">{egp(cashA)}</b>
                        </span>
                        <span className="row gap-1">
                          <i
                            style={{
                              width: 8,
                              height: 8,
                              borderRadius: 99,
                              background: 'var(--info)',
                            }}
                          />
                          بطاقة <b className="num">{egp(cashPart ? cardA : 0)}</b>
                        </span>
                      </span>
                      {valid ? (
                        <span className="t-ok row gap-1 fw-6">
                          <Icon n="check" s={14} w={2.5} />
                          المجموع = إجمالي الفاتورة
                        </span>
                      ) : (
                        <span className="t-3">حدد جزءًا نقديًا أقل من الإجمالي</span>
                      )}
                    </div>
                    <div className="row gap-2">
                      {[0.25, 0.5, 0.75].map((r) => (
                        <button
                          key={r}
                          className="note-chip"
                          onClick={() => setCashPart(String(Math.round(total * r)))}
                        >
                          {fmt(r * 100)}٪ نقدي
                        </button>
                      ))}
                    </div>
                  </div>
                )}

                <Field
                  label={
                    <>
                      ملاحظات على الفاتورة <span className="t-3 fs-12">(اختياري)</span>
                    </>
                  }
                >
                  <input
                    className="input"
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    placeholder="تظهر في أسفل الإيصال"
                  />
                </Field>
                {errorMsg && (
                  <div className="banner b-bad" style={{ margin: '8px 0' }}>
                    <Icon n="alert" s={16} />
                    {errorMsg}
                  </div>
                )}
                <div className="row gap-2" style={{ marginTop: 'auto' }}>
                  <button className="btn btn-secondary btn-lg" disabled={stage === 'sending'} onClick={onClose}>
                    رجوع
                  </button>
                  <button
                    className="pay-btn grow"
                    style={{ margin: 0 }}
                    disabled={!valid || stage === 'sending'}
                    onClick={confirm}
                  >
                    <span className="row gap-2">
                      <Icon n="printer" s={18} />
                      {stage === 'sending' ? (retryStatus || 'جارِ تسجيل البيع…') : 'تأكيد الدفع وطباعة الإيصال'}
                    </span>
                    <span
                      className="kbd"
                      style={{
                        background: 'rgba(255,255,255,.15)',
                        borderColor: 'rgba(255,255,255,.35)',
                        color: '#fff',
                      }}
                    >
                      Enter
                    </span>
                  </button>
                </div>
              </div>
              <div className="co-side">
                <div className="row between">
                  <span className="section-label">ملخص الفاتورة</span>
                  <span className="fs-12 t-3 num">
                    {fmt(cart.reduce((s, l) => s + l.qty, 0))} قطعة
                  </span>
                </div>
                <div className="col gap-2" style={{ flex: 1, overflow: 'auto', maxHeight: 300 }}>
                  {cart.map((l) => {
                    const p = posMockService.getProductById(l.id);
                    if (!p) return null;
                    return (
                      <div key={l.id} className="row gap-2">
                        <PImg p={p} size="xs" />
                        <div className="grow" style={{ minWidth: 0 }}>
                          <div className="fs-12 fw-5 truncate">{p.name}</div>
                          <div className="fs-12 t-3 num">
                            {fmt(l.qty)} × {egp(l.price)}
                          </div>
                        </div>
                        <span className="price fs-13">{egp(l.qty * l.price)}</span>
                      </div>
                    );
                  })}
                </div>
                <div
                  className="col gap-1 fs-13"
                  style={{ borderTop: '1px dashed var(--line-strong)', paddingTop: 10 }}
                >
                  <div className="row between t-2">
                    <span>المجموع</span>
                    <span className="num">{egp(subtotal)}</span>
                  </div>
                  {discount > 0 && (
                    <div className="row between t-2">
                      <span>خصم</span>
                      <span className="num t-ok">−{egp(discount)}</span>
                    </div>
                  )}
                  <div className="row between fw-6">
                    <span>الإجمالي</span>
                    <span className="num fs-16">{egp(total)}</span>
                  </div>
                </div>
                <div
                  className="col gap-2"
                  style={{ borderTop: '1px solid var(--line)', paddingTop: 12 }}
                >
                  <Api m="POST" path="/api/v1/pos/sales" />
                  <div className="fs-12 t-3">Idempotency-Key</div>
                  <code
                    className="mono"
                    style={{
                      fontSize: 10.5,
                      direction: 'ltr',
                      wordBreak: 'break-all',
                      color: 'var(--text-2)',
                    }}
                  >
                    {idem}
                  </code>
                  <div className="fs-12 t-3">يمنع تكرار الخصم عند الضغط المزدوج</div>
                </div>
              </div>
            </div>
          ) : receipt && (
            <ReceiptDone
              {...receipt}
              onNew={onClose}
            />
          )}
        </div>
      </div>
    </div>,
    document.body
  );
}
