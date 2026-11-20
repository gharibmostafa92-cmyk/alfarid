import { cache } from 'react';
import type { ResourceCollection, StoreCategory, StoreProduct, StoreProductCard } from '@alfarid/api-client';
import { cloudRunIdentityToken } from '@/lib/core/cloud-run-api';

async function catalogFetch(path: string, params?: URLSearchParams): Promise<Response> {
  const privateOrigin = process.env.PRIVATE_CLOUD_RUN_API_ORIGIN;
  const origin = new URL(privateOrigin || process.env.API_INTERNAL_URL || process.env.NEXT_PUBLIC_API_URL || 'http://127.0.0.1:8000');
  if (!process.env.API_INTERNAL_URL && origin.hostname === 'localhost') origin.hostname = '127.0.0.1';
  const headers = new Headers({ Accept: 'application/json' });
  if (privateOrigin) headers.set('X-Serverless-Authorization', 'Bearer ' + await cloudRunIdentityToken(origin.origin));
  const url = new URL('/api/v1/store/' + path, origin);
  if (params) url.search = params.toString();
  return fetch(url, {
    headers, cache: 'no-store', redirect: 'error', signal: AbortSignal.timeout(5000),
  });
}

export const getPublicProduct = cache(async (slug: string): Promise<StoreProduct | null> => {
  const response = await catalogFetch('products/' + encodeURIComponent(slug));
  if (response.status === 404) return null;
  if (!response.ok) throw new Error('Catalog API returned ' + response.status);
  return (await response.json() as { data: StoreProduct }).data;
});

export const getPublicCategories = cache(async (): Promise<StoreCategory[]> => {
  const response = await catalogFetch('categories');
  if (!response.ok) throw new Error('Catalog API returned ' + response.status);
  return (await response.json() as { data: StoreCategory[] }).data;
});

export const getPublicProducts = cache(async (category: string, page: number): Promise<ResourceCollection<StoreProductCard>> => {
  const params = new URLSearchParams({ page: String(page), per_page: '24' });
  if (category) params.set('category', category);
  const response = await catalogFetch('products', params);
  if (!response.ok) throw new Error('Catalog API returned ' + response.status);
  return await response.json() as ResourceCollection<StoreProductCard>;
});
