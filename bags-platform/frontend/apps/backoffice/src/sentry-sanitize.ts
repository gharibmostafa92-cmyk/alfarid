import type * as Sentry from '@sentry/react';

const allowedMethods = new Set(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD']);

function safeViewPath(): string {
  if (typeof window === 'undefined') return '/';
  const path = window.location.pathname;
  if (['/', '/pos', '/login', '/auth/callback', '/auth/session', '/auth/session-probe.html'].includes(path)) {
    return path;
  }
  return '/backoffice';
}

function safeTraceContext(value: unknown): Record<string, string> | undefined {
  if (!value || typeof value !== 'object') return undefined;
  const source = value as Record<string, unknown>;
  const result: Record<string, string> = {};
  for (const [key, length] of [['trace_id', 32], ['span_id', 16], ['parent_span_id', 16]] as const) {
    const id = source[key];
    if (typeof id === 'string' && id.length === length && /^[a-f0-9]+$/i.test(id)) result[key] = id.toLowerCase();
  }
  return Object.keys(result).length ? result : undefined;
}

function safeFramePath(value: unknown): unknown {
  if (typeof value !== 'string') return value;
  try {
    const parsed = new URL(value);
    return `${parsed.origin}${parsed.pathname}`;
  } catch {
    return value.split(/[?#]/, 1)[0].slice(-240);
  }
}

export function sanitizeBackofficeEvent<T extends Sentry.Event>(event: T): T {
  const mutable = event as unknown as Record<string, any>;
  const route = safeViewPath();
  const trace = safeTraceContext(mutable.contexts?.trace);

  if (mutable.type !== 'transaction') mutable.message = 'Application error';
  mutable.transaction = route === '/' ? 'backoffice.view' : route;
  mutable.tags = { service: 'backoffice-browser' };
  mutable.user = undefined;
  mutable.extra = undefined;
  mutable.fingerprint = undefined;
  mutable.breadcrumbs = [];
  mutable.request = {
    ...(allowedMethods.has(String(mutable.request?.method ?? '').toUpperCase())
      ? { method: String(mutable.request.method).toUpperCase() }
      : {}),
    url: route,
  };
  mutable.contexts = trace ? { trace } : {};

  if (Array.isArray(mutable.exception?.values)) {
    mutable.exception.values = mutable.exception.values.map((exception: Record<string, any>) => {
      const type = typeof exception.type === 'string' && /^[A-Za-z_$][A-Za-z0-9_$.]{0,120}$/.test(exception.type)
        ? exception.type
        : 'Error';
      const stacktrace = exception.stacktrace;
      if (Array.isArray(stacktrace?.frames)) {
        stacktrace.frames = stacktrace.frames.map((frame: Record<string, any>) => ({
          ...frame,
          filename: safeFramePath(frame.filename),
          abs_path: safeFramePath(frame.abs_path),
          vars: undefined,
          pre_context: undefined,
          context_line: undefined,
          post_context: undefined,
        }));
      }
      return { ...exception, type, value: type, stacktrace };
    });
  }

  if (Array.isArray(mutable.spans)) {
    mutable.spans = mutable.spans.map((span: Record<string, any>) => sanitizeBackofficeSpan(span));
  }

  return event;
}

export function sanitizeBackofficeSpan(span: Record<string, any>): Record<string, any> {
  const op = typeof span.op === 'string' && /^[a-z][a-z0-9_.-]{0,63}$/.test(span.op) ? span.op : 'span';
  const name = span.name === 'pos.sale' ? 'pos.sale' : op;
  return {
    trace_id: span.trace_id,
    span_id: span.span_id,
    parent_span_id: span.parent_span_id,
    start_timestamp: span.start_timestamp,
    timestamp: span.timestamp,
    op,
    name,
    description: name,
    status: span.status,
    origin: span.origin,
    attributes: undefined,
    data: undefined,
    tags: undefined,
  };
}
