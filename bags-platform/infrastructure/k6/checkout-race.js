import http from 'k6/http';
import { check, fail } from 'k6';
import { Counter } from 'k6/metrics';

// Seeded staging only: VARIANT_ID must have exactly one sellable unit.
const BASE = __ENV.BASE_URL;
const VARIANT = Number(__ENV.VARIANT_ID);
const CITY = Number(__ENV.CITY_ID);
const created = new Counter('orders_created');
export const options = {
  scenarios: { race: { executor: 'per-vu-iterations', vus: 50, iterations: 1, maxDuration: '90s' } },
  thresholds: { http_req_failed: ['rate==0'], checks: ['rate==1'], orders_created: ['count==1'] },
};
function uuid() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = Math.floor(Math.random() * 16);
    return (c === 'x' ? r : ((r & 3) | 8)).toString(16);
  });
}
function headers(i, token) {
  return { Accept: 'application/json', 'Content-Type': 'application/json',
    'X-Forwarded-For': `10.88.0.${i + 1}`, 'X-Cart-Session': token };
}
export function setup() {
  if (!BASE || !VARIANT || !CITY) fail('BASE_URL, VARIANT_ID and CITY_ID are required');
  // Prepare all carts before any checkout reserves stock.
  const carts = [];
  for (let i = 0; i < 50; i++) {
    const response = http.post(`${BASE}/api/v1/store/cart/items`,
      JSON.stringify({ product_variant_id: VARIANT, qty: 1 }),
      { headers: { ...headers(i, uuid()), 'Idempotency-Key': uuid() },
        responseCallback: http.expectedStatuses(200, 201) });
    if (!check(response, { 'cart prepared': r => [200, 201].includes(r.status) })) {
      fail(`Cart ${i} failed with HTTP ${response.status}: ${response.body}`);
    }
    carts.push({ token: response.json('data.cart_token'), key: uuid() });
  }
  return carts;
}
export default function(carts) {
  const i = __VU - 1;
  const cart = carts[i];
  created.add(0);
  const body = JSON.stringify({ city_id: CITY, shipping_address_line: '12 Load Test St',
    payment_method: 'cod', recipient_name: `Load Tester ${__VU}`,
    recipient_phone: `0100${String(200000 + __VU * 100).slice(-6)}` });
  const params = { headers: { ...headers(i, cart.token), 'Idempotency-Key': cart.key },
    responseCallback: http.expectedStatuses(200, 201, 409, 422) };
  const response = http.post(`${BASE}/api/v1/store/checkout/`, body, params);
  check(response, { 'checkout succeeds or cleanly rejects unavailable stock': r => [200, 201, 409, 422].includes(r.status) });
  if ([200, 201].includes(response.status)) {
    created.add(1);
    const id = response.json('data.id');
    const replay = http.post(`${BASE}/api/v1/store/checkout/`, body, params);
    check(replay, { 'idempotent replay returns the same order': r => [200, 201].includes(r.status) && r.json('data.id') === id });
  }
}
