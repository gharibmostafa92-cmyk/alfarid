import assert from 'node:assert/strict';
import test from 'node:test';
import {
  parseSentryDsnOrigin,
  proxyTraceContext,
  sanitizeStorefrontEvent,
  sanitizeStorefrontSpan,
  sanitizeStorefrontTransaction,
  storefrontTraceTargets,
  traceableApiPath,
} from '../apps/storefront/src/lib/core/sentry-telemetry.ts';

test('accepts only HTTPS Sentry cloud DSNs and omits CSP origins when disabled', () => {
  assert.equal(parseSentryDsnOrigin(''), null);
  assert.equal(parseSentryDsnOrigin('https://publickey@o123.ingest.de.sentry.io/456'), 'https://o123.ingest.de.sentry.io');
  assert.equal(parseSentryDsnOrigin('https://publickey@o123.ingest.de.sentry.io/456?token=bad'), null);
  assert.equal(parseSentryDsnOrigin('http://publickey@o123.ingest.de.sentry.io/456'), null);
  assert.equal(parseSentryDsnOrigin('https://publickey@evil.example/456'), null);
});

test('extracts only opaque trace identifiers and ignores the caller sampled decision', () => {
  for (const sampled of ['0', '1']) {
    assert.deepEqual(proxyTraceContext(new Headers({
      'sentry-trace': `0123456789abcdef0123456789abcdef-0123456789abcdef-${sampled}`,
      baggage: 'SENTRY_TEST_TOKEN=attacker-value',
    })), { traceId: '0123456789abcdef0123456789abcdef', parentSpanId: '0123456789abcdef' });
  }
  for (const malformed of ['SENTRY_TEST_TOKEN', '0123456789abcdef-0123456789abcdef-1', '0123456789abcdef0123456789abcdef-0123456789abcdef-true']) {
    assert.equal(proxyTraceContext(new Headers({ 'sentry-trace': malformed })), null);
  }
});

test('propagation targets are limited to same-origin BFF APIs and exact private Cloud Run hosts', () => {
  const targets = storefrontTraceTargets(['https://api-one.run.app', 'https://api-two.run.app.evil.test']);
  const matches = (url: string) => targets.some((target) => target.test(url));
  assert.equal(matches('/api/v1/store/catalog'), true);
  assert.equal(matches('/api/v1/webhooks/stripe'), false);
  assert.equal(matches('https://api-one.run.app/api/v1/store/catalog'), true);
  assert.equal(matches('https://api-one.run.app.evil.test/api/v1/store/catalog'), false);
  assert.equal(matches('https://api-two.run.app.evil.test/api/v1/store/catalog'), false);
  assert.equal(matches('https://unlisted.run.app/api/v1/store/catalog'), false);
});

test('does not trace webhooks or metadata paths', () => {
  assert.equal(traceableApiPath(['store', 'checkout']), true);
  assert.equal(traceableApiPath(['admin', 'ai', 'conversations', '8', 'stream']), true);
  assert.equal(traceableApiPath(['webhooks', 'payment', 'stripe']), false);
  assert.equal(traceableApiPath(['store', 'catalog', 'meta']), false);
  assert.equal(traceableApiPath(['store', 'catalog', 'metadata']), false);
  assert.equal(traceableApiPath(['store', '..']), false);
});

test('sanitizes error and trace payloads before they leave the browser or server', () => {
  const raw = {
    event_id: 'event-id',
    message: 'SENTRY_TEST_TOKEN from SENTRY_TEST_QUERY',
    transaction: '/checkout/order/SENTRY_TEST_PHONE?SENTRY_TEST_QUERY=secret',
    request: { url: 'https://example.test/checkout?SENTRY_TEST_TOKEN=secret', headers: { cookie: 'SENTRY_TEST_COOKIE' } },
    user: { id: 'SENTRY_TEST_PHONE', email: 'secret@example.test' },
    extra: { prompt: 'SENTRY_TEST_PROMPT', token: 'SENTRY_TEST_TOKEN' },
    breadcrumbs: [{ message: 'SENTRY_TEST_WA_TEXT' }],
    exception: { values: [{ type: 'TypeError', value: 'SENTRY_TEST_TOKEN', stacktrace: { frames: [{
      filename: 'https://example.test/_next/static/chunks/app.js?SENTRY_TEST_QUERY',
      function: 'submitCheckout',
      lineno: 27,
      vars: { token: 'SENTRY_TEST_TOKEN' },
      context_line: 'SENTRY_TEST_PROMPT',
    }] } }] },
    contexts: { trace: { trace_id: 'safe-trace', span_id: 'safe-span', extra: 'SENTRY_TEST_TOKEN' }, runtime: { secret: 'SENTRY_TEST_TOKEN' } },
    tags: { secret: 'SENTRY_TEST_TOKEN' },
  };
  const event = sanitizeStorefrontEvent(raw)!;
  const transaction = sanitizeStorefrontTransaction({
    ...raw,
    spans: [{ op: 'http.client', description: 'SENTRY_TEST_TOKEN', data: { url: 'SENTRY_TEST_QUERY' }, tags: { secret: 'SENTRY_TEST_TOKEN' } }],
  })!;
  const span = sanitizeStorefrontSpan({ op: 'http.client', description: 'SENTRY_TEST_TOKEN', data: { secret: 'SENTRY_TEST_TOKEN' }, attributes: { token: 'SENTRY_TEST_TOKEN' } });
  const serialized = JSON.stringify({ event, transaction, span });
  for (const marker of ['SENTRY_TEST_TOKEN', 'SENTRY_TEST_QUERY', 'SENTRY_TEST_PHONE', 'SENTRY_TEST_COOKIE', 'SENTRY_TEST_PROMPT', 'SENTRY_TEST_WA_TEXT']) {
    assert.equal(serialized.includes(marker), false, `sanitized payload leaked ${marker}`);
  }
  assert.equal(event.exception.values[0].type, 'TypeError');
  assert.equal(event.exception.values[0].stacktrace.frames[0].lineno, 27);
  assert.equal(event.tags.service, 'storefront-browser');
  assert.equal(transaction.spans[0].description, 'bff.upstream');
  assert.equal(span.description, 'bff.upstream');
});
