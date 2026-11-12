import { isIP } from 'node:net';

const namespaces = new Set(['auth', 'store', 'admin', 'pos', 'wholesale', 'supplier']);
const webhooksNamespace = 'webhooks';
const methods = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];
const requestHeaders = [
  'accept', 'accept-language', 'authorization', 'content-type', 'idempotency-key', 'if-match', 'if-none-match',
  'last-event-id', 'range', 'user-agent', 'x-cart-session', 'x-requested-with',
];
// Provider callbacks authenticate with their own signatures. Forward exactly the
// headers their verifiers need, and never a browser/user credential.
const webhookHeaders = [
  'content-type', 'stripe-signature', 'x-hub-signature-256', 'x-paymob-signature',
  'x-wa-gateway-secret', 'x-wa-signature', 'x-wa-timestamp', 'user-agent',
];
const responseHeaders = [
  'accept-ranges', 'cache-control', 'content-disposition', 'content-range', 'content-type', 'etag',
  'last-modified', 'retry-after', 'x-request-id',
];

type ProxyConfig = {
  upstream: string;
  origins: readonly string[];
  identityToken: (audience: string) => Promise<string>;
  clientIpSecret?: string;
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

/**
 * The platform appends the TCP peer to X-Forwarded-For. Earlier entries can
 * be supplied by the client, so the signed address is the last valid one.
 */
function clientAddress(request: Request): string | null {
  const forwarded = (request.headers.get('x-forwarded-for') ?? '')
    .split(',')
    .map(part => part.trim())
    .filter(part => isIP(part) !== 0);
  if (forwarded.length > 0) return forwarded[forwarded.length - 1];
  const real = request.headers.get('x-real-ip')?.trim();
  if (real && isIP(real) !== 0) return real;

  return null;
}

async function signClientAddress(secret: string, address: string): Promise<string> {
  const encoder = new TextEncoder();
  const key = await crypto.subtle.importKey(
    'raw', encoder.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'],
  );
  const signature = await crypto.subtle.sign('HMAC', key, encoder.encode(address));

  return Array.from(new Uint8Array(signature), byte => byte.toString(16).padStart(2, '0')).join('');
}

function pathIsSafe(path: string[]): boolean {
  return path.length >= 2
    && (namespaces.has(path[0]) || path[0] === webhooksNamespace)
    && path.every(segment => segment !== '' && segment !== '.' && segment !== '..' && !/[/\\%\u0000-\u001f\u007f]/u.test(segment));
}

export async function proxyCloudRunApi(request: Request, path: string[], config: ProxyConfig) {
  const isWebhook = path[0] === webhooksNamespace;
  const headers = new Headers({
    'cache-control': 'no-store',
    'content-security-policy': "default-src 'none'; sandbox",
    'x-content-type-options': 'nosniff',
    vary: 'Origin',
  });
  const fail = (status: number, message: string) => Response.json({ message }, { status, headers });
  const origin = request.headers.get('origin');
  if (pathIsSafe(path) && !isWebhook) {
    if (origin && !config.origins.includes(origin)) return fail(403, 'Origin is not permitted');
    if (origin) headers.set('access-control-allow-origin', origin);
  }
  if (!pathIsSafe(path)) {
    return fail(404, 'API route is not available');
  }
  if (isWebhook && request.method !== 'POST') return fail(405, 'Method is not permitted');

  if (!isWebhook && request.method === 'OPTIONS') {
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
    for (const name of isWebhook ? webhookHeaders : requestHeaders) {
      const value = request.headers.get(name);
      if (value !== null) forwarded.set(name, value);
    }
    const address = clientAddress(request);
    if (address !== null && config.clientIpSecret) {
      forwarded.set('x-alfarid-client-ip', address);
      forwarded.set('x-alfarid-client-ip-signature', await signClientAddress(config.clientIpSecret, address));
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
    // The backend already marks authenticated responses private, but enforce it
    // here too so a misconfigured upstream can never cache a user's response.
    if (!isWebhook && request.headers.has('authorization')) headers.set('cache-control', 'no-store');
    if (!isWebhook) headers.set('access-control-expose-headers', responseHeaders.join(', '));
    return new Response(response.body, { status: response.status, headers });
  } catch {
    return fail(502, 'API is temporarily unavailable');
  }
}
