const { test } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const { spawn } = require('node:child_process');
const path = require('node:path');

test('catalog pages expose public data, safe offers, redirects and sitemap', { timeout: 180000 }, async () => {
  const product = {
    id: 1, slug: 'bag', name_ar: 'شنطة عملية', name_en: 'Bag',
    description_ar: 'شنطة للتجربة', description_en: null, image: '/images/bag.jpg',
    price: 250, old_price: null, in_stock: true, category: null, images: [],
    variants: [{ id: 9, sku: 'BAG-9', color_name_ar: 'أسود', color_name_en: 'Black',
      color_hex: '#000000', size: null, capacity: null, images: [], price: 250,
      old_price: null, in_stock: true, max_qty: 2 }],
    material_ar: null, material_en: null, warranty_months: null, country_of_origin: null,
    occasions: [], is_featured: false, brand: null,
  };
  const category = { id: 1, slug: 'bags', name_ar: 'شنط', name_en: 'Bags',
    description_ar: 'تشكيلة شنط', description_en: null, image: null, products_count: 1, children: [] };
  const api = http.createServer((req, res) => {
    res.setHeader('Content-Type', 'application/json');
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname.endsWith('/branding')) return res.end(JSON.stringify({ data: { name: 'VARYA' } }));
    if (url.pathname.endsWith('/categories')) return res.end(JSON.stringify({ data: [category] }));
    if (url.pathname.endsWith('/products/bag')) return res.end(JSON.stringify({ data: product }));
    if (url.pathname.endsWith('/products')) return res.end(JSON.stringify({ data: [product],
      meta: { current_page: 1, last_page: 1, per_page: 24, total: 1, from: 1, to: 1 } }));
    res.statusCode = 404;
    res.end('{}');
  });
  await new Promise(resolve => api.listen(0, '127.0.0.1', resolve));
  const appDir = path.resolve(__dirname, '../apps/storefront');
  const next = require.resolve('next/dist/bin/next', { paths: [appDir] });
  const portProbe = http.createServer();
  await new Promise(resolve => portProbe.listen(0, '127.0.0.1', resolve));
  const port = portProbe.address().port;
  await new Promise(resolve => portProbe.close(resolve));
  const child = spawn(process.execPath, [next, 'dev', '-p', String(port)], {
    cwd: appDir, stdio: 'ignore',
    env: { ...process.env, API_INTERNAL_URL: `http://127.0.0.1:${api.address().port}`,
      PRIVATE_CLOUD_RUN_API_ORIGIN: '', STOREFRONT_PUBLIC_URL: 'https://store.example', STOREFRONT_SEO_TEST: '1' },
  });
  const base = `http://127.0.0.1:${port}`;
  try {
    let ready = false;
    for (let i = 0; i < 90; i++) {
      try { if ((await fetch(base + '/healthz')).ok) { ready = true; break; } } catch {}
      await new Promise(resolve => setTimeout(resolve, 500));
    }
    assert.ok(ready, 'Next server did not start');
    const productResponse = await fetch(base + '/products/bag');
    assert.equal(productResponse.status, 200);
    const html = await productResponse.text();
    assert.match(html, /شنطة عملية/);
    assert.match(html, /<html lang="ar" dir="rtl"/);
    assert.match(html, /تفاصيل المنتج/);
    const structured = JSON.parse(html.match(/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/s)[1]);
    assert.equal(structured.offers.price, 250);
    assert.equal(structured.offers.priceCurrency, 'EGP');
    assert.equal(structured.offers.availability, 'https://schema.org/InStock');
    const productScripts = [...html.matchAll(/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/gs)].map(match => JSON.parse(match[1]));
    const productBreadcrumb = productScripts.find(item => item['@type'] === 'BreadcrumbList');
    assert.ok(productBreadcrumb, 'Product page should emit BreadcrumbList JSON-LD');
    assert.equal(productBreadcrumb.itemListElement[0].item, 'https://store.example/');
    const categoryResponse = await fetch(base + '/categories/bags');
    assert.equal(categoryResponse.status, 200);
    const categoryHtml = await categoryResponse.text();
    assert.match(categoryHtml, /href="\/products\/bag"/);
    const categoryScripts = [...categoryHtml.matchAll(/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/gs)].map(match => JSON.parse(match[1]));
    assert.ok(categoryScripts.some(item => item['@type'] === 'BreadcrumbList'), 'Category page should emit BreadcrumbList JSON-LD');
    assert.equal((await fetch(base + '/categories/bags?page=2')).status, 404);
    assert.equal((await fetch(base + '/products/missing')).status, 404);
    const redirect = await fetch(base + '/shop?product=bag&color=black', { redirect: 'manual' });
    assert.equal(redirect.status, 308);
    assert.match(redirect.headers.get('location'), /\/products\/bag\?color=black/);
    const categoriesRedirect = await fetch(base + '/categories', { redirect: 'manual' });
    assert.equal(categoriesRedirect.status, 308);
    assert.match(categoriesRedirect.headers.get('location'), /\/shop$/);
    const categoryQueryRedirect = await fetch(base + '/categories?category=bags&utm_source=test', { redirect: 'manual' });
    assert.equal(categoryQueryRedirect.status, 308);
    assert.match(categoryQueryRedirect.headers.get('location'), /\/categories\/bags\?utm_source=test/);
    const priorityRedirect = await fetch(base + '/categories?product=bag&category=bags&color=black', { redirect: 'manual' });
    assert.equal(priorityRedirect.status, 308);
    assert.match(priorityRedirect.headers.get('location'), /\/products\/bag\?color=black/);
    const sitemap = await (await fetch(base + '/sitemap.xml')).text();
    assert.match(sitemap, /https:\/\/store.example\/products\/bag/);
    assert.match(sitemap, /https:\/\/store.example\/categories\/bags/);
    assert.match(sitemap, /https:\/\/store.example\/bags-wholesale/);
    assert.match(sitemap, /https:\/\/store.example\/guides\/laptop-bag-fit/);
    assert.match(sitemap, /https:\/\/store.example\/guides\/crossbody-capacity/);
    assert.match(sitemap, /https:\/\/store.example\/guides\/tote-vs-backpack/);
    const wholesale = await fetch(base + '/bags-wholesale');
    assert.equal(wholesale.status, 200);
    assert.match(await wholesale.text(), /href="\/wholesale\/register"/);
    const guide = await fetch(base + '/guides/laptop-bag-fit');
    assert.equal(guide.status, 200);
    assert.match(await guide.text(), /قِس الجهاز وهو مقفول/);
    assert.equal((await fetch(base + '/guides/crossbody-capacity')).status, 200);
    assert.equal((await fetch(base + '/guides/tote-vs-backpack')).status, 200);
    assert.equal((await fetch(base + '/cart')).headers.get('x-robots-tag'), 'noindex');
    product.variants.push({ ...product.variants[0], id: 10, sku: 'BAG-10', price: 300, color_name_en: 'Red', color_name_ar: 'أحمر' });
    const multiHtml = await (await fetch(base + '/products/bag')).text();
    const multi = JSON.parse(multiHtml.match(/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/s)[1]);
    assert.equal(multi.offers, undefined);
    const scripts = [...multiHtml.matchAll(/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/gs)].map(match => JSON.parse(match[1]));
    const group = scripts.find(item => item['@type'] === 'ProductGroup');
    assert.equal(group.productGroupID, '1');
    assert.deepEqual(group.variesBy, ['https://schema.org/color']);
    assert.equal(group.hasVariant[1].offers.price, 300);
    assert.equal(group.hasVariant[1].offers.url, 'https://store.example/products/bag?variant=10');
    assert.match(multiHtml, /href="\/products\/bag\?variant=10"/);
    const variantHtml = await (await fetch(base + '/products/bag?variant=10')).text();
    const variantData = JSON.parse(variantHtml.match(/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/s)[1]);
    assert.equal(variantData.sku, 'BAG-10');
    assert.equal(variantData.offers.price, 300);
    assert.equal(variantData.offers.url, 'https://store.example/products/bag?variant=10');
    assert.match(variantHtml, /300/);
    const invalidVariantHtml = await (await fetch(base + '/products/bag?variant=999')).text();
    const invalidVariant = JSON.parse(invalidVariantHtml.match(/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/s)[1]);
    assert.equal(invalidVariant.offers, undefined);
    product.variants[1].in_stock = false;
    const unavailableHtml = await (await fetch(base + '/products/bag?variant=10')).text();
    const unavailable = JSON.parse(unavailableHtml.match(/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/s)[1]);
    assert.equal(unavailable.offers.availability, 'https://schema.org/OutOfStock');
    assert.equal(unavailable.offers.price, 300);
    const unavailableScripts = [...unavailableHtml.matchAll(/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/gs)].map(match => JSON.parse(match[1]));
    assert.equal(unavailableScripts.find(item => item['@type'] === 'ProductGroup').hasVariant[1].offers.availability, 'https://schema.org/OutOfStock');
    product.variants[1].color_name_en = 'Black';
    product.variants[1].color_name_ar = 'أسود';
    const duplicateHtml = await (await fetch(base + '/products/bag?variant=10')).text();
    assert.match(duplicateHtml.replace(/<!--.*?-->/g, ''), /class="product-variant-sku">SKU:\s*BAG-10/);
    const duplicateScripts = [...duplicateHtml.matchAll(/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/gs)].map(match => JSON.parse(match[1]));
    assert.equal(duplicateScripts.some(item => item['@type'] === 'ProductGroup'), false);
    product.variants.pop();
    product.variants[0].in_stock = false;
    const soldOutHtml = await (await fetch(base + '/products/bag')).text();
    const soldOut = JSON.parse(soldOutHtml.match(/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/s)[1]);
    assert.equal(soldOut.offers.availability, 'https://schema.org/OutOfStock');
    product.variants[0].price = null;
    const missingPriceHtml = await (await fetch(base + '/products/bag')).text();
    const missingPrice = JSON.parse(missingPriceHtml.match(/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/s)[1]);
    assert.equal(missingPrice.offers, undefined);
  } finally {
    child.kill();
    await new Promise(resolve => api.close(resolve));
  }
});
