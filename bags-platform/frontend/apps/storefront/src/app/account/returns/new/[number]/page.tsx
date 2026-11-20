'use client';

import { useEffect, useRef, useState, type FormEvent } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { accountEndpoints, type StoreOrder, type StoreReturnRequest } from '@alfarid/api-client';
import { useStoreLang } from '@/providers/store-lang-provider';

const fieldStyle = 'min-h-11 rounded-lg border border-slate-400 bg-white px-3 py-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-700';

function ReturnForm({ number }: { number: string }) {
  const { isAr } = useStoreLang();
  const [order, setOrder] = useState<StoreOrder | null>(null);
  const [loadFailed, setLoadFailed] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const [quantities, setQuantities] = useState<Record<number, string>>({});
  const [reason, setReason] = useState('');
  const [reasonType, setReasonType] = useState<'' | 'change_of_mind' | 'defect_or_mismatch'>('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [created, setCreated] = useState<StoreReturnRequest | null>(null);
  const busy = useRef(false);
  const feedback = useRef<HTMLDivElement>(null);

  useEffect(() => {
    let active = true;
    accountEndpoints.order(number).then(
      (result) => { if (active) setOrder(result); },
      () => { if (active) setLoadFailed(true); },
    );
    return () => { active = false; };
  }, [number, attempt]);

  useEffect(() => {
    if (error || created) feedback.current?.focus();
  }, [error, created]);

  const eligible = order?.items.flatMap((item) => {
    const max = Math.min(order.return_quantities?.[item.id] ?? 0, 99_999_999);
    return max > 0 ? [{ ...item, max }] : [];
  }) ?? [];
  const selected = eligible.flatMap((item) => item.id in quantities
    ? [{ order_item_id: item.id, requested_qty: Number(quantities[item.id]), max: item.max }]
    : []);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!order || busy.current) return;
    setError('');
    if (!selected.length || selected.length > 50 || selected.some(({ requested_qty, max }) =>
      !Number.isInteger(requested_qty) || requested_qty < 1 || requested_qty > max
    )) {
      setError(isAr ? 'اختر من منتج واحد إلى 50 منتجًا وحدد كمية الإرجاع لكل منتج.' : 'Select 1–50 products and enter a valid quantity for each.');
      return;
    }
    if (!reason.trim()) {
      setError(isAr ? 'يرجى كتابة سبب الإرجاع.' : 'Enter a reason for the return.');
      return;
    }
    if (!reasonType) {
      setError(isAr ? 'حدد ما إذا كان الطلب بسبب العدول عن الشراء أو وجود عيب.' : 'Choose whether this is a change-of-mind or defect request.');
      return;
    }
    busy.current = true;
    setSubmitting(true);
    try {
      const result = await accountEndpoints.createReturn({
        order_id: order.id,
        reason: reason.trim(),
        reason_type: reasonType,
        items: selected.map(({ order_item_id, requested_qty }) => ({ order_item_id, requested_qty })),
      });
      setCreated(result);
    } catch (failure) {
      const response = (failure as { response?: { status?: number } } | null)?.response;
      if (response?.status === 422) {
        setError(isAr ? 'تعذر قبول الطلب. يرجى مراجعة الكميات المتاحة والمحاولة مرة أخرى.' : 'The request could not be accepted. Review the available quantities and try again.');
        setAttempt((previous) => previous + 1);
      } else {
        setError(isAr ? 'تعذر تأكيد إرسال الطلب. راجع طلبات الإرجاع قبل إعادة المحاولة.' : 'We could not confirm your submission. Check your return requests before trying again.');
      }
    } finally {
      busy.current = false;
      setSubmitting(false);
    }
  };

  return (
    <main id="main-content" tabIndex={-1} className="checkout-wrapper">
      <div className="mx-auto max-w-[720px] px-5 py-10">
        <Link href="/account/orders" className="inline-flex min-h-11 items-center text-sm text-slate-700 underline underline-offset-4">
          {isAr ? 'العودة إلى طلباتي' : 'Back to my orders'}
        </Link>
        <h1 className="checkout-giant-title">{isAr ? 'طلب إرجاع' : 'Request a return'}</h1>
        {created ? (
          <div ref={feedback} tabIndex={-1} className="checkout-block">
            <p role="status" className="font-semibold">{isAr ? 'تم استلام طلب الإرجاع وهو قيد المراجعة.' : 'Your return request was received and is under review.'}</p>
            <p className="mt-2 text-sm"><bdi>{created.return_number || `#${created.id}`}</bdi></p>
            <Link href="/account/returns">{isAr ? 'متابعة طلبات الإرجاع' : 'Track return requests'}</Link>
          </div>
        ) : loadFailed ? (
          <div className="checkout-block">
            <p role="alert">{isAr ? 'تعذر تحميل بيانات الطلب. يرجى المحاولة مرة أخرى.' : 'We could not load this order. Please try again.'}</p>
            <button type="button" className="btn-apply-promo mt-3" onClick={() => {
              setLoadFailed(false);
              setAttempt((previous) => previous + 1);
            }}>{isAr ? 'إعادة المحاولة' : 'Try again'}</button>
          </div>
        ) : !order ? (
          <p className="checkout-block" role="status">{isAr ? 'جارٍ تحميل الطلب…' : 'Loading order…'}</p>
        ) : !eligible.length ? (
          <div className="checkout-block">
            <p>{isAr ? 'لا توجد منتجات متاحة للإرجاع في هذا الطلب.' : 'No items in this order are currently eligible for return.'}</p>
            <Link href="/account/returns">{isAr ? 'عرض طلبات الإرجاع' : 'View return requests'}</Link>
          </div>
        ) : (
          <form className="checkout-block account-detail-card" onSubmit={submit}>
            <p className="mb-4 text-sm text-slate-600">
              {isAr ? 'رقم الطلب' : 'Order number'} <bdi className="font-semibold text-slate-900">{order.order_number}</bdi>
            </p>
            <p className="mb-4 text-sm leading-7 text-slate-700">
              {isAr
                ? 'العدول عن الشراء متاح خلال 14 يومًا من التسلم، والعيب أو عدم المطابقة خلال 30 يومًا، مع مراعاة الحالات التي يحددها القانون.'
                : 'Change-of-mind requests are available within 14 days of receipt. Defect or mismatch requests are available within 30 days, subject to legal exceptions.'}
            </p>
            {error && <div ref={feedback} tabIndex={-1} role="alert" className="checkout-banner is-error mb-4">{error}</div>}
            <fieldset disabled={submitting} className="min-w-0 disabled:opacity-70">
              <legend className="font-semibold">{isAr ? 'المنتجات المتاحة للإرجاع' : 'Items eligible for return'}</legend>
              <ul className="mt-2 divide-y divide-slate-200 rounded-xl border border-slate-200 px-3 sm:px-4">
                {eligible.map((item) => {
                  const checked = item.id in quantities;
                  return (
                    <li key={item.id} className="py-1">
                      <label className="flex min-h-11 cursor-pointer items-start gap-3 py-2">
                        <input type="checkbox" checked={checked} className="mt-1 size-4 shrink-0 accent-slate-800" onChange={(event) => {
                          const enabled = event.currentTarget.checked;
                          setQuantities((current) => {
                            const next = { ...current };
                            if (enabled) next[item.id] = '1';
                            else delete next[item.id];
                            return next;
                          });
                        }} />
                        <span className="min-w-0">
                          <bdi className="block text-sm font-medium">{item.product_name || item.sku || (isAr ? 'منتج' : 'Product')}</bdi>
                          <span className="block text-xs text-slate-600">{isAr ? 'الكمية المتاحة' : 'Available quantity'} {item.max.toLocaleString(isAr ? 'ar-EG' : 'en-EG')}</span>
                        </span>
                      </label>
                      {checked && (
                        <label className="flex flex-wrap items-center gap-3 pb-3 ps-7 text-sm">
                          <span>{isAr ? 'كمية الإرجاع' : 'Return quantity'}</span>
                          <input type="number" dir="ltr" inputMode="numeric" min={1} max={item.max} step={1} required className={`${fieldStyle} w-36 max-w-full`}
                            value={quantities[item.id]} onChange={(event) => {
                              const value = event.currentTarget.value;
                              setQuantities((current) => ({ ...current, [item.id]: value }));
                            }} />
                        </label>
                      )}
                    </li>
                  );
                })}
              </ul>
              <label className="mt-5 grid gap-2 text-sm font-semibold">
                <span>{isAr ? 'نوع الطلب' : 'Request type'}</span>
                <select className={`${fieldStyle} w-full font-normal`} required value={reasonType}
                  onChange={(event) => setReasonType(event.currentTarget.value as typeof reasonType)}>
                  <option value="">{isAr ? 'اختر نوع الطلب' : 'Choose a request type'}</option>
                  <option value="change_of_mind">{isAr ? 'العدول عن الشراء' : 'Change of mind'}</option>
                  <option value="defect_or_mismatch">{isAr ? 'عيب أو عدم مطابقة' : 'Defective or not as described'}</option>
                </select>
              </label>
              <label className="mt-5 grid gap-2 text-sm font-semibold">
                <span>{isAr ? 'سبب الإرجاع' : 'Reason for return'}</span>
                <textarea className={`${fieldStyle} w-full resize-y font-normal`} dir="auto" rows={4} required maxLength={2000}
                  value={reason} onChange={(event) => setReason(event.currentTarget.value)} />
              </label>
              <button type="submit" className="btn-place-order mt-5 min-h-11 w-full active:opacity-80 sm:w-auto" disabled={submitting}>
                {submitting ? (isAr ? 'جارٍ الإرسال…' : 'Submitting…') : (isAr ? 'إرسال طلب الإرجاع' : 'Submit return request')}
              </button>
            </fieldset>
          </form>
        )}
      </div>
    </main>
  );
}

export default function NewReturnPage() {
  const { number } = useParams<{ number: string }>();
  return <ReturnForm key={number} number={number} />;
}
