// Read-only audit of all current catalog originals and thumbnails.
const api = process.env.STOREFRONT_API_URL || 'http://127.0.0.1:8000';
(async () => {
  const json = async url => {
    const response = await fetch(url, { signal: AbortSignal.timeout(10000) });
    if (!response.ok) throw new Error(`API HTTP ${response.status}`);
    return response.json();
  };
  const products = [], urls = new Set();
  let page = 1, last = 1;
  do {
    const list = await json(`${api}/api/v1/store/products?per_page=48&page=${page}`);
    products.push(...list.data); last = list.meta.last_page; page++;
  } while (page <= last);
  for (const card of products) {
    if (card.image) urls.add(card.image);
    const { data } = await json(`${api}/api/v1/store/products/${encodeURIComponent(card.slug)}`);
    for (const image of [...data.images, ...data.variants.flatMap(variant => variant.images)]) {
      if (image.url) urls.add(image.url);
      if (image.thumb) urls.add(image.thumb);
    }
  }
  const sources = [...urls], failures = [];
  for (let start = 0; start < sources.length; start += 6) {
    await Promise.all(sources.slice(start, start + 6).map(async source => {
      const parsed = new URL(source);
      const requestUrl = new URL(source);
      // The local PHP server binds IPv4; Node resolves localhost to IPv6 on this host.
      if (requestUrl.hostname === 'localhost') requestUrl.hostname = '127.0.0.1';
      try {
        const response = await fetch(requestUrl, { signal: AbortSignal.timeout(5000) });
        if (!response.ok || !response.headers.get('content-type')?.startsWith('image/'))
          failures.push({ source: parsed.origin + parsed.pathname, status: response.status });
        await response.body?.cancel();
      } catch (error) { failures.push({ source: parsed.origin + parsed.pathname, error: error.message }); }
    }));
  }
  console.log(JSON.stringify({ products: products.length, media: sources.length, failed: failures.length, failures }, null, 2));
  if (failures.length) process.exitCode = 1;
})().catch(error => { console.error(error); process.exitCode = 1; });
