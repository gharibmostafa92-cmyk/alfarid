// Synthetic, browser-only fixtures. No demo data is written to the application or database.
const image = '/images/sophie-hero-fallback.png';
const category = { id: 1, slug: 'travel', name_en: 'Travel & everyday bags', name_ar: 'حقائب السفر والاستخدام اليومي', products_count: 4, image, children: [] };
const product = {
  id: 1, slug: 'responsive-test-bag', name_en: 'The everyday travel bag with a comfortably long product name',
  name_ar: 'حقيبة يومية للسفر والعمل', category, image, images: [{ id: 1, url: image, thumb: image }],
  description_en: 'A long description to verify wrapping and reading comfort across small screens.',
  price: 1250, old_price: 1500, in_stock: true, discount_percentage: 17, colors: [],
  variants: [{ id: 10, sku: 'UI-TEST-BAG', color_name_en: 'Forest green', color_name_ar: 'أخضر', color_hex: '#234437', size: null, capacity: null,
    price: 1250, old_price: 1500, in_stock: true, max_qty: 5, images: [{ url: image, thumb: image }] }],
};
const products = Array.from({ length: 4 }, (_, index) => ({ ...product, id: index + 1, slug: index ? `responsive-test-bag-${index}` : product.slug }));
const cart = {
  id: 1, cart_token: 'ui-test-cart', customer_id: null,
  items: [{ id: 1, product_id: 1, product_variant_id: 10, product_name: product.name_en, product_slug: product.slug,
    unit_price: 1250, qty: 1, available: 5, max_qty: 5, image, color: 'Forest green', size: null, priced: true, line_total: 1250 }],
  totals: { subtotal: 1250, pricing_discount: 0, items_total: 1250, coupon_discount: 0, total: 1250, coupon_code: null },
};

async function installFixtures(page) {
  await page.route('**/api/v1/store/**', route => {
    const path = new URL(route.request().url()).pathname;
    const wrapped = data => ({ data });
    let payload;
    if (path.endsWith('/home')) payload = wrapped({ banners: [], best_sellers: products, latest_products: products, discounted_products: [] });
    else if (path.endsWith('/categories')) payload = wrapped([category]);
    else if (path.endsWith('/catalog/filters')) payload = wrapped({ colors: [], price_ranges: [], occasions: [] });
    else if (path.endsWith('/products')) payload = { data: products, meta: { total: products.length, current_page: 1, last_page: 1 } };
    else if (path.endsWith('/similar')) payload = wrapped([]);
    else if (path.includes('/products/')) payload = wrapped(product);
    else if (path.endsWith('/showcase')) payload = wrapped([product]);
    else if (path.endsWith('/cart/items')) payload = wrapped(cart);
    else if (path.endsWith('/cities')) payload = wrapped([{ id: 1, name_ar: 'القاهرة', name_en: 'Cairo' }]);
    else if (path.endsWith('/account/orders')) payload = wrapped([{ id: 1, order_number: 'UI-ORDER-1234567890', status: 'pending', grand_total: 1250 }]);
    else if (path.endsWith('/account/wallet')) payload = { balance: 12345, transactions: { data: [{ id: 1, type: 'إضافة رصيد إلى المحفظة', amount: 12345, created_at: '2026-10-01T10:00:00Z' }] } };
    else if (path.endsWith('/account/returns')) payload = { data: [], meta: { total: 0, current_page: 1, last_page: 1 } };
    else if (path.endsWith('/account/addresses')) payload = wrapped([]);
    else return route.continue();
    return route.fulfill({ json: payload });
  });
}

module.exports = { installFixtures, product };
