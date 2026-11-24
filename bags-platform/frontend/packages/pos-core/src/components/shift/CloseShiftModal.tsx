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
    const doneDiff = closedResult.cash_difference ?? 0;
    const doneDiffState: 'ok' | 'over' | 'short' = Math.abs(doneDiff) < 0.01 ? 'ok' : doneDiff > 0 ? 'over' : 'short';
    return ReactDOM.createPortal(
      <div
        dir="rtl"
        className={darkClass}
      >
        <div className="pos-overlay" onClick={() => onClosed(closedResult)} />
        <div
          className="pos-modal pos-sheet-mobile close-shift-done-modal"
          data-screen-label="02 Close Shift Done"
          role="dialog"
          aria-modal="true"
          aria-label="ملخص إغلاق الوردية"
        >
          <div className="pop pos-close-done-body pos-close-done-grid">
            <div className="pos-close-done-summary">
              <div className="pos-sheet-handle" aria-hidden="true" />
              <span className="pos-close-done-icon" aria-hidden="true">
                <Icon n="check" s={28} w={2.4} />
              </span>
              <div>
                <h3 className="fs-20">تم إغلاق الوردية بنجاح</h3>
                <p className="t-2 fs-13" style={{ marginTop: 4 }}>
                  {closedResult.status === 'closed' ? 'تمت تسوية النقدية وأصبح الدرج متاحًا.' : 'الوردية بانتظار اعتماد المدير'}
                </p>
              </div>

              <div className="pos-close-done-kpis">
                <div>
                  <span className="fs-12 t-2">النقد الفعلي</span>
                  <b className="num fs-16">{egp(Number(actual))} ج</b>
                </div>
                {closedResult.expected_closing_cash !== undefined && (
                  <div>
                    <span className="fs-12 t-2">النقد المتوقع</span>
                    <b className="num fs-16">{egp(closedResult.expected_closing_cash)} ج</b>
                  </div>
                )}
                {closedResult.cash_difference != null && (
                  <div className={cx('is-diff', doneDiffState)}>
                    <span className="fs-12">الفرق</span>
                    <b className="num fs-16">
                      {doneDiff > 0 ? `+${egp(doneDiff)}` : doneDiff < 0 ? `−${egp(Math.abs(doneDiff))}` : '٠'} ج
                    </b>
                  </div>
                )}
              </div>

              <div className="pos-dialog-actions" style={{ width: '100%' }}>
                <button className="btn btn-secondary btn-lg" type="button" onClick={() => printReceipt(receiptPreview.current)}>
                  <Icon n="printer" s={16} aria-hidden="true" />
                  طباعة الملخص
                </button>
                <button className="btn btn-primary btn-lg" type="button" autoFocus onClick={() => onClosed(closedResult)}>
                  تم
                </button>
              </div>
            </div>

            <div className="pos-close-done-receipt">
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
                      {doneDiff > 0 ? `+${egp(doneDiff)}` : doneDiff < 0 ? `−${egp(Math.abs(doneDiff))}` : '٠'} ج
                    </span>
                  </div>
                )}
                {notes && (
                  <div style={{ marginTop: 8, fontSize: 11, color: '#6f6a76' }}>
                    ملاحظات: {notes}
                  </div>
                )}
              </div>
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
      <section className="pos-modal pos-sheet-mobile close-shift-modal" data-screen-label="02 Close Shift"
        role="dialog" aria-modal="true" aria-label="إغلاق الوردية وجرد النقدية">
        <header className="pos-close-header">
          <div className="pos-sheet-handle" aria-hidden="true" />
          <div className="pos-close-header-row">
            <div>
              <h3>إغلاق الوردية وجرد النقدية</h3>
              <p className="t-2 fs-13">وردية <bdi className="num">{shift.shift_number}</bdi> · {shift.treasury.name}</p>
            </div>
            <button className="icon-btn" type="button" disabled={busy} onClick={dismiss} aria-label="إغلاق"><Icon n="x" s={18} /></button>
          </div>
        </header>
        <form className="pos-close-form" onSubmit={(event) => { event.preventDefault(); void handleSubmit(); }}>
          <div className={cx('pos-close-content', canViewExpected && 'has-ledger')}>
            <div className="pos-close-count">
              <div className="pos-close-count-head">
                <div>
                  <h4>النقد الفعلي في الدرج</h4>
                  <p className="fs-13 t-2">عدّ النقد الموجود في الدرج وأدخل المبلغ بالجنيه.</p>
                </div>
                {canViewExpected && (
                  <button
                    type="button"
                    className="pos-close-match-btn"
                    disabled={busy}
                    onClick={() => setActual(String(expected))}
                  >
                    مطابقة المتوقع
                  </button>
                )}
              </div>
              <div role="group" aria-label="النقد الفعلي في الدرج">
                <MoneyInput
                  value={actual}
                  onChange={setActual}
                  autoFocus
                  placeholder="0.00"
                  disabled={busy}
                  onEnter={() => { if (canSubmit) void handleSubmit(); }}
                />
              </div>
              {canViewExpected && (
                <div className={cx('diff', state)} role="status">
                  <span className="diff-ic" aria-hidden="true"><Icon n={diffIcon} s={18} /></span>
                  <div className="col" style={{ gap: 2 }}>
                    <b className="fs-13">{diffLabel}</b>
                    {state !== 'idle' && (
                      <span className="fs-12" style={{ opacity: 0.85 }}>
                        {state === 'ok' ? 'الرصيد مطابق تمامًا للمتوقع' : state === 'over' ? 'فوق النقد المتوقع' : 'أقل من النقد المتوقع'}
                      </span>
                    )}
                  </div>
                  <bdi className="v">{has ? `${egp(Math.abs(diff))} ج` : '—'}</bdi>
                </div>
              )}
              <div className="pos-close-notes-wrap">
                <div className="row between gap-2">
                  <label htmlFor={notesId} className="fs-13 fw-6">
                    {needNote ? 'سبب الفرق (مطلوب)' : 'ملاحظات (اختياري)'}
                  </label>
                  <span className="fs-12 t-3 num">{notes.length}/500</span>
                </div>
                <textarea
                  id={notesId}
                  className="input"
                  rows={2}
                  maxLength={500}
                  placeholder={needNote ? 'اكتب سبب الزيادة أو العجز…' : 'أضف أي ملاحظات للوردية…'}
                  disabled={busy}
                  required={needNote}
                  value={notes}
                  onChange={(event) => setNotes(event.target.value)}
                  onKeyDown={(event) => {
                    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter' && canSubmit) {
                      event.preventDefault();
                      void handleSubmit();
                    }
                  }}
                />
              </div>
              {error && <p className="pos-inline-error" role="alert">{error}</p>}
              {!statsReady && !error && <p className="fs-13 t-2" role="status">جارٍ تحميل ملخص الوردية…</p>}
            </div>
            {canViewExpected && (
              <aside className="pos-close-ledger" aria-label="ملخص حركة النقدية">
                <div className="row between gap-2">
                  <h4>حركة النقدية</h4>
                  <span className="fs-12 t-2">الوردية الحالية</span>
                </div>
                <div className="pos-close-kpis">
                  <div className="pos-close-kpi">
                    <span className="fs-12 t-2"><Icon n="orders" s={13} /> الفواتير</span>
                    <b className="num fs-14">{fmt(stats.invoices)}</b>
                  </div>
                  <div className="pos-close-kpi">
                    <span className="fs-12 t-2"><Icon n="card" s={13} /> مبيعات البطاقة</span>
                    <b className="num fs-14">{egp(stats.cardSales)} ج</b>
                  </div>
                </div>
                <div className="ledger">
                  <div className="lr">
                    <span className="op base">=</span>
                    <span>العهدة الافتتاحية</span>
                    <span className="amt">{egp(shift.opening_cash)} ج</span>
                  </div>
                  <div className="lr">
                    <span className="op plus">+</span>
                    <span>المبيعات النقدية</span>
                    <span className="amt t-ok">+{egp(stats.cashSales)} ج</span>
                  </div>
                  <div className="lr">
                    <span className="op minus">−</span>
                    <span>المرتجعات النقدية</span>
                    <span className="amt">−{egp(stats.returns)} ج</span>
                  </div>
                  <div className="lr">
                    <span className="op minus">−</span>
                    <span>مصروفات الدرج</span>
                    <span className="amt">−{egp(stats.expenses)} ج</span>
                  </div>
                  <div className="lr pos-close-total">
                    <span className="op eq">=</span>
                    <span>النقد المتوقع</span>
                    <span className="amt fs-16">{egp(expected)} ج</span>
                  </div>
                </div>
              </aside>
            )}
          </div>
          <footer className="pos-close-footer">
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
