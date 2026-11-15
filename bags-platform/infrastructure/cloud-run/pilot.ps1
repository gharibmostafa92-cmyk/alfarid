#Requires -Version 7.0
[CmdletBinding()]
param(
    [ValidateSet('Plan', 'DeployApi', 'DeployAi', 'DeployJobs', 'EnableQueue', 'EnableLaravelSchedule', 'PauseQueue', 'PauseLaravelSchedule')]
    [string]$Action = 'Plan',
    [ValidatePattern('^[a-z][a-z0-9-]{4,28}[a-z0-9]$')]
    [string]$ProjectId,
    [ValidatePattern('^[a-z]+(?:-[a-z]+)+[0-9]+$')]
    [string]$Region = 'us-central1',
    [string]$Image,
    [string]$RuntimeServiceAccount,
    [string]$SchedulerServiceAccount,
    [string]$DatabaseHost,
    [string]$DatabaseName,
    [string]$DatabaseUser,
    [string]$AppKeySecret,
    [string]$DatabasePasswordSecret,
    [string]$GeminiApiKeySecret,
    [string]$GeminiModel,
    [string]$ProxyClientIpSecret,
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
$queueCron = '*/10 10 * * 1-5'
$laravelCron = '5-55/5 10 * * 1-5'
$queueTimeout = 120
$laravelTimeout = 240

if ($Action -eq 'Plan') {
    [pscustomobject]@{
        TimeZone = 'Africa/Cairo'
        QueueCron = $queueCron
        LaravelCron = $laravelCron
        QueueTimeoutSeconds = $queueTimeout
        LaravelTimeoutSeconds = $laravelTimeout
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
            $apiEnvironment = ($environment + @('PHP_FPM_PM_MAX_CHILDREN=8')) -join ','
            $apiRuntime = $runtime | Where-Object { $_ -notlike '--set-secrets=*' }
            $apiRuntime += "--set-secrets=$secrets,PROXY_CLIENT_IP_SECRET=$ProxyClientIpSecret"
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
            $aiEnvironment = ($environment + @('PHP_FPM_PM_MAX_CHILDREN=2', "GEMINI_MODEL=$GeminiModel")) -join ','
            $aiRuntime = $runtime | Where-Object { $_ -notlike '--set-secrets=*' }
            $aiRuntime += "--set-secrets=$secrets,GEMINI_API_KEY=$GeminiApiKeySecret,PROXY_CLIENT_IP_SECRET=$ProxyClientIpSecret"
            Invoke-Gcloud -Arguments (@(
                'run', 'deploy', 'alfarid-ai', '--no-allow-unauthenticated', '--invoker-iam-check',
                '--cpu-throttling', '--no-cpu-boost', '--min=0', '--max=4', '--concurrency=2',
                '--port=8080', '--timeout=180s', "--set-env-vars=$aiEnvironment"
            ) + $scope + $aiRuntime)
            return
        }

        $jobSettings = $scope + $runtime + @(
            '--tasks=1', '--parallelism=1', '--max-retries=0',
            '--command=php', "--set-env-vars=$($environment -join ',')"
        )
        Invoke-Gcloud -Arguments (@(
            'run', 'jobs', 'deploy', $queueJob, "--task-timeout=$($queueTimeout)s",
            '--args=artisan,queue:work,database,--queue=default,--tries=3,--timeout=60,--max-time=50,--max-jobs=10,--stop-when-empty'
        ) + $jobSettings)
        Invoke-Gcloud -Arguments (@(
            'run', 'jobs', 'deploy', $laravelJob, "--task-timeout=$($laravelTimeout)s",
            '--args=artisan,schedule:run'
        ) + $jobSettings)
    }
    { $_ -in 'EnableQueue', 'EnableLaravelSchedule' } {
        if ($SchedulerServiceAccount -cnotmatch "^[a-z][a-z0-9-]+@$ProjectId\.iam\.gserviceaccount\.com$") {
            throw 'SchedulerServiceAccount must be a dedicated service account in the pilot project.'
        }
        $jobName, $cron = if ($Action -eq 'EnableQueue') { $queueJob, $queueCron } else { $laravelJob, $laravelCron }
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
    { $_ -in 'PauseQueue', 'PauseLaravelSchedule' } {
        $jobName = if ($Action -eq 'PauseQueue') { $queueJob } else { $laravelJob }
        Invoke-Gcloud -Arguments @('scheduler', 'jobs', 'pause', $jobName, "--project=$ProjectId", "--location=$Region")
    }
}
