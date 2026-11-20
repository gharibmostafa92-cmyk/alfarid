import type { Metadata } from 'next';
import Link from 'next/link';
import { headers } from 'next/headers';
import { notFound } from 'next/navigation';
import { formatStorePrice } from '@alfarid/utils';
import { findCategory } from '@/lib/catalog/catalog-mappers';
import { breadcrumbJsonLd } from '@/lib/catalog/product-jsonld';
import { getPublicCategories, getPublicProducts } from '@/lib/catalog/server-product';
import { getServerBranding } from '@/lib/core/server-branding';
import { storeImageUrl } from '@/lib/catalog/store-image';
import { STORE_ROUTES } from '@/lib/orders/store-routes';

type Props = {
  params: Promise<{ slug: string }>;
  searchParams: Promise<{ page?: string }>;
};

export async function generateMetadata({ params, searchParams }: Props): Promise<Metadata> {
  const { slug } = await params;
  const { page } = await searchParams;
  const [tree, branding] = await Promise.all([getPublicCategories(), getServerBranding()]);
  const category = findCategory(tree, slug)?.node;
  if (!category) return {};
  const name = category.name_ar || category.name_en || '';
  const base = process.env.STOREFRONT_PUBLIC_URL?.replace(/\/$/, '');
  const canonical = base ? `${base}/categories/${encodeURIComponent(slug)}${page && page !== '1' ? '?page=' + encodeURIComponent(page) : ''}` : undefined;
  const title = `${name} | ${branding.name}`;
  const description = category.description_ar || name;
  return { title, description, openGraph: { title, description, siteName: branding.name }, alternates: canonical ? { canonical } : undefined };
}

export default async function CategoryPage({ params, searchParams }: Props) {
  const { slug } = await params;
  const { page: pageParam } = await searchParams;
  if (pageParam && !/^[1-9]\d*$/.test(pageParam)) notFound();
  const page = pageParam ? Number(pageParam) : 1;
  if (!Number.isSafeInteger(page)) notFound();
  const category = findCategory(await getPublicCategories(), slug)?.node;
  if (!category) notFound();
  const result = await getPublicProducts(slug, page);
  if (page > Math.max(result.meta.last_page, 1)) notFound();
  const categoryUrl = '/categories/' + encodeURIComponent(slug);
  const origin = process.env.STOREFRONT_PUBLIC_URL;
  const breadcrumb = origin
    ? breadcrumbJsonLd(
        [
          { name: 'الرئيسية', path: '/' },
          { name: 'الشنط', path: '/shop' },
          { name: category.name_ar || category.name_en || '', path: categoryUrl },
        ],
        origin,
      )
    : null;
  const nonce = (await headers()).get('x-nonce') ?? undefined;
  return <main id="main-content" dir="rtl" lang="ar" className="categories-container">
    {breadcrumb && <script type="application/ld+json" nonce={nonce} dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumb).replace(/</g, '\\u003c') }} />}
    <nav aria-label="مسار التصفح"><Link href="/">الرئيسية</Link> / <Link href="/shop">الشنط</Link></nav>
    <h1>{category.name_ar || category.name_en}</h1>
    {category.description_ar && <p>{category.description_ar}</p>}
    {slug === 'laptop-backpacks' && <p><Link href="/guides/laptop-bag-fit">إزاي تتأكد إن اللابتوب هيدخل في الشنطة؟</Link></p>}
    <Link href={`/shop?category=${encodeURIComponent(slug)}`}>تصفية المنتجات</Link>
    <div className="product-grid">
      {result.data.map((product) => {
        const image = storeImageUrl(product.image);
        return <article className="product-card" key={product.id}>
          <Link href={STORE_ROUTES.product(product.slug)}>
            {image && <img src={image} alt={product.name_ar || product.name_en || ''} loading="lazy" />}
            <h2>{product.name_ar || product.name_en}</h2>
          </Link>
          {product.price !== null && <p>{formatStorePrice(Number(product.price))}</p>}
          {!product.in_stock && <p>نفدت الكمية</p>}
        </article>;
      })}
    </div>
    <nav aria-label="صفحات المنتجات">
      {page > 1 && <Link href={page === 2 ? categoryUrl : `${categoryUrl}?page=${page - 1}`}>السابق</Link>}
      {page < result.meta.last_page && <Link href={`${categoryUrl}?page=${page + 1}`}>التالي</Link>}
    </nav>
  </main>;
}
