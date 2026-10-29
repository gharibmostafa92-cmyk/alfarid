import { existsSync, readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';
import { execFileSync } from 'node:child_process';

const root = fileURLToPath(new URL('../', import.meta.url));
const envPath = resolve(root, '.env');
if (!existsSync(envPath)) {
  let env = readFileSync(resolve(root, '.env.example'), 'utf8');
  for (const key of ['DB_PASSWORD', 'REDIS_PASSWORD', 'HAPROXY_STATS_PASSWORD', 'GRAFANA_PASSWORD']) {
    env = env.replace(new RegExp(`^${key}=$`, 'm'), `${key}=${randomBytes(24).toString('hex')}`);
  }
  env = env.replace(/^APP_KEY=$/m, `APP_KEY=base64:${randomBytes(32).toString('base64')}`);
  writeFileSync(envPath, env, { mode: 0o600 });
  console.log('Created infrastructure/.env with random local credentials. Configure R2 and mail for deployment.');
} else {
  console.log('Kept existing infrastructure/.env.');
}
if (process.argv.includes('--certs')) {
  const certs = resolve(root, 'haproxy/certs');
  mkdirSync(certs, { recursive: true });
  if (!existsSync(resolve(certs, 'demo.pem'))) {
    execFileSync('docker', ['run', '--rm', '--entrypoint', 'sh', '-v', `${certs}:/certs`, 'alfarid-data:local', '-c',
      'openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj /CN=alfarid.example -addext "subjectAltName=DNS:alfarid.example,DNS:api.alfarid.example,DNS:admin.alfarid.example,DNS:pos.alfarid.example" -keyout /tmp/demo.key -out /tmp/demo.crt && cat /tmp/demo.crt /tmp/demo.key > /certs/demo.pem && chmod 600 /certs/demo.pem'], { stdio: 'inherit' });
    console.log('Created a 30-day self-signed demo certificate. Replace it with a trusted certificate for production.');
  }
}
