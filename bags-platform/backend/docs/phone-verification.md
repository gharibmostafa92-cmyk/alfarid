# Phone verification during registration

Retail and wholesale registration use the same phone verification flow. The storefront
reads `GET /api/v1/store/auth/registration-options` before accepting registration.
The response contains only `phone_verification_required` and the public
`turnstile_site_key`, with `Cache-Control: no-store, private`.

When `CUSTOMER_REQUIRE_PHONE_VERIFICATION=true`, configure `TURNSTILE_SITE_KEY`
and `TURNSTILE_SECRET_KEY` on the API server using a Cloudflare widget registered
for the storefront hostname. Restart queue workers and refresh Laravel's config
cache after changing deployment configuration. Never put the secret in a
`NEXT_PUBLIC_*` variable. No frontend environment key is needed.

The browser completes Turnstile and sends its token with the phone number to
`POST /api/v1/store/auth/phone-verification/send`. The API validates the token
before queuing SMS, retaining the existing network quotas and send throttles.
The browser exchanges the six-digit OTP for a proof at
`POST /api/v1/store/auth/phone-verification/verify`, then includes that proof as
`phone_verification_token` in JSON retail registration or multipart wholesale
registration. Changing the number clears the proof. The proof expires after
ten minutes; the API remains authoritative and consumes it once.

Turnstile is refreshed after each send attempt because its token is single use.
Script errors and expired challenges allow retry. Missing configuration blocks
required verification with a visible error. With phone verification disabled,
registration proceeds without loading Turnstile. The storefront CSP retains
nonce-based scripts and permits Cloudflare's challenge iframe.

References: [Cloudflare widget configuration](https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/widget-configurations/)
and [CSP requirements](https://developers.cloudflare.com/turnstile/reference/content-security-policy/).

Verification:

```sh
php artisan test --compact --filter=PhoneRegistrationTest
```

From `frontend`, with the storefront running on port 3001:

```sh
node tests/phone-verification.e2e.cjs
```

The browser test mocks Cloudflare and the API; backend feature tests independently
verify that missing/rejected tokens and verifier outages never queue an SMS.
