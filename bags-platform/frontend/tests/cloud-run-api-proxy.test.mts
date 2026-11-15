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

test('routes assistant turns to a separate worker pool and keeps other API routes on the main pool', async () => {
  const aiUpstream = 'https://private-ai.run.app';
  const audiences: string[] = [];
  const config = {
    upstream, aiUpstream, origins: [office], clientIpSecret,
    identityToken: async (audience: string) => { audiences.push(audience); return 'service-token'; },
    send: async (url: URL | RequestInfo) => new Response(new URL(String(url)).origin),
  };
  for (const [method, path, expected] of [
    ['POST', ['admin', 'ai', 'conversations', '7', 'stream'], aiUpstream],
    ['POST', ['admin', 'ai', 'conversations', '7', 'messages'], aiUpstream],
    ['GET', ['admin', 'ai', 'conversations', '7', 'messages'], upstream],
    ['POST', ['pos', 'sales'], upstream],
  ] as const) {
    const response = await proxyCloudRunApi(makeRequest(office, { method }), [...path], config);
    assert.equal(await response.text(), expected);
  }
  assert.deepEqual(audiences, [aiUpstream, aiUpstream, upstream, upstream]);
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
      assert.match(headers.get('x-alfarid-client-ip-timestamp') ?? '', /^\d{10,}$/);
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
    upstream, origins: [office], clientIpSecret,
    identityToken: async () => 'service-token',
    send: async () => new Response('catalog', { status: 200, headers: { 'cache-control': 'public, s-maxage=60' } }),
  });
  assert.equal(publicResponse.headers.get('cache-control'), 'public, s-maxage=60');

  const authenticatedResponse = await proxyCloudRunApi(new Request(storefront + '/api/v1/store/catalog', {
    headers: { authorization: 'Bearer user-token' },
  }), ['store', 'catalog'], {
    upstream, origins: [office], clientIpSecret,
    identityToken: async () => 'service-token',
    send: async () => new Response('catalog', { status: 200, headers: { 'cache-control': 'public, s-maxage=60' } }),
  });
  assert.equal(authenticatedResponse.headers.get('cache-control'), 'no-store');
});

test('fails closed with 503 when clientIpSecret is missing or blank', async () => {
  let called = false;
  for (const secret of [undefined, '', '   ']) {
    const response = await proxyCloudRunApi(new Request(storefront + '/api/v1/store/catalog'), ['store', 'catalog'], {
      upstream, origins: [office], clientIpSecret: secret,
      identityToken: async () => { called = true; return 'service-token'; },
      send: async () => { called = true; return new Response('ok'); },
    });
    assert.equal(response.status, 503);
    assert.equal(called, false);
  }
});

test('extracts client IP using trustedProxyHops without sliding back on malformed hops', async () => {
  let capturedHeaders: Headers | undefined;
  const response = await proxyCloudRunApi(new Request(storefront + '/api/v1/store/catalog', {
    headers: { 'x-forwarded-for': '203.0.113.9, 198.51.100.44, 10.0.0.1' },
  }), ['store', 'catalog'], {
    upstream, origins: [office], clientIpSecret, trustedProxyHops: 2,
    identityToken: async () => 'service-token',
    send: async (_url, init) => {
      capturedHeaders = new Headers(init?.headers);
      return new Response('ok', { status: 200 });
    },
  });
  assert.equal(response.status, 200);
  assert.equal(capturedHeaders?.get('x-alfarid-client-ip'), '198.51.100.44');
  assert.match(capturedHeaders?.get('x-alfarid-client-ip-timestamp') ?? '', /^\d{10,}$/);
  assert.match(capturedHeaders?.get('x-alfarid-client-ip-signature') ?? '', /^[0-9a-f]{64}$/);

  let invalidHopHeaders: Headers | undefined;
  await proxyCloudRunApi(new Request(storefront + '/api/v1/store/catalog', {
    headers: { 'x-forwarded-for': '198.51.100.44, not-an-ip, 10.0.0.1' },
  }), ['store', 'catalog'], {
    upstream, origins: [office], clientIpSecret, trustedProxyHops: 2,
    identityToken: async () => 'service-token',
    send: async (_url, init) => {
      invalidHopHeaders = new Headers(init?.headers);
      return new Response('ok', { status: 200 });
    },
  });
  assert.equal(invalidHopHeaders?.get('x-alfarid-client-ip'), null);
});

test('allows standard caching and content headers in CORS preflight', async () => {
  const config = {
    upstream, origins: [office], clientIpSecret,
    identityToken: async () => 'service-token',
  };
  const preflight = await proxyCloudRunApi(makeRequest(office, {
    method: 'OPTIONS',
    headers: {
      'access-control-request-method': 'POST',
      'access-control-request-headers': 'authorization,cache-control,pragma,if-modified-since,content-length',
    },
  }), ['pos', 'sales'], config);
  assert.equal(preflight.status, 204);
  assert.equal(preflight.headers.get('access-control-allow-origin'), office);
});

test('rejects requests with content-length exceeding 30 MiB with 413', async () => {
  const config = {
    upstream, origins: [office], clientIpSecret,
    identityToken: async () => 'service-token',
  };
  const response = await proxyCloudRunApi(makeRequest(office, {
    method: 'POST',
    body: 'payload',
    headers: {
      'content-length': String(31 * 1024 * 1024),
    },
  }), ['pos', 'sales'], config);
  assert.equal(response.status, 413);
});

test('rejects a chunked body that crosses the byte limit even when fetch wraps the stream error', async () => {
  const chunk = new Uint8Array(1024 * 1024);
  const body = new ReadableStream({ pull(controller) { controller.enqueue(chunk); } });
  const request = new Request(storefront + '/api/v1/pos/sales', {
    method: 'POST', body, duplex: 'half',
  } as RequestInit & { duplex: 'half' });

  const response = await proxyCloudRunApi(request, ['pos', 'sales'], {
    upstream, origins: [office], clientIpSecret,
    identityToken: async () => 'service-token',
    send: async (_url, init) => {
      const reader = (init?.body as ReadableStream<Uint8Array>).getReader();
      try {
        while (!(await reader.read()).done) {
          // Drain the request as a streaming fetch would.
        }
      } catch (cause) {
        throw new TypeError('fetch failed', { cause });
      }
      return new Response('unexpected upstream success');
    },
  });
  assert.equal(response.status, 413);
});

test('uses 180s upstream timeout for AI conversation turns and 60s for regular API calls', async () => {
  const timeouts: number[] = [];
  const originalTimeout = AbortSignal.timeout;
  AbortSignal.timeout = ((ms: number) => {
    timeouts.push(ms);
    return originalTimeout(ms);
  }) as typeof AbortSignal.timeout;
  try {
    const config = {
      upstream,
      aiUpstream: 'https://private-ai.run.app',
      origins: [office],
      clientIpSecret,
      identityToken: async () => 'service-token',
      send: async () => new Response('ok', { status: 200 }),
    };
    await proxyCloudRunApi(makeRequest(office, { method: 'POST' }), ['admin', 'ai', 'conversations', '7', 'stream'], config);
    await proxyCloudRunApi(makeRequest(office, { method: 'POST' }), ['pos', 'sales'], config);
    assert.deepEqual(timeouts, [180_000, 60_000]);
  } finally {
    AbortSignal.timeout = originalTimeout;
  }
});
