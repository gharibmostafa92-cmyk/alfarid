#Requires -Version 7.0
[CmdletBinding()]
param(
    [ValidateSet('Plan', 'DeployApi', 'DeployAi', 'DeployJobs', 'EnableQueue', 'EnableLaravelSchedule', 'EnableCustomerEmailDelivery', 'PauseQueue', 'PauseLaravelSchedule', 'PauseCustomerEmailDelivery')]
    [string]$Action = 'Plan',
    [ValidatePattern('^[a-z][a-z0-9-]{4,28}[a-z0-9]$')]
    [string]$ProjectId,
    [ValidatePattern('^[a-z]+(?:-[a-z]+)+[0-9]+$')]
    [string]$Region = 'us-central1',
    [string]$Image,
    [string]$RuntimeServiceAccount,
    [string]$CustomerEmailServiceAccount,
    [string]$SchedulerServiceAccount,
    [string]$DatabaseHost,
    [string]$DatabaseName,
    [string]$DatabaseUser,
    [string]$AppKeySecret,
    [string]$DatabasePasswordSecret,
    [string]$GeminiApiKeySecret,
    [string]$GeminiModel,
    [string]$ProxyClientIpSecret,
    [string]$ResendApiKeySecret,
    [string]$ResendFromAddress,
    [ValidateSet('staging', 'production')]
    [string]$ResendEnvironment = 'staging',
    [ValidateSet('true', 'false')]
    [string]$CustomerEmailCaptureEnabled = 'false',
    [ValidatePattern('^[0-9*/,-]+(?: [0-9*/,-]+){4}$')]
    [string]$CustomerEmailCron = '2-59/10 10 * * 1-5',
    [string]$SentryDsnSecret,
    [ValidateSet('staging', 'production')]
    [string]$SentryEnvironment = 'staging',
    [string]$SentryRelease,
    [ValidateRange(0.0, 1.0)]
    [double]$SentryTraceSampleRate = 0.05,
    [ValidateNotNullOrEmpty()]
    [ValidatePattern('^(?!log$|failover$|array$).+$')]
    [string]$MailMailer = 'smtp',
    [string]$MailHost,
    [int]$MailPort = 587,
    [ValidateNotNullOrEmpty()]
    [ValidatePattern('^(?!\*$|none$).+$')]
    [string]$TrustedProxies = '169.254.1.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'
)

$ErrorActionPreference = 'Stop'
$queueJob = 'alfarid-pilot-queue'
$laravelJob = 'alfarid-pilot-schedule'
$customerEmailJob = 'alfarid-pilot-customer-email'
$queueCron = '*/10 10 * * 1-5'
$laravelCron = '5-55/5 10 * * 1-5'
$queueTimeout = 120
$laravelTimeout = 240
$customerEmailTimeout = 50

if ($Action -eq 'Plan') {
    [pscustomobject]@{
        TimeZone = 'Africa/Cairo'
        QueueCron = $queueCron
        LaravelCron = $laravelCron
        QueueTimeoutSeconds = $queueTimeout
        LaravelTimeoutSeconds = $laravelTimeout
        CustomerEmailCron = $CustomerEmailCron
        CustomerEmailTimeoutSeconds = $customerEmailTimeout
        ApiConcurrency = 8
        ApiMaxInstances = 10
        AiConcurrency = 2
        AiMaxInstances = 4
        MonthlyScheduledVcpuSecondsAtTaskTimeout = 23 * (6 * $queueTimeout + 11 * $laravelTimeout)
    }
    return
}

if (!$ProjectId) { throw 'ProjectId must identify the dedicated Google Cloud pilot project.' }

function Invoke-Gcloud {
    param([string[]]$Arguments)

    & gcloud @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "gcloud $($Arguments[0..2] -join ' ') exited with code $LASTEXITCODE."
    }
}

function Join-GcloudEnvVars([string[]]$Entries) {
    # Cloud Run's default comma separator conflicts with TRUSTED_PROXIES CIDR lists.
    # Use gcloud's documented custom delimiter and reject ambiguous values.
    if ($Entries | Where-Object { $_ -match '[~\r\n]' }) {
        throw 'Environment variable values may not contain the reserved ~ delimiter or newlines.'
    }
    return '^~^' + ($Entries -join '~')
}

$scope = @("--project=$ProjectId", "--region=$Region")
switch ($Action) {
    { $_ -in 'DeployApi', 'DeployAi', 'DeployJobs' } {
        $registryPattern = '^{0}-docker\.pkg\.dev/{1}/[a-z0-9_-]+/[a-z0-9_./-]+@sha256:[0-9a-f]{{64}}$' -f $Region, $ProjectId
        if ($Image -cnotmatch $registryPattern) {
            throw 'Image must be a digest reference in the pilot project regional Artifact Registry.'
        }
        if ($RuntimeServiceAccount -cnotmatch "^[a-z][a-z0-9-]+@$ProjectId\.iam\.gserviceaccount\.com$") {
            throw 'RuntimeServiceAccount must be a dedicated service account in the pilot project.'
        }
        if ($DatabaseHost -cnotmatch '^ep-[a-z0-9-]+(?:\.[a-z0-9-]+)+\.neon\.tech$' -or $DatabaseHost -match '-pooler\.') {
            throw 'DatabaseHost must be the direct Neon endpoint hostname.'
        }
        if ($DatabaseHost -match '\.eu-central-1\.aws\.neon\.tech$' -and $Region -ne 'europe-west3') {
            throw 'This Neon host is in eu-central-1. Deploy the API in europe-west3. us-central1 adds a transatlantic round trip to every query.'
        }
        if ($DatabaseName -cnotmatch '^[a-zA-Z0-9_.-]+$' -or $DatabaseUser -cnotmatch '^[a-zA-Z0-9_.-]+$') {
            throw 'DatabaseName and DatabaseUser must contain only letters, numbers, dots, underscores or hyphens.'
        }
        $secretPattern = '^[a-zA-Z0-9_-]+:[1-9][0-9]*$'
        if ($AppKeySecret -cnotmatch $secretPattern -or $DatabasePasswordSecret -cnotmatch $secretPattern) {
            throw 'AppKeySecret and DatabasePasswordSecret must be names with pinned numeric versions.'
        }
        if ($ResendFromAddress -and ($ResendFromAddress.Length -gt 255 -or $ResendFromAddress -cnotmatch '^[^@\s<>;,]+@[^@\s<>;,]+\.[^@\s<>;,]+$')) {
            throw 'ResendFromAddress must be one valid email address on a verified Resend domain.'
        }

        $environment = @(
            'APP_ENV=production', 'APP_DEBUG=false', 'AUTORUN_ENABLED=false',
            "TRUSTED_PROXIES=$TrustedProxies", "MAIL_MAILER=$MailMailer",
            'DB_CONNECTION=pgsql', "DB_HOST=$DatabaseHost", 'DB_PORT=5432',
            "DB_DATABASE=$DatabaseName", "DB_USERNAME=$DatabaseUser", 'DB_SSLMODE=verify-full',
            'PGSSLROOTCERT=/etc/ssl/certs/ca-certificates.crt',
            'DB_SSLROOTCERT=/etc/ssl/certs/ca-certificates.crt',
            'CACHE_STORE=database', 'CACHE_LIMITER=database', 'SESSION_DRIVER=array',
            'APP_MAINTENANCE_DRIVER=cache', 'APP_MAINTENANCE_STORE=database',
            'READINESS_CHECK_REDIS=false', 'QUEUE_CONNECTION=database', 'LOG_STACK=stderr'
        )
        if ($MailHost) {
            $environment += @("MAIL_HOST=$MailHost", "MAIL_PORT=$MailPort")
        }
        $secrets = "APP_KEY=$AppKeySecret,DB_PASSWORD=$DatabasePasswordSecret"
        $runtime = @("--image=$Image", "--service-account=$RuntimeServiceAccount", '--cpu=1', '--memory=512Mi', "--set-secrets=$secrets")

        if ($Action -eq 'DeployApi') {
            if ($ProxyClientIpSecret -cnotmatch $secretPattern) {
                throw 'DeployApi requires a pinned ProxyClientIpSecret.'
            }
            if ($CustomerEmailCaptureEnabled -eq 'true' -and !$ResendFromAddress) {
                throw 'DeployApi requires a verified Resend sender address when customer email capture is enabled.'
            }
        if ($SentryDsnSecret -and ($SentryDsnSecret -cnotmatch $secretPattern -or $SentryRelease -cnotmatch '^[a-fA-F0-9]{40}([a-fA-F0-9]{24})?$')) {
            throw 'SentryDsnSecret must be pinned and SentryRelease must be a full 40 or 64 character commit SHA.'
        }
        $sentryRuntimeEntries = @()
        if ($SentryDsnSecret) {
            $sentryRuntimeEntries = @(
                "SENTRY_ENVIRONMENT=$SentryEnvironment",
                "SENTRY_RELEASE=$SentryRelease",
                "SENTRY_TRACES_SAMPLE_RATE=$($SentryTraceSampleRate.ToString([System.Globalization.CultureInfo]::InvariantCulture))"
            )
        }
        $apiCustomerEmailEntries = @(
            "RESEND_FROM_ADDRESS=$ResendFromAddress",
            "RESEND_ENVIRONMENT=$ResendEnvironment",
            "CUSTOMER_EMAIL_CAPTURE_ENABLED=$CustomerEmailCaptureEnabled",
            'CUSTOMER_EMAIL_DELIVERY_ENABLED=false',
            'CUSTOMER_EMAIL_SCHEDULE_ENABLED=false'
        )
        $apiEnvironment = Join-GcloudEnvVars ($environment + @('PHP_FPM_PM_MAX_CHILDREN=8') + $apiCustomerEmailEntries + $sentryRuntimeEntries + $(if ($SentryDsnSecret) { @('SENTRY_SERVICE=api') } else { @() }))
            $apiRuntime = $runtime | Where-Object { $_ -notlike '--set-secrets=*' }
        $apiSecrets = "$secrets,PROXY_CLIENT_IP_SECRET=$ProxyClientIpSecret"
        if ($SentryDsnSecret) { $apiSecrets += ",SENTRY_LARAVEL_DSN=$SentryDsnSecret" }
        $apiRuntime += "--set-secrets=$apiSecrets"
            Invoke-Gcloud -Arguments (@(
                'run', 'deploy', 'alfarid-api', '--no-allow-unauthenticated', '--invoker-iam-check',
                '--cpu-throttling', '--no-cpu-boost', '--min=0', '--max=10', '--concurrency=8',
                '--port=8080', '--timeout=60s', "--set-env-vars=$apiEnvironment"
            ) + $scope + $apiRuntime)
            return
        }
        if ($Action -eq 'DeployAi') {
            if ($GeminiApiKeySecret -cnotmatch $secretPattern -or $ProxyClientIpSecret -cnotmatch $secretPattern -or $GeminiModel -cnotmatch '^[a-zA-Z0-9_-]+$') {
                throw 'DeployAi requires pinned Gemini and proxy signing secrets and a model name.'
            }
            if ($SentryDsnSecret -and ($SentryDsnSecret -cnotmatch $secretPattern -or $SentryRelease -cnotmatch '^[a-fA-F0-9]{40}([a-fA-F0-9]{24})?$')) {
                throw 'SentryDsnSecret must be pinned and SentryRelease must be a full 40 or 64 character commit SHA.'
            }
            $sentryRuntimeEntries = @()
            if ($SentryDsnSecret) {
                $sentryRuntimeEntries = @(
                    "SENTRY_ENVIRONMENT=$SentryEnvironment",
                    "SENTRY_RELEASE=$SentryRelease",
                    "SENTRY_TRACES_SAMPLE_RATE=$($SentryTraceSampleRate.ToString([System.Globalization.CultureInfo]::InvariantCulture))"
                )
            }
            $aiEnvironment = Join-GcloudEnvVars ($environment + @('PHP_FPM_PM_MAX_CHILDREN=2', "GEMINI_MODEL=$GeminiModel") + $sentryRuntimeEntries + $(if ($SentryDsnSecret) { @('SENTRY_SERVICE=ai') } else { @() }))
            $aiRuntime = $runtime | Where-Object { $_ -notlike '--set-secrets=*' }
            $aiSecrets = "$secrets,GEMINI_API_KEY=$GeminiApiKeySecret,PROXY_CLIENT_IP_SECRET=$ProxyClientIpSecret"
            if ($SentryDsnSecret) { $aiSecrets += ",SENTRY_LARAVEL_DSN=$SentryDsnSecret" }
            $aiRuntime += "--set-secrets=$aiSecrets"
            Invoke-Gcloud -Arguments (@(
                'run', 'deploy', 'alfarid-ai', '--no-allow-unauthenticated', '--invoker-iam-check',
                '--cpu-throttling', '--no-cpu-boost', '--min=0', '--max=4', '--concurrency=2',
                '--port=8080', '--timeout=180s', "--set-env-vars=$aiEnvironment"
            ) + $scope + $aiRuntime)
            return
        }

        if ($SentryDsnSecret -and ($SentryDsnSecret -cnotmatch $secretPattern -or $SentryRelease -cnotmatch '^[a-fA-F0-9]{40}([a-fA-F0-9]{24})?$')) {
            throw 'SentryDsnSecret must be pinned and SentryRelease must be a full 40 or 64 character commit SHA.'
        }
        $customerEmailRequested = [bool]$CustomerEmailServiceAccount -or [bool]$ResendApiKeySecret -or [bool]$ResendFromAddress
        if ($customerEmailRequested) {
            if ($CustomerEmailServiceAccount -cnotmatch "^[a-z][a-z0-9-]+@$ProjectId\.iam\.gserviceaccount\.com$") {
                throw 'CustomerEmailServiceAccount must be a dedicated service account in the pilot project.'
            }
            if ($CustomerEmailServiceAccount -ieq $RuntimeServiceAccount) {
                throw 'CustomerEmailServiceAccount must be distinct from RuntimeServiceAccount.'
            }
            if ($ResendApiKeySecret -cnotmatch $secretPattern -or !$ResendFromAddress) {
                throw 'Customer email deployment requires a pinned ResendApiKeySecret and ResendFromAddress.'
            }
        }
        $sentryRuntimeEntries = @()
        if ($SentryDsnSecret) {
            $sentryRuntimeEntries = @(
                "SENTRY_ENVIRONMENT=$SentryEnvironment",
                "SENTRY_RELEASE=$SentryRelease",
                "SENTRY_TRACES_SAMPLE_RATE=$($SentryTraceSampleRate.ToString([System.Globalization.CultureInfo]::InvariantCulture))"
            )
        }
        $jobRuntime = $runtime | Where-Object { $_ -notlike '--set-secrets=*' }
        $jobSecrets = $secrets
        if ($SentryDsnSecret) { $jobSecrets += ",SENTRY_LARAVEL_DSN=$SentryDsnSecret" }
        $jobRuntime += "--set-secrets=$jobSecrets"
        $jobCommonSettings = $scope + $jobRuntime + @('--tasks=1', '--parallelism=1', '--max-retries=0', '--command=php')
        Invoke-Gcloud -Arguments (@(
            'run', 'jobs', 'deploy', $queueJob, "--task-timeout=$($queueTimeout)s",
            '--args=artisan,queue:work,database,--queue=default,--tries=3,--timeout=60,--max-time=50,--max-jobs=10,--stop-when-empty',
            "--set-env-vars=$(Join-GcloudEnvVars ($environment + $sentryRuntimeEntries + $(if ($SentryDsnSecret) { @('SENTRY_SERVICE=queue') } else { @() })))"
        ) + $jobCommonSettings)
        Invoke-Gcloud -Arguments (@(
            'run', 'jobs', 'deploy', $laravelJob, "--task-timeout=$($laravelTimeout)s",
            '--args=artisan,schedule:run',
            "--set-env-vars=$(Join-GcloudEnvVars ($environment + @('CUSTOMER_EMAIL_DELIVERY_ENABLED=false', 'CUSTOMER_EMAIL_SCHEDULE_ENABLED=false', 'CUSTOMER_EMAIL_CAPTURE_ENABLED=false', "RESEND_FROM_ADDRESS=$ResendFromAddress", "RESEND_ENVIRONMENT=$ResendEnvironment") + $sentryRuntimeEntries + $(if ($SentryDsnSecret) { @('SENTRY_SERVICE=scheduler') } else { @() })))"
        ) + $jobCommonSettings)

        if ($customerEmailRequested) {
            $customerEmailRuntime = @($runtime | Where-Object { $_ -notlike '--set-secrets=*' -and $_ -notlike '--service-account=*' })
            $customerEmailRuntime += "--service-account=$CustomerEmailServiceAccount"
            $customerEmailSecrets = "$secrets,RESEND_API_KEY=$ResendApiKeySecret"
            if ($SentryDsnSecret) { $customerEmailSecrets += ",SENTRY_LARAVEL_DSN=$SentryDsnSecret" }
            $customerEmailRuntime += "--set-secrets=$customerEmailSecrets"
            $customerEmailCommonSettings = $scope + $customerEmailRuntime + @('--tasks=1', '--parallelism=1', '--max-retries=0', '--command=php')
            $customerEmailEnvironment = @(
                'CUSTOMER_EMAIL_CAPTURE_ENABLED=false',
                'CUSTOMER_EMAIL_DELIVERY_ENABLED=true',
                'CUSTOMER_EMAIL_SCHEDULE_ENABLED=false',
                "RESEND_FROM_ADDRESS=$ResendFromAddress",
                "RESEND_ENVIRONMENT=$ResendEnvironment",
                'CUSTOMER_EMAIL_RATE_INTERVAL_SECONDS=1'
            )
            if ($SentryDsnSecret) { $customerEmailEnvironment += 'SENTRY_SERVICE=customer-email' }
            Invoke-Gcloud -Arguments (@(
                'run', 'jobs', 'deploy', $customerEmailJob, "--task-timeout=$($customerEmailTimeout)s",
                '--args=artisan,customer-email:deliver',
                "--set-env-vars=$(Join-GcloudEnvVars ($environment + $customerEmailEnvironment + $sentryRuntimeEntries))"
            ) + $customerEmailCommonSettings)
        }
    }
    { $_ -in 'EnableQueue', 'EnableLaravelSchedule', 'EnableCustomerEmailDelivery' } {
        if ($SchedulerServiceAccount -cnotmatch "^[a-z][a-z0-9-]+@$ProjectId\.iam\.gserviceaccount\.com$") {
            throw 'SchedulerServiceAccount must be a dedicated service account in the pilot project.'
        }
        $jobName, $cron = switch ($Action) {
            'EnableQueue' { $queueJob, $queueCron; break }
            'EnableLaravelSchedule' { $laravelJob, $laravelCron; break }
            default { $customerEmailJob, $CustomerEmailCron }
        }
        $jobUri = "https://run.googleapis.com/v2/projects/$ProjectId/locations/$Region/jobs/${jobName}:run"
        Invoke-Gcloud -Arguments @(
            'scheduler', 'jobs', 'create', 'http', $jobName,
            "--project=$ProjectId", "--location=$Region", "--schedule=$cron",
            '--time-zone=Africa/Cairo', '--http-method=POST', "--uri=$jobUri",
            "--oauth-service-account-email=$SchedulerServiceAccount",
            '--oauth-token-scope=https://www.googleapis.com/auth/cloud-platform',
            '--max-retry-attempts=0', '--attempt-deadline=30s'
        )
    }
    { $_ -in 'PauseQueue', 'PauseLaravelSchedule', 'PauseCustomerEmailDelivery' } {
        $jobName = switch ($Action) {
            'PauseQueue' { $queueJob; break }
            'PauseLaravelSchedule' { $laravelJob; break }
            default { $customerEmailJob }
        }
        Invoke-Gcloud -Arguments @('scheduler', 'jobs', 'pause', $jobName, "--project=$ProjectId", "--location=$Region")
    }
}
