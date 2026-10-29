// After `php artisan uploads:migrate-to-r2`, public catalog media lives on
// R2_PUBLIC_URL (r2_public disk). Prefer it; fall back to the backend /storage
// origin for local dev where R2_PUBLIC_URL is unset.
const r2Public = process.env.R2_PUBLIC_URL || process.env.NEXT_PUBLIC_R2_PUBLIC_URL || '';
let mediaDestination = null;
let r2Hostname = null;
try {
  if (r2Public) {
    const u = new URL(r2Public);
    mediaDestination = `${u.origin}/:path*`;
    r2Hostname = u.hostname;
  }
} catch {
  mediaDestination = null;
}
const mediaApi = new URL(process.env.API_INTERNAL_URL || process.env.NEXT_PUBLIC_API_URL || 'http://127.0.0.1:8000');
if (mediaApi.hostname === 'localhost') mediaApi.hostname = '127.0.0.1';
if (!mediaDestination) mediaDestination = `${mediaApi.origin}/storage/:path*`;

/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  devIndicators: false,
  transpilePackages: ['@alfarid/ui', '@alfarid/api-client', '@alfarid/utils', '@alfarid/config'],
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

export default nextConfig;
