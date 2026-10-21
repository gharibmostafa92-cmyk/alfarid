import type { Metadata } from 'next';
import './globals.css';
import { QueryProvider } from '../providers/query-provider';
import { StorefrontShell } from '@/components/StorefrontShell';

export const metadata: Metadata = {
  title: 'منصة الفريد للحقائب | متجر التجزئة والجملة',
  description: 'المتجر الإلكتروني وبوابة الجملة للحقائب والمصنوعات الجلدية',
};

export default function RootLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <html lang="ar" dir="rtl">
      <head>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="anonymous" />
        <link
          href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap"
          rel="stylesheet"
        />
      </head>
      <body className="min-h-screen bg-slate-50 text-slate-900 antialiased font-sans">
        <QueryProvider>
          <StorefrontShell>{children}</StorefrontShell>
        </QueryProvider>
      </body>
    </html>
  );
}
