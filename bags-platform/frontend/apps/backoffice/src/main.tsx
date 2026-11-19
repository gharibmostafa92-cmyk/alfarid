import React, { lazy, Suspense } from 'react';
import ReactDOM from 'react-dom/client';
import './index.css';
import SessionProbePage from './pages/auth/SessionProbePage';
import { AdminLoading } from './components/admin/AdminLoading';
import { BrandingProvider } from '@alfarid/ui/branding';
import { brandingEndpoints } from '@alfarid/api-client';
import { configurePosSaleTracing } from '@alfarid/pos-core';
import * as Sentry from '@sentry/react';
import { initBackofficeSentry } from './sentry';

const App = lazy(() => import('./App'));
const isSessionProbe = window.location.pathname === '/auth/session';

if (!isSessionProbe) {
  initBackofficeSentry();
  configurePosSaleTracing((send) => Sentry.startSpan({ name: 'pos.sale', op: 'ui.action' }, send));
}

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    {isSessionProbe ? (
      <SessionProbePage />
    ) : (
      <Sentry.ErrorBoundary fallback={<div role="alert" dir="rtl">حدث خطأ غير متوقع. أعد تحميل الصفحة للمتابعة.</div>}>
        <BrandingProvider loadBranding={brandingEndpoints.get} titleSuffix="لوحة الإدارة">
          <Suspense
            fallback={
              <div style={{ minHeight: '100svh', display: 'grid', placeItems: 'center', backgroundColor: '#f2eff3' }}>
                <AdminLoading compact label="جارٍ التحميل" />
              </div>
            }
          >
            <App />
          </Suspense>
        </BrandingProvider>
      </Sentry.ErrorBoundary>
    )}
  </React.StrictMode>
);
