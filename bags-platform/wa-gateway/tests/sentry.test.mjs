import assert from 'node:assert/strict';
import test from 'node:test';
import Fastify from 'fastify';
import {
  registerFastifyTelemetry,
  sampleRateFromEnvironment,
  sanitizeGatewayEvent,
  sanitizeGatewaySpan,
  validGatewayDsnOrigin,
} from '../src/instrumentation.js';

function recordingSdk() {
  const events = [];
  const spans = [];
  return {
    events,
    spans,
    withScope(callback) { callback({ setTag() {} }); },
    captureException(error) {
      events.push(sanitizeGatewayEvent({ exception: { values: [{ type: error.name, value: error.message }] } }));
    },
    startInactiveSpan(options) {
      const span = {
        options,
        status: null,
        ended: false,
        setStatus(status) { this.status = status; },
        end() { this.ended = true; },
      };
      spans.push(span);
      return span;
    },
  };
}

test('validates Sentry DSN and sampling settings without enabling a local DSN', () => {
  assert.equal(validGatewayDsnOrigin(''), null);
  assert.equal(validGatewayDsnOrigin('https://publickey@o123.ingest.de.sentry.io/456'), 'https://o123.ingest.de.sentry.io');
  assert.equal(validGatewayDsnOrigin('http://publickey@o123.ingest.de.sentry.io/456'), null);
  assert.equal(validGatewayDsnOrigin('https://publickey@evil.example/456'), null);
  assert.equal(sampleRateFromEnvironment({}), 0.05);
  assert.equal(sampleRateFromEnvironment({ SENTRY_TRACES_SAMPLE_RATE: '1' }), 1);
  assert.throws(() => sampleRateFromEnvironment({ SENTRY_TRACES_SAMPLE_RATE: '1.5' }), /between 0 and 1/);
});

test('captures one sanitized Fastify 500, skips health and 4xx, and records successful request duration', async () => {
  const app = Fastify();
  const sdk = recordingSdk();
  registerFastifyTelemetry(app, sdk);
  app.get('/status', async () => ({ ok: true }));
  app.get('/client-error', async (_request, reply) => reply.code(404).send({ message: 'not found' }));
  app.get('/success', async () => ({ ok: true }));
  app.get('/failure', async () => { throw new Error('SENTRY_TEST_WA_TEXT SENTRY_TEST_QR SENTRY_TEST_SESSION SENTRY_TEST_PHONE SENTRY_TEST_TOKEN'); });

  try {
    const health = await app.inject('/status');
    const clientError = await app.inject('/client-error');
    const success = await app.inject('/success');
    const failure = await app.inject('/failure');
    assert.equal(health.statusCode, 200);
    assert.equal(clientError.statusCode, 404);
    assert.equal(success.statusCode, 200);
    assert.equal(failure.statusCode, 500);
    assert.equal(sdk.events.length, 1);
    assert.equal(sdk.spans.length, 2);
    assert.equal(sdk.spans.every(span => span.ended), true);
    assert.equal(sdk.spans[0].options.name, 'whatsapp.gateway.request');
    assert.equal(sdk.spans[0].options.op, 'http.server');
    assert.equal(sdk.spans[1].status.code, 2);
    const serialized = JSON.stringify(sdk.events);
    assert.doesNotMatch(serialized, /SENTRY_TEST_(WA_TEXT|QR|SESSION|PHONE|TOKEN)/);
  } finally {
    await app.close();
  }
});

test('sanitizes request and span data and a failed telemetry transport does not change Fastify responses', async () => {
  const event = sanitizeGatewayEvent({
    message: 'SENTRY_TEST_WA_TEXT',
    request: { url: '/send?SENTRY_TEST_PHONE=secret', data: 'SENTRY_TEST_TOKEN' },
    user: { id: 'SENTRY_TEST_SESSION' },
    breadcrumbs: [{ message: 'SENTRY_TEST_QR' }],
    extra: { token: 'SENTRY_TEST_TOKEN' },
    exception: { values: [{ type: 'Error', value: 'SENTRY_TEST_WA_TEXT' }] },
  });
  const span = sanitizeGatewaySpan({ name: 'SENTRY_TEST_WA_TEXT', description: 'SENTRY_TEST_QR', data: { token: 'SENTRY_TEST_TOKEN' }, attributes: { phone: 'SENTRY_TEST_PHONE' } });
  assert.doesNotMatch(JSON.stringify({ event, span }), /SENTRY_TEST_(WA_TEXT|QR|SESSION|PHONE|TOKEN)/);

  const app = Fastify();
  registerFastifyTelemetry(app, {
    withScope(callback) { callback({ setTag() {} }); },
    captureException() { throw new Error('transport unavailable'); },
    startInactiveSpan() { throw new Error('transport unavailable'); },
  });
  app.get('/failure', async () => { throw new Error('controlled failure'); });
  try {
    const response = await app.inject('/failure');
    assert.equal(response.statusCode, 500);
  } finally {
    await app.close();
  }
});
