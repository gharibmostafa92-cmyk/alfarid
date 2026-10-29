import { execFile, execFileSync } from 'node:child_process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
import { mkdirSync, writeFileSync } from 'node:fs';
const exec = promisify(execFile);
const root = fileURLToPath(new URL('../', import.meta.url));
const results = fileURLToPath(new URL('../k6/results/', import.meta.url));
mkdirSync(results, { recursive: true });
const mode = process.argv[2];
if (!['app', 'redis', 'vip'].includes(mode)) throw new Error('Choose app, redis or vip');
const target = mode === 'vip' ? 'haproxy1' : mode === 'app' ? 'app2' : (process.env.REDIS_PRIMARY || 'redis1');
const container = `alfarid-lb-${target}-1`;
const url = mode === 'vip' ? 'https://172.31.0.100/api/v1/store/branding' : 'https://haproxy1/api/v1/store/branding';
execFileSync('docker', ['cp', `${root}scripts/traffic-probe.mjs`, 'alfarid-lb-storefront1-1:/tmp/traffic-probe.mjs']);
let healthy = false;
for (let attempt = 0; attempt < 6; attempt++) {
  const baseline = await exec('docker', ['exec', 'alfarid-lb-storefront1-1', 'node', '/tmp/traffic-probe.mjs', url, '3000']);
  const data = JSON.parse(baseline.stdout);
  if (data.samples.length >= 3 && data.samples.every(s => s.status === 200)) { healthy = true; break; }
}
if (!healthy) throw new Error('The baseline is not healthy; no fault was injected');
const probe = exec('docker', ['exec', 'alfarid-lb-storefront1-1', 'node', '/tmp/traffic-probe.mjs', url, '20000'], { maxBuffer: 4e6 });
await new Promise(resolve => setTimeout(resolve, 3000));
const faultAt = Date.now();
try {
  execFileSync('docker', ['kill', container]);
  const { stdout } = await probe;
  const data = JSON.parse(stdout);
  const failed = data.samples.filter(s => s.at >= faultAt && s.status !== 200);
  const firstFailure = failed[0];
  const recovered = firstFailure && data.samples.find(s => s.at > firstFailure.at && s.status === 200);
  const output = { mode, target, faultAt, requests: data.samples.length,
    failed: failed.length, errorRate: failed.length / data.samples.length,
    maxLatencyMs: Math.max(...data.samples.map(s => s.end - s.at)),
    recoveryFromKillMs: recovered ? recovered.end - faultAt : (firstFailure ? null : 0),
    observedOutageMs: recovered ? recovered.end - firstFailure.at : (firstFailure ? null : 0), ...data };
  writeFileSync(`${results}/fault-${mode}.json`, JSON.stringify(output, null, 2));
  const { samples, ...summary } = output;
  console.log(JSON.stringify(summary, null, 2));
  if (firstFailure && !recovered) process.exitCode = 1;
  if (mode === 'app' && output.errorRate >= 0.005) process.exitCode = 1;
  if (mode === 'redis' && output.recoveryFromKillMs >= 10000) process.exitCode = 1;
  if (mode === 'vip' && output.recoveryFromKillMs >= 3000) process.exitCode = 1;
} finally {
  execFileSync('docker', ['start', container]);
}
