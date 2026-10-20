import type { Metadata } from 'next';
import './globals.css';
import { QueryProvider } from '../providers/query-provider';

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
          href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800&display=swap"
          rel="stylesheet"
        />
      </head>
      <body className="min-h-screen bg-slate-50 text-slate-900 antialiased font-sans">
        <QueryProvider>
          <header className="border-b border-slate-200 bg-white sticky top-0 z-40 shadow-sm">
            <div className="container mx-auto px-4 h-16 flex items-center justify-between">
              <div className="flex items-center gap-6">
                <span className="text-xl font-bold text-brand-600">الفريد ستور</span>
                <nav className="hidden md:flex items-center gap-4 text-sm font-medium text-slate-600">
                  <a href="/" className="hover:text-brand-600">الرئيسية</a>
                  <a href="/wholesale" className="hover:text-brand-600">بوابة الجملة</a>
                </nav>
              </div>
            </div>
          </header>
          <main>{children}</main>
        </QueryProvider>
      </body>
    </html>
  );
}
