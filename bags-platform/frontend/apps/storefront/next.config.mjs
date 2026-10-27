const mediaApi = new URL(process.env.API_INTERNAL_URL || process.env.NEXT_PUBLIC_API_URL || 'http://127.0.0.1:8000');
if (mediaApi.hostname === 'localhost') mediaApi.hostname = '127.0.0.1';

/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  devIndicators: false,
  transpilePackages: ['@alfarid/ui', '@alfarid/api-client', '@alfarid/utils', '@alfarid/config'],
  async rewrites() {
    // Only the configured backend's public storage is exposed; no arbitrary URL proxy.
    return [{ source: '/store-media/:path*', destination: `${mediaApi.origin}/storage/:path*` }];
  },
  images: {
    remotePatterns: [
      {
        protocol: 'https',
        hostname: 'images.unsplash.com',
      },
    ],
  },
};

export default nextConfig;
