import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';

const base = 'http://127.0.0.1:8000';
const valid = new Trend('login_valid_ms', true);
const invalid = new Trend('login_invalid_ms', true);
const shared = new Trend('login_shared_email_ms', true);
const alert = new Trend('login_sendnow_ms', true);
const listing = new Trend('staff_search_ms', true);
export const options = {
  scenarios: { baseline: {
    executor: 'ramping-vus', startVUs: 0,
    stages: [{ duration: '5s', target: 1 }, { duration: '5s', target: 3 }, { duration: '5s', target: 6 }],
    gracefulRampDown: '15s',
  } },
  thresholds: { checks: ['rate==1'] },
};
function login(identifier, password, metric) {
  const response = http.post(`${base}/api/v1/auth/login`, JSON.stringify({ login: identifier, password }), {
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    responseCallback: http.expectedStatuses(200, 401, 429),
  });
  if (metric) metric.add(response.timings.duration);
  return response;
}
export function setup() {
  for (let i = 0; i < 9; i++) login(`alert-setup-${i}@example.test`, 'Wrong-password12');
  const response = login('alert-setup-9@example.test', 'Wrong-password12', alert);
  check(response, { 'sendNow-triggering failure returns 401': r => r.status === 401 });
}
export default function () {
  const kind = (__ITER + __VU) % 4;
  // Spread successful requests over fixture identities; keep the real pair limiter.
  const identifier = `baseline-${((__VU - 1) * 10 + __ITER) % 100 + 1}@example.test`;
  if (kind === 0) {
    const response = login(identifier, 'Baseline-password12', valid);
    check(response, { 'valid staff credentials return 200': r => r.status === 200 });
  } else if (kind === 1) {
    const response = login(`missing-${__VU}-${__ITER}@example.test`, 'Wrong-password12', invalid);
    check(response, { 'bad credentials fail closed': r => [401, 429].includes(r.status) });
  } else if (kind === 2) {
    const response = login(identifier, 'Customer-password34', shared);
    check(response, { 'historical shared email resolves matching customer': r => r.status === 200 && r.json('actor.type') === 'customer' });
  } else {
    const response = http.get(`${base}/api/v1/admin/users?search=Baseline&per_page=15`, {
      headers: { Authorization: `Bearer ${__ENV.BASELINE_STAFF_TOKEN}`, Accept: 'application/json' },
    });
    listing.add(response.timings.duration);
    check(response, { 'search across 50000 staff returns 15 rows': r => r.status === 200 && r.json('data').length === 15 });
  }
  sleep(0.1);
}
