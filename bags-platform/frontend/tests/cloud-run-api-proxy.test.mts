import assert from 'node:assert/strict';
import test from 'node:test';
import { proxyCloudRunApi } from '../apps/storefront/src/lib/core/cloud-run-api.ts';

const storefront = 'https://storefront.run.app';
const office = 'https://backoffice.pages.dev';
const upstream = 'https://private-api.run.app';
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
      'x-forwarded-for': '192.0.2.9',
      'content-type': 'application/json',
    },
  }), ['pos', 'sales'], {
    upstream, origins: [office],
    identityToken: async () => 'service-token',
    send: async (url, init) => {
      assert.equal(String(url), upstream + '/api/v1/pos/sales?include=items');
      const headers = new Headers(init?.headers);
      assert.equal(headers.get('authorization'), 'Bearer user-token');
      assert.equal(headers.get('x-serverless-authorization'), 'Bearer service-token');
      assert.equal(headers.get('idempotency-key'), 'test-key');
      assert.equal(headers.get('x-forwarded-for'), null);
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

test('handles preflight locally and rejects foreign origins, traversal, and webhook routes', async () => {
  const config = {
    upstream, origins: [office],
    identityToken: async () => { throw new Error('must not request identity'); },
  };
  const preflight = await proxyCloudRunApi(makeRequest(office, {
    method: 'OPTIONS',
    headers: { 'access-control-request-method': 'POST', 'access-control-request-headers': 'authorization,idempotency-key' },
  }), ['pos', 'sales'], config);
  assert.equal(preflight.status, 204);
  assert.equal(preflight.headers.get('access-control-allow-origin'), office);
  assert.equal((await proxyCloudRunApi(makeRequest('https://evil.example'), ['pos', 'sales'], config)).status, 403);
  for (const path of [['webhooks', 'orders'], ['pos', '..'], ['pos', '%2fadmin']]) {
    assert.equal((await proxyCloudRunApi(makeRequest(), path, config)).status, 404);
  }
});
