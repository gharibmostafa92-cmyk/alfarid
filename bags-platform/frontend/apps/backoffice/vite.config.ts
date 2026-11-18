import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';
import { sentryVitePlugin } from '@sentry/bundler-plugins/vite';
import { backofficeSentryConfig } from './sentry-config';

// https://vitejs.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_');
  const sentryConfig = backofficeSentryConfig({ ...env, MODE: mode });
  const connectOrigins = new Set(["'self'"]);
  const imageOrigins = new Set(["'self'", 'data:', 'blob:']);
  if (sentryConfig.ingestOrigin) connectOrigins.add(sentryConfig.ingestOrigin);
  const apiUrl = env.VITE_API_URL || 'http://localhost:8000';
  for (const value of [apiUrl, ...(env.VITE_CSP_CONNECT_ORIGINS || '').split(',')]) {
    if (value.trim()) {
      const url = new URL(value.trim());
      if (!['https:', 'http:', 'wss:', 'ws:'].includes(url.protocol)) throw new Error('Invalid CSP connection origin');
      connectOrigins.add(url.origin);
      if (value === apiUrl && ['https:', 'http:'].includes(url.protocol)) imageOrigins.add(url.origin);
    }
  }
  for (const value of (env.VITE_CSP_IMG_ORIGINS || '').split(',').map((entry) => entry.trim()).filter(Boolean)) {
    const url = new URL(value);
    if (!['https:', 'http:'].includes(url.protocol)) throw new Error('Invalid CSP image origin');
    imageOrigins.add(url.origin);
  }
  const webOrigin = new URL(env.VITE_WEB_URL || 'http://localhost:3000').origin;
  if (!/^https?:\/\//.test(webOrigin)) throw new Error('Invalid web origin');
  const ancestors = (url: string) => ['/auth/session', '/auth/session-probe.html'].includes(new URL(url, 'http://localhost').pathname)
    ? `frame-ancestors 'self' ${webOrigin}` : "frame-ancestors 'none'";
  const policy = [
    "default-src 'self'", "script-src 'self'", "object-src 'none'", "base-uri 'self'", "form-action 'self'",
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com",
    "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com",
    `img-src ${[...imageOrigins].join(' ')}`,
    `connect-src ${[...connectOrigins].join(' ')}`,
  ].join('; ');
  const sentryAuthToken = process.env.SENTRY_AUTH_TOKEN;
  const sentryOrg = process.env.SENTRY_ORG;
  const sentryProject = process.env.SENTRY_PROJECT;
  const hasPartialUploadConfig = Boolean(sentryAuthToken || sentryOrg || sentryProject)
    && !(sentryAuthToken && sentryOrg && sentryProject);
  if (hasPartialUploadConfig) throw new Error('Sentry source map upload requires SENTRY_AUTH_TOKEN, SENTRY_ORG, and SENTRY_PROJECT');
  const uploadSourcemaps = Boolean(sentryAuthToken && sentryOrg && sentryProject);
  if (uploadSourcemaps && !sentryConfig.enabled) throw new Error('Sentry source map upload requires a configured browser DSN');
  if (uploadSourcemaps && !sentryConfig.release) throw new Error('Sentry source map upload requires a full commit SHA release');
  if (sentryConfig.enabled && mode === 'production' && !uploadSourcemaps) {
    throw new Error('Sentry release builds require source map upload credentials');
  }
  return {
  plugins: [react(), {
    name: 'backoffice-security-headers',
    configurePreviewServer(server) {
      server.middlewares.use((request, response, next) => {
        response.setHeader('Content-Security-Policy', `${policy}; ${ancestors(request.url || '/')}`);
        response.setHeader('X-Content-Type-Options', 'nosniff');
        next();
      });
    },
    generateBundle() {
      this.emitFile({ type: 'asset', fileName: '_headers', source: `/*
  Content-Security-Policy: ${policy}
  X-Frame-Options: DENY
  X-Content-Type-Options: nosniff
/auth/session
  ! X-Frame-Options
  Content-Security-Policy: frame-ancestors 'self' ${webOrigin}
/auth/session-probe.html
  ! X-Frame-Options
  Content-Security-Policy: frame-ancestors 'self' ${webOrigin}
` });
      // Include HTTP headers for static hosting; meta CSP cannot enforce frame-ancestors.
      this.emitFile({ type: 'asset', fileName: 'security-headers.nginx-map.conf', source: `map $request_uri $backoffice_frame_ancestors {
    default "'none'";
    "~^/auth/session(?:[?].*)?$" "'self' ${webOrigin}";
    "~^/auth/session-probe[.]html(?:[?].*)?$" "'self' ${webOrigin}";
}
` });
      this.emitFile({ type: 'asset', fileName: 'security-headers.nginx-server.conf', source: `add_header Content-Security-Policy "${policy}; frame-ancestors $backoffice_frame_ancestors" always;
add_header X-Content-Type-Options "nosniff" always;
` });
    },
  }, ...(env.VITE_ENABLE_META_CSP === 'true' ? [{
    name: 'backoffice-meta-csp',
    apply: 'build' as const,
    transformIndexHtml: () => [{ tag: 'meta', attrs: { 'http-equiv': 'Content-Security-Policy', content: policy }, injectTo: 'head-prepend' as const }],
  }] : []), ...(uploadSourcemaps ? sentryVitePlugin({
    org: sentryOrg,
    project: sentryProject,
    authToken: sentryAuthToken,
    release: { name: sentryConfig.release, create: true, finalize: false, setCommits: false, deploy: false },
    sourcemaps: { deleteSourcemapsAfterUpload: true },
  }) : [])],
  build: { sourcemap: uploadSourcemaps ? 'hidden' : false },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    port: 5173,
    host: '0.0.0.0',
  },
  };
});
