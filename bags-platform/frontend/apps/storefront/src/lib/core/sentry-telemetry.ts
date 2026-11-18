export type ProxyTraceContext = { traceId: string; parentSpanId: string };

const SAFE_ID = /^[a-f0-9]+$/i;
const ROUTE_NAMESPACES = new Set(['auth', 'store', 'wholesale', 'supplier', 'pos', 'admin']);

export function proxyTraceContext(headers: Headers): ProxyTraceContext | null {
  const raw = headers.get('sentry-trace')?.trim() ?? '';
  const match = /^([a-f0-9]{32})-([a-f0-9]{16})(?:-[01])?$/i.exec(raw);
  if (!match || !SAFE_ID.test(match[1]) || !SAFE_ID.test(match[2])) return null;
  return { traceId: match[1].toLowerCase(), parentSpanId: match[2].toLowerCase() };
}

export function traceableApiPath(path: readonly string[]): boolean {
  return path.length >= 2 && ROUTE_NAMESPACES.has(path[0]) && path[0] !== 'webhooks'
    && !path.some((part) => part.toLowerCase() === 'meta' || part.toLowerCase() === 'metadata')
    && path.every((part) => part !== '' && part !== '.' && part !== '..');
}

export function parseSentryDsnOrigin(raw: string | undefined): string | null {
  const value = (raw ?? '').trim();
  if (!value) return null;
  try {
    const url = new URL(value);
    if (url.protocol !== 'https:' || !url.username || url.password || url.search || url.hash) return null;
    if (!/^(?:o\d+\.ingest(?:\.[a-z0-9-]+)?\.sentry\.io|[a-z0-9-]+\.ingest(?:\.[a-z0-9-]+)?\.sentry\.io)$/i.test(url.hostname)) return null;
    if (!/^\/[0-9]+$/.test(url.pathname)) return null;
    return url.origin;
  } catch {
    return null;
  }
}

export function storefrontTraceTargets(apiOrigins: readonly string[]): RegExp[] {
  const escaped = apiOrigins.flatMap((raw) => {
    try {
      const url = new URL(raw);
      if (url.protocol !== 'https:' || !url.hostname.endsWith('.run.app') || raw !== url.origin) return [];
      return [url.origin.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')];
    } catch {
      return [];
    }
  });
  const pathPattern = '/api/v1/(?!webhooks(?:/|$))(?![a-z0-9_-]+(?:/[a-z0-9_-]+)*/(?:meta|metadata)(?:/|$))[a-z0-9_-]+(?:/[a-z0-9_-]+)*$';
  const targets: RegExp[] = [new RegExp(`^${pathPattern}`, 'i')];
  if (escaped.length > 0) {
    targets.push(new RegExp(`^(?:${escaped.join('|')})${pathPattern}`, 'i'));
  }
  return targets;
}

function safeRouteLabel(value: unknown): string {
  if (typeof value !== 'string') return 'app.unhandled';
  const pathname = value.split(/[?#]/, 1)[0] ?? '';
  if (/^\/api\/v1\//.test(pathname)) return 'api.request';
  if (/^\/checkout(?:\/|$)/.test(pathname)) return 'page.checkout';
  if (/^\/product(?:\/|$)/.test(pathname)) return 'page.product';
  if (/^\/category(?:\/|$)/.test(pathname)) return 'page.category';
  if (pathname === '/' || pathname === '') return 'page.home';
  return 'page.render';
}

type EventFrame = {
  filename?: string;
  function?: string;
  lineno?: number;
  colno?: number;
  in_app?: boolean;
  [key: string]: unknown;
};

export function sanitizeStorefrontEvent(input: Record<string, any> | null): Record<string, any> | null {
  if (!input) return null;
  const exceptionValues = (input.exception?.values ?? []).map((value: Record<string, any>) => ({
    type: typeof value.type === 'string' ? value.type.slice(0, 80) : 'Error',
    value: 'Unhandled application error',
    stacktrace: value.stacktrace ? {
      frames: (value.stacktrace.frames ?? []).map((frame: EventFrame) => ({
        filename: typeof frame.filename === 'string' ? frame.filename.split(/[?#]/, 1)[0].split(/[\\/]/).slice(-3).join('/') : undefined,
        function: typeof frame.function === 'string' ? frame.function.slice(0, 120) : undefined,
        lineno: Number.isInteger(frame.lineno) ? frame.lineno : undefined,
        colno: Number.isInteger(frame.colno) ? frame.colno : undefined,
        in_app: typeof frame.in_app === 'boolean' ? frame.in_app : undefined,
      })),
    } : undefined,
  }));
  const contexts = input.contexts?.trace ? {
    trace: {
      trace_id: input.contexts.trace.trace_id,
      span_id: input.contexts.trace.span_id,
      parent_span_id: input.contexts.trace.parent_span_id,
      op: input.contexts.trace.op,
      status: input.contexts.trace.status,
    },
  } : undefined;
  return {
    type: input.type ?? 'error',
    event_id: input.event_id,
    timestamp: input.timestamp,
    platform: input.platform,
    level: input.level,
    release: input.release,
    environment: input.environment,
    exception: exceptionValues.length ? { values: exceptionValues } : undefined,
    message: exceptionValues.length ? undefined : 'Unhandled application error',
    transaction: safeRouteLabel(input.transaction),
    tags: { service: 'storefront-browser' },
    contexts,
  };
}

export function sanitizeStorefrontTransaction(input: Record<string, any> | null): Record<string, any> | null {
  if (!input) return null;
  const spans = (input.spans ?? []).map((span: Record<string, any>) => ({
    trace_id: span.trace_id,
    span_id: span.span_id,
    parent_span_id: span.parent_span_id,
    start_timestamp: span.start_timestamp,
    timestamp: span.timestamp,
    op: typeof span.op === 'string' ? span.op.slice(0, 80) : undefined,
    description: span.op === 'ui.action' ? 'checkout.submit' : span.op === 'http.client' ? 'bff.upstream' : 'app.operation',
    status: span.status,
    origin: span.origin,
  }));
  return {
    type: 'transaction',
    event_id: input.event_id,
    timestamp: input.timestamp,
    start_timestamp: input.start_timestamp,
    platform: input.platform,
    release: input.release,
    environment: input.environment,
    transaction: safeRouteLabel(input.transaction),
    transaction_info: { source: 'route' },
    contexts: input.contexts?.trace ? { trace: {
      trace_id: input.contexts.trace.trace_id,
      span_id: input.contexts.trace.span_id,
      parent_span_id: input.contexts.trace.parent_span_id,
      op: input.contexts.trace.op,
      status: input.contexts.trace.status,
    } } : undefined,
    tags: { service: 'storefront-server' },
    spans,
  };
}

export function sanitizeStorefrontSpan(input: Record<string, any>): Record<string, any> {
  const op = typeof input.op === 'string' ? input.op.slice(0, 80) : undefined;
  const name = op === 'ui.action' ? 'checkout.submit' : op === 'http.client' ? 'bff.upstream' : 'app.operation';
  return {
    trace_id: input.trace_id,
    span_id: input.span_id,
    parent_span_id: input.parent_span_id,
    start_timestamp: input.start_timestamp,
    timestamp: input.timestamp,
    op,
    name,
    description: name,
    status: input.status,
    origin: input.origin,
    attributes: undefined,
    data: undefined,
  };
}
