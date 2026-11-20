import type { MetadataRoute } from 'next';

export const dynamic = 'force-dynamic';

export default function robots(): MetadataRoute.Robots {
  const configured = process.env.STOREFRONT_PUBLIC_URL;
  return {
    rules: { userAgent: '*', allow: '/' },
    ...(configured ? { sitemap: configured.replace(/\/$/, '') + '/sitemap.xml' } : {}),
  };
}
