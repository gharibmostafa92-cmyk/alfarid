import React, { useState, useEffect } from 'react';
import ReactDOM from 'react-dom';
import { Icon } from '../ui/Icons';
import { egp, fmt, MoneyInput, Api, Field, useEsc } from '../ui/SharedUI';
import { pos as posMockService } from '../../services/pos-data-source';
import { serverMessage } from '../../lib/pos-error';
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
  const [error, setError] = useState<string>('');
  const [stats, setStats] = useState<ShiftStats>({
    cashSales: 0,
    cardSales: 0,
    returns: 0,
    expenses: 0,
    invoices: 0,
  });

  useEffect(() => {
    if (open) {
      setActual('');
      setNotes('');
      setBusy(false);
      setError('');
      posMockService
        .getShiftStats()
        .then((s) => setStats({ ...s }))
        .catch((err) => setError(serverMessage(err)));
    }
  }, [open]);

  useEsc(onClose, open);
  if (!open || !shift) return null;

  // Blind close: the cashier never sees the expected cash or any difference.
  // Notes stay optional at close so a blind cashier is never stuck behind a
  // 422 and can never oracle the expected total; the supervisor documents the
  // difference at review/reopen-for-recount.
  const has = actual !== '';
  const actualCash = Number(actual);
  const canSubmit = has && Number.isFinite(actualCash) && !busy;

  const started = new Date(shift.opened_at);

  const handleSubmit = async () => {
    if (!canSubmit) return;
    setError('');
    setBusy(true);
    try {
      const closedShift = await posMockService.closeShift(actualCash, notes || undefined);
      onClosed(closedShift);
    } catch (err) {
      setError(serverMessage(err));
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

          {error && (
            <div className="banner b-bad" role="alert" style={{ margin: '0 22px' }}>
              <Icon n="alert" s={16} />
              {error}
            </div>
          )}

          <div className="grid" style={{ gridTemplateColumns: '1fr 1fr' }}>
            <div
              style={{
                padding: '18px 22px',
                borderInlineEnd: '1px solid var(--line)',
                background: 'var(--bg-sunken)',
              }}
            >
              <div className="section-label" style={{ marginBottom: 6 }}>
                ملخص الوردية (إغلاق أعمى)
              </div>
              <div className="ledger">
                <div className="lr">
                  <span className="op base">·</span>
                  عدد الفواتير
                  <span className="amt">{fmt(stats.invoices)}</span>
                </div>
                <div className="lr">
                  <span className="op base">·</span>
                  مبيعات البطاقة
                  <span className="amt">{egp(stats.cardSales)}</span>
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
                <Icon n="lock" s={15} />
                الإغلاق أعمى: لا يظهر النقد المتوقع ولا الفرق — عدّ الدرج وسجّل الفعلي فقط
              </div>
            </div>

            <div style={{ padding: '18px 22px', display: 'flex', flexDirection: 'column', gap: 14 }}>
              <div className="col gap-2">
                <span className="fs-13 fw-5">النقد الفعلي بالدرج</span>
                <MoneyInput value={actual} onChange={setActual} autoFocus placeholder="0.00" />
              </div>
              <Field
                label={
                  <>
                    ملاحظات <span className="t-3 fs-12">(اختياري)</span>
                  </>
                }
              >
                <textarea
                  className="input"
                  rows={3}
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="أي ملاحظات على الوردية…"
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
