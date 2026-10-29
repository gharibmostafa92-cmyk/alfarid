#!/usr/bin/env bash
# Fill slugs.json from staging (run once before k6 browse tests).
set -euo pipefail
BASE=${BASE_URL:?set BASE_URL}
curl -fsS "${BASE}/api/v1/store/products?per_page=48" \
  | php -r '$j=json_decode(stream_get_contents(STDIN),true); foreach (($j["data"]??[]) as $p) echo $p["slug"]."\n";' \
  | head -n 500 | php -r 'echo json_encode(array_values(array_filter(array_map("trim",explode("\n",stream_get_contents(STDIN))))),JSON_PRETTY_PRINT);' \
  > "$(dirname "$0")/slugs.json"
echo "wrote slugs.json"
