import { spawn } from 'node:child_process';
import { createWriteStream, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
const root = fileURLToPath(new URL('../', import.meta.url));
const [id, base = 'http://haproxy1:8081', vus = '50', duration = '60s'] = process.argv.slice(2);
if (!id || !/^[a-z0-9-]+$/.test(id)) throw new Error('Supply a lowercase test ID');
mkdirSync(`${root}/k6/results`, { recursive: true });
const args = ['compose', '--project-directory', root,
  '-f', `${root}/docker-compose.yml`, '-f', `${root}/docker-compose.monitoring.yml`,
  '-f', `${root}/docker-compose.loadtest.yml`, '--profile', 'loadtest', 'run', '--rm', '--no-deps',
  '-e', `BASE_URL=${base}`, '-e', 'STAGE1_DUR=10s', '-e', `STAGE1_VUS=${vus}`,
  '-e', `STAGE2_DUR=${duration}`, '-e', `STAGE2_VUS=${vus}`, '-e', 'STAGE3_DUR=10s', '-e', 'STAGE3_VUS=0',
  'k6', 'run', '--quiet', '-o', 'experimental-prometheus-rw', '--tag', `testid=${id}`,
  `--summary-export=results/${id}.json`, 'store-load.js'];
const log = createWriteStream(`${root}/k6/results/${id}.log`);
const child = spawn('docker', args, { stdio: ['ignore', 'pipe', 'pipe'] });
child.stdout.pipe(log); child.stderr.pipe(log);
child.on('error', error => { console.error(error.message); process.exitCode = 1; });
child.on('close', code => { console.log(`${id}: k6 exit ${code}; evidence: infrastructure/k6/results/${id}.{json,log}`); process.exitCode = code || 0; });
