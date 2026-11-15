import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const rawTarget = (process.env.POS_TARGET_URL || '').trim();
if (!rawTarget) {
  console.error('POS_TARGET_URL environment variable is required (e.g. https://admin.alfarid.com/pos).');
  process.exit(1);
}
const target = new URL(rawTarget);
if (target.protocol !== 'https:') {
  console.error('POS_TARGET_URL must use https://');
  process.exit(1);
}

const outDir = path.join(dir, 'dist');
fs.mkdirSync(outDir, { recursive: true });
const targetHref = target.toString().replace(/\/+$/, '');
fs.writeFileSync(path.join(outDir, '_redirects'), `/* ${targetHref} 302\n`);
fs.writeFileSync(
  path.join(outDir, 'index.html'),
  `<!doctype html>\n<html lang="ar" dir="rtl">\n<meta charset="utf-8">\n<title>الفريد | نقطة البيع</title>\n<a href="${targetHref}">فتح نقطة البيع</a>\n</html>\n`,
);
console.log(`Generated pos-pages/dist pointing to ${targetHref}`);
