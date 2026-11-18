import assert from 'node:assert/strict';
import test from 'node:test';
import {
  backofficeSentryConfig,
  backofficeTraceTargets,
} from '../apps/backoffice/sentry-config.ts';
import { sanitizeBackofficeEvent, sanitizeBackofficeSpan } from '../apps/backoffice/src/sentry-sanitize.ts';
import { configurePosSaleTracing, runPosSaleWithTrace } from '../packages/pos-core/src/services/pos-sale-tracing.ts';

test('trace propagation is limited to the configured API and excludes webhook paths', () => {
  const targets = backofficeTraceTargets('https://api.example.test');

  assert.equal(targets.some((target) => target.test('https://api.example.test/api/v1/pos/sales')), true);
  assert.equal(targets.some((target) => target.test('https://api.example.test.evil.test/api/v1/pos/sales')), false);
  assert.equal(targets.some((target) => target.test('https://api.example.test/api/v1/webhooks/whatsapp/inbound')), false);
  assert.equal(targets.some((target) => target.test('https://other.example.test/api/v1/pos/sales')), false);
});

test('blank DSN disables browser capture and does not add an ingest origin', () => {
  const config = backofficeSentryConfig({
    VITE_SENTRY_DSN: '',
    VITE_SENTRY_ENVIRONMENT: 'staging',
    VITE_SENTRY_RELEASE: 'a'.repeat(40),
    VITE_SENTRY_TRACES_SAMPLE_RATE: '0.05',
  });

  assert.equal(config.enabled, false);
  assert.equal(config.dsn, undefined);
  assert.equal(config.ingestOrigin, undefined);
  assert.equal(config.sendDefaultPii, false);
});

test('event sanitizer removes secrets from messages, requests, contexts, breadcrumbs, frames, and spans', () => {
  const sanitized = sanitizeBackofficeEvent({
    message: 'SENTRY_TEST_PROMPT',
    request: {
      method: 'POST',
      url: 'https://admin.example.test/pos?phone=SENTRY_TEST_PHONE',
      headers: { authorization: 'SENTRY_TEST_TOKEN' },
      data: 'SENTRY_TEST_PROMPT',
    },
    user: { id: 'SENTRY_TEST_PHONE' },
    extra: { token: 'SENTRY_TEST_TOKEN' },
    tags: { service: 'wrong', token: 'SENTRY_TEST_TOKEN' },
    contexts: {
      trace: { trace_id: '0123456789abcdef0123456789abcdef', span_id: '0123456789abcdef', prompt: 'SENTRY_TEST_PROMPT' },
      customer: { phone: 'SENTRY_TEST_PHONE' },
    },
    breadcrumbs: [{ message: 'SENTRY_TEST_PROMPT' }],
    exception: {
      values: [{
        type: 'Error',
        value: 'SENTRY_TEST_TOKEN',
        stacktrace: { frames: [{ filename: 'src/main.tsx', lineno: 10, vars: { token: 'SENTRY_TEST_TOKEN' }, context_line: 'SENTRY_TEST_PHONE' }] },
      }],
    },
    spans: [{ op: 'http.client', description: 'https://api.example.test/orders?phone=SENTRY_TEST_PHONE', data: { url: 'SENTRY_TEST_PHONE' }, tags: { token: 'SENTRY_TEST_TOKEN' } }],
  });

  const payload = JSON.stringify(sanitized);
  for (const marker of ['SENTRY_TEST_PROMPT', 'SENTRY_TEST_PHONE', 'SENTRY_TEST_TOKEN']) {
    assert.equal(payload.includes(marker), false, `payload contains ${marker}`);
  }
  assert.deepEqual(sanitized.tags, { service: 'backoffice-browser' });
  assert.deepEqual(sanitized.contexts?.trace, {
    trace_id: '0123456789abcdef0123456789abcdef',
    span_id: '0123456789abcdef',
  });
  assert.equal(sanitized.exception?.values?.[0]?.value, 'Error');
  assert.equal(sanitized.spans?.[0]?.description, 'http.client');
});

test('streamed span sanitizer keeps the POS operation name and strips its attributes', () => {
  const span = sanitizeBackofficeSpan({
    name: 'pos.sale',
    op: 'ui.action',
    description: 'SENTRY_TEST_PHONE',
    data: { prompt: 'SENTRY_TEST_TOKEN' },
    attributes: { url: 'SENTRY_TEST_QUERY' },
    tags: { service: 'SENTRY_TEST_TOKEN' },
    trace_id: 'trace-id',
    span_id: 'span-id',
  });
  assert.equal(span.name, 'pos.sale');
  assert.equal(span.description, 'pos.sale');
  assert.doesNotMatch(JSON.stringify(span), /SENTRY_TEST_(PHONE|TOKEN|QUERY)/);
});

test('POS sale tracer invokes the existing request exactly once and preserves its result and rejection', async (context) => {
  let traceCalls = 0;
  configurePosSaleTracing(async (send) => {
    traceCalls += 1;
    return send();
  });
  context.after(() => configurePosSaleTracing());

  let requestCalls = 0;
  const result = await runPosSaleWithTrace(async () => {
    requestCalls += 1;
    return { invoiceNumber: 'POS-7', idempotencyKey: 'existing-key' };
  });
  assert.equal(traceCalls, 1);
  assert.equal(requestCalls, 1);
  assert.deepEqual(result, { invoiceNumber: 'POS-7', idempotencyKey: 'existing-key' });

  await assert.rejects(runPosSaleWithTrace(async () => { throw new Error('request failed'); }), /request failed/);
  assert.equal(traceCalls, 2);
});
