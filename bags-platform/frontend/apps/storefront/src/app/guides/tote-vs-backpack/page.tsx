import type { Metadata } from 'next';
import Link from 'next/link';
import { getServerBranding } from '@/lib/core/server-branding';

export async function generateMetadata(): Promise<Metadata> {
  const { name } = await getServerBranding();
  const base = process.env.STOREFRONT_PUBLIC_URL?.replace(/\/$/, '');
  return {
    title: `شنطة توت ولا ظهر للشغل؟ | ${name}`,
    description: 'مقارنة عملية بين شنطة التوت وشنطة الظهر للشغل والجامعة في مصر من حيث توزيع الوزن وحماية اللابتوب وسهولة الحركة.',
    alternates: base ? { canonical: base + '/guides/tote-vs-backpack' } : undefined,
  };
}

export default function ToteVsBackpackGuide() {
  return <main id="main-content" lang="ar" dir="rtl" className="categories-container">
    <nav aria-label="مسار التصفح"><Link href="/">الرئيسية</Link> / <Link href="/shop">الشنط</Link></nav>
    <article>
      <h1>شنطة توت ولا ظهر للشغل؟</h1>
      <p>الاختيار بين شنطة التوت وشنطة الظهر يتحدد بطبيعة مشوارك اليومي ووزن الأغراض التي تحملها. هذه المقارنة تساعدك على اختيار النوع الأنسب.</p>
      <h2>متى تختار شنطة ظهر للابتوب؟</h2>
      <p>إذا كنت تحمل لابتوب يوميًا أو تتحرك بالمواصلات أو تمشي مسافات طويلة، فشنطة الظهر توزع الوزن على الكتفين وتوفر جيبًا مبطنًا أكثر ثباتًا للجهاز والشاحن.</p>
      <h2>متى تناسبك شنطة التوت؟</h2>
      <p>إذا كانت أغراضك خفيفة أو تفضل الوصول السريع للأوراق والمستلزمات مع مظهر رسمي أو كاجوال أنيق في المكتب والجامعة، فشنطة التوت خيار عملي بشرط التأكد من متانة اليدين ووجود سحّاب علوي.</p>
      <h2>نقطة الحسم: أبعاد الجهاز والوزن الفارغ</h2>
      <p>قبل الشراء، قِس جهازك بالسنتيمتر وتأكد من الوزن الفارغ للشنطة حتى لا يزيد الحمل اليومي.</p>
      <p>
        <Link href="/shop?category=laptop-backpacks">تصفح شنط الظهر واللابتوب</Link> · <Link href="/guides/laptop-bag-fit">دليل قياس اللابتوب</Link> · <Link href="/shop">كل الشنط</Link>
      </p>
    </article>
  </main>;
}
