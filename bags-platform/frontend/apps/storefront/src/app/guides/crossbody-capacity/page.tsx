import type { Metadata } from 'next';
import Link from 'next/link';
import { getServerBranding } from '@/lib/core/server-branding';

export async function generateMetadata(): Promise<Metadata> {
  const { name } = await getServerBranding();
  const base = process.env.STOREFRONT_PUBLIC_URL?.replace(/\/$/, '');
  return {
    title: `شنطة كروس صغيرة تشيل إيه؟ | ${name}`,
    description: 'كيف تختار شنطة كروس حريمي بالحجم المناسب لموبايلك ومحفظتك ومفاتيحك مع التأكد من طول السير قبل الشراء.',
    alternates: base ? { canonical: base + '/guides/crossbody-capacity' } : undefined,
  };
}

export default function CrossbodyCapacityGuide() {
  return <main id="main-content" lang="ar" dir="rtl" className="categories-container">
    <nav aria-label="مسار التصفح"><Link href="/">الرئيسية</Link> / <Link href="/shop">الشنط</Link></nav>
    <article>
      <h1>شنطة كروس صغيرة تشيل إيه؟</h1>
      <p>اختيار شنطة الكروس الصغيرة يعتمد على المقاس الداخلي وطول السير، وليس الشكل الخارجي فقط. راجع هذه الخطوات قبل الطلب لتتأكد أن الشنطة تناسب أغراضك اليومية.</p>
      <h2>١. حدد الأغراض الأساسية</h2>
      <p>تحقق من أبعاد الموبايل مع الجراب، وحجم المحفظة، والمفاتيح، وأي غرض يومي ثابت مثل نظارة شمس أو باور بانك صغير.</p>
      <h2>٢. راجع السُمك وفتحة السحّاب</h2>
      <p>الشنط الرفيعة قد تتسع لطول الموبايل لكنها تضيق عند إغلاق السحّاب مع المحفظة. قارن العرض والارتفاع والسُمك بالسنتيمتر في صفحة المنتج.</p>
      <h2>٣. تأكد من طول السير وقابلية التعديل</h2>
      <p>راجع أقل وأكبر طول للسير بالسنتيمتر لتعرف إن كانت الشنطة مناسبة للارتداء كروس أو على الكتف بحسب طولك وملابسك.</p>
      <h2>٤. تصفح الموديلات المتاحة</h2>
      <p>قارن بين الموديلات المتوفرة في المتجر وراجع صور المنتج وتفاصيل الشحن والاسترجاع قبل الإضافة للسلة.</p>
      <p>
        <Link href="/shop?category=crossbody">تصفح شنط الكروس</Link> · <Link href="/guides/laptop-bag-fit">دليل قياس شنط اللابتوب</Link> · <Link href="/shop">كل الشنط</Link>
      </p>
    </article>
  </main>;
}
