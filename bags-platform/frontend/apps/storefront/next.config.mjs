/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  transpilePackages: ['@alfarid/ui', '@alfarid/api-client', '@alfarid/utils', '@alfarid/config'],
};

export default nextConfig;
