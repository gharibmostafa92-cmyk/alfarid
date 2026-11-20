import type { Metadata } from 'next';
import Link from 'next/link';
import { getServerBranding } from '@/lib/core/server-branding';

export async function generateMetadata(): Promise<Metadata> {
  const { name } = await getServerBranding();
  const base = process.env.STOREFRONT_PUBLIC_URL?.replace(/\/$/, '');
  return {
    title: `شنط جملة للتجار في مصر | ${name}`,
    description: `توريد شنط جملة للتجار وأصحاب المتاجر في مصر من ${name}: أنواع الشنط المتاحة، آلية التسعير والحد الأدنى، وطلب فتح حساب تاجر.`,
    alternates: base ? { canonical: base + '/bags-wholesale' } : undefined,
  };
}

export default async function BagsWholesalePage() {
  const { name } = await getServerBranding();
  return <main id="main-content" lang="ar" dir="rtl" className="categories-container">
    <nav aria-label="مسار التصفح"><Link href="/">الرئيسية</Link> / <Link href="/shop">الشنط</Link></nav>
    <article>
      <h1>شنط جملة للتجار في مصر | {name}</h1>
      <p>نوفر بوابة مخصصة لتجار الشنط وأصحاب المتاجر في مصر لطلب التوريد بأسعار الجملة ومتابعة عروض الأسعار والفواتير إلكترونيًا.</p>
      <h2>أنواع الشنط المتاحة للتوريد</h2>
      <p>تشمل التشكيلة المتاحة للتجار شنط اللابتوب والظهر للشغل والجامعة، وشنط الكروس الحريمي، وشنط التوت والاستخدام اليومي، بحسب المخزون الفعلي المحدث في بوابة الجملة.</p>
      <h2>الحد الأدنى للطلب وخلط الألوان</h2>
      <p>يظهر الحد الأدنى المعتمد للطلب وإمكانية خلط الألوان أو المقاسات لكل موديل مباشرة داخل حساب التاجر المعتمد قبل تأكيد الطلب.</p>
      <h2>آلية التسعير وعروض الأسعار</h2>
      <p>بعد مراجعة طلب التسجيل واعتماد الحساب من مسؤول الجملة، تظهر أسعار الجملة الخاصة بالتاجر في البوابة مع إمكانية طلب عرض سعر رسمي وتحميل الفواتير ومتابعة حالة الطلبات.</p>
      <h2>التوصيل والاستلام</h2>
      <p>تتوفر خدمات الشحن والتوصيل للمناطق المشمولة بسياسة الشحن المعتمدة في المتجر. راجع <Link href="/legal/shipping-delivery">سياسة الشحن والتوصيل</Link> للتفاصيل المحدثة.</p>
      <p>
        <Link href="/wholesale/register">تقديم طلب تسجيل تاجر جملة</Link> · <Link href="/shop">تصفح كتالوج التجزئة</Link>
      </p>
    </article>
  </main>;
}
