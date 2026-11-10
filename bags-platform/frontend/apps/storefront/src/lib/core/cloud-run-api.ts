const namespaces = new Set(['auth', 'store', 'admin', 'pos', 'wholesale', 'supplier']);
const methods = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];
const requestHeaders = [
  'accept', 'authorization', 'content-type', 'idempotency-key', 'if-match', 'if-none-match',
  'last-event-id', 'range', 'x-cart-session', 'x-requested-with',
];
const responseHeaders = [
  'accept-ranges', 'content-disposition', 'content-range', 'content-type', 'etag',
  'last-modified', 'retry-after', 'x-request-id',
];

type ProxyConfig = {
  upstream: string;
  origins: readonly string[];
  identityToken: (audience: string) => Promise<string>;
  send?: typeof fetch;
};

let cachedIdentity: { audience: string; refreshAt: number; token: Promise<string> } | undefined;

export function cloudRunIdentityToken(audience: string): Promise<string> {
  if (cachedIdentity?.audience === audience && cachedIdentity.refreshAt > Date.now()) {
    return cachedIdentity.token;
  }

  const url = new URL('http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/identity');
  url.searchParams.set('audience', audience);
  const token = fetch(url, {
    headers: { 'Metadata-Flavor': 'Google' },
    cache: 'no-store',
    redirect: 'error',
    signal: AbortSignal.timeout(3000),
  }).then(async response => {
    if (!response.ok) throw new Error('Cloud Run identity is unavailable');
    const value = (await response.text()).trim();
    if (!/^[\w-]+\.[\w-]+\.[\w-]+$/.test(value)) throw new Error('Invalid Cloud Run identity');
    return value;
  }).catch(error => {
    if (cachedIdentity?.token === token) cachedIdentity = undefined;
    throw error;
  });
  cachedIdentity = { audience, refreshAt: Date.now() + 45 * 60 * 1000, token };
  return token;
}

export async function proxyCloudRunApi(request: Request, path: string[], config: ProxyConfig) {
  const headers = new Headers({
    'cache-control': 'no-store',
    'content-security-policy': "default-src 'none'; sandbox",
    'x-content-type-options': 'nosniff',
    vary: 'Origin',
  });
  const fail = (status: number, message: string) => Response.json({ message }, { status, headers });
  const origin = request.headers.get('origin');
  if (origin && !config.origins.includes(origin)) return fail(403, 'Origin is not permitted');
  if (origin) headers.set('access-control-allow-origin', origin);
  if (path.length < 2 || !namespaces.has(path[0])
    || path.some(segment => !segment || segment === '.' || segment === '..' || /[/\\%\u0000-\u001f\u007f]/u.test(segment))) {
    return fail(404, 'API route is not available');
  }

  if (request.method === 'OPTIONS') {
    const method = request.headers.get('access-control-request-method');
    const requested = (request.headers.get('access-control-request-headers') ?? '')
      .split(',').map(name => name.trim().toLowerCase()).filter(Boolean);
    if (!origin || !method || !methods.includes(method) || requested.some(name => !requestHeaders.includes(name))) {
      return fail(403, 'Preflight is not permitted');
    }
    headers.set('vary', 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers');
    headers.set('access-control-allow-methods', methods.join(', '));
    headers.set('access-control-allow-headers', requestHeaders.join(', '));
    return new Response(null, { status: 204, headers });
  }
  if (!methods.includes(request.method)) return fail(405, 'Method is not permitted');

  try {
    const upstream = new URL(config.upstream);
    if (upstream.protocol !== 'https:' || !upstream.hostname.endsWith('.run.app')
      || config.upstream !== upstream.origin) return fail(503, 'API is not configured');
    upstream.pathname = '/api/v1/' + path.map(encodeURIComponent).join('/');
    upstream.search = new URL(request.url).search;
    const forwarded = new Headers();
    for (const name of requestHeaders) {
      const value = request.headers.get(name);
      if (value !== null) forwarded.set(name, value);
    }
    forwarded.set('x-serverless-authorization', 'Bearer ' + await config.identityToken(upstream.origin));
    const init: RequestInit & { duplex?: 'half' } = {
      method: request.method,
      headers: forwarded,
      cache: 'no-store',
      redirect: 'manual',
      signal: AbortSignal.any([request.signal, AbortSignal.timeout(60000)]),
    };
    if (request.body) {
      init.body = request.body;
      init.duplex = 'half';
    }
    const response = await (config.send ?? fetch)(upstream, init);
    if (response.status >= 300 && response.status < 400 && response.status !== 304) {
      await response.body?.cancel();
      return fail(502, 'API redirect is not supported');
    }
    for (const name of responseHeaders) {
      const value = response.headers.get(name);
      if (value !== null) headers.set(name, value);
    }
    headers.set('access-control-expose-headers', responseHeaders.join(', '));
    return new Response(response.body, { status: response.status, headers });
  } catch {
    return fail(502, 'API is temporarily unavailable');
  }
}
