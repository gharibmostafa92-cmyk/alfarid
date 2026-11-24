import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { egp, fmt, Modal, MoneyInput, normalizeMoneyDigits, Seg } from '../ui/SharedUI';
import { Icon } from '../ui/Icons';
import { pos } from '../../services/pos-data-source';
import { isCancel, normalizeError } from '../../lib/pos-error';
import type { PosExpense } from '../../types/pos.types';

const EXPENSE_PRESETS = ['نقل بضاعة', 'ضيافة', 'تغليف ومطبوعات', 'مواصلات', 'صيانة'] as const;
const VOID_REASON_PRESETS = ['إدخال بالخطأ', 'تكرار بالخطأ', 'مبلغ غير صحيح', 'تراجع عن الصرف'] as const;

export function ExpenseModal({
  onClose,
  onSaved,
  onMutated,
}: {
  onClose: () => void;
  onSaved: () => void;
  onMutated?: (message: string) => void;
}) {
  const [tab, setTab] = useState<'new' | 'list'>('new');
  const [amount, setAmount] = useState('');
  const [category, setCategory] = useState('');
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const submitting = useRef(false);

  const [expenses, setExpenses] = useState<PosExpense[]>([]);
  const [loadingList, setLoadingList] = useState(false);
  const [listError, setListError] = useState('');

  const [editingId, setEditingId] = useState<number | null>(null);
  const [editAmount, setEditAmount] = useState('');
  const [editCategory, setEditCategory] = useState('');
  const [editNotes, setEditNotes] = useState('');

  const [voidingId, setVoidingId] = useState<number | null>(null);
  const [voidReason, setVoidReason] = useState('');
  const [rowBusy, setRowBusy] = useState(false);
  const [rowError, setRowError] = useState('');

  const loadExpenses = useCallback(async () => {
    setLoadingList(true);
    setListError('');
    try {
      const list = await pos.getShiftExpenses();
      setExpenses(list);
    } catch (err) {
      if (!isCancel(err)) setListError(normalizeError(err).message);
    } finally {
      setLoadingList(false);
    }
  }, []);

  useEffect(() => {
    void loadExpenses();
  }, [loadExpenses]);

  const activeExpenses = expenses.filter((e) => e.status === 'posted');
  const activeTotal = activeExpenses.reduce((sum, e) => sum + e.amount, 0);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting.current) return;
    const normalizedAmount = normalizeMoneyDigits(amount).trim();
    const trimmedCategory = category.trim();
    if (!/^\d+(?:\.\d{1,2})?$/.test(normalizedAmount) || Number(normalizedAmount) <= 0 || Number(normalizedAmount) > 999999.99 || !trimmedCategory) {
      setError('أدخل مبلغًا صحيحًا وبيان المصروف.');
      return;
    }

    submitting.current = true;
    setBusy(true);
    setError('');
    try {
      await pos.recordExpense(Number(normalizedAmount), trimmedCategory, notes.trim());
      onSaved();
    } catch (err) {
      setError(normalizeError(err).message);
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  }

  function startEdit(exp: PosExpense) {
    setVoidingId(null);
    setRowError('');
    setEditingId(exp.id);
    setEditAmount(String(exp.amount));
    setEditCategory(exp.category);
    setEditNotes(exp.description ?? '');
  }

  async function saveEdit(exp: PosExpense) {
    if (rowBusy) return;
    const normalized = normalizeMoneyDigits(editAmount).trim();
    const trimmedCategory = editCategory.trim();
    if (!/^\d+(?:\.\d{1,2})?$/.test(normalized) || Number(normalized) <= 0 || Number(normalized) > 999999.99 || !trimmedCategory) {
      setRowError('أدخل مبلغًا صحيحًا وبيانًا صالحًا للتعديل.');
      return;
    }

    setRowBusy(true);
    setRowError('');
    try {
      await pos.updateExpense(exp.id, {
        amount: Number(normalized),
        category: trimmedCategory,
        notes: editNotes.trim() || null,
      });
      setEditingId(null);
      await loadExpenses();
      onMutated?.('تم تعديل المصروف بنجاح');
    } catch (err) {
      setRowError(normalizeError(err).message);
    } finally {
      setRowBusy(false);
    }
  }

  function startVoid(exp: PosExpense) {
    setEditingId(null);
    setRowError('');
    setVoidingId(exp.id);
    setVoidReason('');
  }

  async function confirmVoid(exp: PosExpense) {
    if (rowBusy) return;
    const reason = voidReason.trim();
    if (reason.length < 3) {
      setRowError('يرجى كتابة سبب الإلغاء (٣ أحرف على الأقل).');
      return;
    }

    setRowBusy(true);
    setRowError('');
    try {
      await pos.voidExpense(exp.id, reason);
      setVoidingId(null);
      setVoidReason('');
      await loadExpenses();
      onMutated?.('تم إلغاء المصروف وإعادة المبلغ إلى الدرج');
    } catch (err) {
      setRowError(normalizeError(err).message);
    } finally {
      setRowBusy(false);
    }
  }

  return (
    <Modal
      open
      title="مصروفات الدرج"
      className="pos-sheet-mobile"
      onClose={() => { if (!submitting.current && !rowBusy) onClose(); }}
      width={540}
    >
      <div className="pos-sheet-handle" aria-hidden="true" />
      <div className="pos-expense-tabs-row">
        <Seg
          value={tab}
          onChange={(v) => {
            setTab(v);
            setError('');
            setRowError('');
          }}
          options={[
            { value: 'new', label: 'تسجيل مصروف' },
            { value: 'list', label: `مصروفات الوردية (${fmt(activeExpenses.length)})` },
          ]}
        />
        {tab === 'list' && activeTotal > 0 && (
          <span className="badge b-neutral num">الصافي: {egp(activeTotal)} ج</span>
        )}
      </div>

      {tab === 'new' ? (
        <>
          <p className="t-2 fs-13">سجّل المبلغ المدفوع نقدًا من درج الوردية الحالية.</p>
          <form className="pos-expense-form" onSubmit={submit} aria-busy={busy}>
            <fieldset disabled={busy}>
              <div className="pos-expense-field">
                <label htmlFor="pos-expense-amount">المبلغ بالجنيه</label>
                <MoneyInput
                  id="pos-expense-amount"
                  name="amount"
                  size="md"
                  value={amount}
                  onChange={(v) => { setAmount(v); if (error) setError(''); }}
                  normalize={normalizeMoneyDigits}
                  autoFocus
                  required
                />
              </div>
              <div className="pos-expense-field">
                <label htmlFor="pos-expense-category">بيان المصروف</label>
                <div className="pos-expense-chips" role="group" aria-label="بنود مصروفات شائعة">
                  {EXPENSE_PRESETS.map((preset) => (
                    <button
                      key={preset}
                      type="button"
                      className="pos-expense-chip"
                      aria-pressed={category === preset}
                      onClick={() => { setCategory(preset); if (error) setError(''); }}
                    >
                      {preset}
                    </button>
                  ))}
                </div>
                <input
                  className="input"
                  id="pos-expense-category"
                  name="category"
                  maxLength={100}
                  placeholder="مثال: نقل بضاعة"
                  value={category}
                  onChange={(e) => { setCategory(e.target.value); if (error) setError(''); }}
                  required
                />
              </div>
              <div className="pos-expense-field">
                <label htmlFor="pos-expense-notes">ملاحظات (اختياري)</label>
                <textarea
                  className="input"
                  id="pos-expense-notes"
                  name="notes"
                  maxLength={500}
                  rows={2}
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                  placeholder="أي تفاصيل إضافية..."
                />
              </div>
            </fieldset>
            {error && <p role="alert" className="pos-inline-error">{error}</p>}
            <div className="pos-dialog-actions">
              <button className="btn btn-primary" disabled={busy}>{busy ? 'جارٍ التسجيل…' : 'تسجيل المصروف'}</button>
              <button className="btn btn-secondary" type="button" disabled={busy} onClick={onClose}>إغلاق</button>
            </div>
          </form>
        </>
      ) : (
        <div className="pos-expense-history">
          {listError && <p role="alert" className="pos-inline-error">{listError}</p>}
          {rowError && <p role="alert" className="pos-inline-error">{rowError}</p>}

          {loadingList ? (
            <div className="t-3 fs-13" style={{ padding: '24px 0', textAlign: 'center' }}>جارٍ تحميل مصروفات الوردية…</div>
          ) : expenses.length === 0 ? (
            <div className="t-3 fs-13" style={{ padding: '28px 0', textAlign: 'center' }}>
              لا توجد مصروفات مسجلة في الوردية الحالية بعد.
            </div>
          ) : (
            <div className="pos-expense-list">
              {expenses.map((exp) => {
                const isVoided = exp.status === 'voided';
                const isEditing = editingId === exp.id;
                const isVoiding = voidingId === exp.id;

                return (
                  <div key={exp.id} className={`pos-expense-item ${isVoided ? 'is-voided' : ''}`}>
                    <div className="pos-expense-item-head">
                      <div className="pos-expense-item-title">
                        <span className="fw-6">{exp.category}</span>
                        <span className={`badge ${isVoided ? 'b-bad' : 'b-ok'}`}>
                          {isVoided ? 'مُلغى' : 'مُعتمد'}
                        </span>
                      </div>
                      <b className="num fs-15">{egp(exp.amount)} ج</b>
                    </div>

                    {exp.description && !isEditing && (
                      <p className="t-2 fs-12 pos-expense-item-note">{exp.description}</p>
                    )}
                    {isVoided && exp.voidReason && (
                      <p className="t-bad fs-12 pos-expense-item-note">سبب الإلغاء: {exp.voidReason}</p>
                    )}

                    <div className="pos-expense-item-meta fs-11 t-3">
                      <span>{exp.createdBy?.name ?? 'كاشير'}</span>
                      <span>
                        {exp.createdAt
                          ? new Date(exp.createdAt).toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' })
                          : ''}
                      </span>
                    </div>

                    {!isVoided && !isEditing && !isVoiding && (exp.canEdit || exp.canVoid) && (
                      <div className="pos-expense-item-actions">
                        {exp.canEdit && (
                          <button type="button" className="btn btn-secondary btn-sm" onClick={() => startEdit(exp)}>
                            <Icon n="edit" s={13} />
                            تعديل
                          </button>
                        )}
                        {exp.canVoid && (
                          <button type="button" className="btn btn-ghost btn-sm t-bad" onClick={() => startVoid(exp)}>
                            <Icon n="trash" s={13} />
                            إلغاء المصروف
                          </button>
                        )}
                      </div>
                    )}

                    {isEditing && (
                      <div className="pos-expense-inline-box">
                        <div className="pos-expense-inline-grid">
                          <div>
                            <label className="fs-12 fw-6">المبلغ الجديد</label>
                            <MoneyInput
                              size="sm"
                              value={editAmount}
                              onChange={setEditAmount}
                              normalize={normalizeMoneyDigits}
                            />
                          </div>
                          <div>
                            <label className="fs-12 fw-6">البيان</label>
                            <input
                              className="input"
                              maxLength={100}
                              value={editCategory}
                              onChange={(e) => setEditCategory(e.target.value)}
                            />
                          </div>
                        </div>
                        <div>
                          <label className="fs-12 fw-6">ملاحظات</label>
                          <input
                            className="input"
                            maxLength={500}
                            value={editNotes}
                            onChange={(e) => setEditNotes(e.target.value)}
                          />
                        </div>
                        <p className="fs-11 t-3">عند تغيير المبلغ يتم عكس القيد القديم تلقائيًا وتسجيل قيد جديد بالمبلغ الصحيح.</p>
                        <div className="pos-expense-item-actions">
                          <button type="button" className="btn btn-primary btn-sm" disabled={rowBusy} onClick={() => saveEdit(exp)}>
                            {rowBusy ? 'جارٍ الحفظ…' : 'حفظ التعديل'}
                          </button>
                          <button type="button" className="btn btn-secondary btn-sm" disabled={rowBusy} onClick={() => setEditingId(null)}>
                            تراجع
                          </button>
                        </div>
                      </div>
                    )}

                    {isVoiding && (
                      <div className="pos-expense-inline-box">
                        <div className="pos-expense-chips">
                          {VOID_REASON_PRESETS.map((r) => (
                            <button
                              key={r}
                              type="button"
                              className="pos-expense-chip"
                              aria-pressed={voidReason === r}
                              onClick={() => setVoidReason(r)}
                            >
                              {r}
                            </button>
                          ))}
                        </div>
                        <input
                          className="input"
                          maxLength={300}
                          placeholder="اكتب سبب إلغاء المصروف..."
                          value={voidReason}
                          onChange={(e) => setVoidReason(e.target.value)}
                        />
                        <div className="pos-expense-item-actions">
                          <button type="button" className="btn btn-danger btn-sm" disabled={rowBusy} onClick={() => confirmVoid(exp)}>
                            {rowBusy ? 'جارٍ الإلغاء…' : 'تأكيد إلغاء المصروف'}
                          </button>
                          <button type="button" className="btn btn-secondary btn-sm" disabled={rowBusy} onClick={() => setVoidingId(null)}>
                            تراجع
                          </button>
                        </div>
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          )}

          <div className="pos-dialog-actions" style={{ marginTop: 12 }}>
            <button className="btn btn-secondary" type="button" disabled={rowBusy} onClick={onClose}>إغلاق</button>
          </div>
        </div>
      )}
    </Modal>
  );
}
