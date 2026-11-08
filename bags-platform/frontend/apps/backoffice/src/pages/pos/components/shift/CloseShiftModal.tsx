import React, { useState, useEffect } from 'react';
import ReactDOM from 'react-dom';
import { Icon } from '../ui/Icons';
import { egp, fmt, MoneyInput, Api, Field, cx, useEsc } from '../ui/SharedUI';
import { pos as posMockService } from '../../services/pos-data-source';
import type { PosShift, ShiftStats } from '../../types/pos.types';

interface CloseShiftModalProps {
  open: boolean;
  onClose: () => void;
  shift: PosShift | null;
  onClosed: (shift: PosShift) => void;
}

export function CloseShiftModal({ open, onClose, shift, onClosed }: CloseShiftModalProps) {
  const [actual, setActual] = useState<string>('');
  const [notes, setNotes] = useState<string>('');
  const [busy, setBusy] = useState<boolean>(false);
  const [stats, setStats] = useState<ShiftStats>({
    cashSales: 7360,
    cardSales: 2840,
    returns: 450,
    expenses: 180,
    invoices: 23,
  });

  useEffect(() => {
    if (open) {
      setActual('');
      setNotes('');
      setBusy(false);
      posMockService.getShiftStats().then(setStats);
    }
  }, [open]);

  useEsc(onClose, open);
  if (!open || !shift) return null;

  const expected = shift.opening_cash + stats.cashSales - stats.returns - stats.expenses;
  const has = actual !== '';
  const diff = Number(actual || 0) - expected;
  const state: 'idle' | 'ok' | 'over' | 'short' = !has
    ? 'idle'
    : Math.abs(diff) < 0.01
    ? 'ok'
    : diff > 0
    ? 'over'
    : 'short';

  const needNote = state === 'short' || state === 'over';
  const canSubmit = has && (!needNote || notes.trim().length >= 3) && !busy;

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

  const started = new Date(shift.opened_at);

  const handleSubmit = async () => {
    if (!canSubmit) return;
    setBusy(true);
    try {
      const closedShift = await posMockService.closeShift(Number(actual), notes);
      onClosed(closedShift);
    } finally {
      setBusy(false);
    }
  };

  return ReactDOM.createPortal(
    <div
      dir="rtl"
      className={document.querySelector('.app')?.classList.contains('theme-dark') ? 'theme-dark' : ''}
    >
      <div className="overlay" onClick={onClose} />
      <div
        className="modal"
        style={{ width: 'min(860px, calc(100vw - 32px))' }}
        data-screen-label="02 Close Shift"
        role="dialog"
        aria-modal="true"
      >
        <div className="pop">
          <div
            className="row between"
            style={{ padding: '18px 22px', borderBottom: '1px solid var(--line)' }}
          >
            <div className="col">
              <h3 className="fs-16">إغلاق الوردية وجرد النقدية</h3>
              <div className="fs-12 t-3 row gap-1">
                <span className="num ltr">{shift.shift_number}</span>
                <span className="dot-sep" />
                {shift.treasury.name}
                <span className="dot-sep" />
                بدأت{' '}
                <span className="num">
                  {started.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' })}
                </span>
              </div>
            </div>
            <button className="icon-btn sm" onClick={onClose} aria-label="إغلاق">
              <Icon n="x" s={16} />
            </button>
          </div>

          <div className="grid" style={{ gridTemplateColumns: '1fr 1fr' }}>
            <div
              style={{
                padding: '18px 22px',
                borderInlineEnd: '1px solid var(--line)',
                background: 'var(--bg-sunken)',
              }}
            >
              <div className="section-label" style={{ marginBottom: 6 }}>
                ملخص حركة النقدية
              </div>
              <div className="ledger">
                <div className="lr">
                  <span className="op base">·</span>
                  العهدة الافتتاحية
                  <span className="amt">{egp(shift.opening_cash)}</span>
                </div>
                <div className="lr">
                  <span className="op plus">+</span>
                  مبيعات نقدية <span className="t-3 fs-12">({fmt(stats.invoices)} فاتورة)</span>
                  <span className="amt">{egp(stats.cashSales)}</span>
                </div>
                <div className="lr">
                  <span className="op minus">−</span>
                  مرتجعات نقدية
                  <span className="amt">{egp(stats.returns)}</span>
                </div>
                <div className="lr">
                  <span className="op minus">−</span>
                  مصروفات نثرية
                  <span className="amt">{egp(stats.expenses)}</span>
                </div>
                <div className="lr" style={{ paddingTop: 14 }}>
                  <span className="op eq">=</span>
                  <b>النقد المتوقع بالدرج</b>
                  <span className="amt fs-20 fw-6">{egp(expected)}</span>
                </div>
              </div>
              <div
                className="row gap-2 fs-12 t-3"
                style={{
                  marginTop: 10,
                  padding: '10px 12px',
                  borderRadius: 12,
                  border: '1px dashed var(--line-strong)',
                }}
              >
                <Icon n="card" s={15} />
                مبيعات البطاقة <b className="num t-1">{egp(stats.cardSales)}</b> ج — لا تدخل في جرد الدرج
              </div>
            </div>

            <div style={{ padding: '18px 22px', display: 'flex', flexDirection: 'column', gap: 14 }}>
              <div className="col gap-2">
                <span className="fs-13 fw-5">النقد الفعلي بالدرج</span>
                <MoneyInput
                  value={actual}
                  onChange={setActual}
                  autoFocus
                  placeholder={String(expected)}
                />
                <div className="row gap-2">
                  <button className="note-chip" onClick={() => setActual(String(expected))}>
                    مطابق للمتوقع
                  </button>
                </div>
              </div>
              <div className={cx('diff', state)}>
                <span className="diff-ic">
                  <Icon n={diffIcon} s={18} w={2} />
                </span>
                <div className="col">
                  <span className="fs-12" style={{ opacity: 0.8 }}>
                    الفرق
                  </span>
                  <b className="fs-13">{diffLabel}</b>
                </div>
                <span className="v">
                  {has
                    ? (diff > 0 ? '+' : diff < 0 ? '−' : '') + egp(Math.abs(diff))
                    : '—'}
                </span>
              </div>
              <Field
                label={
                  <>
                    ملاحظات{' '}
                    {needNote ? (
                      <span className="t-bad fs-12">(مطلوبة لتبرير الفرق)</span>
                    ) : (
                      <span className="t-3 fs-12">(اختياري)</span>
                    )}
                  </>
                }
              >
                <textarea
                  className={cx('input', needNote && notes.trim().length < 3 && 'is-error')}
                  rows={3}
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder={
                    state === 'short'
                      ? `مثال: عجز ${egp(Math.abs(diff))} جنيه فرق فكة مع عميل`
                      : 'أي ملاحظات على الوردية…'
                  }
                />
              </Field>
            </div>
          </div>

          <div
            className="row gap-2"
            style={{ padding: '14px 22px', borderTop: '1px solid var(--line)' }}
          >
            <div className="row gap-2 fs-12 t-3 grow">
              <Icon n="info" s={14} />
              ستُرسل الوردية بحالة{' '}
              <span className="badge b-warn" style={{ height: 20 }}>
                بانتظار الاعتماد
              </span>
              <Api m="POST" path="/pos/shifts/close" />
            </div>
            <button className="btn btn-secondary" onClick={onClose}>
              رجوع للبيع
            </button>
            <button
              className="btn"
              style={{ background: 'var(--bad)', color: '#fff' }}
              disabled={!canSubmit}
              onClick={handleSubmit}
            >
              <Icon n="lock" s={16} />
              {busy ? 'جارِ الإغلاق…' : 'إغلاق الوردية وإرسالها للاعتماد'}
            </button>
          </div>
        </div>
      </div>
    </div>,
    document.body
  );
}
