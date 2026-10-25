import { NextRequest, NextResponse } from 'next/server';

export function middleware(request: NextRequest) {
  const nonce = btoa(crypto.randomUUID());
  const development = process.env.NODE_ENV !== 'production';
  const api = new URL(process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000');
  // Match the API client's loopback-to-LAN resolution during local previews.
  const hostname = request.nextUrl.hostname;
  if (/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(hostname) && ['localhost', '127.0.0.1'].includes(api.hostname)) api.hostname = hostname;
  const reverbHost = process.env.NEXT_PUBLIC_REVERB_HOST || 'localhost';
  const reverbPort = process.env.NEXT_PUBLIC_REVERB_PORT || '8080';
  const socket = `${process.env.NEXT_PUBLIC_REVERB_SCHEME === 'https' ? 'wss' : 'ws'}://${reverbHost}:${reverbPort}`;
  const csp = [
    "default-src 'self'",
    `script-src 'self' 'nonce-${nonce}' 'strict-dynamic'${development ? " 'unsafe-eval'" : ''}`,
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
    "font-src 'self' https://fonts.gstatic.com",
    "img-src 'self' https: data: blob: http://localhost:8000 http://127.0.0.1:8000" + (api.protocol === 'http:' ? ` ${api.origin}` : ''),
    `connect-src 'self' ${api.origin} ${socket}${development ? ' ws: wss:' : ''}`,
    "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'none'",
  ].join('; ');
  const headers = new Headers(request.headers);
  headers.set('x-nonce', nonce);
  headers.set('Content-Security-Policy', csp);
  const response = NextResponse.next({ request: { headers } });
  response.headers.set('Content-Security-Policy', csp);
  response.headers.set('X-Content-Type-Options', 'nosniff');
  response.headers.set('X-Frame-Options', 'DENY');
  response.headers.set('Referrer-Policy', 'strict-origin-when-cross-origin');
  response.headers.set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
  return response;
}

export const config = {
  matcher: ['/((?!api|_next/static|_next/image|favicon.ico|images/).*)'],
};
