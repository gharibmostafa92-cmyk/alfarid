import assert from 'node:assert/strict';
import test from 'node:test';
import { proxyCloudRunApi } from '../apps/storefront/src/lib/core/cloud-run-api.ts';

const storefront = 'https://storefront.run.app';
const office = 'https://backoffice.pages.dev';
const upstream = 'https://private-api.run.app';
const clientIpSecret = 'proxy-client-ip-secret';
const makeRequest = (origin = office, init: RequestInit = {}) =>
  new Request(storefront + '/api/v1/pos/sales?include=items', {
    ...init,
    headers: { ...init.headers, origin },
  });

test('forwards the user token and idempotency key separately from the service identity', async () => {
  const response = await proxyCloudRunApi(makeRequest(office, {
    method: 'POST',
    body: '{"items":[]}',
    headers: {
      authorization: 'Bearer user-token',
      'idempotency-key': 'test-key',
      'x-serverless-authorization': 'Bearer forged',
      'x-forwarded-for': '192.0.2.9, 10.0.0.1',
      'user-agent': 'Alfarid-Test/1.0',
      'accept-language': 'ar-EG,ar;q=0.9',
      'content-type': 'application/json',
    },
  }), ['pos', 'sales'], {
    upstream, origins: [office], clientIpSecret,
    identityToken: async () => 'service-token',
    send: async (url, init) => {
      assert.equal(String(url), upstream + '/api/v1/pos/sales?include=items');
      const headers = new Headers(init?.headers);
      assert.equal(headers.get('authorization'), 'Bearer user-token');
      assert.equal(headers.get('x-serverless-authorization'), 'Bearer service-token');
      assert.equal(headers.get('idempotency-key'), 'test-key');
      assert.equal(headers.get('x-forwarded-for'), null);
      assert.equal(headers.get('x-alfarid-client-ip'), '10.0.0.1');
      assert.match(headers.get('x-alfarid-client-ip-signature') ?? '', /^[0-9a-f]{64}$/);
      assert.equal(headers.get('user-agent'), 'Alfarid-Test/1.0');
      assert.equal(headers.get('accept-language'), 'ar-EG,ar;q=0.9');
      assert.equal(init?.redirect, 'manual');
      assert.equal(await new Response(init?.body).text(), '{"items":[]}');
      return new Response('created', { status: 201, headers: { 'set-cookie': 'private=1', 'x-request-id': 'request-id' } });
    },
  });
  assert.equal(response.status, 201);
  assert.equal(response.headers.get('access-control-allow-origin'), office);
  assert.equal(response.headers.get('set-cookie'), null);
  assert.equal(response.headers.get('x-request-id'), 'request-id');
});

test('handles preflight locally and rejects foreign origins and traversal', async () => {
  const config = {
    upstream, origins: [office], clientIpSecret,
    identityToken: async () => { throw new Error('must not request identity'); },
  };
  const preflight = await proxyCloudRunApi(makeRequest(office, {
    method: 'OPTIONS',
    headers: { 'access-control-request-method': 'POST', 'access-control-request-headers': 'authorization,idempotency-key' },
  }), ['pos', 'sales'], config);
  assert.equal(preflight.status, 204);
  assert.equal(preflight.headers.get('access-control-allow-origin'), office);
  assert.equal((await proxyCloudRunApi(makeRequest('https://evil.example'), ['pos', 'sales'], config)).status, 403);
  for (const path of [['pos', '..'], ['pos', '%2fadmin'], ['not-a-namespace', 'sales']]) {
    assert.equal((await proxyCloudRunApi(makeRequest(), path, config)).status, 404);
  }
});

test('forwards provider webhooks with signature headers and the raw body', async () => {
  const request = new Request(storefront + '/api/v1/webhooks/payment/stripe', {
    method: 'POST',
    body: '{"id":"evt_1","type":"checkout.session.completed"}',
    headers: {
      'content-type': 'application/json',
      'stripe-signature': 't=1,v1=signature',
      'x-paymob-signature': 'paymob-signature',
      'x-forwarded-for': '198.51.100.7',
      authorization: 'Bearer should-not-pass',
    },
  });
  const response = await proxyCloudRunApi(request, ['webhooks', 'payment', 'stripe'], {
    upstream, origins: [office], clientIpSecret,
    identityToken: async () => 'service-token',
    send: async (url, init) => {
      assert.equal(String(url), upstream + '/api/v1/webhooks/payment/stripe');
      const headers = new Headers(init?.headers);
      assert.equal(headers.get('stripe-signature'), 't=1,v1=signature');
      assert.equal(headers.get('x-paymob-signature'), 'paymob-signature');
      assert.equal(headers.get('authorization'), null);
      assert.equal(headers.get('x-alfarid-client-ip'), '198.51.100.7');
      assert.equal(await new Response(init?.body).text(), '{"id":"evt_1","type":"checkout.session.completed"}');
      return new Response('ok', { status: 200 });
    },
  });
  assert.equal(response.status, 200);
});

test('rejects non-POST webhook methods', async () => {
  const response = await proxyCloudRunApi(new Request(storefront + '/api/v1/webhooks/payment/stripe'), ['webhooks', 'payment', 'stripe'], {
    upstream, origins: [office], clientIpSecret,
    identityToken: async () => 'service-token',
  });
  assert.equal(response.status, 405);
});

test('passes safe public cache headers through while keeping authenticated responses private', async () => {
  const publicResponse = await proxyCloudRunApi(new Request(storefront + '/api/v1/store/catalog'), ['store', 'catalog'], {
    upstream, origins: [office],
    identityToken: async () => 'service-token',
    send: async () => new Response('catalog', { status: 200, headers: { 'cache-control': 'public, s-maxage=60' } }),
  });
  assert.equal(publicResponse.headers.get('cache-control'), 'public, s-maxage=60');

  const authenticatedResponse = await proxyCloudRunApi(new Request(storefront + '/api/v1/store/catalog', {
    headers: { authorization: 'Bearer user-token' },
  }), ['store', 'catalog'], {
    upstream, origins: [office],
    identityToken: async () => 'service-token',
    send: async () => new Response('catalog', { status: 200, headers: { 'cache-control': 'public, s-maxage=60' } }),
  });
  assert.equal(authenticatedResponse.headers.get('cache-control'), 'no-store');
});
