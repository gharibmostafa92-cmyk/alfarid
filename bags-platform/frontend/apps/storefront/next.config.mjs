import { resolve } from 'node:path';
import { withSentryConfig } from '@sentry/nextjs/config';

// After `php artisan uploads:migrate-to-r2`, public catalog media lives on
// R2_PUBLIC_URL (r2_public disk). Prefer it; fall back to the backend /storage
// origin for local dev where R2_PUBLIC_URL is unset.
const r2Public = process.env.R2_PUBLIC_URL || process.env.NEXT_PUBLIC_R2_PUBLIC_URL || '';
let mediaDestination = null;
let r2Hostname = null;
try {
  if (r2Public) {
    const u = new URL(r2Public);
    const basePath = u.pathname.replace(/\/+$/, '');
    if (!u.hostname.toLowerCase().endsWith('.r2.cloudflarestorage.com')) {
      mediaDestination = `${u.origin}${basePath}/:path*`;
    }
    r2Hostname = u.hostname;
  }
} catch {
  mediaDestination = null;
}
function configuredOrigin(raw, fallback) {
  const value = (raw || '').trim();
  try {
    return new URL(value || fallback, fallback);
  } catch {
    return new URL(fallback);
  }
}
const mediaApi = configuredOrigin(process.env.API_INTERNAL_URL || process.env.NEXT_PUBLIC_API_URL, 'http://127.0.0.1:8000');
if (mediaApi.hostname === 'localhost') mediaApi.hostname = '127.0.0.1';
if (!mediaDestination) mediaDestination = `${mediaApi.origin}/storage/:path*`;

/** @type {import('next').NextConfig} */
const nextConfig = {
  ...(process.env.STOREFRONT_SEO_TEST === '1' ? { distDir: '.next-seo-test' } : {}),
  ...(process.env.STOREFRONT_STANDALONE === '1' ? {
    output: 'standalone',
    outputFileTracingRoot: resolve(import.meta.dirname, '../..'),
  } : {}),
  reactStrictMode: true,
  env: { NEXT_PUBLIC_R2_PUBLIC_URL: r2Public },
  devIndicators: false,
  transpilePackages: ['@alfarid/ui', '@alfarid/api-client', '@alfarid/utils', '@alfarid/config'],
  async redirects() {
    return [{ source: '/reset-password', destination: '/auth/reset-password', permanent: false }];
  },
  async rewrites() {
    // Only the configured media origin is exposed; no arbitrary URL proxy.
    return [{ source: '/store-media/:path*', destination: mediaDestination }];
  },
  images: {
    remotePatterns: [
      {
        protocol: 'https',
        hostname: 'images.unsplash.com',
      },
      ...(r2Hostname ? [{ protocol: 'https', hostname: r2Hostname }] : []),
    ],
  },
};

function validatedSentryDsn(value) {
  const raw = (value || '').trim();
  if (!raw) return undefined;
  let url;
  try { url = new URL(raw); } catch { throw new Error('Invalid Storefront Sentry DSN'); }
  if (url.protocol !== 'https:' || !url.username || url.password || url.search || url.hash
    || !/^(?:o\d+\.ingest(?:\.[a-z0-9-]+)?\.sentry\.io|[a-z0-9-]+\.ingest(?:\.[a-z0-9-]+)?\.sentry\.io)$/i.test(url.hostname)
    || !/^\/[0-9]+$/.test(url.pathname)) {
    throw new Error('Invalid Storefront Sentry DSN');
  }
  return raw;
}

const sentryDsn = validatedSentryDsn(process.env.NEXT_PUBLIC_SENTRY_DSN || process.env.SENTRY_DSN);
const release = (process.env.NEXT_PUBLIC_SENTRY_RELEASE || process.env.SENTRY_RELEASE || '').trim();
const environment = (process.env.NEXT_PUBLIC_SENTRY_ENVIRONMENT || process.env.SENTRY_ENVIRONMENT || '').trim();
const sampleRate = (process.env.NEXT_PUBLIC_SENTRY_TRACES_SAMPLE_RATE || process.env.SENTRY_TRACES_SAMPLE_RATE || '0.05').trim();
if (!Number.isFinite(Number(sampleRate)) || Number(sampleRate) < 0 || Number(sampleRate) > 1) {
  throw new Error('Storefront Sentry trace sample rate must be between 0 and 1');
}
const sentryAuthToken = process.env.SENTRY_AUTH_TOKEN;
const sentryOrg = process.env.SENTRY_ORG;
const sentryProject = process.env.SENTRY_PROJECT;
const hasPartialUploadConfig = Boolean(sentryAuthToken || sentryOrg || sentryProject)
  && !(sentryAuthToken && sentryOrg && sentryProject);
if (hasPartialUploadConfig) throw new Error('Sentry source map upload requires SENTRY_AUTH_TOKEN, SENTRY_ORG, and SENTRY_PROJECT');
const uploadSourcemaps = Boolean(sentryDsn && sentryAuthToken && sentryOrg && sentryProject);
if (sentryAuthToken && sentryOrg && sentryProject && !sentryDsn) {
  throw new Error('Sentry source map upload requires a configured Storefront DSN');
}
if (uploadSourcemaps && !/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/i.test(release)) {
  throw new Error('Sentry source map upload requires a full commit SHA release');
}
if (process.env.NODE_ENV === 'production' && sentryDsn) {
  if (!uploadSourcemaps) throw new Error('Monitored Storefront release builds require source map upload credentials');
  if (!['staging', 'production'].includes(environment)) throw new Error('Monitored Storefront release builds require staging or production environment');
}

export default uploadSourcemaps
  ? withSentryConfig({ ...nextConfig, productionBrowserSourceMaps: true }, {
    org: sentryOrg,
    project: sentryProject,
    authToken: sentryAuthToken,
    release: { name: release, create: true, finalize: false, setCommits: false, deploy: false },
    sourcemaps: { deleteSourcemapsAfterUpload: true },
    telemetry: false,
    silent: true,
  })
  : nextConfig;
