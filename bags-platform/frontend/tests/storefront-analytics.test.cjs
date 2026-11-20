const { test } = require('node:test');
const assert = require('node:assert/strict');

test('analytics waits for consent, strips query strings and stops after refusal', async () => {
  process.env.NEXT_PUBLIC_GA_MEASUREMENT_ID = 'G-TEST123';
  const values = new Map();
  global.localStorage = {
    getItem: key => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
  };
  const scripts = [];
  global.window = { location: { origin: 'https://store.example', pathname: '/products/bag', search: '?email=private', reload() {} } };
  global.document = {
    querySelector: () => ({ content: 'nonce' }),
    createElement: () => ({}),
    head: { appendChild: script => scripts.push(script) },
  };
  const analytics = await import('../apps/storefront/src/lib/analytics/ecommerce.ts');
  const event = { currency: 'EGP', value: 250, items: [{ item_id: '9', price: 250, quantity: 1 }] };
  analytics.trackEcommerce('view_item', event);
  assert.equal(scripts.length, 0);
  analytics.setAnalyticsConsent(true);
  analytics.trackEcommerce('view_item', event);
  assert.equal(scripts.length, 1);
  assert.match(scripts[0].src, /^https:\/\/www\.googletagmanager\.com\//);
  const sent = window.dataLayer.find(entry => entry[0] === 'event');
  assert.equal(sent[1], 'view_item');
  assert.equal(sent[2].page_location, 'https://store.example/products/bag');
  assert.equal(sent[2].page_referrer, '');
  const purchase = { ...event, transaction_id: 'ORDER-1' };
  analytics.trackEcommerce('purchase', purchase);
  analytics.trackEcommerce('purchase', purchase);
  assert.equal(window.dataLayer.filter(entry => entry[0] === 'event' && entry[1] === 'purchase').length, 1);
  analytics.setAnalyticsConsent(false);
  analytics.trackEcommerce('add_to_cart', event);
  assert.equal(window.dataLayer.filter(entry => entry[0] === 'event').length, 2);
});
