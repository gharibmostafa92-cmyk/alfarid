import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

// https://vitejs.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_');
  const connectOrigins = new Set(["'self'"]);
  for (const value of [env.VITE_API_URL || 'http://localhost:8000', ...(env.VITE_CSP_CONNECT_ORIGINS || '').split(',')]) {
    if (value.trim()) {
      const url = new URL(value.trim());
      if (!['https:', 'http:', 'wss:', 'ws:'].includes(url.protocol)) throw new Error('Invalid CSP connection origin');
      connectOrigins.add(url.origin);
    }
  }
  const webOrigin = new URL(env.VITE_WEB_URL || 'http://localhost:3000').origin;
  if (!/^https?:\/\//.test(webOrigin)) throw new Error('Invalid web origin');
  const ancestors = (url: string) => new URL(url, 'http://localhost').pathname === '/auth/session'
    ? `frame-ancestors 'self' ${webOrigin}` : "frame-ancestors 'none'";
  const policy = [
    "default-src 'self'", "script-src 'self'", "object-src 'none'", "base-uri 'self'", "form-action 'self'",
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com",
    "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com",
    `img-src 'self' data: blob: https: ${[...connectOrigins].filter(origin => origin.startsWith('http:')).join(' ')}`,
    `connect-src ${[...connectOrigins].join(' ')}`,
  ].join('; ');
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
      // Include HTTP headers for static hosting; meta CSP cannot enforce frame-ancestors.
      this.emitFile({ type: 'asset', fileName: 'security-headers.nginx-map.conf', source: `map $request_uri $backoffice_frame_ancestors {
    default "'none'";
    "~^/auth/session(?:[?].*)?$" "'self' ${webOrigin}";
}
` });
      this.emitFile({ type: 'asset', fileName: 'security-headers.nginx-server.conf', source: `add_header Content-Security-Policy "${policy}; frame-ancestors $backoffice_frame_ancestors" always;
add_header X-Content-Type-Options "nosniff" always;
` });
    },
  }, {
    name: 'backoffice-production-csp',
    apply: 'build',
    transformIndexHtml: () => [{ tag: 'meta', attrs: { 'http-equiv': 'Content-Security-Policy', content: policy }, injectTo: 'head-prepend' }],
  }],
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
