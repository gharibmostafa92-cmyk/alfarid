# Uploaded files

Private business uploads use the `r2` disk. Catalog images, generated sizes and
product videos use `MEDIA_CATALOG_DISK`: the default `r2_public` provides stable
URLs from a separate public bucket, while an explicit `r2` selection uses the
existing private bucket with signed URLs. Production rejects uploads to local
disks. Local development may explicitly select `public`; a failed R2 upload
never silently falls back to local storage.

Set the R2 credentials and endpoint in the server's `.env`. Keep the `alfarid`
key prefix when sharing a bucket with another application. Credentials must never
be exposed through frontend environment variables. `INTEGRATIONS_CA_BUNDLE` may
point to a trusted local certificate bundle when required by the PHP installation;
TLS verification stays enabled.

Catalog resources on `r2` generate temporary signed URLs. Resources on
`r2_public` use its configured public URL. Private business attachments use their existing
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

Use `--catalog-only` to migrate catalog media without processing private
attachments or orphan files. The migration respects an explicit private `r2`
catalog destination; the public destination still requires a separate bucket.

Local/public disks remain defined for legacy migration. Temporary upload buffers,
image processing work files, bundled interface assets and test fixtures are not
persistent user upload storage. Tests use a fake R2 disk and never need live keys.
