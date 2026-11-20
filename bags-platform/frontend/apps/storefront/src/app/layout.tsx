import type { Metadata, Viewport } from 'next';
import { Cairo, Oswald, Poppins } from 'next/font/google';
import { headers } from 'next/headers';
import './globals.css';
import { QueryProvider } from '../providers/query-provider';
import { StorefrontShell } from '@/components/StorefrontShell';
import { StoreBrandingProvider } from '@/providers/store-branding-provider';
import { StoreLangProvider } from '@/providers/store-lang-provider';
import { NonceProvider } from '@/providers/csp-nonce';
import { getServerBranding, getServerBrandingResult } from '@/lib/core/server-branding';

const oswald = Oswald({ weight: ['300', '400', '500', '600', '700'], subsets: ['latin'], display: 'swap', variable: '--font-oswald' });
const poppins = Poppins({ weight: ['300', '400', '500', '600', '700'], subsets: ['latin'], display: 'swap', variable: '--font-poppins' });
const cairo = Cairo({ weight: ['400', '600', '700'], subsets: ['arabic', 'latin'], display: 'swap', variable: '--font-cairo' });

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  viewportFit: 'cover',
  themeColor: '#ffffff',
};

export async function generateMetadata(): Promise<Metadata> {
  const branding = await getServerBranding();
  return {
    title: `${branding.name} | Bag Collection`,
    applicationName: branding.name,
    icons: { icon: '/favicon-transparent.png' },
    description: `المتجر الإلكتروني لـ ${branding.name} للحقائب والمصنوعات الجلدية`,
    openGraph: { siteName: branding.name, title: `${branding.name} | Bag Collection` },
  };
}

export default async function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  // Reading the per-request nonce keeps document rendering dynamic and lets Next.js
  // stamp the same nonce on every generated script tag.
  const nonce = (await headers()).get('x-nonce') ?? undefined;
  const { branding, fromFallback } = await getServerBrandingResult();
  return (
    <html lang="en" dir="ltr" className={`${oswald.variable} ${poppins.variable} ${cairo.variable}`}>
      <head>
        {nonce ? <meta property="csp-nonce" content={nonce} /> : null}
        <link
          rel="stylesheet"
          href="/vendor/fontawesome/css/all.min.css"
        />
      </head>
      <body className="min-h-screen bg-white text-[#111111] antialiased overflow-x-clip selection:bg-[#159a8c]/15 selection:text-[#111111]">
        <NonceProvider nonce={nonce}>
          <StoreBrandingProvider initialBranding={branding} fromFallback={fromFallback}><StoreLangProvider><QueryProvider>
            <StorefrontShell>{children}</StorefrontShell>
          </QueryProvider></StoreLangProvider></StoreBrandingProvider>
        </NonceProvider>
      </body>
    </html>
  );
}
