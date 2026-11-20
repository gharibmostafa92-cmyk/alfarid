import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { Suspense } from 'react';
import { headers } from 'next/headers';
import { getPublicProduct } from '@/lib/catalog/server-product';
import { getServerBranding } from '@/lib/core/server-branding';
import { ProductPageClient } from '@/components/products/ProductPageClient';
import { breadcrumbJsonLd, productGroupJsonLd, productJsonLd } from '@/lib/catalog/product-jsonld';

type Props = { params: Promise<{ slug: string }>; searchParams: Promise<{ variant?: string | string[] }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const [product, branding] = await Promise.all([getPublicProduct(slug), getServerBranding()]);
  if (!product) return {};
  const name = product.name_ar || product.name_en || '';
  const base = process.env.STOREFRONT_PUBLIC_URL?.replace(/\/$/, '');
  const title = `${name} | ${branding.name}`;
  const description = product.description_ar || product.description_en || name;
  return {
    title,
    description,
    openGraph: { title, description, siteName: branding.name },
    alternates: base ? { canonical: `${base}/products/${encodeURIComponent(product.slug)}` } : undefined,
  };
}

export default async function ProductPage({ params, searchParams }: Props) {
  const { slug } = await params;
  const product = await getPublicProduct(slug);
  if (!product) notFound();
  const origin = process.env.STOREFRONT_PUBLIC_URL;
  const { variant } = await searchParams;
  const variantId = typeof variant === 'string' && /^[1-9][0-9]*$/.test(variant) ? variant : undefined;
  const structuredData = origin ? productJsonLd(product, origin, variantId) : null;
  const productGroup = origin ? productGroupJsonLd(product, origin) : null;
  const categoryName = product.category ? (product.category.name_ar || product.category.name_en) : '';
  const breadcrumbItems = [
    { name: 'الرئيسية', path: '/' },
    { name: 'الشنط', path: '/shop' },
    ...(product.category && categoryName ? [{ name: categoryName, path: '/categories/' + encodeURIComponent(product.category.slug) }] : []),
    { name: product.name_ar || product.name_en || '', path: '/products/' + encodeURIComponent(product.slug) },
  ];
  const breadcrumb = origin ? breadcrumbJsonLd(breadcrumbItems, origin) : null;
  const nonce = (await headers()).get('x-nonce') ?? undefined;
  return <>
    {structuredData && <script type="application/ld+json" nonce={nonce} dangerouslySetInnerHTML={{ __html: JSON.stringify(structuredData).replace(/</g, '\\u003c') }} />}
    {productGroup && <script type="application/ld+json" nonce={nonce} dangerouslySetInnerHTML={{ __html: JSON.stringify(productGroup).replace(/</g, '\\u003c') }} />}
    {breadcrumb && <script type="application/ld+json" nonce={nonce} dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumb).replace(/</g, '\\u003c') }} />}
    <Suspense fallback={null}><ProductPageClient product={product} /></Suspense>
  </>;
}
