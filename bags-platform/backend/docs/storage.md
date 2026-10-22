# Uploaded files

All persistent uploads use the `r2` disk: catalog images, generated image sizes,
product videos, customer verification documents, return evidence, supplier
attachments and payment receipts. The filesystem default and Media Library
defaults are fixed to R2. Explicit Media Library uploads to another disk are
rejected. An unavailable R2 connection fails the upload; there is no local fallback.

Set the R2 credentials and endpoint in the server's `.env`. Keep the `alfarid`
key prefix when sharing a bucket with another application. Credentials must never
be exposed through frontend environment variables. `INTEGRATIONS_CA_BUNDLE` may
point to a trusted local certificate bundle when required by the PHP installation;
TLS verification stays enabled.

Catalog resources generate temporary signed URLs when no R2 public CDN URL is
configured. Private business attachments are streamed through their existing
authenticated, authorized download endpoints. Keep the bucket private, including
when configuring a CDN; do not expose the private attachment prefixes publicly.

Check configuration with `php artisan integrations:check`. Add `--live` to verify
R2 upload/download and Gemini connectivity with temporary probe data.

## Existing uploads

Run `php artisan uploads:migrate-to-r2` to copy and verify existing uploads and
update media disk references. Add `--delete-source` to remove local copies only
after SHA-256 verification. The command covers media originals and derivatives,
public uploaded files and the private business attachment directories. It leaves
application certificates and runtime files in place. Repeating the command is
safe; conflicting content or a missing media original stops migration and
preserves the source. Run migration while old upload workers are stopped, then
restart long-running workers with the new application code.

Local/public disks remain defined for legacy migration. Temporary upload buffers,
image processing work files, bundled interface assets and test fixtures are not
persistent user upload storage. Tests use a fake R2 disk and never need live keys.
