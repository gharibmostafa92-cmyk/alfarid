export type BackofficeSentryEnvironment = Record<string, string | undefined> & {
  VITE_SENTRY_DSN?: string;
  VITE_SENTRY_ENVIRONMENT?: string;
  VITE_SENTRY_RELEASE?: string;
  VITE_SENTRY_TRACES_SAMPLE_RATE?: string;
  VITE_API_URL?: string;
  MODE?: string;
  NODE_ENV?: string;
};

export interface BackofficeSentryConfig {
  enabled: boolean;
  dsn?: string;
  environment?: string;
  release?: string;
  tracesSampleRate: number;
  sendDefaultPii: false;
  ingestOrigin?: string;
  tracePropagationTargets: RegExp[];
}

export function sentryIngestOrigin(dsn: string): string {
  let parsed: URL;
  try {
    parsed = new URL(dsn);
  } catch {
    throw new Error('Invalid Sentry DSN');
  }

  if (
    parsed.protocol !== 'https:'
    || parsed.username.length === 0
    || parsed.password.length > 0
    || !/^o\d+\.ingest(?:\.[a-z0-9-]+)?\.sentry\.io$/i.test(parsed.hostname)
    || !/^\/\d+\/?$/.test(parsed.pathname)
    || parsed.search.length > 0
    || parsed.hash.length > 0
  ) {
    throw new Error('Invalid Sentry DSN');
  }

  return parsed.origin;
}

export function backofficeTraceTargets(apiOrigin: string): RegExp[] {
  let origin: string;
  try {
    const parsed = new URL(apiOrigin);
    if (!['http:', 'https:'].includes(parsed.protocol) || parsed.username || parsed.password) return [];
    origin = parsed.origin;
  } catch {
    return [];
  }

  const escapedOrigin = origin.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return [new RegExp(`^${escapedOrigin}/api/v1/(?!webhooks(?:/|$))`)];
}

export function backofficeSentryConfig(environment: BackofficeSentryEnvironment): BackofficeSentryConfig {
  const dsn = environment.VITE_SENTRY_DSN?.trim() || undefined;
  const ingestOrigin = dsn ? sentryIngestOrigin(dsn) : undefined;
  const rawRate = environment.VITE_SENTRY_TRACES_SAMPLE_RATE?.trim();
  const tracesSampleRate = rawRate ? Number(rawRate) : 0.05;
  const release = environment.VITE_SENTRY_RELEASE?.trim() || undefined;
  const sentryEnvironment = environment.VITE_SENTRY_ENVIRONMENT?.trim() || undefined;
  const mode = environment.MODE ?? environment.NODE_ENV ?? 'development';

  if (!Number.isFinite(tracesSampleRate) || tracesSampleRate < 0 || tracesSampleRate > 1) {
    throw new Error('Invalid Sentry trace sample rate');
  }
  if (release && !/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/i.test(release)) {
    throw new Error('Sentry release must be a full commit SHA');
  }
  if (dsn && mode === 'production' && (!release || !['staging', 'production'].includes(sentryEnvironment ?? ''))) {
    throw new Error('Sentry release builds require an environment and full commit SHA');
  }

  return {
    enabled: Boolean(dsn),
    dsn,
    environment: sentryEnvironment,
    release,
    tracesSampleRate,
    sendDefaultPii: false,
    ingestOrigin,
    tracePropagationTargets: backofficeTraceTargets(environment.VITE_API_URL ?? 'http://localhost:8000'),
  };
}
