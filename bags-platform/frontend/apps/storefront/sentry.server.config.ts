import * as Sentry from '@sentry/nextjs';
import type { ErrorEvent, StreamedSpanJSON, TransactionEvent } from '@sentry/core';
import {
  parseSentryDsnOrigin,
  sanitizeStorefrontEvent,
  sanitizeStorefrontSpan,
  sanitizeStorefrontTransaction,
  storefrontTraceTargets,
} from './src/lib/core/sentry-telemetry';

const dsn = process.env.SENTRY_DSN?.trim() || undefined;
const sampleRate = Number(process.env.SENTRY_TRACES_SAMPLE_RATE ?? process.env.NEXT_PUBLIC_SENTRY_TRACES_SAMPLE_RATE ?? '0.05');
const validSampleRate = Number.isFinite(sampleRate) && sampleRate >= 0 && sampleRate <= 1 ? sampleRate : 0.05;

Sentry.init({
  dsn: dsn && parseSentryDsnOrigin(dsn) ? dsn : undefined,
  environment: process.env.SENTRY_ENVIRONMENT || process.env.NEXT_PUBLIC_SENTRY_ENVIRONMENT,
  release: process.env.SENTRY_RELEASE || process.env.NEXT_PUBLIC_SENTRY_RELEASE,
  tracesSampler: () => validSampleRate,
  tracePropagationTargets: storefrontTraceTargets([
    process.env.PRIVATE_CLOUD_RUN_API_ORIGIN ?? '',
    process.env.PRIVATE_CLOUD_RUN_AI_ORIGIN ?? '',
  ]),
  beforeSend: (event) => sanitizeStorefrontEvent(event as Record<string, any>) as ErrorEvent | null,
  beforeSendTransaction: (event) => sanitizeStorefrontTransaction(event as Record<string, any>) as TransactionEvent | null,
  beforeSendSpan: (span) => sanitizeStorefrontSpan(span as Record<string, any>) as StreamedSpanJSON,
  beforeBreadcrumb: () => null,
  initialScope: { tags: { service: 'storefront-server' } },
});
