const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const path = require('node:path');

const user = {
  id: 1,
  name: 'مراجع التجار',
  email: 'dealer-admin@example.test',
  roles: [{ name: 'Super Admin' }],
  permissions: [
    'ai_agent.use', 'ai_agent.execute', 'ai_agent.logs.view', 'ai_agent.settings',
    'customers.view', 'customers.create', 'customers.update', 'customers.approve', 'customers.documents.view',
    'customer_groups.view',
  ],
  branch_id: null,
};

const dealer = {
  id: 31,
  name: 'أحمد النور',
  company_name: 'شركة النور للتجارة',
  phone: '01012345678',
  email: 'noor@example.test',
  type: 'wholesale',
  status: 'pending',
  tax_number: '123456789',
  commercial_register: '987654',
  credit_limit: '50000.00',
  documents: [{ id: 11, original_name: 'cr.jpg', status: 'pending', document_type: 'commercial_register' }],
};

(async () => {
  // Start vite dev server on a free port
  const preview = spawn('npx', ['vite', '--port', '4179', '--strictPort'], {
    cwd: path.resolve(__dirname, '../apps/backoffice'),
    shell: true,
    stdio: 'pipe',
  });
  await new Promise((resolve, reject) => {
    const t = setTimeout(() => reject(new Error('vite preview timeout')), 15000);
    preview.stdout.on('data', d => {
      if (String(d).includes('4179')) { clearTimeout(t); resolve(); }
    });
    preview.stderr.on('data', d => {
      if (String(d).includes('4179')) { clearTimeout(t); resolve(); }
    });
  });

  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    const errors = [];
    const sent = [];
    page.on('pageerror', e => errors.push(e.message));

    const conv = { id: 1, user_id: 1, is_pinned: false, title: 'مراجعة شركة النور', total_tokens: 40, model: 'test', messages: [], pending_actions: [] };
    let exists = false;
    let actionState = 'pending';

    await context.route('**/api/v1/**', async route => {
      const request = route.request(), url = new URL(request.url()), p = url.pathname, method = request.method();
      const headers = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': '*' };
      const json = (data, status = 200) => route.fulfill({ headers, status, json: data });
      if (method === 'OPTIONS') return route.fulfill({ headers, status: 204 });
      if (p.endsWith('/auth/handoff/exchange')) return json({ token: 'dealer-e2e-token', data: user, redirect: { path: '/assistant' } });
      if (p.endsWith('/auth/me') || p.endsWith('/admin/me')) return json({ actor: { type: 'staff' }, data: user });
      if (p.endsWith('/notifications')) return json({ data: [], meta: { unread_count: 0 } });
      if (p.endsWith('/ai/welcome')) return json({ data: { type: 'welcome_card', title: 'ملخص طابور التجار', sla_hours: 48, rows: [{ label: 'قيد المراجعة', value: 4 }, { label: 'جاهز للاعتماد', value: 2 }] } });
      if (p.endsWith('/ai/mentions')) return json({ data: { customers: [{ id: 31, label: 'أحمد النور — شركة النور للتجارة', meta: '010*****678 · قيد المراجعة · جملة' }] } });
      if (p.endsWith('/ai/conversations') && method === 'POST') { exists = true; return json({ data: conv }, 201); }
      if (p.endsWith('/ai/conversations')) return json({ data: exists ? [conv] : [], last_page: 1 });
      if (p.endsWith('/conversations/1')) return json({ data: conv });
      if (p.endsWith('/conversations/1/stream')) {
        const body = request.postDataJSON();
        sent.push(body);
        const id = conv.messages.length + 1;
        const checklistBlock = {
          type: 'checklist',
          customer_id: 31,
          verdict: 'ready',
          checks: [
            { key: 'legal_fields', ok: true, severity: 'blocker', message: 'البيانات القانونية التلاتة موجودة.' },
            { key: 'phone_unverified', ok: false, severity: 'warning', message: 'رقم الموبايل لسه مش مؤكد.' },
          ],
          suggestion: { credit_limit: 50000, source: 'مقترح: 50,000 (وسيط 14 تاجر جملة اتعمدوا آخر 3 شهور)' },
        };
        const confirmBlock = {
          type: 'confirm_action',
          action_id: 'act-approve-31',
          tool: 'approve_dealer_application',
          risk: 'high',
          title: 'اعتماد تاجر شركة النور للتجارة',
          before: { status: 'pending' },
          after: {
            status: 'approved',
            documents_to_verify: [{ id: 11, name: 'cr.jpg', download_url: '/api/v1/admin/customers/31/documents/11/download' }],
            suggestion: { source: 'مقترح: 50,000 (وسيط 14 تاجر جملة اتعمدوا آخر 3 شهور)' },
          },
          warnings: ['رقم الموبايل لسه مش مؤكد.'],
          typed_confirmation: '5678',
          typed_confirmation_hint: 'آخر 4 أرقام من موبايل التاجر',
          expires_at: new Date(Date.now() + 600000).toISOString(),
        };
        conv.messages.push({ id, role: 'user', content: body.text, ui_blocks: null, created_at: new Date().toISOString() });
        conv.messages.push({ id: id + 1, role: 'model', content: 'راجعت طلب شركة النور.', ui_blocks: [checklistBlock, confirmBlock], created_at: new Date().toISOString() });
        conv.pending_actions = [{ id: 'act-approve-31', status: actionState, expires_at: confirmBlock.expires_at }];
        return route.fulfill({ headers: { ...headers, 'content-type': 'text/event-stream' }, body: 'event: status\ndata: {"message":"بفحص مستندات التاجر…"}\n\nevent: reply\ndata: {"message_id":2,"text":"راجعت طلب شركة النور.","ui_blocks":[]}\n\n' });
      }
      if (p.endsWith('/actions/act-approve-31/confirm')) {
        assert.equal(request.postDataJSON().typed_confirmation, '5678');
        actionState = 'executed';
        conv.pending_actions[0].status = 'executed';
        return json({
          data: {
            ok: true,
            message: 'تم اعتماد التاجر.',
            status: 'executed',
            result: { customer_id: 31 },
            ui_block: { type: 'result', ok: true, message: 'تم اعتماد التاجر.', deep_link: '/dealers?customer=31' },
          },
        });
      }
      if (p.endsWith('/admin/customers/31')) return json({ data: dealer });
      if (p.endsWith('/admin/customers')) return json({ data: [dealer], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } });
      return json({ data: [] });
    });

    await page.goto('http://127.0.0.1:4179/#/assistant');
    const assistant = page.locator('.assistant');
    await assistant.getByText('ملخص طابور التجار').waitFor();

    // 1. Mention لعميل بعلامة $
    const box = assistant.getByRole('textbox', { name: 'رسالة المساعد' });
    await box.fill('$النور');
    await assistant.getByRole('button', { name: /أحمد النور — شركة النور للتجارة/ }).click();
    await box.press('Enter');

    // 2. عرض الـ checklist وزرار "جهّز الاعتماد"
    await assistant.getByRole('region', { name: 'فحص طلب التاجر' }).waitFor();
    await assistant.getByRole('button', { name: 'جهّز الاعتماد' }).waitFor();
    assert.equal(sent[0].mentions[0].entity, 'customer');
    assert.equal(sent[0].mentions[0].id, 31);

    // 3. التأكيد بالكتابة (آخر 4 أرقام من الموبايل — الواجهة تعرض التلميح مش الرقم)
    const confirmRegion = assistant.getByRole('region', { name: 'مراجعة وتأكيد العملية' });
    await confirmRegion.getByText('المستندات اللي هتتوثق').waitFor();
    const confirmBtn = confirmRegion.getByRole('button', { name: 'تأكيد التنفيذ' });
    assert.equal(await confirmBtn.isDisabled(), true);
    const typedInput = confirmRegion.getByPlaceholder('آخر 4 أرقام من موبايل التاجر');
    await typedInput.fill('0000');
    assert.equal(await confirmBtn.isDisabled(), true);
    await typedInput.fill('5678');
    assert.equal(await confirmBtn.isDisabled(), false);
    await confirmBtn.click();
    await confirmRegion.locator('.as-badge').getByText('تم التنفيذ').waitFor();

    // 4. اختبار المقاسات 320 و768 و1024 و1440
    for (const width of [1440, 1024, 768, 320]) {
      await page.setViewportSize({ width, height: 900 });
      const fits = await assistant.evaluate(el => el.scrollWidth <= el.clientWidth + 2);
      assert.equal(fits, true, `assistant fits at ${width}px`);
    }
    await page.setViewportSize({ width: 1440, height: 1000 });

    // 5. فتح الـ deep link في الـ drawer جوه DealersPage + زرار "اسأل المساعد"
    await page.evaluate(() => { window.location.hash = '/dealers?customer=31'; });
    await page.getByRole('button', { name: 'اسأل المساعد' }).waitFor();
    await page.getByRole('button', { name: 'اسأل المساعد' }).click();
    await assistant.getByRole('textbox', { name: 'رسالة المساعد' }).waitFor();
    const seededVal = await assistant.getByRole('textbox', { name: 'رسالة المساعد' }).inputValue();
    assert.equal(seededVal.includes('راجع طلب'), true);

    assert.deepEqual(errors, []);
    console.log('Dealer assistant E2E passed on 320/768/1024/1440 viewports!');
  } finally {
    await browser.close();
    preview.kill();
    process.exit(0);
  }
})().catch(err => {
  console.error(err);
  process.exit(1);
});
