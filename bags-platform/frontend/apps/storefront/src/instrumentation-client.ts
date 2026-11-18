import * as Sentry from '@sentry/nextjs';
import type { ErrorEvent, StreamedSpanJSON, TransactionEvent } from '@sentry/core';
import {
  parseSentryDsnOrigin,
  sanitizeStorefrontEvent,
  sanitizeStorefrontSpan,
  sanitizeStorefrontTransaction,
  storefrontTraceTargets,
} from './lib/core/sentry-telemetry';

const dsn = process.env.NEXT_PUBLIC_SENTRY_DSN?.trim() || undefined;
const sampleRate = Number(process.env.NEXT_PUBLIC_SENTRY_TRACES_SAMPLE_RATE ?? '0.05');
const validSampleRate = Number.isFinite(sampleRate) && sampleRate >= 0 && sampleRate <= 1 ? sampleRate : 0.05;

Sentry.init({
  dsn: dsn && parseSentryDsnOrigin(dsn) ? dsn : undefined,
  environment: process.env.NEXT_PUBLIC_SENTRY_ENVIRONMENT,
  release: process.env.NEXT_PUBLIC_SENTRY_RELEASE,
  tracesSampleRate: validSampleRate,
  // Browser traces enter through the same-origin BFF only. The BFF creates
  // fresh trace headers for its allowlisted private Cloud Run upstreams.
  tracePropagationTargets: storefrontTraceTargets([]),
  beforeSend: (event) => sanitizeStorefrontEvent(event as Record<string, any>) as ErrorEvent | null,
  beforeSendTransaction: (event) => sanitizeStorefrontTransaction(event as Record<string, any>) as TransactionEvent | null,
  beforeSendSpan: (span) => sanitizeStorefrontSpan(span as Record<string, any>) as StreamedSpanJSON,
  beforeBreadcrumb: () => null,
  initialScope: { tags: { service: 'storefront-browser' } },
});

export function onRouterTransitionStart(...args: Parameters<typeof Sentry.captureRouterTransitionStart>): void {
  Sentry.captureRouterTransitionStart(...args);
}
