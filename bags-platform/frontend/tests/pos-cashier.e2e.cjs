'use strict';

/* POS cashier journey E2E (task.txt §28) — real Chromium against the POS dev
 * server, deterministic mock backend (pos-mock.service). Covers: terminal
 * load, cart clear, barcode scan via fast typing + Enter, discount, mixed
 * cash/card checkout to receipt, new sale reset, hold/resume, return of the
 * issued invoice, and close-shift with the 3-char variance-note rule.
 * Console errors fail the run. This exercises the standalone demo, which
 * starts with an open shift and uses an in-memory sales backend.
 *
 *   POS_URL=http://localhost:3002 node tests/pos-cashier.e2e.cjs
 */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const POS_URL = process.env.POS_URL || 'http://localhost:3002';
const OUT = path.join(__dirname, 'test-results', 'pos-cashier');
fs.mkdirSync(OUT, { recursive: true });

async function step(page, name, fn) {
  try {
    await fn();
    console.log(`ok - ${name}`);
  } catch (err) {
    await page.screenshot({ path: path.join(OUT, `${name.replace(/[^a-z0-9]+/gi, '_')}.png`) });
    throw err;
  }
}

test('cashier journey: scan, discount, mixed pay, hold, resume, return, close', async (t) => {
  const browser = await chromium.launch({ headless: true });
  t.after(() => browser.close());
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  await page.route('**/api/v1/store/branding', (route) => route.fulfill({
    headers: { 'access-control-allow-origin': '*' },
    json: { data: { name: 'Alfarid', logo: null, email: null, phone: null, address: null } },
  }));
  const consoleErrors = [];
  page.on('console', (msg) => {
    if (msg.type() === 'error') consoleErrors.push(msg.text());
  });
  page.on('pageerror', (err) => consoleErrors.push(String(err)));

  await step(page, 'terminal loads with the open shift cart', async () => {
    await page.goto(POS_URL, { waitUntil: 'networkidle' });
    await page.getByText('الفاتورة الحالية').first().waitFor({ timeout: 15000 });
  });

  await step(page, 'clear the prefilled cart', async () => {
    await page.getByRole('button', { name: 'إلغاء ومسح الفاتورة' }).click();
    await page.getByText('الفاتورة فارغة').waitFor();
  });

  await step(page, 'scan a barcode with fast typing and Enter', async () => {
    const scan = page.getByPlaceholder('امسح الباركود أو ابحث بالاسم / الكود (SKU)…');
    await scan.click();
    await scan.pressSequentially('6221031204115', { delay: 5 });
    await scan.press('Enter');
    await page.getByText('شنطة يد جلد كلاسيك — أسود').first().waitFor();
  });

  await step(page, 'apply an invoice discount', async () => {
    await page.getByRole('button', { name: '+ إضافة' }).click();
    const input = page.locator('.price-input').last();
    await input.fill('50');
    await input.press('Enter');
    await page.getByRole('button', { name: 'تعديل' }).first().waitFor();
  });

  let invoice = '';
  await step(page, 'mixed cash+card checkout to receipt', async () => {
    await page.getByRole('button', { name: 'إتمام الدفع' }).click();
    await page.getByRole('radio', { name: 'دفع مختلط' }).check();
    const amounts = page.locator('label.money-in input:not([readonly])');
    await amounts.first().fill('100');
    const confirm = page.getByRole('button', { name: 'تأكيد الدفع وطباعة الإيصال' });
    await confirm.click();
    const invoiceEl = page.locator('.num.ltr.t-1').first();
    await invoiceEl.waitFor();
    invoice = (await invoiceEl.textContent()).trim();
    assert.match(invoice, /POS-NSR-/);
    await page.getByRole('button', { name: /فاتورة جديدة/ }).click();
    await page.getByText('الفاتورة فارغة').waitFor();
  });

  await step(page, 'hold the next sale and resume it', async () => {
    const scan = page.getByPlaceholder('امسح الباركود أو ابحث بالاسم / الكود (SKU)…');
    await scan.click();
    await scan.pressSequentially('6221031204115', { delay: 5 });
    await scan.press('Enter');
    await page.getByRole('button', { name: 'تعليق' }).click();
    await page.getByRole('button', { name: 'تعليق وخدمة زبون آخر' }).click();
    await page.getByText('الفاتورة فارغة').waitFor();
    await page.getByRole('button', { name: 'المعلقة' }).first().click();
    await page.locator('.held-card').last().getByRole('button', { name: 'استرجاع الفاتورة' }).click();
    await page.getByText('شنطة يد جلد كلاسيك — أسود').first().waitFor();
    await page.getByRole('button', { name: 'إلغاء ومسح الفاتورة' }).click();
  });

  await step(page, 'return the issued invoice', async () => {
    await page.keyboard.press('F8');
    const lookup = page.getByPlaceholder('امسح باركود الإيصال أو اكتب رقم الفاتورة (order_number)');
    await lookup.fill(invoice);
    await lookup.press('Enter');
    await page.getByRole('checkbox').first().click();
    await page.getByRole('button', { name: 'تأكيد المرتجع' }).click();
    await page.getByText('تم تسجيل المرتجع').waitFor({ timeout: 10000 });
  });

  await step(page, 'close with variance requires a 3+ char note', async () => {
    await page.locator('button[title="انقر لإغلاق الوردية وجرد النقدية"]').click();
    const dialog = page.getByRole('dialog');
    await dialog.getByText('إغلاق الوردية وجرد النقدية').waitFor();
    const actual = dialog.locator('label.money-in input').first();
    const expected = Number(await actual.getAttribute('placeholder'));
    await actual.fill(String(expected + 10));
    const note = dialog.locator('textarea').first();
    const submit = dialog.getByRole('button', { name: 'إغلاق الوردية وإرسالها للاعتماد' });
    await note.fill('ab');
    assert.equal(await submit.isDisabled(), true, 'short note must keep submit disabled');
    await note.fill('فرق جرد معتمد');
    await submit.click();
    await page.getByText('تم إغلاق الوردية').first().waitFor({ timeout: 10000 });
  });

  assert.deepEqual(consoleErrors, [], `console errors during journey: ${consoleErrors.join(' | ').slice(0, 2000)}`);
  fs.writeFileSync(path.join(OUT, 'result.json'), JSON.stringify({ ok: true, invoice }, null, 2));
  console.log(`invoice under test: ${invoice}`);
});
