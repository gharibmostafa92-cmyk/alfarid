import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE = __ENV.BASE_URL;
const SLUGS = JSON.parse(open('./slugs.json'));
if (!BASE || !Array.isArray(SLUGS) || SLUGS.length === 0) throw new Error('BASE_URL and nonempty slugs.json are required');

export const options = {
  // Avoid one Prometheus percentile series per product URL; this is the mixed
  // browse latency for the scenario, matching the summary's global percentile.
  systemTags: ['method', 'scenario'],
  summaryTrendStats: ['min', 'med', 'avg', 'p(95)', 'p(99)', 'max'],
  scenarios: {
    browse: {
      executor: 'ramping-vus',
      // Overridable for bounded runs; defaults are the plan's canonical matrix.
      stages: [
        { duration: __ENV.STAGE1_DUR || '2m', target: parseInt(__ENV.STAGE1_VUS || '200', 10) },
        { duration: __ENV.STAGE2_DUR || '5m', target: parseInt(__ENV.STAGE2_VUS || '500', 10) },
        { duration: __ENV.STAGE3_DUR || '2m', target: parseInt(__ENV.STAGE3_VUS || '0', 10) },
      ],
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<800'],
    checks: ['rate==1'],
  },
};

function fakeIp() {
  return `10.${__VU % 250}.${Math.floor(Math.random() * 250)}.${(__ITER % 250) + 1}`;
}

export default function () {
  const h = { headers: { 'X-Forwarded-For': fakeIp(), Accept: 'application/json' } };
  check(http.get(`${BASE}/api/v1/store/home`, h), { home: (r) => r.status === 200 });
  check(http.get(`${BASE}/api/v1/store/products?sort=newest`, h), { list: (r) => r.status === 200 });
  const s = SLUGS[Math.floor(Math.random() * SLUGS.length)];
  check(http.get(`${BASE}/api/v1/store/products/${s}`, h), { pdp: (r) => r.status === 200 });
  sleep(1);
}
