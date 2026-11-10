# Alfarid Cloud Run pilot with Neon

This runbook uses Neon Free for PostgreSQL, one IAM-private Laravel API on Cloud Run, and two optional Cloud Scheduler triggers for bounded Cloud Run Jobs. The pilot uses disposable data. Production promotion requires the original release checks for all frontends, R2, media and WhatsApp, the full business schedule, and backup/restore.

Cloud Scheduler invokes the Jobs API with scoped IAM, so both tasks can reuse the API image and exit after each run. A Compute Engine e2-micro would need a separate network-cost check for Neon access, including any external IPv4 charges. GitHub Actions adds a separate identity and can delay or drop scheduled runs under load. [VM IPv4 pricing](https://cloud.google.com/vpc/pricing-announce-external-ips) · [GitHub scheduling](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#schedule)

## Cost gate before any cloud write

- Verify a dedicated billed Google Cloud project, a regional private Artifact Registry repository, the billing account's existing free-tier usage and project budget alerts. Enable `run.googleapis.com`, `artifactregistry.googleapis.com`, `secretmanager.googleapis.com` and `cloudscheduler.googleapis.com` only when needed. Keep the rollout local if a delayed overage is unacceptable. [Google Cloud Free Tier](https://docs.cloud.google.com/free/docs/free-cloud-features) · [Budgets](https://docs.cloud.google.com/billing/docs/how-to/budgets)
- Scheduler has three free job definitions per billing account per month, including paused definitions. This pilot uses two. Verify room before creating either. Deleting unused Scheduler definitions frees future job-days. [Scheduler pricing](https://cloud.google.com/scheduler/pricing)
- The API uses request-based billing and scales to zero. Jobs have a 60-second billing minimum per task and use instance-based rates. The published Jobs allowance at Iowa rates is 240,000 vCPU-seconds and 450,000 GiB-seconds monthly. Check the billing account's effective remaining discount across usage types. A 10-minute Job around the clock alone consumes at least 267,840 vCPU-seconds in a 31-day month at 1 vCPU. [Cloud Run pricing](https://cloud.google.com/run/pricing)
- Default recurring window is Monday–Friday, 10:00–10:59 Africa/Cairo. Queue at :00, :10, :20, :30, :40, :50. Laravel schedule at :05, :10, …, :55. With up to 23 weekdays, 1 vCPU, and task timeouts of 120/240 seconds, the timeout-based estimate is **77,280 vCPU-seconds** and **38,640 GiB-seconds** at 512 MiB. Include startup/shutdown, duplicate dispatch, manual runs, and other Cloud Run usage. This estimate is a planning input. Monitor actual settled costs and project 31 days before increasing the window.
- Check the compressed unique Registry layers, including rollback overlap, against the shared 0.5 GiB-month allowance. Start with one API image shared by the API and Jobs. Check Secret Manager active versions and accesses, Cloud Logging and eligible internet egress. Check egress to Neon and Egypt by SKU. Build locally to avoid Cloud Build minutes. [Registry pricing](https://cloud.google.com/artifact-registry/pricing) · [Secret pricing](https://cloud.google.com/secret-manager/pricing)
- Neon currently advertises 100 CU-hours, 1 GB PostgreSQL storage and 5 GB monthly network transfer per Free project. A 0.25 CU compute kept warm 24/7 would use about 186 CU-hours in a 31-day month. Check actual configured size, suspend behavior, storage and egress in the Neon dashboard. Run without a keepalive or an automated DB readiness poll. [Neon pricing](https://neon.com/pricing) · [Scale to zero](https://neon.com/docs/introduction/scale-to-zero)
- Keep projected usage below 70% of each remaining allowance. Check cost by SKU after credits settle. Use the stop procedure on a forecast breach or unexpected charge. Google's billing budgets and optional spend caps have reporting lag. [Cloud Run spend caps](https://docs.cloud.google.com/billing/docs/how-to/budgets-spend-caps)

## 1. Prepare Neon and the release image

1. Create a disposable Neon Free project in `eu-central-1` and deploy Cloud Run in `europe-west3`. A Neon host in Frankfurt with the API in `us-central1` adds about 110ms to every query, which turned a branding request into 5 seconds. Use its direct host (no `-pooler`) and PostgreSQL version compatible with the source. Verify `pg_trgm` support and rehearse a backup and restore before moving live data.
2. Use a migration owner with DDL access and a distinct runtime login. Grant the runtime role only the app's required DML on the migrated objects, usage on the schema and required sequences, and appropriate default privileges for future objects. Rehearse both roles on fresh/sanitized data.
3. Use the runtime's system CA bundle and `DB_SSLMODE=verify-full`. The deploy script supplies both `DB_SSLROOTCERT` and the libpq `PGSSLROOTCERT`. Verify in the built image that the valid Neon hostname connects and a wrong CA/hostname fails. Use the direct endpoint for both roles in the pilot.
4. Pick a clean reviewed commit containing the existing opt-out for the Redis readiness check. The pilot uses database cache, limiter, sessions and locks. Verify the schema contains `cache`, `cache_locks`, `sessions` and `jobs`. Laravel readiness should report Redis skipped and return 503 when DB is unavailable. Preserve the default Redis behavior for other environments.
5. In `bags-platform/`, build locally. On a clean checkout, use a local runtime-only env file containing migration credentials for two serialized rehearsal runs. Keep it out of the repo, shell history and image. Never use the migration login for the deployed API.

```powershell
docker buildx build --platform linux/amd64 -f infrastructure/docker/app.Dockerfile -t alfarid-api:pilot --load .
docker run --rm --entrypoint php alfarid-api:pilot -m
docker run --rm --entrypoint composer alfarid-api:pilot check-platform-reqs --no-dev
docker run --rm --env-file $migrationEnvPath --entrypoint php alfarid-api:pilot artisan migrate --force
docker run --rm --env-file $migrationEnvPath --entrypoint php alfarid-api:pilot artisan migrate --force
docker run --rm --env-file $migrationEnvPath --entrypoint php alfarid-api:pilot artisan migrate:status
```

Check for `pcntl` and `pdo_pgsql`. The second migrate run must report nothing to migrate. Validate the image contents and its compressed layer sizes before publishing. Authenticate Docker with `gcloud auth configure-docker REGION-docker.pkg.dev`, tag with the release SHA, push to the same-region private repo, and record the immutable digest.

Run the storage gate immediately before each push. It sums repository storage across billed projects and adds the full local image size, without assuming any deduplication. It stops the push workflow if a measurement fails or the total exceeds 70% of 0.5 GiB. Check the billing report separately for usage already accrued this month. Backoffice and POS on Cloudflare Pages need no Registry image.

```powershell
./infrastructure/cloud-run/check-registry-budget.ps1 -ProjectId PROJECT -Image alfarid-storefront:pages-pilot
# Push only if the gate passes.
```

## 2. Deploy the IAM-private pilot

Log in with `gcloud auth login` and verify access to the dedicated project. Provision a runtime service account with Secret Manager accessor on the two required secrets only. Store the runtime DB password and stable `APP_KEY` as separate pinned versions. The deployment identity needs permission to deploy and act as the runtime identity. Give the separate Scheduler service account `roles/run.invoker` on the two Jobs individually after deployment. Keep the Cloud Scheduler service agent role intact. Use a separate tester identity with invoker access to the API. [Run Jobs IAM](https://docs.cloud.google.com/run/docs/execute/jobs-on-schedule)

Run these commands from `bags-platform/`. Replace all placeholders locally. The settings below contain references only.

```powershell
$pilot = @{
    ProjectId = 'your-alfarid-pilot-project'
    Region = 'europe-west3'
    Image = 'europe-west3-docker.pkg.dev/your-alfarid-pilot-project/private/api@sha256:REPLACE'
    RuntimeServiceAccount = 'alfarid-runtime@your-alfarid-pilot-project.iam.gserviceaccount.com'
    SchedulerServiceAccount = 'alfarid-scheduler@your-alfarid-pilot-project.iam.gserviceaccount.com'
    DatabaseHost = 'ep-your-direct-host.us-east-2.aws.neon.tech'
    DatabaseName = 'alfarid'
    DatabaseUser = 'alfarid_runtime'
    AppKeySecret = 'alfarid-app-key:1'
    DatabasePasswordSecret = 'alfarid-neon-password:1'
}
./infrastructure/cloud-run/pilot.ps1 -Action Plan
./infrastructure/cloud-run/pilot.ps1 -Action DeployApi @pilot
./infrastructure/cloud-run/pilot.ps1 -Action DeployJobs @pilot
```

The script targets the named pilot resources in the explicitly selected project. Use it only on the dedicated pilot project. `DeployApi` and `DeployJobs` replace environment bindings with the pilot profile. They deploy definitions without enabling recurring execution. The private pilot trusts no forwarded headers (`TRUSTED_PROXIES=none`) and uses `MAIL_MAILER=array` to discard test mail. Set these same values in the local production-mode migration env file. For public rollout, validate proxy handling and configure the approved mailer first. Use Cloud Run's authenticated console or a tester identity token to verify `/up`, `/health/ready`, and a DB-backed read. Confirm an anonymous request is rejected by Cloud Run. Check warm and cold latency from Egypt, and verify the two jobs use the same digest and pinned secrets.

## 3. Verify Jobs before turning on either trigger

1. Use a benign test payload on the default queue in the disposable DB. Run `gcloud run jobs execute alfarid-pilot-queue --project PROJECT --region REGION --wait`. Check the job's result and failed-job table. Repeat on an empty queue and verify it exits. Test a killed task and redelivery after the 180-second DB queue lease. The command checks elapsed time between jobs, so the 120-second platform deadline bounds the task independently of `--max-time=50`.
2. Run `gcloud run jobs execute alfarid-pilot-schedule --project PROJECT --region REGION --wait` on a test tick. Confirm actual due commands in execution logs. A successful Scheduler dispatch means the Run API accepted the request. Check the Run execution status for command failures. Rehearse overlapping dispatch with DB queue reservations and Laravel's shared locks. Delayed execution can miss a due-minute check. Track scheduled time versus actual start and rehearse manual replay of missed jobs.
3. The pilot scheduler starts at :05 to skip top-of-hour jobs that assume R2 or production integrations. Daily, weekly and monthly commands outside the window remain a production release gate. The default worker consumes only the default queue. Leave long media conversions, media and WhatsApp queues, live webhooks, and real notifications outside this pilot until their separate runtime and cost profiles pass. Use manual, individually bounded Run Jobs for media rehearsal if needed. Do not enqueue real work that this pilot cannot process.
4. Verify at least two available Scheduler slots and the cost gate before enabling the triggers. Enable the queue trigger first and inspect Run/Neon metrics before enabling the Laravel trigger.

```powershell
./infrastructure/cloud-run/pilot.ps1 -Action EnableQueue @pilot
./infrastructure/cloud-run/pilot.ps1 -Action EnableLaravelSchedule @pilot
```

These two commands create the named trigger definitions. For an existing paused trigger use `gcloud scheduler jobs resume NAME --location REGION --project PROJECT` after rechecking the cost gate. Scheduler uses OAuth for the Google Run Jobs API, so there is no queue endpoint on the public application.

## 4. Pause or promote

- Pause each Scheduler trigger using `-Action PauseQueue` and `-Action PauseLaravelSchedule`. Verify no new executions and inspect any in-flight execution. Pause the private API with `gcloud run services update alfarid-api --scaling=0 --project PROJECT --region REGION`. Remove traffic tags if added and check tagged and default URLs. Paused Scheduler jobs, retained Registry layers and active secret versions still count toward their allowances.
- Compare the three frontends' image and egress budgets before publishing their images. Deploy one at a time using the existing Dockerfiles and exact build-time origins. Rehearse CSP/CORS, app authorization, POS handoff, checkout, R2 and rollback before public access or real traffic. Test compatible old/new revisions against the Neon schema.
- Move live data only after a backup/restore rehearsal, the complete business schedule and all required queues/integrations pass. Preserve the existing `APP_KEY` for data migration and keep the source read-only during cutover. After new writes, reconcile them before switching back to the original database.

## Pilot frontend entry points

Storefront runs on Cloud Run with request-based billing, zero minimum instances and one maximum instance. It forwards selected `/api/v1` routes to the private API using a dedicated invoker service account. The proxy sends the Cloud Run identity separately from the user's application token. Use the exact production origins for CORS, and keep the API's IAM invoker check enabled.

Backoffice deploys its Vite build to Cloudflare Pages. The POS Pages project deploys [pos-pages](pos-pages/) and redirects to the connected POS at Backoffice's `/pos`. The standalone `apps/pos` build uses a mock service for its sales; keep it out of the pilot release. Both frontend entry points share the existing staff sign-in flow.

## Managed Redis cache experiment

Use a separate Google Cloud project with a disposable Neon database. Start from the IAM-private database-only API above. Keep its image digest and baseline revision ID, and verify that the base URL returns HTTP 200 at `/health/ready` with `checks.db=ok` and `checks.redis=skipped`. Leave pilot schedulers paused. The two serving Alfarid regions are outside this experiment.

Provision an Upstash database near the pilot region with eviction disabled and no read replicas. Use a region-selectable database for latency measurements. Upstash's anonymous scratch database expires after 72 hours and is useful for protocol checks with synthetic values. Store the Redis TCP password in the pilot project's Secret Manager as `alfarid-pilot-redis-password` and grant the pilot runtime identity access to that secret. Pass only its numeric version to the command.

In PowerShell, set `$PilotProject`, `$PilotRegion`, `$PilotImage` (the baseline digest), `$RedisHost` (hostname only), `$RedisPort` and `$RedisSecretVersion`. Verify the inherited service has no active `REDIS_URL` or `REDIS_USERNAME` override when using this password-only setup. Keep `SESSION_DRIVER`, `QUEUE_CONNECTION`, `CACHE_LIMITER` and `APP_MAINTENANCE_STORE` set to `database`.

```powershell
$redisEnv = "REDIS_CLIENT=phpredis,REDIS_HOST=tls://$RedisHost,REDIS_PORT=$RedisPort,REDIS_DB=0,REDIS_CACHE_DB=0,REDIS_PREFIX=alfarid-redis-pilot:,REDIS_TIMEOUT=2,REDIS_READ_TIMEOUT=2,REDIS_MAX_RETRIES=0,REDIS_PERSISTENT=false,READINESS_CHECK_REDIS=true"
$redisSecret = "REDIS_PASSWORD=alfarid-pilot-redis-password:$RedisSecretVersion"
gcloud run deploy alfarid-api --project $PilotProject --region $PilotRegion --image $PilotImage --no-traffic --tag redis-connected --update-env-vars "$redisEnv,CACHE_STORE=database" --update-secrets $redisSecret
```

1. Call the tagged URL with an authorized identity using the base service URL as token audience. Expect HTTP 200 and `checks.redis=ok`. From the same image verify `default`, `cache` and `health` Redis connections, plus a Laravel cache write/read/delete and expiration. With a test credential or hostname mismatch, expect HTTP 503 and `checks.redis=fail` on separate tagged revisions. Keep tokens and configuration dumps out of logs.
2. Repeat the positive deploy command with `--tag redis-cache` and `CACHE_STORE=redis`. Reapply the full positive env and secret binding to overwrite any negative-test configuration inherited from the latest revision. Verify zero traffic on the base URL, then check that a Laravel cache lock can be acquired by only one of two workers and only released by its owner.
3. Compare the baseline with the Redis revision using `GET /api/v1/store/branding` and `GET /api/v1/store/home`. Measure the first request after scale-to-zero and p50/p95 for 100 warm requests per route and variant. Check one-time staff handoff with a synthetic account. Record errors, Upstash command and bandwidth deltas, and available Neon workload metrics. Consider a rollout only if the trial adds no application errors, increases neither route's p95 by more than 10%, and measurably lowers p95 or Neon load. For the free-tier pilot, keep the monthly projections below 350,000 commands and 7 GB.
4. For rollback rehearsal, briefly route the isolated pilot service to the tested revision at 100% and smoke test the base URL. Route back to the recorded baseline with `gcloud run services update-traffic alfarid-api --project $PilotProject --region $PilotRegion --to-revisions "${BaselineRevision}=100"`. Confirm baseline traffic and `checks.redis=skipped`, then remove the test tags. A live rollout requires mapping and coordinating every API and job that uses the same default cache state.

Preflight on 2026-11-10 passed against a temporary Upstash instance over native TCP/TLS. Redis accepted password authentication, `SELECT 0` and `PING`. Two independent connections verified expiration, lock contention and owner-only Lua release. The provider rejected an invalid password and the TLS client rejected a mismatched hostname. This was a protocol check. The Laravel/Cloud Run experiment and performance comparison require the separate project and disposable Neon database.

[Upstash Laravel setup](https://upstash.com/docs/redis/quickstarts/laravel) · [Upstash scratch Redis](https://upstash.com/docs/redis/overall/getstarted) · [Cloud Run revision routing](https://docs.cloud.google.com/run/docs/rollouts-rollbacks-traffic-migration)
