import * as Sentry from '@sentry/react';
import { backofficeSentryConfig } from '../sentry-config';
import type { BackofficeSentryEnvironment } from '../sentry-config';
import { sanitizeBackofficeEvent, sanitizeBackofficeSpan } from './sentry-sanitize';

export { backofficeSentryConfig, backofficeTraceTargets, sentryIngestOrigin } from '../sentry-config';
export { sanitizeBackofficeEvent } from './sentry-sanitize';

export function initBackofficeSentry(): void {
  const config = backofficeSentryConfig(import.meta.env as BackofficeSentryEnvironment);
  if (!config.enabled) return;

  Sentry.init({
    dsn: config.dsn,
    environment: config.environment,
    release: config.release,
    tracesSampleRate: config.tracesSampleRate,
    tracePropagationTargets: config.tracePropagationTargets,
    integrations: [Sentry.browserTracingIntegration()],
    beforeSend: sanitizeBackofficeEvent,
    beforeSendTransaction: sanitizeBackofficeEvent,
    beforeSendSpan: (span) => sanitizeBackofficeSpan(span) as typeof span,
    beforeBreadcrumb: () => null,
    initialScope: { tags: { service: 'backoffice-browser' } },
  });
}
