'use client';

import { useEffect, useState } from 'react';
import type { TrackOrderResponse } from '@alfarid/api-client';
import { formatStorePrice } from '@alfarid/utils';

// Maximum delay accepted by setTimeout (32-bit signed integer); larger numbers wrap around to 0.
const MAX_TIMEOUT_MS = 2 ** 31 - 1;

/**
 * Renders InstaPay payment instructions with Cairo deadline and live expiration handler.
 */
export function InstaPayInstructions({ details, className }: {
  details: NonNullable<TrackOrderResponse['instapay']>;
  className?: string;
}) {
  const parsedDeadline = Date.parse(details.expires_at);
  const deadline = Number.isFinite(parsedDeadline) ? parsedDeadline : 0;
  const [expiredDeadline, setExpiredDeadline] = useState(() => deadline <= Date.now() ? deadline : null);

  useEffect(() => {
    let timer: ReturnType<typeof setTimeout> | undefined;
    function checkDeadline() {
      const remaining = deadline - Date.now();
      if (remaining <= 0) {
        setExpiredDeadline(deadline);
        return;
      }
      timer = setTimeout(checkDeadline, Math.min(remaining, MAX_TIMEOUT_MS));
    }
    checkDeadline();
    return () => clearTimeout(timer);
  }, [deadline]);

  if (expiredDeadline === deadline) return <p className={className}>انتهت مهلة التحويل لهذا الطلب.</p>;

  return (
    <section aria-label="تعليمات التحويل عبر InstaPay" className={className} dir="rtl">
      <p>حوّل <strong>{formatStorePrice(Number(details.amount))}</strong> عبر InstaPay إلى <strong dir="ltr">{details.address}</strong>.</p>
      <p>التحويل متاح لحد {new Date(deadline).toLocaleString('ar-EG', { timeZone: 'Africa/Cairo' })} بتوقيت القاهرة.</p>
      <p>ابعث للمتجر رقم الطلب والرقم المرجعي للتحويل عشان يراجع الدفع.</p>
    </section>
  );
}
