import * as Sentry from '@sentry/node';

const SERVICE = 'wa-gateway';
const HEALTH_PATHS = new Set(['/status', '/healthz', '/health']);
const FULL_SHA = /^[a-f0-9]{40}(?:[a-f0-9]{24})?$/i;

export function validGatewayDsnOrigin(raw) {
  const value = String(raw ?? '').trim();
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

function safeRoute() {
  return 'whatsapp.gateway';
}

function requestSpanName() {
  return 'whatsapp.gateway.request';
}

export function sanitizeGatewayEvent(event) {
  if (!event || typeof event !== 'object') return null;
  const exceptionValues = (event.exception?.values ?? []).map(value => ({
    type: typeof value.type === 'string' ? value.type.slice(0, 80) : 'Error',
    value: 'Unhandled gateway error',
    stacktrace: value.stacktrace ? {
      frames: (value.stacktrace.frames ?? []).map(frame => ({
        filename: typeof frame.filename === 'string'
          ? frame.filename.split(/[?#]/, 1)[0].split(/[\\/]/).slice(-3).join('/')
          : undefined,
        function: typeof frame.function === 'string' ? frame.function.slice(0, 120) : undefined,
        lineno: Number.isInteger(frame.lineno) ? frame.lineno : undefined,
        colno: Number.isInteger(frame.colno) ? frame.colno : undefined,
        in_app: typeof frame.in_app === 'boolean' ? frame.in_app : undefined,
      })),
    } : undefined,
  }));
  const trace = event.contexts?.trace;
  return {
    type: event.type ?? 'error',
    event_id: event.event_id,
    timestamp: event.timestamp,
    platform: event.platform,
    level: event.level,
    release: event.release,
    environment: event.environment,
    exception: exceptionValues.length ? { values: exceptionValues } : undefined,
    message: exceptionValues.length ? undefined : 'Unhandled gateway error',
    transaction: safeRoute(),
    tags: { service: SERVICE },
    contexts: trace ? { trace: {
      trace_id: trace.trace_id,
      span_id: trace.span_id,
      parent_span_id: trace.parent_span_id,
      op: trace.op,
      status: trace.status,
    } } : undefined,
  };
}

export function sanitizeGatewaySpan(span) {
  const op = ['http.server', 'http.request'].includes(span?.op) ? span.op : 'app.operation';
  return {
    trace_id: span.trace_id,
    span_id: span.span_id,
    parent_span_id: span.parent_span_id,
    start_timestamp: span.start_timestamp,
    timestamp: span.timestamp,
    op,
    name: requestSpanName(),
    description: requestSpanName(),
    status: span.status,
    origin: span.origin,
    attributes: undefined,
    data: undefined,
  };
}

export function sampleRateFromEnvironment(environment = process.env) {
  const raw = environment.SENTRY_TRACES_SAMPLE_RATE ?? '0.05';
  const rate = Number(raw);
  if (!Number.isFinite(rate) || rate < 0 || rate > 1) throw new Error('SENTRY_TRACES_SAMPLE_RATE must be between 0 and 1');
  return rate;
}

export function registerFastifyTelemetry(app, sdk = Sentry) {
  app.decorateRequest('sentryTelemetryStartedAt', 0);
  app.addHook('onRequest', async request => {
    request.sentryTelemetryStartedAt = Date.now();
  });
  app.addHook('onError', async (request, reply, error) => {
    const explicitStatus = Number(error?.statusCode ?? 0);
    const replyStatus = Number(reply.statusCode ?? 0);
    if ((explicitStatus >= 400 && explicitStatus < 500)
      || (replyStatus >= 400 && replyStatus < 500)
      || HEALTH_PATHS.has(request.routeOptions?.url ?? request.url.split('?', 1)[0])) return;
    try {
      sdk.withScope(scope => {
        scope.setTag('service', SERVICE);
        scope.setTag('route', safeRoute());
        sdk.captureException(error);
      });
    } catch {
      // Telemetry must never change Fastify's application error handling.
    }
  });
  app.addHook('onResponse', async (request, reply) => {
    const route = request.routeOptions?.url ?? request.url.split('?', 1)[0];
    if (HEALTH_PATHS.has(route) || (reply.statusCode >= 400 && reply.statusCode < 500)) return;
    try {
      const span = sdk.startInactiveSpan({
        name: requestSpanName(),
        op: 'http.server',
        startTime: new Date(request.sentryTelemetryStartedAt),
      });
      if (reply.statusCode >= 500) span.setStatus({ code: 2 });
      span.end();
    } catch {
      // An unavailable Sentry transport must not affect the HTTP response.
    }
  });
}

const dsn = process.env.SENTRY_DSN?.trim() || undefined;
const environment = process.env.SENTRY_ENVIRONMENT?.trim() || undefined;
const release = process.env.SENTRY_RELEASE?.trim() || undefined;
const tracesSampleRate = sampleRateFromEnvironment();

if (dsn && !validGatewayDsnOrigin(dsn)) throw new Error('Invalid Sentry DSN for WhatsApp gateway');
if (environment && !['staging', 'production'].includes(environment)) throw new Error('SENTRY_ENVIRONMENT must be staging or production');
if (process.env.NODE_ENV === 'production' && dsn && (!release || !FULL_SHA.test(release) || !environment)) {
  throw new Error('Monitored WhatsApp gateway releases require an environment and full commit SHA');
}

Sentry.init({
  dsn: dsn && validGatewayDsnOrigin(dsn) ? dsn : undefined,
  environment,
  release,
  tracesSampler: () => tracesSampleRate,
  tracePropagationTargets: [],
  maxBreadcrumbs: 0,
  beforeSend: event => sanitizeGatewayEvent(event),
  beforeSendSpan: span => sanitizeGatewaySpan(span),
  beforeBreadcrumb: () => null,
});
