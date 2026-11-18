'use client';

import { useEffect } from 'react';
import * as Sentry from '@sentry/nextjs';

export default function GlobalError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  useEffect(() => {
    Sentry.captureException(error);
  }, [error]);

  return (
    <html lang="ar" dir="rtl">
      <body>
        <main role="alert" style={{ margin: '4rem auto', maxWidth: 560, padding: '1.5rem', textAlign: 'center' }}>
          <h1>حصل خطأ غير متوقع</h1>
          <p>حاول تحديث الصفحة. لو استمرت المشكلة، تواصل معنا.</p>
          <button onClick={reset} type="button">حاول مرة أخرى</button>
        </main>
      </body>
    </html>
  );
}
