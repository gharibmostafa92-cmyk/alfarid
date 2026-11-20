import React, { useState, useEffect, useRef, useId } from 'react';
import ReactDOM from 'react-dom';
import { Icon } from '../ui/Icons';
import { egp, fmt, MoneyInput, cx, useEsc } from '../ui/SharedUI';
import { pos } from '../../services/pos-data-source';
import type { PosShift, ShiftStats } from '../../types/pos.types';
import { fromCents, toCents } from '../../lib/money';
import { normalizeError } from '../../lib/pos-error';
import { printReceipt } from '../../lib/print-receipt';

interface CloseShiftModalProps {
  open: boolean;
  onClose: () => void;
  shift: PosShift | null;
  onClosed: (shift: PosShift) => void;
  onCommitted: (shift: PosShift) => void;
  isDark?: boolean;
}

export function CloseShiftModal({ open, onClose, shift, onClosed, onCommitted, isDark }: CloseShiftModalProps) {
  const notesId = useId();
  const [actual, setActual] = useState<string>('');
  const [notes, setNotes] = useState<string>('');
  const [busy, setBusy] = useState<boolean>(false);
  const submitting = useRef(false);
  const receiptPreview = useRef<HTMLDivElement>(null);
  const [statsReady, setStatsReady] = useState(false);
  const [error, setError] = useState('');
  const [closedResult, setClosedResult] = useState<PosShift | null>(null);
  const [stats, setStats] = useState<ShiftStats>({
    cashSales: 0,
    cardSales: 0,
    returns: 0,
    expenses: 0,
    invoices: 0,
  });

  useEffect(() => {
    let active = true;
    if (open) {
      setActual('');
      setNotes('');
      setBusy(false);
      setClosedResult(null);
      setStatsReady(false);
      setError('');
      void pos.getShiftStats().then((summary) => {
        if (!active) return;
        setStats(summary);
        setStatsReady(true);
      }).catch((error: Error) => {
        if (active) setError(error.message);
      });
    }
    return () => { active = false; };
  }, [open]);

  const dismiss = () => {
    if (submitting.current) return;
    if (closedResult) onClosed(closedResult);
    else onClose();
  };
  useEsc(dismiss, open);
  if (!open || !shift) return null;

  const canViewExpected = statsReady && stats.expected_closing_cash != null;

  const expectedCents = canViewExpected ? toCents(stats.expected_closing_cash) : 0;
  const expected = closedResult
    ? (closedResult.expected_closing_cash ?? fromCents(expectedCents))
    : fromCents(expectedCents);
  const has = actual !== '';
  const diff = closedResult
    ? (closedResult.cash_difference ?? 0)
    : canViewExpected
    ? fromCents(toCents(actual) - expectedCents)
    : 0;
  const state: 'idle' | 'ok' | 'over' | 'short' = (!canViewExpected || !has)
    ? 'idle'
    : Math.abs(diff) < 0.01
    ? 'ok'
    : diff > 0
    ? 'over'
    : 'short';

  const needNote = canViewExpected && has && Math.abs(diff) >= 0.01;
  const canSubmit = statsReady && has && Number.isFinite(Number(actual)) && Number(actual) >= 0 && !busy && (!needNote || notes.trim().length > 0);

  const diffLabel = {
    idle: 'أدخل النقد الفعلي لحساب الفرق',
    ok: 'مطابق تمامًا',
    over: 'زيادة في الدرج',
    short: 'عجز في الدرج',
  }[state];

  const diffIcon = {
    idle: 'info',
    ok: 'check',
    over: 'arrowUpLeft',
    short: 'alert',
  }[state];


  const handleSubmit = async () => {
    if (!canSubmit || submitting.current) return;
    submitting.current = true;
    setError('');
    setBusy(true);
    try {
      const res = await pos.closeShift(Number(actual), notes);
      setClosedResult(res);
      onCommitted(res);
    } catch (error) {
      // Surface the server message (e.g. the 422 for a missing note) instead of a generic Error text.
      try {
        setError(normalizeError(error).message);
      } catch {
        setError('تعذر إغلاق الوردية');
      }
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  };

  const darkClass = isDark != null
    ? (isDark ? 'theme-dark' : '')
    : (document.querySelector('.app')?.classList.contains('theme-dark') ? 'theme-dark' : '');

  if (closedResult) {
    return ReactDOM.createPortal(
      <div
        dir="rtl"
        className={darkClass}
      >
        <div className="pos-overlay" onClick={() => onClosed(closedResult)} />
        <div
          className="pos-modal"
          style={{ width: 'min(520px, calc(100vw - 32px))' }}
          data-screen-label="02 Close Shift Done"
          role="dialog"
          aria-modal="true"
          aria-label="ملخص إغلاق الوردية"
        >
          <div className="pop col gap-4" style={{ padding: 24, textAlign: 'center', alignItems: 'center' }}>
            <span
              style={{
                width: 56,
                height: 56,
                borderRadius: 18,
                background: 'var(--ok-bg)',
                color: 'var(--ok)',
                display: 'grid',
                placeItems: 'center',
              }}
            >
              <Icon n="check" s={28} w={2.4} />
            </span>
            <div>
              <h3 className="fs-18">تم إغلاق الوردية بنجاح</h3>
              <p className="t-2" style={{ marginTop: 4 }}>
                الوردية بانتظار اعتماد المدير
              </p>
            </div>

            <div ref={receiptPreview} className="receipt" style={{ width: '100%', maxWidth: 300, textAlign: 'right' }}>
              <div style={{ textAlign: 'center' }}>
                <b className="fs-14 display">ملخص إغلاق الوردية</b>
                <div className="num" style={{ color: '#6f6a76' }}>
                  {closedResult.shift_number} · {shift.treasury.name}
                </div>
              </div>
              <div className="dash" />
              <div className="rr">
                <span>العهدة الافتتاحية:</span>
                <span className="num">{egp(shift.opening_cash)} ج</span>
              </div>
              {closedResult.cash_sales_total !== undefined && (
                <div className="rr">
                  <span>مبيعات نقدية:</span>
                  <span className="num">{egp(closedResult.cash_sales_total)} ج</span>
                </div>
              )}
              {closedResult.card_sales_total !== undefined && (
                <div className="rr">
                  <span>مبيعات بطاقة:</span>
                  <span className="num">{egp(closedResult.card_sales_total)} ج</span>
                </div>
              )}
              {closedResult.cash_returns_total !== undefined && (
                <div className="rr">
                  <span>مرتجعات نقدية:</span>
                  <span className="num">{egp(closedResult.cash_returns_total)} ج</span>
                </div>
              )}
              {closedResult.cash_expenses_total !== undefined && (
                <div className="rr">
                  <span>مصروفات:</span>
                  <span className="num">{egp(closedResult.cash_expenses_total)} ج</span>
                </div>
              )}
              <div className="dash" />
              {closedResult.expected_closing_cash !== undefined && (
                <div className="rr">
                  <span>النقد المتوقع:</span>
                  <span className="num">{egp(closedResult.expected_closing_cash)} ج</span>
                </div>
              )}
              <div className="rr" style={{ fontWeight: 700 }}>
                <span>النقد الفعلي:</span>
                <span className="num">{egp(Number(actual))} ج</span>
              </div>
              {closedResult.cash_difference != null && (
                <div className="rr">
                  <span>الفرق:</span>
                  <span className="num">
                    {diff > 0 ? `+${egp(diff)}` : diff < 0 ? `−${egp(Math.abs(diff))}` : '٠'} ج
                  </span>
                </div>
              )}
              {notes && (
                <div style={{ marginTop: 8, fontSize: 11, color: '#6f6a76' }}>
                  ملاحظات: {notes}
                </div>
              )}
            </div>

            <div className="pos-dialog-actions" style={{ width: '100%' }}>
              <button className="btn btn-secondary btn-lg" onClick={() => printReceipt(receiptPreview.current)}>
                <Icon n="printer" s={16} />
                طباعة الملخص
              </button>
              <button className="btn btn-primary btn-lg" autoFocus onClick={() => onClosed(closedResult)}>
                تم
              </button>
            </div>
          </div>
        </div>
      </div>,
      document.body
    );
  }

  return ReactDOM.createPortal(
    <div dir="rtl" className={darkClass}>
      <div className="pos-overlay" onClick={dismiss} />
      <section className="pos-modal close-shift-modal" data-screen-label="02 Close Shift"
        role="dialog" aria-modal="true" aria-label="إغلاق الوردية وجرد النقدية">
        <header className="pos-close-header">
          <div>
            <h3>إغلاق الوردية وجرد النقدية</h3>
            <p className="t-2 fs-13">وردية <bdi>{shift.shift_number}</bdi> · {shift.treasury.name}</p>
          </div>
          <button className="icon-btn" disabled={busy} onClick={dismiss} aria-label="إغلاق"><Icon n="x" s={18} /></button>
        </header>
        <form onSubmit={(event) => { event.preventDefault(); void handleSubmit(); }}>
          <div className={cx('pos-close-content', canViewExpected && 'has-ledger')}>
            <div className="pos-close-count">
              <h4>النقد الفعلي في الدرج</h4>
              <p className="fs-13 t-2">عدّ النقد الموجود في الدرج وأدخل المبلغ بالجنيه.</p>
              <div role="group" aria-label="النقد الفعلي في الدرج">
                <MoneyInput value={actual} onChange={setActual} autoFocus placeholder="0.00" disabled={busy} />
              </div>
              {canViewExpected && has && (
                <div className={cx('diff', state)} role="status">
                  <Icon n={diffIcon} s={18} />
                  <b className="fs-13">{diffLabel}</b>
                  <bdi className="v">{egp(Math.abs(diff))} ج</bdi>
                </div>
              )}
              <label htmlFor={notesId} className="fs-13 fw-6">
                {needNote ? 'سبب الفرق (مطلوب)' : 'ملاحظات (اختياري)'}
              </label>
              <textarea id={notesId} className="input" rows={2} maxLength={500}
                disabled={busy} required={needNote} value={notes} onChange={(event) => setNotes(event.target.value)} />
              {error && <p className="pos-inline-error" role="alert">{error}</p>}
              {!statsReady && !error && <p className="fs-13 t-2" role="status">جارٍ تحميل ملخص الوردية…</p>}
            </div>
            {canViewExpected && (
              <aside className="pos-close-ledger" aria-label="ملخص حركة النقدية">
                <h4>حركة النقدية</h4>
                <dl>
                  <div><dt>العهدة الافتتاحية</dt><dd>{egp(shift.opening_cash)}</dd></div>
                  <div><dt>المبيعات النقدية</dt><dd>+{egp(stats.cashSales)}</dd></div>
                  <div><dt>المرتجعات النقدية</dt><dd>−{egp(stats.returns)}</dd></div>
                  <div><dt>مصروفات الدرج</dt><dd>−{egp(stats.expenses)}</dd></div>
                  <div className="pos-close-total"><dt>النقد المتوقع</dt><dd>{egp(expected)} ج</dd></div>
                </dl>
                <p className="fs-12 t-2">{fmt(stats.invoices)} فاتورة · مبيعات البطاقة {egp(stats.cardSales)} ج</p>
              </aside>
            )}
          </div>
          <footer className="pos-close-footer">
            <p className="fs-13 t-2">تُرسل الوردية للمدير للاعتماد بعد إغلاقها.</p>
            <div className="pos-dialog-actions">
              <button className="btn btn-primary" disabled={!canSubmit}>{busy ? 'جارٍ الإغلاق…' : 'إغلاق الوردية'}</button>
              <button className="btn btn-secondary" type="button" disabled={busy} onClick={dismiss}>رجوع للبيع</button>
            </div>
          </footer>
        </form>
      </section>
    </div>,
    document.body
  );
}
