import { request } from 'node:https';
// Run inside a storefront container to reach the VIP on the Docker bridge.
const url = new URL(process.argv[2] || 'https://haproxy1/api/v1/store/branding');
const duration = Number(process.argv[3] || 20000);
const started = Date.now();
const samples = [];
function probe() {
  return new Promise(resolve => {
    const at = Date.now();
    const req = request(url, { rejectUnauthorized: false, headers: { Host: 'api.alfarid.example' } }, res => {
      res.resume();
      res.on('end', () => resolve({ at, end: Date.now(), status: res.statusCode, node: res.headers['x-served-by'] }));
    });
    req.setTimeout(15000, () => req.destroy(new Error('timeout')));
    req.on('error', error => resolve({ at, end: Date.now(), status: 0, error: error.message }));
    req.end();
  });
}
while (Date.now() - started < duration) {
  samples.push(await probe());
  await new Promise(resolve => setTimeout(resolve, 75));
}
console.log(JSON.stringify({ started, ended: Date.now(), samples }));
