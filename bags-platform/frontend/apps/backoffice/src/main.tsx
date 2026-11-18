import React from 'react';
import ReactDOM from 'react-dom/client';
import { App } from './App';
import './index.css';
import { BrandingProvider } from '@alfarid/ui/branding';
import { brandingEndpoints } from '@alfarid/api-client';
import { configurePosSaleTracing } from '@alfarid/pos-core';
import * as Sentry from '@sentry/react';
import { initBackofficeSentry } from './sentry';

initBackofficeSentry();
configurePosSaleTracing((send) => Sentry.startSpan({ name: 'pos.sale', op: 'ui.action' }, send));

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <Sentry.ErrorBoundary fallback={<div role="alert" dir="rtl">حدث خطأ غير متوقع. أعد تحميل الصفحة للمتابعة.</div>}>
      <BrandingProvider loadBranding={brandingEndpoints.get} titleSuffix="لوحة الإدارة"><App /></BrandingProvider>
    </Sentry.ErrorBoundary>
  </React.StrictMode>
);
