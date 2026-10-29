// Fetch up to 500 product slugs from the storefront API for k6.
// Usage: node fetch-slugs.cjs [BASE_URL]
// Writes: ../slugs.json
const BASE = process.argv[2] || process.env.BASE_URL || 'http://127.0.0.1:8000';
const TARGET = 500;
const PER_PAGE = 48;

async function main() {
  const slugs = [];
  let page = 1;
  for (;;) {
    const url = `${BASE}/api/v1/store/products?sort=newest&per_page=${PER_PAGE}&page=${page}`;
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error(`HTTP ${res.status} on ${url}`);
    const body = await res.json();
    const items = body.data ?? body ?? [];
    if (!Array.isArray(items) || items.length === 0) break;
    for (const p of items) {
      if (p.slug) slugs.push(p.slug);
      if (slugs.length >= TARGET) break;
    }
    if (slugs.length >= TARGET) break;
    const meta = body.meta;
    if (meta && page >= (meta.last_page ?? page)) break;
    page += 1;
    if (page > 20) break;
  }
  if (slugs.length === 0) throw new Error('no slugs collected');
  const fs = await import('node:fs');
  const path = await import('node:path');
  const out = path.join(__dirname, 'slugs.json');
  fs.writeFileSync(out, JSON.stringify(slugs, null, 1));
  console.log(`wrote ${slugs.length} slugs to ${out}`);
}

main().catch((e) => { console.error(e.message); process.exit(1); });
