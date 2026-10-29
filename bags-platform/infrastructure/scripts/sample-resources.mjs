import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
const exec = promisify(execFile);
const duration = Number(process.argv[2] || 240);
const samples = [];
const started = Date.now();
while (Date.now() - started < duration * 1000) {
  const { stdout } = await exec('docker', ['stats', '--no-stream', '--format', '{{json .}}']);
  samples.push({ at: new Date().toISOString(), containers: stdout.trim().split('\n').filter(Boolean)
    .map(line => JSON.parse(line)).filter(row => row.Name.startsWith('alfarid-lb-')) });
  writeFileSync(fileURLToPath(new URL('../k6/results/docker-resources.json', import.meta.url)), JSON.stringify(samples, null, 2));
  await new Promise(resolve => setTimeout(resolve, 5000));
}
console.log(`Recorded ${samples.length} resource samples`);
