import { useRef, useState, type FormEvent } from 'react';
import { Modal, normalizeMoneyDigits } from '../ui/SharedUI';
import { pos } from '../../services/pos-data-source';
import { normalizeError } from '../../lib/pos-error';

export function ExpenseModal({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const submitting = useRef(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (submitting.current) return;
    const data = new FormData(event.currentTarget);
    const amount = normalizeMoneyDigits(String(data.get('amount') ?? '')).trim();
    const category = String(data.get('category') ?? '').trim();
    const notes = String(data.get('notes') ?? '').trim();
    if (!/^\d+(?:\.\d{1,2})?$/.test(amount) || Number(amount) <= 0 || Number(amount) > 999999.99 || !category) {
      setError('أدخل مبلغًا صحيحًا وبيان المصروف.');
      return;
    }

    submitting.current = true;
    setBusy(true);
    setError('');
    try {
      await pos.recordExpense(Number(amount), category, notes);
      onSaved();
    } catch (err) {
      setError(normalizeError(err).message);
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  }

  return (
    <Modal open title="مصروف من الدرج" onClose={() => { if (!submitting.current) onClose(); }} width={440}>
      <p className="t-2 fs-13">سجّل المبلغ المدفوع نقدًا من درج الوردية الحالية.</p>
      <form className="pos-expense-form" onSubmit={submit} aria-busy={busy}>
        <fieldset disabled={busy}>
          <label htmlFor="pos-expense-amount">المبلغ بالجنيه</label>
          <input className="input" id="pos-expense-amount" name="amount" inputMode="decimal" autoFocus required />
          <label htmlFor="pos-expense-category">بيان المصروف</label>
          <input className="input" id="pos-expense-category" name="category" maxLength={100} placeholder="مثال: نقل بضاعة" required />
          <label htmlFor="pos-expense-notes">ملاحظات (اختياري)</label>
          <textarea className="input" id="pos-expense-notes" name="notes" maxLength={500} rows={2} />
        </fieldset>
        {error && <p role="alert" className="pos-inline-error">{error}</p>}
        <div className="pos-dialog-actions">
          <button className="btn btn-primary" disabled={busy}>{busy ? 'جارٍ التسجيل…' : 'تسجيل المصروف'}</button>
          <button className="btn btn-secondary" type="button" disabled={busy} onClick={onClose}>إلغاء</button>
        </div>
      </form>
    </Modal>
  );
}
