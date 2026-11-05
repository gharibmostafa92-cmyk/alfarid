const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const base = process.env.BACKOFFICE_URL || 'http://127.0.0.1:5173';
const actor = { id: 1, name: 'مراجع المخزون', roles: [{ name: 'Super Admin' }], branch_id: null, permissions: ['ai_agent.use', 'ai_agent.execute', 'inventory.view', 'stocktakes.view', 'stocktakes.update'] };
(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    for (const width of [1440, 390]) {
      const context = await browser.newContext({ viewport: { width, height: 1000 } });
      const page = await context.newPage();
      page.setDefaultTimeout(6000);
      const errors = [], confirmations = [], sent = [];
      page.on('pageerror', e => errors.push(e.message));
      const blocks = ['stock_diff', 'count_sheet', 'cost_impact', 'transfer_timeline'].map((type, i) => ({
        type, action_id: `inventory-${i}`, tool: ['adjust_stock', 'record_counts', 'receive_production'][i],
        title: `عملية مخزون ${i}`, risk: 'high', typed_confirmation: 'تنفيذ',
        expires_at: new Date(Date.now() + 600000).toISOString(),
        before: { on_hand: 10 }, after: { on_hand: 8 },
        rows: [{ sku: 'BAG-01', counted_qty: 8, confidence: 0.6, quantity: 8, cost_before: 100, cost_after: 110 }],
      }));
      const conversation = { id: 1, user_id: 1, title: 'مراجعة مخزون', model: 'test', total_tokens: 0, messages: [], pending_actions: blocks.map(b => ({ id: b.action_id, status: 'pending' })) };
      await context.route('**/api/v1/**', async route => {
        const request = route.request(), p = new URL(request.url()).pathname;
        const headers = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': '*' };
        const json = data => route.fulfill({ headers, json: data });
        if (request.method() === 'OPTIONS') return route.fulfill({ headers, status: 204 });
        if (p.endsWith('/auth/handoff/exchange')) return json({ token: 'inventory-ui-test', data: actor, redirect: { path: '/assistant' } });
        if (p.endsWith('/admin/me')) return json({ data: actor });
        if (p.endsWith('/ai/welcome')) return json({ data: null });
        if (p.endsWith('/ai/mentions')) return json({ data: { variants: [{ id: 401, label: 'P00042-BLK' }] } });
        if (p.endsWith('/stocktakes/7')) return json({ data: { id: 7, stocktake_number: 'STK-00007', branch: { id: 1, name: 'المعادي' }, status: 'draft', items: [] } });
        if (p.endsWith('/ai/conversations')) return json(request.method() === 'POST' ? { data: conversation } : { data: [conversation], last_page: 1 });
        if (p.endsWith('/conversations/1')) return json({ data: conversation });
        if (p.endsWith('/conversations/1/stream')) {
          sent.push(request.postDataJSON());
          conversation.messages = [{ id: 1, role: 'model', content: 'راجع المخزون', ui_blocks: blocks }];
          return route.fulfill({ headers: { ...headers, 'content-type': 'text/event-stream' }, body: 'event: reply\ndata: {"text":"راجع المخزون","ui_blocks":[],"message_id":1}\n\n' });
        }
        if (p.endsWith('/confirm')) {
          confirmations.push(request.postDataJSON());
          const id = p.split('/').at(-2);
          conversation.pending_actions.find(a => a.id === id).status = 'executed';
          return json({ data: { ok: true, status: 'executed', message: 'تم التنفيذ', result: {} } });
        }
        return json({ data: [], meta: { unread_count: 0 } });
      });
      await page.goto(`${base}/auth/callback#code=inventory-ui-handoff`);
      const assistant = page.locator('#view-assistant .assistant');
      const composer = assistant.getByRole('textbox', { name: 'رسالة المساعد' });
      await composer.fill('@6221234567890');
      await composer.press('Enter');
      await assistant.getByText('P00042-BLK', { exact: true }).first().waitFor();
      await composer.fill('راجع المخزون');
      await composer.press('Enter');
      for (let i = 0; i < blocks.length; i++) {
        const card = assistant.getByRole('region', { name: 'مراجعة وتأكيد العملية', exact: true }).filter({ hasText: `عملية مخزون ${i}` });
        const confirm = card.getByRole('button', { name: 'تأكيد التنفيذ', exact: true });
        await confirm.waitFor();
        assert.equal(await confirm.isDisabled(), true);
        await card.getByRole('textbox').fill('تنفيذ');
        if (blocks[i].type === 'count_sheet') {
          assert.equal(await confirm.isDisabled(), true, 'uncertain image rows require review');
          await assistant.getByRole('checkbox', { name: 'راجعت السطور ذات الثقة المنخفضة' }).check();
        }
        assert.equal(await confirm.isEnabled(), true);
        await confirm.click();
        await card.getByText('تم التنفيذ', { exact: true }).first().waitFor();
      }
      assert.equal(confirmations.length, 4);
      assert.deepEqual(sent[0].mentions, [{ entity: 'variant', id: 401 }]);
      assert.ok(confirmations.every(c => c.typed_confirmation === 'تنفيذ'));
      await page.evaluate(() => { window.location.hash = '/inventory/count?id=7'; });
      await page.getByRole('heading', { name: /STK-00007/ }).waitFor();
      await page.evaluate(() => { window.location.hash = '/assistant'; });
      await composer.fill('اعتمده');
      await Promise.all([page.waitForResponse(r => r.url().endsWith('/conversations/1/stream')), composer.press('Enter')]);
      assert.deepEqual(sent.at(-1).selection, { entity: 'stocktake', id: 7 });
      await assistant.locator('textarea[aria-label="رسالة المساعد"]:enabled').waitFor();
      await assistant.locator('.as-msg').evaluateAll(async elements => {
        await Promise.all(elements.flatMap(el => el.getAnimations({ subtree: true })).filter(a => a.effect?.getComputedTiming().iterations !== Infinity).map(a => a.finished.catch(() => {})));
      });
      assert.deepEqual(errors, []);
      await page.screenshot({ path: require('node:path').resolve(__dirname, `../../../test-results/assistant/inventory-${width}.png`), fullPage: true });
      await context.close();
    }
    console.log('Inventory assistant confirmation cards passed at desktop and mobile widths.');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
