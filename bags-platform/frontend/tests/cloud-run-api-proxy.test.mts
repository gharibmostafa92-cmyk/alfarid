import assert from 'node:assert/strict';
import test from 'node:test';
import { clientAddress, cloudRunIdentityToken, proxyCloudRunApi } from '../apps/storefront/src/lib/core/cloud-run-api.ts';

const storefront = 'https://storefront.run.app';
const office = 'https://backoffice.pages.dev';
const upstream = 'https://private-api.run.app';
const clientIpSecret = 'proxy-client-ip-secret';
const openedNamespaces = ['auth', 'store', 'admin', 'pos', 'wholesale', 'supplier', 'webhooks'] as const;
const makeRequest = (origin = office, init: RequestInit = {}) =>
  new Request(storefront + '/api/v1/pos/sales?include=items', {
    ...init,
    headers: { ...init.headers, origin },
  });

test('routes assistant turns to a separate worker pool and keeps other API routes on the main pool', async () => {
  const aiUpstream = 'https://private-ai.run.app';
  const audiences: string[] = [];
  const config = {
    upstream, aiUpstream, origins: [office], clientIpSecret, allowedNamespaces: openedNamespaces,
    identityToken: async (audience: string) => { audiences.push(audience); return 'service-token'; },
    send: async (url: URL | RequestInfo) => new Response(new URL(String(url)).origin),
  };
  for (const [method, path, expected] of [
    ['POST', ['admin', 'ai', 'conversations', '7', 'stream'], aiUpstream],
    ['POST', ['admin', 'ai', 'conversations', '7', 'messages'], aiUpstream],
    ['GET', ['admin', 'ai', 'conversations', '7', 'messages'], upstream],
    ['POST', ['pos', 'sales'], upstream],
  ] as const) {
    const response = await proxyCloudRunApi(makeRequest(office, { method, headers: { authorization: 'Bearer staff-token' } }), [...path], config);
    assert.equal(await response.text(), expected);
  }
  assert.deepEqual(audiences, [aiUpstream, aiUpstream, upstream, upstream]);
});

test('keeps the admin namespace closed except for authenticated AI turns and rejects originless cross-site writes', async () => {
  let sent = false;
  const config = {
    upstream, aiUpstream: 'https://private-ai.run.app', origins: [office], clientIpSecret,
    allowedNamespaces: openedNamespaces,
    identityToken: async () => 'service-token',
    send: async () => { sent = true; return new Response('ok'); },
  };
  const admin = await proxyCloudRunApi(makeRequest(office), ['admin', 'users'], config);
  assert.equal(admin.status, 404);
  assert.equal(sent, false);

  const anonymousAi = await proxyCloudRunApi(makeRequest(office, { method: 'POST' }), ['admin', 'ai', 'conversations', '7', 'stream'], config);
  assert.equal(anonymousAi.status, 401);
  assert.equal(sent, false);

  const crossSite = await proxyCloudRunApi(new Request(storefront + '/api/v1/store/checkout', {
    method: 'POST', body: '{}', headers: { 'sec-fetch-site': 'cross-site', 'content-type': 'application/json' },
  }), ['store', 'checkout'], config);
  assert.equal(crossSite.status, 403);
  assert.equal(sent, false);
});

test('refreshes identity tokens before their JWT expiry and bounds the audience cache', async () => {
  const originalFetch = globalThis.fetch;
  const originalNow = Date.now;
  let now = 1_900_000_000_000;
  let fetches = 0;
  Date.now = () => now;
  globalThis.fetch = async () => {
    fetches += 1;
    const exp = Math.floor(now / 1000) + (fetches === 1 ? 600 : 3600);
    const payload = Buffer.from(JSON.stringify({ exp })).toString('base64url');
    return new Response(`header.${payload}.signature`);
  };
  try {
    const prefix = `token-cache-${Math.random()}`;
    await cloudRunIdentityToken(`${prefix}-exp`);
    now += 541_000;
    await cloudRunIdentityToken(`${prefix}-exp`);
    assert.equal(fetches, 2);

    for (let index = 0; index < 17; index += 1) {
      await cloudRunIdentityToken(`${prefix}-${index}`);
    }
    const before = fetches;
    await cloudRunIdentityToken(`${prefix}-0`);
    assert.equal(fetches, before + 1);
  } finally {
    globalThis.fetch = originalFetch;
    Date.now = originalNow;
  }
});

test('evicts a cached Cloud Run identity token when the upstream rejects it', async () => {
  const originalFetch = globalThis.fetch;
  let metadataCalls = 0;
  globalThis.fetch = async () => {
    metadataCalls += 1;
    const payload = Buffer.from(JSON.stringify({ exp: Math.floor(Date.now() / 1000) + 3600 })).toString('base64url');
    return new Response(`header.${payload}.signature`);
  };
  try {
    const audience = `token-rejection-${Math.random()}`;
    const config = {
      upstream, origins: [], clientIpSecret,
      identityToken: cloudRunIdentityToken,
      send: async () => new Response('service rejected identity', { status: 401 }),
    };
    const request = new Request(storefront + '/api/v1/store/catalog');
    await proxyCloudRunApi(request, ['store', 'catalog'], config);
    await proxyCloudRunApi(request, ['store', 'catalog'], config);
    assert.equal(metadataCalls, 2);
  } finally {
    globalThis.fetch = originalFetch;
  }
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
    upstream, origins: [office], clientIpSecret, allowedNamespaces: openedNamespaces,
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
      assert.equal(headers.get('accept-language'), 'ar');
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
    upstream, origins: [office], clientIpSecret, allowedNamespaces: openedNamespaces,
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
    upstream, origins: [office], clientIpSecret, allowedNamespaces: openedNamespaces,
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
    upstream, origins: [office], clientIpSecret, allowedNamespaces: openedNamespaces,
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
    upstream, origins: [office], clientIpSecret, allowedNamespaces: openedNamespaces,
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
    upstream, origins: [office], clientIpSecret, allowedNamespaces: openedNamespaces,
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
    upstream, origins: [office], clientIpSecret, allowedNamespaces: openedNamespaces,
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

test('keeps a shorter forwarded chain and rejects a junk IPv6 port suffix', async () => {
  let shortChain: string | null = 'missing';
  await proxyCloudRunApi(new Request(storefront + '/api/v1/store/catalog', {
    headers: { 'x-forwarded-for': '203.0.113.9' },
  }), ['store', 'catalog'], {
    upstream, origins: [office], clientIpSecret, trustedProxyHops: 2,
    identityToken: async () => 'service-token',
    send: async (_url, init) => {
      shortChain = new Headers(init?.headers).get('x-alfarid-client-ip');
      return new Response('ok');
    },
  });
  assert.equal(shortChain, '203.0.113.9');

  let junk: string | null = 'missing';
  await proxyCloudRunApi(new Request(storefront + '/api/v1/store/catalog', {
    headers: { 'x-forwarded-for': '[::1]junk' },
  }), ['store', 'catalog'], {
    upstream, origins: [office], clientIpSecret,
    identityToken: async () => 'service-token',
    send: async (_url, init) => {
      junk = new Headers(init?.headers).get('x-alfarid-client-ip');
      return new Response('ok');
    },
  });
  assert.equal(junk, null);

  let bracketed: string | null = null;
  await proxyCloudRunApi(new Request(storefront + '/api/v1/store/catalog', {
    headers: { 'x-forwarded-for': '[::1]:443' },
  }), ['store', 'catalog'], {
    upstream, origins: [office], clientIpSecret,
    identityToken: async () => 'service-token',
    send: async (_url, init) => {
      bracketed = new Headers(init?.headers).get('x-alfarid-client-ip');
      return new Response('ok');
    },
  });
  assert.equal(bracketed, '::1');
});

test('rejects json login above 1 MiB and allows upload routes up to 30 MiB', async () => {
  let sent = false;
  const config = {
    upstream, origins: [office], clientIpSecret,
    identityToken: async () => 'service-token',
    send: async () => { sent = true; return new Response('ok'); },
  };
  const login = await proxyCloudRunApi(new Request(storefront + '/api/v1/auth/login', {
    method: 'POST',
    body: '{}',
    headers: { origin: office, 'content-type': 'application/json', 'content-length': String(1024 * 1024 + 1) },
  }), ['auth', 'login'], config);
  assert.equal(login.status, 413);
  assert.equal(sent, false);

  const upload = await proxyCloudRunApi(new Request(storefront + '/api/v1/store/account/returns', {
    method: 'POST',
    body: '{}',
    headers: { origin: office, authorization: 'Bearer user-token', 'content-type': 'application/json', 'content-length': String(2 * 1024 * 1024) },
  }), ['store', 'account', 'returns'], config);
  assert.equal(upload.status, 200);

  const wholesale = await proxyCloudRunApi(new Request(storefront + '/api/v1/wholesale/auth/register', {
    method: 'POST',
    body: '{}',
    headers: { origin: office, 'content-type': 'multipart/form-data; boundary=x', 'content-length': String(2 * 1024 * 1024) },
  }), ['wholesale', 'auth', 'register'], config);
  assert.equal(wholesale.status, 200);

  const huge = await proxyCloudRunApi(new Request(storefront + '/api/v1/store/account/returns', {
    method: 'POST',
    body: '{}',
    headers: { origin: office, authorization: 'Bearer user-token', 'content-type': 'multipart/form-data; boundary=x', 'content-length': String(31 * 1024 * 1024) },
  }), ['store', 'account', 'returns'], config);
  assert.equal(huge.status, 413);
});

test('merges upstream Vary with Origin without dropping Authorization or Accept-Language', async () => {
  const response = await proxyCloudRunApi(new Request(storefront + '/api/v1/store/catalog', {
    headers: { authorization: 'Bearer user-token' },
  }), ['store', 'catalog'], {
    upstream, origins: [office], clientIpSecret,
    identityToken: async () => 'service-token',
    send: async () => new Response('catalog', {
      status: 200,
      headers: { vary: 'Authorization, Accept-Language', 'cache-control': 'public, s-maxage=60' },
    }),
  });
  const vary = response.headers.get('vary') ?? '';
  assert.match(vary, /Origin/);
  assert.match(vary, /Authorization/);
  assert.match(vary, /Accept-Language/);
  assert.equal(response.headers.get('cache-control'), 'no-store');
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
      allowedNamespaces: openedNamespaces,
      identityToken: async () => 'service-token',
      send: async () => new Response('ok', { status: 200 }),
    };
    await proxyCloudRunApi(makeRequest(office, { method: 'POST', headers: { authorization: 'Bearer staff-token' } }), ['admin', 'ai', 'conversations', '7', 'stream'], config);
    await proxyCloudRunApi(makeRequest(office, { method: 'POST' }), ['pos', 'sales'], config);
    assert.deepEqual(timeouts, [180_000, 60_000]);
  } finally {
    AbortSignal.timeout = originalTimeout;
  }
});

test('default namespaces reject admin, pos, and webhooks without calling upstream', async () => {
  const previous = process.env.API_PROXY_ALLOWED_NAMESPACES;
  delete process.env.API_PROXY_ALLOWED_NAMESPACES;
  let sent = false;
  try {
    const config = {
      upstream, origins: [office], clientIpSecret,
      identityToken: async () => 'service-token',
      send: async () => { sent = true; return new Response('ok'); },
    };
    for (const path of [['admin', 'orders'], ['pos', 'sales'], ['webhooks', 'stripe']] as const) {
      const response = await proxyCloudRunApi(new Request(storefront + '/api/v1/' + path.join('/'), {
        method: 'POST',
        body: '{}',
      }), [...path], config);
      assert.equal(response.status, 404);
    }
    assert.equal(sent, false);

    const store = await proxyCloudRunApi(new Request(storefront + '/api/v1/store/catalog'), ['store', 'catalog'], config);
    assert.equal(store.status, 200);
    assert.equal(sent, true);
  } finally {
    if (previous === undefined) delete process.env.API_PROXY_ALLOWED_NAMESPACES;
    else process.env.API_PROXY_ALLOWED_NAMESPACES = previous;
  }
});

test('requires authorization before an upload-sized body unless the path is registration', async () => {
  let sent = false;
  const config = {
    upstream, origins: [office], clientIpSecret,
    identityToken: async () => 'service-token',
    send: async () => { sent = true; return new Response('ok'); },
  };
  const anonymous = await proxyCloudRunApi(new Request(storefront + '/api/v1/store/account/returns', {
    method: 'POST',
    body: '{}',
    headers: { origin: office, 'content-type': 'application/json' },
  }), ['store', 'account', 'returns'], config);
  assert.equal(anonymous.status, 401);
  assert.equal(sent, false);
});

test('trusts the nearest forwarded hop when the chain is shorter than trustedProxyHops', () => {
  const request = (forwarded: string) => new Request(storefront + '/api/v1/store/catalog', {
    headers: { 'x-forwarded-for': forwarded },
  });
  assert.equal(clientAddress(request('192.0.2.9, 10.0.0.1'), 1), '10.0.0.1');
  assert.equal(clientAddress(request('1.2.3.4, 192.0.2.9'), 3), '192.0.2.9');
  assert.equal(clientAddress(request('192.0.2.9, 10.0.0.1'), 2), '192.0.2.9');
});

test('forwards accept-language as en when the first range is not Arabic', async () => {
  const response = await proxyCloudRunApi(new Request(storefront + '/api/v1/store/catalog', {
    headers: { 'accept-language': 'en-US,en;q=0.9' },
  }), ['store', 'catalog'], {
    upstream, origins: [office], clientIpSecret,
    identityToken: async () => 'service-token',
    send: async (_url, init) => {
      assert.equal(new Headers(init?.headers).get('accept-language'), 'en');
      return new Response('ok', { status: 200 });
    },
  });
  assert.equal(response.status, 200);
});
