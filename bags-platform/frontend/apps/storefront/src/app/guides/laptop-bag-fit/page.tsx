import type { Metadata } from 'next';
import Link from 'next/link';
import { getServerBranding } from '@/lib/core/server-branding';

export async function generateMetadata(): Promise<Metadata> {
  const { name } = await getServerBranding();
  const base = process.env.STOREFRONT_PUBLIC_URL?.replace(/\/$/, '');
  return {
    title: `إزاي تعرف إن اللابتوب هيدخل في الشنطة؟ | ${name}`,
    description: 'طريقة قياس اللابتوب وجيب الشنطة بالسنتيمتر قبل الشراء، مع خطوات التأكد من الغلق والحماية.',
    alternates: base ? { canonical: base + '/guides/laptop-bag-fit' } : undefined,
  };
}

export default function LaptopBagFitGuide() {
  return <main id="main-content" lang="ar" dir="rtl" className="categories-container">
    <nav aria-label="مسار التصفح"><Link href="/">الرئيسية</Link> / <Link href="/shop">الشنط</Link></nav>
    <article>
      <h1>إزاي تعرف إن اللابتوب هيدخل في الشنطة؟</h1>
      <p>مقاس الشاشة بالبوصة بيساعدك تبدأ البحث. للتأكد من الملاءمة، قارن أبعاد الجهاز الفعلية بالمساحة المتاحة داخل جيب اللابتوب.</p>
      <h2>١. قِس الجهاز وهو مقفول</h2>
      <p>استخدم مسطرة أو شريط قياس وسجّل العرض والارتفاع والسمك بالسنتيمتر. لو بتستخدم جرابًا مع الجهاز، قِسه وهو داخل الجراب.</p>
      <h2>٢. قارن بمقاسات الجيب الداخلية</h2>
      <p>راجع عرض فتحة الجيب وارتفاعه وعرضه وسمكه من الداخل. الأبعاد الخارجية للشنطة بتشمل الحواف والبطانة، فاطلب قياسات الجيب لو مش مذكورة في صفحة المنتج.</p>
      <h2>٣. تأكد من الغلق والحماية</h2>
      <p>عند تجربة الشنطة، أدخل الجهاز بدون ضغط وتأكد إن السحّاب أو الغطاء يقفل بالكامل. راجع وجود بطانة مناسبة، وفكّر في مساحة الشاحن وباقي أغراضك.</p>
      <h2>٤. راجع التفاصيل قبل الطلب</h2>
      <p>شوف صور الجيب والمقاسات وتفاصيل الشحن والاسترجاع في صفحة المنتج. لو القياسات غير واضحة، تواصل مع المتجر باسم الموديل وأبعاد جهازك.</p>
      <Link href="/shop">تصفح الشنط المتاحة</Link>
    </article>
  </main>;
}
