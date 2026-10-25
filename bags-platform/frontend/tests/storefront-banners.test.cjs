const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const ts = require('typescript');

function loadTypeScript(relativePath, dependencies = {}) {
  const filename = path.resolve(__dirname, '..', relativePath);
  const compiled = new Module(filename, module);
  compiled.filename = filename;
  compiled.paths = Module._nodeModulePaths(path.dirname(filename));
  compiled.require = (name) => {
    if (name in dependencies) return dependencies[name];
    const sibling = path.resolve(path.dirname(filename), name + '.ts');
    if (name.startsWith('.') && fs.existsSync(sibling)) {
      return loadTypeScript(path.relative(path.resolve(__dirname, '..'), sibling), dependencies);
    }
    return Module.prototype.require.call(compiled, name);
  };
  compiled._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true },
  }).outputText, filename);
  return compiled.exports;
}

const { cardToSophie, mapCards } = loadTypeScript('apps/storefront/src/lib/sophie-mappers.ts');
const { fromApi, toFormData, isoToLocalInput, localInputToIso } = loadTypeScript('apps/backoffice/src/pages/admin/admin-mock/banner-mappers.ts');
const card = (overrides = {}) => ({
  id: 1, slug: 'leather-bag', name_en: '  Leather Bag  ', name_ar: 'حقيبة', image: null,
  price: '1250.50', old_price: '1500', in_stock: true, discount_percentage: 17, ...overrides,
});

test('products with no usable price are omitted while a zero price remains valid', () => {
  for (const price of [null, undefined, '', 'invalid', Infinity]) {
    assert.equal(cardToSophie(card({ price }), 'auto'), null);
  }
  assert.equal(cardToSophie(card({ price: '0' }), 'auto').price, 0);
  assert.equal(mapCards([card(), card({ price: null })], 'auto').length, 1);
  assert.deepEqual(mapCards(undefined, 'auto'), []);
});

test('real product cards preserve photos, prices, routes and Arabic names without demo tints', () => {
  const mapped = cardToSophie(card({ image: '/bags/real.jpg' }), 'auto');
  assert.equal(mapped.name, 'Leather Bag');
  assert.equal(mapped.price, 1250.5);
  assert.equal(mapped.oldPrice, 1500);
  assert.equal(mapped.href, '/products/leather-bag');
  assert.equal(mapped.imgUrl, '/bags/real.jpg');
  assert.equal(mapped.tintClass, undefined);
  const arabic = cardToSophie(card({ name_en: ' ', old_price: '1200' }), 'new');
  assert.equal(arabic.name, 'حقيبة');
  assert.equal(arabic.oldPrice, undefined);
});

test('sold out takes precedence over sale and new badges', () => {
  const soldOut = cardToSophie(card({ in_stock: false }), 'new');
  assert.equal(soldOut.badge, 'SOLD OUT');
  assert.equal(soldOut.badgeTone, 'muted');
  assert.equal(soldOut.inStock, false);
  assert.equal(cardToSophie(card(), 'new').badgeTone, 'sale');
  assert.equal(cardToSophie(card({ discount_percentage: null }), 'new').badge, 'NEW');
  assert.equal(cardToSophie(card({ discount_percentage: null }), 'auto').badge, undefined);
});

test('admin banner round trip preserves placement, optional copy, target and uploaded file', () => {
  const banner = fromApi({
    id: 7, title: ' Banner ', image_url: '/banner.jpg', position: 'middle_banner',
    link_type: 'product', link_value: '42', link_label: 'Bag', sort_order: 0,
    starts_at: null, ends_at: null, is_active: false, status: 'disabled', headline_bold: 'BAGS',
  });
  const file = new File(['image'], 'new.png', { type: 'image/png' });
  const data = toFormData({ ...banner, imageFile: file });
  assert.equal(banner.placement, 'middle');
  assert.equal(banner.linkLabel, 'Bag');
  assert.equal(data.get('position'), 'middle_banner');
  assert.equal(data.get('title'), 'Banner');
  assert.equal(data.get('link_value'), '42');
  assert.equal(data.get('sort_order'), '0');
  assert.equal(data.get('is_active'), '0');
  assert.equal(data.get('headline_bold'), 'BAGS');
  assert.equal(data.get('image'), file);
  assert.equal(data.get('starts_at'), '');
  assert.equal(data.get('description'), '');
  assert.equal(toFormData({ ...banner, linkType: 'none' }).get('link_value'), '');
  assert.equal(toFormData({ ...banner, linkType: 'external', url: ' https://example.com ' }).get('link_value'), 'https://example.com');
});

test('banner schedule inputs round trip Cairo local time without shifting the instant', () => {
  const previousTimezone = process.env.TZ;
  process.env.TZ = 'Africa/Cairo';
  try {
    assert.equal(isoToLocalInput('2026-01-10T08:30:00Z'), '2026-01-10T10:30');
    assert.equal(localInputToIso('2026-01-10T10:30'), '2026-01-10T08:30:00.000Z');
    assert.equal(isoToLocalInput(null), '');
    assert.equal(localInputToIso(''), '');
  } finally {
    if (previousTimezone === undefined) delete process.env.TZ;
    else process.env.TZ = previousTimezone;
  }
});

test('banner create and update preserve multipart file data through the actual HTTP client', async () => {
  const clientModule = loadTypeScript('packages/api-client/src/axios-instance.ts');
  const { adminApi } = loadTypeScript('apps/backoffice/src/pages/admin/admin-mock/api/endpoints.ts', {
    '@alfarid/api-client': clientModule,
  });
  const captured = [];
  clientModule.defaultApiClient.defaults.adapter = async (config) => {
    captured.push(config);
    config.onUploadProgress({ loaded: 3, total: 4 });
    return { data: { data: { id: 7 } }, status: 201, statusText: '', headers: {}, config };
  };
  const form = new FormData();
  form.append('image', new File(['bytes'], 'banner.png', { type: 'image/png' }));
  const progress = [];
  await adminApi.createBanner(form, (percentage) => progress.push(percentage));
  await adminApi.updateBanner(7, form, (percentage) => progress.push(percentage));
  assert.equal(captured[0].data, form);
  assert.equal(captured[1].data, form);
  assert.equal(captured[1].url, '/api/v1/admin/banners/7');
  assert.deepEqual(progress, [75, 75]);
});
