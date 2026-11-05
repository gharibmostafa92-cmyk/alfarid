const { chromium } = require('playwright');
const assert = require('node:assert/strict');
(async () => {
  const browser = await chromium.launch({ headless: true, channel: 'msedge' });
  try {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
    await context.addInitScript(() => {
      window.SpeechRecognition = class {
        start() { this.onresult({ results: [[{ transcript: 'اتنين وعشرين' }]] }); this.onend(); }
        abort() {}
      };
    });
    const page = await context.newPage();
    const scans = [], errors = [];
    page.on('pageerror', e => errors.push(e.message));
    const actor = { id: 1, name: 'موظف جرد', roles: ['staff'], branch_id: 1, permissions: ['pos.access', 'stocktakes.update'] };
    await context.route('**/api/v1/**', async route => {
      const p = new URL(route.request().url()).pathname;
      const headers = { 'access-control-allow-origin': '*', 'access-control-allow-headers': '*', 'access-control-allow-methods': '*' };
      const json = (data, status = 200) => route.fulfill({ headers, status, json: data });
      if (route.request().method() === 'OPTIONS') return route.fulfill({ headers, status: 204 });
      if (p.endsWith('/auth/handoff/exchange')) return json({ token: 'pos-count-test', data: actor, redirect: { path: '/pos' } });
      if (p.endsWith('/admin/me')) return json({ data: actor });
      if (p.endsWith('/stocktakes/7/scan')) {
        scans.push(route.request().postDataJSON());
        if (scans.length === 1) return json({ message: 'فشل مؤقت بعد الحفظ' }, 503);
        return json({ sku: 'BAG-01', counted_qty: 22, system_qty: 987654 });
      }
      return json({ data: [] });
    });
    await page.goto(`${process.env.BACKOFFICE_URL || 'http://127.0.0.1:5173'}/auth/callback#code=pos-count-handoff`);
    const counter = page.getByRole('region', { name: 'عد الجرد بالصوت' });
    await counter.getByRole('textbox', { name: 'معرّف جلسة الجرد' }).fill('٧');
    await counter.getByRole('textbox', { name: 'باركود الجرد' }).fill('6221234567890');
    await counter.getByRole('button', { name: 'قول العدد' }).click();
    await counter.getByRole('textbox', { name: 'العدد المنطوق' }).filter({ visible: true }).waitFor();
    assert.equal(await counter.getByRole('textbox', { name: 'العدد المنطوق' }).inputValue(), 'اتنين وعشرين');
    await counter.getByRole('button', { name: 'تسجيل المسحة' }).click();
    await counter.getByRole('alert').waitFor();
    await counter.getByRole('button', { name: 'تسجيل المسحة' }).click();
    await counter.getByRole('status').filter({ hasText: '22' }).waitFor();
    assert.equal(scans.length, 2);
    assert.equal(scans[0].scan_id, scans[1].scan_id);
    assert.match(scans[0].scan_id, /^[0-9a-f-]{36}$/);
    assert.equal(scans[0].qty_words, 'اتنين وعشرين');
    assert.ok(!(await counter.innerText()).includes('987654'));
    assert.deepEqual(errors, []);
    console.log('POS voice count passed: speech, Arabic session digits, stable scan retry, blind count.');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exitCode = 1; });
