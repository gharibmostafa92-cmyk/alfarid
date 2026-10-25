import type { Metadata } from 'next';
import { headers } from 'next/headers';
import './globals.css';
import { QueryProvider } from '../providers/query-provider';
import { StorefrontShell } from '@/components/StorefrontShell';
import { StoreBrandingProvider } from '@/providers/store-branding-provider';
import { getServerBranding } from '@/lib/server-branding';
// Each document needs a fresh nonce; the branding fetch remains cached for one minute.
export const revalidate = 60;

export async function generateMetadata(): Promise<Metadata> {
  const branding = await getServerBranding();
  return {
    title: `${branding.name} | Bag Collection`,
    applicationName: branding.name,
    icons: branding.logo ? { icon: branding.logo } : undefined,
    description: `المتجر الإلكتروني لـ ${branding.name} للحقائب والمصنوعات الجلدية`,
    openGraph: { siteName: branding.name, title: `${branding.name} | Bag Collection` },
  };
}

export default async function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  await headers();
  const branding = await getServerBranding();
  return (
    <html lang="en" dir="ltr">
      <head>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="anonymous" />
        <link
          href="https://fonts.googleapis.com/css2?family=Oswald:wght@300;400;500;600;700&family=Poppins:wght@300;400;500;600;700&family=Cairo:wght@400;600;700&display=swap"
          rel="stylesheet"
        />
        <link
          rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
          crossOrigin="anonymous"
        />
      </head>
      <body className="min-h-screen bg-white text-[#111111] antialiased">
        <StoreBrandingProvider initialBranding={branding}><QueryProvider>
          <StorefrontShell>{children}</StorefrontShell>
        </QueryProvider></StoreBrandingProvider>
      </body>
    </html>
  );
}
