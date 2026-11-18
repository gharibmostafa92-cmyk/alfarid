import { isIP } from 'node:net';
import { proxyTraceContext, traceableApiPath, type ProxyTraceContext } from './sentry-telemetry.ts';

const DEFAULT_ALLOWED_NAMESPACES = ['auth', 'store', 'wholesale', 'supplier'] as const;
const webhooksNamespace = 'webhooks';
const methods = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];
const requestHeaders = [
  'accept', 'accept-language', 'authorization', 'cache-control', 'content-length', 'content-type',
  'idempotency-key', 'if-match', 'if-modified-since', 'if-none-match', 'last-event-id',
  'pragma', 'range', 'user-agent', 'x-cart-session', 'x-requested-with',
];
const traceHeaders = ['sentry-trace', 'baggage'];
// Provider callbacks authenticate with their own signatures. Forward exactly the
// headers their verifiers need, and never a browser/user credential.
const webhookHeaders = [
  'content-length', 'content-type', 'stripe-signature', 'x-hub-signature-256', 'x-paymob-signature',
  'x-wa-gateway-secret', 'x-wa-signature', 'x-wa-timestamp', 'user-agent',
];
const responseHeaders = [
  'accept-ranges', 'cache-control', 'content-disposition', 'content-range', 'content-type', 'etag',
  'last-modified', 'retry-after', 'x-request-id',
];

type ProxyConfig = {
  upstream: string;
  aiUpstream?: string;
  origins: readonly string[];
  identityToken: (audience: string) => Promise<string>;
  clientIpSecret?: string;
  trustedProxyHops?: number;
  allowedNamespaces?: readonly string[];
  withTraceContext?: (
    context: ProxyTraceContext | null,
    send: (traceHeaders: Headers) => Promise<Response>,
  ) => Promise<Response>;
  send?: typeof fetch;
};

function resolveAllowedNamespaces(config: ProxyConfig): Set<string> {
  if (config.allowedNamespaces !== undefined) return new Set(config.allowedNamespaces);
  const raw = process.env.API_PROXY_ALLOWED_NAMESPACES;
  if (raw === undefined || raw.trim() === '') return new Set(DEFAULT_ALLOWED_NAMESPACES);
  const parsed = raw.split(',').map((item) => item.trim()).filter(Boolean);
  return new Set(parsed.length > 0 ? parsed : DEFAULT_ALLOWED_NAMESPACES);
}

const MAX_IDENTITY_CACHE_ENTRIES = 16;
const MAX_IDENTITY_CACHE_AGE_MS = 45 * 60 * 1000;
const IDENTITY_REFRESH_MARGIN_MS = 60 * 1000;
const cachedIdentities = new Map<string, { refreshAt: number; token: Promise<string> }>();

function tokenExpiry(value: string): number {
  const parts = value.split('.');
  if (parts.length !== 3) throw new Error('Invalid Cloud Run identity');

  try {
    const payload = JSON.parse(Buffer.from(parts[1], 'base64url').toString('utf8')) as { exp?: unknown };
    if (typeof payload.exp !== 'number' || !Number.isFinite(payload.exp)) {
      throw new Error('Invalid Cloud Run identity expiry');
    }
    return payload.exp * 1000;
  } catch {
    throw new Error('Invalid Cloud Run identity expiry');
  }
}

function invalidateCloudRunIdentityToken(audience: string): void {
  cachedIdentities.delete(audience);
}

export function cloudRunIdentityToken(audience: string): Promise<string> {
  const cached = cachedIdentities.get(audience);
  if (cached && cached.refreshAt > Date.now()) {
    cachedIdentities.delete(audience);
    cachedIdentities.set(audience, cached);
    return cached.token;
  }
  if (cached) cachedIdentities.delete(audience);

  const url = new URL('http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/identity');
  url.searchParams.set('audience', audience);
  let entry: { refreshAt: number; token: Promise<string> };
  const token = fetch(url, {
    headers: { 'Metadata-Flavor': 'Google' },
    cache: 'no-store',
    redirect: 'error',
    signal: AbortSignal.timeout(3000),
  }).then(async response => {
    if (!response.ok) throw new Error('Cloud Run identity is unavailable');
    const value = (await response.text()).trim();
    if (!/^[\w-]+\.[\w-]+\.[\w-]+$/.test(value)) throw new Error('Invalid Cloud Run identity');
    const expiresAt = tokenExpiry(value);
    const now = Date.now();
    const refreshAt = Math.min(now + MAX_IDENTITY_CACHE_AGE_MS, expiresAt - IDENTITY_REFRESH_MARGIN_MS);
    if (refreshAt <= now) throw new Error('Cloud Run identity is expired or near expiry');
    if (cachedIdentities.get(audience) === entry) entry.refreshAt = refreshAt;
    return value;
  }).catch(error => {
    if (cachedIdentities.get(audience) === entry) cachedIdentities.delete(audience);
    throw error;
  });
  entry = { refreshAt: Number.POSITIVE_INFINITY, token };
  cachedIdentities.delete(audience);
  cachedIdentities.set(audience, entry);
  while (cachedIdentities.size > MAX_IDENTITY_CACHE_ENTRIES) {
    const oldestAudience = cachedIdentities.keys().next().value;
    if (oldestAudience === undefined) break;
    cachedIdentities.delete(oldestAudience);
  }
  return token;
}

/** Strips an optional port without mangling bare IPv6 addresses. */
export function stripPort(value: string): string {
  if (value.startsWith('[')) {
    const end = value.indexOf(']');
    if (end <= 0) return value;
    const rest = value.slice(end + 1);
    if (rest !== '' && !/^:\d{1,5}$/.test(rest)) return value;
    return value.slice(1, end);
  }
  const first = value.indexOf(':');
  if (first !== -1 && first === value.lastIndexOf(':') && /^\d{1,5}$/.test(value.slice(first + 1))) {
    return value.slice(0, first);
  }
  return value;
}

/**
 * The platform appends the TCP peer to X-Forwarded-For. Earlier entries can
 * be supplied by the client. Count back `trustedProxyHops` from the right when
 * the chain is long enough. A shorter chain trusts only the rightmost entry,
 * the nearest proxy's peer, and never a client-supplied left entry. If that
 * entry is not a clean IP we refuse to sign anything rather than sliding to
 * another value. X-Real-IP is ignored unless a proxy is explicitly declared
 * as its source.
 */
export function clientAddress(request: Request, trustedProxyHops = 1): string | null {
  const hops = Number.isInteger(trustedProxyHops) && trustedProxyHops >= 1 ? trustedProxyHops : 1;
  const forwarded = request.headers.get('x-forwarded-for') ?? '';
  const parts = forwarded.split(',').map(part => part.trim()).filter(Boolean);
  if (parts.length > 0) {
    const index = parts.length >= hops ? parts.length - hops : parts.length - 1;
    const candidate = stripPort(parts[index]);
    if (candidate && isIP(candidate) !== 0) return candidate;
    // Refuse to slide into a different XFF entry when the selected hop is invalid.
    return null;
  }
  if (process.env.API_PROXY_TRUST_X_REAL_IP === '1') {
    const real = stripPort(request.headers.get('x-real-ip')?.trim() ?? '');
    if (real && isIP(real) !== 0) return real;
  }

  return null;
}

async function signClientAddress(secret: string, timestamp: string, address: string): Promise<string> {
  const encoder = new TextEncoder();
  const key = await crypto.subtle.importKey(
    'raw', encoder.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'],
  );
  const signature = await crypto.subtle.sign('HMAC', key, encoder.encode(`${timestamp}.${address}`));

  return Array.from(new Uint8Array(signature), byte => byte.toString(16).padStart(2, '0')).join('');
}

const DEFAULT_BODY_BYTES = 1 * 1024 * 1024;
const UPLOAD_BODY_BYTES = 30 * 1024 * 1024;
const UPLOAD_PATH_MARKERS = [
  'account/returns',
  'auth/register',
  'documents',
  'media',
  'attachments',
  'invoice',
  'payment-notices',
  'banners',
  'quick-order/import',
];

export function requestBodyLimit(path: readonly string[], contentType: string | null): number {
  const type = (contentType ?? '').toLowerCase();
  if (type.includes('multipart/form-data')) return UPLOAD_BODY_BYTES;
  const joined = path.join('/');
  if (UPLOAD_PATH_MARKERS.some((marker) => joined.includes(marker))) return UPLOAD_BODY_BYTES;
  return DEFAULT_BODY_BYTES;
}

function mergeVary(upstream: string | null): string {
  const values = new Set<string>(['Origin']);
  for (const part of (upstream ?? '').split(',')) {
    const name = part.trim();
    if (name) values.add(name);
  }
  return [...values].join(', ');
}

class PayloadTooLargeError extends Error {}

function limitRequestBody(body: ReadableStream<Uint8Array>, maxBytes: number): ReadableStream<Uint8Array> {
  const reader = body.getReader();
  let total = 0;

  return new ReadableStream<Uint8Array>({
    async pull(controller) {
      const { done, value } = await reader.read();
      if (done) {
        controller.close();
        return;
      }
      total += value.byteLength;
      if (total > maxBytes) {
        controller.error(new PayloadTooLargeError('Payload too large'));
        void reader.cancel().catch(() => undefined);
        return;
      }
      controller.enqueue(value);
    },
    cancel(reason) {
      return reader.cancel(reason);
    },
  });
}

function pathIsSafe(path: string[], allowed: ReadonlySet<string>): boolean {
  return path.length >= 2
    && path[0] !== 'admin'
    && allowed.has(path[0])
    && path.every(segment => segment !== '' && segment !== '.' && segment !== '..' && !/[/\\%\u0000-\u001f\u007f]/u.test(segment));
}

function isAiTurnRequest(request: Request, path: string[], config: ProxyConfig): boolean {
  return Boolean(config.aiUpstream)
    && request.method === 'POST'
    && path.length === 5
    && path[0] === 'admin'
    && path[1] === 'ai'
    && path[2] === 'conversations'
    && path[3] !== ''
    && (path[4] === 'stream' || path[4] === 'messages');
}

function isAiMessagesReadRequest(request: Request, path: string[], config: ProxyConfig): boolean {
  return Boolean(config.aiUpstream)
    && request.method === 'GET'
    && path.length === 5
    && path[0] === 'admin'
    && path[1] === 'ai'
    && path[2] === 'conversations'
    && path[3] !== ''
    && path[4] === 'messages';
}

function catalogAcceptLanguage(value: string): string {
  const first = value.split(',')[0]?.split(';')[0]?.trim().toLowerCase() ?? '';
  return first.startsWith('ar') ? 'ar' : 'en';
}

export async function proxyCloudRunApi(request: Request, path: string[], config: ProxyConfig) {
  const allowed = resolveAllowedNamespaces(config);
  const isAiTurn = isAiTurnRequest(request, path, config);
  const isAiMessagesRead = isAiMessagesReadRequest(request, path, config);
  const isAllowedAdminAiPath = isAiTurn || isAiMessagesRead;
  const safe = isAllowedAdminAiPath || pathIsSafe(path, allowed);
  const isWebhook = safe && path[0] === webhooksNamespace;
  const headers = new Headers({
    'cache-control': 'no-store',
    'content-security-policy': "default-src 'none'; sandbox",
    'x-content-type-options': 'nosniff',
    vary: 'Origin',
  });
  const fail = (status: number, message: string) => Response.json({ message }, { status, headers });
  const origin = request.headers.get('origin');
  const selfOrigin = new URL(request.url).origin;
  if (safe && !isWebhook) {
    // Same-origin requests are always legitimate; the allow-list is only needed
    // for cross-origin clients such as the backoffice and POS apps.
    if (origin && origin !== selfOrigin && !config.origins.includes(origin)) return fail(403, 'Origin is not permitted');
    const fetchSite = request.headers.get('sec-fetch-site')?.toLowerCase();
    if (fetchSite === 'cross-site'
      && (!origin || (origin !== selfOrigin && !config.origins.includes(origin)))) {
      return fail(403, 'Cross-site request is not permitted');
    }
    if (origin && origin === selfOrigin) headers.set('access-control-allow-origin', selfOrigin);
    else if (origin) headers.set('access-control-allow-origin', origin);
  }
  if (!safe) {
    return fail(404, 'API route is not available');
  }
  if (isAllowedAdminAiPath && !/^Bearer\s+\S+$/i.test(request.headers.get('authorization') ?? '')) {
    return fail(401, 'Authentication is required');
  }
  if (isWebhook && request.method !== 'POST') return fail(405, 'Method is not permitted');

  if (!isWebhook && request.method === 'OPTIONS') {
    const method = request.headers.get('access-control-request-method');
    const requested = (request.headers.get('access-control-request-headers') ?? '')
      .split(',').map(name => name.trim().toLowerCase()).filter(Boolean);
    if (!origin || !method || !methods.includes(method)
      || requested.some(name => !requestHeaders.includes(name) && !traceHeaders.includes(name))) {
      return fail(403, 'Preflight is not permitted');
    }
    headers.set('vary', 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers');
    headers.set('access-control-allow-methods', methods.join(', '));
    headers.set('access-control-allow-headers', [...requestHeaders, ...traceHeaders].join(', '));
    return new Response(null, { status: 204, headers });
  }
  if (!methods.includes(request.method)) return fail(405, 'Method is not permitted');

  const maxPayloadBytes = requestBodyLimit(path, request.headers.get('content-type'));
  const joined = path.join('/');
  if (!isWebhook && maxPayloadBytes === UPLOAD_BODY_BYTES && !joined.includes('auth/register') && !request.headers.has('authorization')) {
    return fail(401, 'Authentication is required');
  }
  const rawContentLength = request.headers.get('content-length');
  if (rawContentLength !== null) {
    const parsedLength = Number.parseInt(rawContentLength, 10);
    if (Number.isFinite(parsedLength) && parsedLength > maxPayloadBytes) {
      return fail(413, 'Payload too large');
    }
  }

  try {
    const target = isAiTurn ? config.aiUpstream! : config.upstream;
    const upstream = new URL(target);
    if (upstream.protocol !== 'https:' || !upstream.hostname.endsWith('.run.app')
      || target !== upstream.origin) return fail(503, 'API is not configured');
    const clientIpSecret = config.clientIpSecret?.trim();
    if (!clientIpSecret) return fail(503, 'API proxy signing secret is not configured');
    upstream.pathname = '/api/v1/' + path.map(encodeURIComponent).join('/');
    upstream.search = new URL(request.url).search;
    const forwarded = new Headers();
    for (const name of isWebhook ? webhookHeaders : requestHeaders) {
      const value = request.headers.get(name);
      if (value !== null) forwarded.set(name, name === 'accept-language' ? catalogAcceptLanguage(value) : value);
    }
    const address = clientAddress(request, config.trustedProxyHops);
    if (address !== null) {
      const timestamp = String(Math.floor(Date.now() / 1000));
      forwarded.set('x-alfarid-client-ip', address);
      forwarded.set('x-alfarid-client-ip-timestamp', timestamp);
      forwarded.set('x-alfarid-client-ip-signature', await signClientAddress(clientIpSecret, timestamp, address));
    }
    forwarded.set('x-serverless-authorization', 'Bearer ' + await config.identityToken(upstream.origin));
    const init: RequestInit & { duplex?: 'half' } = {
      method: request.method,
      headers: forwarded,
      cache: 'no-store',
      redirect: 'manual',
      signal: AbortSignal.any([request.signal, AbortSignal.timeout(isAiTurn ? 180_000 : 60_000)]),
    };
    if (request.body) {
      init.body = limitRequestBody(request.body, maxPayloadBytes);
      init.duplex = 'half';
    }
    const send = config.send ?? fetch;
    const sendWithSafeTrace = (traceHeaders: Headers) => {
      const outgoingHeaders = new Headers(forwarded);
      const safeTrace = traceHeaders.get('sentry-trace');
      const safeBaggage = traceHeaders.get('baggage');
      if (safeTrace && /^([a-f0-9]{32})-([a-f0-9]{16})-[01]$/i.test(safeTrace)) {
        outgoingHeaders.set('sentry-trace', safeTrace);
        if (safeBaggage && safeBaggage.length <= 4096 && !/[\r\n]/.test(safeBaggage)) {
          outgoingHeaders.set('baggage', safeBaggage);
        }
      }
      init.headers = outgoingHeaders;
      return send(upstream, init);
    };
    const response = !isWebhook && traceableApiPath(path) && config.withTraceContext
      ? await config.withTraceContext(proxyTraceContext(request.headers), sendWithSafeTrace)
      : await send(upstream, init);
    if ((response.status === 401 || response.status === 403) && config.identityToken === cloudRunIdentityToken) {
      invalidateCloudRunIdentityToken(upstream.origin);
    }
    if (response.status >= 300 && response.status < 400 && response.status !== 304) {
      await response.body?.cancel();
      return fail(502, 'API redirect is not supported');
    }
    for (const name of responseHeaders) {
      const value = response.headers.get(name);
      if (value !== null) headers.set(name, value);
    }
    headers.set('vary', mergeVary(response.headers.get('vary')));
    // The backend already marks authenticated responses private, but enforce it
    // here too so a misconfigured upstream can never cache a user's response.
    // Vary is merged above and is not overwritten with cache-control.
    if (!isWebhook && request.headers.has('authorization')) headers.set('cache-control', 'no-store');
    if (!isWebhook) headers.set('access-control-expose-headers', responseHeaders.join(', '));
    return new Response(response.body, { status: response.status, headers });
  } catch (error) {
    // Fetch wraps request-body stream errors in a TypeError with a cause.
    if (error instanceof PayloadTooLargeError ||
        (error instanceof Error && error.cause instanceof PayloadTooLargeError)) {
      return fail(413, 'Payload too large');
    }
    if (!request.signal.aborted) {
      console.error('[CloudRunProxy] Upstream error:', error instanceof Error ? error.message : String(error));
    }
    return fail(502, 'API is temporarily unavailable');
  }
}
