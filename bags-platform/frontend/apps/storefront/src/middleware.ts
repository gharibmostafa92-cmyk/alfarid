import { NextRequest, NextResponse } from 'next/server';

function blockedStoreMediaSegment(segment: string): boolean {
  if (!segment || segment.includes('\\') || /[\u0000-\u001f\u007f]/.test(segment)) return true;
  if (/%(?:2e|2f|5c)/i.test(segment)) return true;
  let decoded: string;
  try {
    decoded = decodeURIComponent(segment);
  } catch {
    return true;
  }
  if (!decoded || decoded === '.' || decoded === '..') return true;
  if (decoded.includes('\\') || decoded.includes('/') || /[\u0000-\u001f\u007f]/.test(decoded)) return true;
  if (/%(?:2e|2f|5c)/i.test(decoded)) return true;
  return false;
}

function resolveEnvUrl(raw: string | undefined, base: string): URL {
  const fallback = base.trim() || 'http://localhost:3000';
  const value = (raw ?? '').trim();
  try {
    return new URL(value || fallback, fallback);
  } catch {
    return new URL(fallback);
  }
}

export function middleware(request: NextRequest) {
  const pathname = request.nextUrl.pathname;
  if (pathname === '/store-media' || pathname.startsWith('/store-media/')) {
    const segments = pathname.slice('/store-media/'.length).split('/');
    for (const segment of segments) {
      if (blockedStoreMediaSegment(segment)) return new NextResponse(null, { status: 404 });
    }
    return NextResponse.next();
  }

  const nonce = btoa(crypto.randomUUID());
  const development = process.env.NODE_ENV !== 'production';
  const base = request.nextUrl.origin || 'http://localhost:3000';
  const api = resolveEnvUrl(process.env.NEXT_PUBLIC_API_URL, base);
  const office = resolveEnvUrl(process.env.NEXT_PUBLIC_BACKOFFICE_URL, base);
  // Match the API client's loopback-to-LAN resolution during local previews.
  const hostname = request.nextUrl.hostname;
  if (/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(hostname) && ['localhost', '127.0.0.1'].includes(api.hostname)) api.hostname = hostname;
  if (/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(hostname) && ['localhost', '127.0.0.1'].includes(office.hostname)) office.hostname = hostname;
  const reverbHost = process.env.NEXT_PUBLIC_REVERB_HOST || 'localhost';
  const reverbPort = process.env.NEXT_PUBLIC_REVERB_PORT || '8080';
  const socket = `${process.env.NEXT_PUBLIC_REVERB_SCHEME === 'https' ? 'wss' : 'ws'}://${reverbHost}:${reverbPort}`;
  const csp = [
    "default-src 'self'",
    `script-src 'self' 'nonce-${nonce}' 'strict-dynamic' https://challenges.cloudflare.com${development ? " 'unsafe-eval'" : ''}`,
    "style-src 'self' 'unsafe-inline'",
    "font-src 'self'",
    "img-src 'self' https: data: blob:" + (development ? ` http://localhost:8000 http://127.0.0.1:8000${api.protocol === 'http:' ? ` ${api.origin}` : ''}` : ''),
    `connect-src 'self' ${api.origin} ${socket}${development ? ' ws: wss:' : ''}`,
    `frame-src 'self' ${office.origin} https://challenges.cloudflare.com`,
    "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'none'",
  ].join('; ');
  const headers = new Headers(request.headers);
  headers.set('x-nonce', nonce);
  headers.set('Content-Security-Policy', csp);
  const response = NextResponse.next({ request: { headers } });
  response.headers.set('Content-Security-Policy', csp);
  // Private documents can be retained by the browser but must be revalidated.
  // Shared caches must never replay HTML with a per-response CSP nonce.
  response.headers.set('Cache-Control', 'private, no-cache, must-revalidate');
  response.headers.set('X-Content-Type-Options', 'nosniff');
  response.headers.set('X-Frame-Options', 'DENY');
  response.headers.set('Referrer-Policy', 'strict-origin-when-cross-origin');
  response.headers.set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
  return response;
}

export const config = {
  matcher: ['/store-media/:path*', '/((?!api|_next/static|_next/image|favicon.ico|images/|store-media|vendor|healthz).*)'],
};
