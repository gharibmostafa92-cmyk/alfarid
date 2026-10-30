const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const base = process.env.BACKOFFICE_URL || 'http://127.0.0.1:5173';
const out = path.resolve(__dirname, '../../../test-results/assistant');
const user = { id: 1, name: 'مراجع المساعد', email: 'assistant@example.test', roles: [{ name: 'Super Admin' }], permissions: ['ai_agent.use', 'ai_agent.execute', 'ai_agent.logs.view', 'ai_agent.settings', 'branches.view', 'users.view', 'users.update', 'roles.view', 'inventory.all_branches'], branch_id: null };
(async () => {
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage(); page.setDefaultTimeout(15000);
    const errors = [], sent = [];
    page.on('pageerror', e => errors.push(e.message));
    const conv = { id: 1, user_id: 1, is_pinned: false, title: 'مراجعة الفرع', total_tokens: 25, model: 'test', messages: [], pending_actions: [] };
    let exists = false, state = 'pending';
    const confirmation = { type: 'confirm_action', action_id: 'action-1', title: 'تعطيل فرع المعادي', tool: 'set_branch_status', before: { is_active: true }, after: { is_active: false }, warnings: ['الموظفين هيتأثروا بالتعطيل'], typed_confirmation: 'BR-MAADI', expires_at: new Date(Date.now() + 600000).toISOString() };
    await context.route('**/api/v1/**', async route => {
      const request = route.request(), url = new URL(request.url()), p = url.pathname, method = request.method();
      const headers = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': '*' };
      const json = (data, status = 200) => route.fulfill({ headers, status, json: data });
      if (method === 'OPTIONS') return route.fulfill({ headers, status: 204 });
      if (p.endsWith('/auth/handoff/exchange')) return json({ token: 'isolated-assistant-test', data: user, redirect: { path: '/assistant' } });
      if (p.endsWith('/admin/me')) return json({ data: user });
      const linkedUser = { id: 7, name: 'أحمد الاختبار', email: 'ahmed@example.test', roles: [], permissions: [], phone: null, branch_id: null, is_active: true, version: 1, created_at: '2026-10-30T10:00:00Z' };
      if (p.endsWith('/admin/users/7')) return json({ data: linkedUser });
      if (p.endsWith('/admin/users')) return json({ data: [linkedUser], meta: { last_page: 1, current_page: 1, per_page: 8, total: 1 } });
      if (p.endsWith('/admin/roles') || p.endsWith('/admin/branches')) return json({ data: [], meta: { last_page: 1, current_page: 1, total: 0 } });
      if (p.endsWith('/notifications')) return json({ data: [], meta: { unread_count: 0 } });
      if (p.endsWith('/ai/usage')) return json({ data: [{ day: '2026-10-30', input_tokens: 20, output_tokens: 5 }] });
      if (p.endsWith('/ai/settings')) return json({ data: { ai_enabled: true, ai_daily_token_budget_per_user: 200000, ai_action_ttl_minutes: 10, ai_retention_days: 180, ai_bulk_max_items: 25, ai_disabled_tools: [] }, tools: ['set_branch_status'] });
      if (p.endsWith('/ai/mentions')) return json({ data: { users: [{ id: 7, label: 'أحمد الاختبار' }], branches: [{ id: 9, label: 'المعادي' }] } });
      if (p.endsWith('/ai/conversations') && method === 'POST') { exists = true; return json({ data: conv }, 201); }
      if (p.endsWith('/ai/conversations')) return json({ data: exists ? [conv] : [], last_page: 1 });
      if (p.endsWith('/conversations/1/pin')) { conv.is_pinned = request.postDataJSON().is_pinned; return json({ data: conv }); }
      if (p.endsWith('/conversations/1') && method === 'DELETE') { exists = false; return route.fulfill({ headers, status: 204 }); }
      if (p.endsWith('/conversations/1')) return json({ data: conv });
      if (p.endsWith('/conversations/1/stream')) {
        const body = request.postDataJSON(); sent.push(body);
        if (body.text === 'طلب فاشل') return json({ message: 'فشل اختباري مؤقت' }, 502);
        const id = conv.messages.length + 1;
        const block = body.text === 'جماعي' ? { ...confirmation, type: 'bulk_plan', action_id: 'bulk-1', typed_confirmation: 'تنفيذ', items: [{ key: 'item-1', label: 'تعطيل المعادي', before: { is_active: true }, after: { is_active: false } }, { key: 'item-2', label: 'تعطيل الدقي', before: { is_active: true }, after: { is_active: false } }] } : confirmation;
        conv.messages.push({ id, role: 'user', content: body.text, ui_blocks: null });
        conv.messages.push({ id: id + 1, role: 'model', content: '**راجع التغيير**\n<script>window.injected=true</script>\n![tracking](https://example.invalid/tracker.png)', ui_blocks: [block] });
        conv.pending_actions = [{ id: 'action-1', status: state }];
        if (block.type === 'bulk_plan') conv.pending_actions.push({ id: 'bulk-1', status: 'pending' });
        return route.fulfill({ headers: { ...headers, 'content-type': 'text/event-stream' }, body: 'event: status\ndata: {"message":"بحسب التأثير…"}\n\nevent: reply\ndata: {"message_id":2,"text":"راجع التغيير","ui_blocks":[]}\n\n' });
      }
      if (p.endsWith('/actions/bulk-1/confirm')) {
        assert.equal(request.postDataJSON().typed_confirmation, 'تنفيذ');
        assert.deepEqual(request.postDataJSON().selected_items, ['item-2']);
        conv.pending_actions.find(action => action.id === 'bulk-1').status = 'executed';
        return json({ data: { ok: true, message: 'تم تنفيذ 1 من 1 عمليات.', status: 'executed', result: {}, ui_block: { type: 'result', ok: true, message: 'تم تنفيذ 1 من 1 عمليات.' } } });
      }
      if (p.endsWith('/actions/action-1/confirm')) {
        assert.equal(request.postDataJSON().typed_confirmation, 'BR-MAADI');
        state = 'executed'; conv.pending_actions[0].status = state;
        return json({ data: { ok: true, message: 'تم تحديث حالة الفرع.', status: state, result: {}, ui_block: { type: 'result', ok: true, message: 'تم تحديث حالة الفرع.' } } });
      }
      return json({ data: [] });
    });
    await page.goto(`${base}/auth/callback#code=isolated-assistant-handoff`);
    const assistant = page.locator('#view-assistant .assistant');
    await assistant.getByRole('heading', { name: 'مساعد الفريد', exact: true }).waitFor();
    assert.equal(await assistant.locator('.assistant-layout').evaluate(element => getComputedStyle(element).display), 'grid', 'assistant styles reach the Vortix shadow root');
    assert.equal(await page.locator('#side-logout-btn').count(), 1, 'Vortix shell remains available');
    await assistant.getByRole('button', { name: 'الفروع المتوقفة', exact: true }).click();
    await assistant.getByRole('heading', { name: 'تعطيل فرع المعادي' }).waitFor();
    assert.equal(sent[0].text, 'الفروع المتوقفة');
    assert.equal(await assistant.locator('script, img').count(), 0, 'unsafe HTML and remote images are excluded');
    const confirm = assistant.getByRole('button', { name: 'تأكيد التنفيذ' });
    assert.equal(await confirm.isDisabled(), true);
    await assistant.getByRole('textbox', { name: 'اكتب BR-MAADI للتأكيد' }).fill('wrong');
    assert.equal(await confirm.isDisabled(), true);
    await assistant.getByRole('textbox', { name: 'اكتب BR-MAADI للتأكيد' }).fill('BR-MAADI');
    await confirm.click();
    await assistant.getByText('تم التنفيذ', { exact: true }).waitFor();
    assert.equal(await confirm.isDisabled(), true);
    await assistant.getByRole('textbox', { name: 'رسالة المساعد' }).fill('@أح');
    await assistant.getByRole('button', { name: 'أحمد الاختبار', exact: true }).click();
    await assistant.getByRole('textbox', { name: 'رسالة المساعد' }).press('Enter');
    await page.waitForFunction(() => !document.querySelector('.assistant-chat[aria-busy="true"]'));
    assert.equal(sent.at(-1).mentions[0].id, 7);
    await assistant.getByRole('textbox', { name: 'رسالة المساعد' }).fill('جماعي');
    await assistant.getByRole('textbox', { name: 'رسالة المساعد' }).press('Enter');
    const bulk = assistant.getByRole('region', { name: 'مراجعة وتأكيد العملية' }).last();
    await bulk.getByRole('checkbox', { name: /تعطيل المعادي/ }).uncheck();
    await bulk.getByRole('textbox', { name: 'اكتب تنفيذ للتأكيد' }).fill('تنفيذ');
    await bulk.getByRole('button', { name: 'تأكيد التنفيذ' }).click();
    await bulk.getByText('تم التنفيذ', { exact: true }).waitFor();
    await assistant.getByRole('textbox', { name: 'رسالة المساعد' }).fill('طلب فاشل');
    await assistant.getByRole('textbox', { name: 'رسالة المساعد' }).press('Enter');
    await assistant.getByRole('alert').getByText('فشل اختباري مؤقت').waitFor();
    assert.equal(await assistant.getByRole('textbox', { name: 'رسالة المساعد' }).inputValue(), 'طلب فاشل');
    await assistant.getByRole('button', { name: 'إغلاق رسالة الخطأ' }).click();
    await assistant.getByRole('button', { name: 'تثبيت المحادثة', exact: true }).click();
    await assistant.getByRole('button', { name: 'إلغاء تثبيت المحادثة' }).waitFor();
    await assistant.getByRole('button', { name: 'الاستهلاك والإعدادات', exact: true }).click();
    await assistant.getByText('حد التوكنز اليومي لكل مستخدم (0 = بلا حد)').waitFor();
    await assistant.getByRole('button', { name: 'الاستهلاك والإعدادات', exact: true }).click();
    for (const width of [1440, 1024, 768, 320]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.screenshot({ path: path.join(out, `${width}.png`), fullPage: true });
      assert.equal(await assistant.evaluate(element => element.scrollWidth <= element.clientWidth + 1), true, `assistant fits ${width}px`);
    }
    await page.setViewportSize({ width: 1440, height: 1000 });
    await assistant.getByRole('button', { name: 'سجل الموظفين', exact: true }).click();
    await assistant.getByRole('button', { name: /مراجعة الفرع/ }).click();
    assert.equal(await assistant.getByRole('textbox', { name: 'رسالة المساعد' }).count(), 0, 'logs are read-only');
    await assistant.getByRole('button', { name: 'محادثاتي', exact: true }).click();
    await assistant.getByRole('button', { name: 'حذف مراجعة الفرع' }).click();
    await assistant.getByText('مفيش محادثات لسه.').waitFor();
    await page.evaluate(() => { window.location.hash = '/users?userId=7'; });
    await page.getByRole('heading', { name: 'تعديل المستخدم', exact: true }).waitFor();
    assert.equal(await page.getByRole('textbox', { name: 'الاسم كامل *', exact: true }).inputValue(), 'أحمد الاختبار');
    user.roles = [{ name: 'cashier' }]; user.permissions = ['ai_agent.use'];
    await page.goto(`${base}/auth/callback#code=isolated-assistant-cashier`);
    await page.locator('#view-assistant .assistant').getByRole('heading', { name: 'مساعد الفريد', exact: true }).waitFor();
    await page.getByText('المساعد الذكي', { exact: true }).waitFor();
    assert.deepEqual(errors, []);
    console.log('Assistant browser flow passed: shell, suggestions, typed confirmation, safe Markdown, mentions, pins, settings, logs, delete, four viewport widths.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
