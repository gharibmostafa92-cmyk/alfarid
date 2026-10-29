import { writeFileSync } from 'node:fs';
const api = new URL(process.env.VITE_API_URL);
if (!['http:', 'https:'].includes(api.protocol)) throw new Error('Invalid API origin');
const policy = `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: blob: https: ${api.origin}; connect-src 'self' ${api.origin}; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'`;
writeFileSync('apps/pos/dist/security-headers.nginx-map.conf', '# POS has no cross-origin session frame.\n');
writeFileSync('apps/pos/dist/security-headers.nginx-server.conf', `add_header Content-Security-Policy "${policy}" always;\nadd_header X-Content-Type-Options "nosniff" always;\n`);
