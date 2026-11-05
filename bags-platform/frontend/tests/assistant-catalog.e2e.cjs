const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const path = require('node:path');
const base = process.env.BACKOFFICE_URL || 'http://127.0.0.1:5173';
const pixel = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jBZkAAAAASUVORK5CYII=';
const actor = { id: 1, name: 'مراجع الكتالوج', roles: [{ name: 'Super Admin' }], branch_id: null, permissions: ['ai_agent.use', 'ai_agent.execute', 'products.view', 'products.update', 'categories.view'] };
const product = { type: 'product_card', id: 42, name_ar: 'شنطة اختبار', code: 'P00042', status: 'published', category_name: 'شنط', thumb_url: pixel, price_range: { min_retail: 450, max_retail: 500, min_wholesale: 300, max_wholesale: 350 }, variants: [{ id: 7, sku: 'P00042-BLK', color_name_ar: 'أسود', size: 'M', is_active: true, retail_price: 450, wholesale_price: 300 }], readiness: { ready: false, checks: [{ key: 'image', ok: false, label: 'يجب رفع صورة واحدة على الأقل', hint: 'ابعتلي صور المنتج' }] }, deep_link: '#/products/42' };
const row = { variant_id: 7, sku: 'P00042-BLK', product_name: 'شنطة اختبار', old_price: 450, new_price: 495, delta: 45, delta_pct: 10 };
const blocks = [product,
  { type: 'category_tree', nodes: [{ id: 1, name: 'حقائب', active: true, products_count: 1, children: [{ id: 2, name: 'حقائب سفر', active: false, products_count: 0, children: [] }] }] },
  { type: 'media_gallery', items: [{ id: 11, thumb_url: pixel, order: 1, is_cover: true }, { id: 12, thumb_url: pixel, order: 2, is_cover: false }] },
  { type: 'price_diff', action_id: 'prices', risk: 'high', title: 'تعديل الأسعار', typed_confirmation: 'تنفيذ', expires_at: new Date(Date.now() + 600000).toISOString(), summary: { field: 'retail_price', updated_count: 2, blocked_count: 1, total_delta: 90, average_delta: 45 }, top: [row], rows: [row, { ...row, variant_id: 8, sku: 'P00042-NAVY' }], blocked: [{ sku: 'P00042-RED', reason: 'أسباب تسعير محمية' }] },
  { type: 'variant_matrix', action_id: 'matrix', title: 'تركيبات المنتج', expires_at: new Date(Date.now() + 600000).toISOString(), rows: [{ color: 'كحلي', color_hex: '#1F2A44', size: 'L', retail: 450, wholesale: 300, status: 'new' }] },
  { type: 'checklist', title: 'الجاهزية', verdict: 'blocked', checks: [{ key: 'image', ok: false, label: 'صورة ناقصة', severity: 'blocker' }], next_actions: [{ label: 'ارفع صور', prompt: 'هبعتلك صور المنتج P00042' }] },
];
(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    const errors = [], sent = [];
    page.on('pageerror', error => errors.push(error.message));
    const conversation = { id: 1, user_id: 1, title: 'مراجعة كتالوج', model: 'test', is_pinned: false, total_tokens: 0, messages: [], pending_actions: [] };
    await context.route('**/api/v1/**', async route => {
      const request = route.request(), pathname = new URL(request.url()).pathname;
      const headers = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': '*' };
      const json = data => route.fulfill({ headers, json: data });
      if (request.method() === 'OPTIONS') return route.fulfill({ headers, status: 204 });
      if (pathname.endsWith('/auth/handoff/exchange')) return json({ token: 'isolated-catalog-test', data: actor, redirect: { path: '/assistant' } });
      if (pathname.endsWith('/admin/me')) return json({ data: actor });
      if (pathname.endsWith('/ai/welcome')) return json({ data: null });
      if (pathname.endsWith('/ai/conversations') && request.method() === 'POST') return json({ data: conversation });
      if (pathname.endsWith('/ai/conversations')) return json({ data: [conversation], last_page: 1 });
      if (pathname.endsWith('/ai/mentions')) return json({ data: { products: [{ id: 42, label: 'شنطة اختبار — P00042' }], variants: [{ id: 7, label: 'P00042-BLK' }], categories: [{ id: 1, label: 'حقائب' }] } });
      if (pathname.endsWith('/conversations/1/attachments')) return json({ data: [{ id: 'attachment-1', filename: 'bag.png', mime: 'image/png', size: 68, thumb_url: pixel }] });
      if (pathname.endsWith('/conversations/1')) return json({ data: conversation });
      if (pathname.endsWith('/conversations/1/stream')) {
        sent.push(request.postDataJSON());
        conversation.messages = [{ id: 1, role: 'model', content: 'راجع الكتالوج', ui_blocks: blocks }];
        return route.fulfill({ headers: { ...headers, 'content-type': 'text/event-stream' }, body: 'event: reply\ndata: {"text":"راجع الكتالوج","ui_blocks":[],"message_id":1}\n\n' });
      }
      return json({ data: [], meta: { unread_count: 0 } });
    });
    await page.goto(`${base}/auth/callback#code=isolated-catalog-handoff`);
    const assistant = page.locator('#view-assistant .assistant');
    const composer = assistant.getByRole('textbox', { name: 'رسالة المساعد' });
    await composer.fill('$شنطة');
    await assistant.getByRole('option').filter({ hasText: 'شنطة اختبار — P00042' }).getByRole('button').click();
    await composer.fill('&حقائب');
    await assistant.getByRole('option').filter({ hasText: 'حقائب' }).getByRole('button').click();
    await assistant.locator('input[type=file]').setInputFiles({ name: 'bag.png', mimeType: 'image/png', buffer: Buffer.from(pixel.split(',')[1], 'base64') });
    await composer.fill('اعرض الكتالوج');
    await composer.press('Enter');
    const card = assistant.getByRole('region', { name: 'كارت المنتج', exact: true });
    await card.getByRole('heading', { name: 'شنطة اختبار' }).waitFor();
    assert.ok((await card.innerText()).includes('٤٥٠ – ٥٠٠'), 'product range uses the API min/max fields');
    await card.getByText('أسود · M', { exact: true }).waitFor();
    await card.getByText('يجب رفع صورة واحدة على الأقل — ابعتلي صور المنتج', { exact: true }).waitFor();
    assert.equal(await card.getByRole('img', { name: 'شنطة اختبار' }).count(), 1);
    const prices = assistant.getByRole('region', { name: 'مراجعة وتأكيد العملية' }).filter({ hasText: 'تعديل الأسعار' });
    assert.ok((await prices.locator('tbody').innerText()).includes('٤٥٠ → ٤٩٥'), 'price diff shows actual old/new prices');
    await prices.getByRole('button', { name: /اعرض كل/ }).click();
    await prices.getByText('P00042-NAVY', { exact: true }).waitFor();
    await assistant.getByText('ترتيب 2', { exact: true }).waitFor();
    const tree = assistant.getByRole('region', { name: 'شجرة الأقسام', exact: true });
    await tree.getByRole('button', { name: 'طي قسم حقائب', exact: true }).click();
    assert.equal(await tree.getByText('حقائب سفر', { exact: true }).count(), 0);
    await tree.getByRole('button', { name: 'فتح قسم حقائب', exact: true }).click();
    await tree.getByText('حقائب سفر', { exact: true }).waitFor();
    const matrix = assistant.getByRole('region', { name: 'مراجعة وتأكيد العملية' }).filter({ hasText: 'تركيبات المنتج' });
    await matrix.getByRole('cell', { name: 'كحلي', exact: true }).waitFor();
    assert.equal(sent[0].mentions[0].entity, 'product');
    assert.equal(sent[0].mentions[1].entity, 'category');
    assert.deepEqual(sent[0].attachments, ['attachment-1']);
    await assistant.getByRole('button', { name: 'ارفع صور', exact: true }).click();
    assert.equal(await composer.inputValue(), 'هبعتلك صور المنتج P00042');
    await page.screenshot({ path: path.resolve(__dirname, '../../../test-results/assistant/catalog-blocks.png'), fullPage: true });
    assert.deepEqual(errors, []);
    console.log('Catalog browser contract passed: mentions, attachment upload, product card, readiness, price diff, matrix, gallery, guided actions.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
