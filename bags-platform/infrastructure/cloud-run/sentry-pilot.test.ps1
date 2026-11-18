# Mock-only assertions for the Sentry deployment arguments. No gcloud command
# reaches the real executable, and this script never deploys a service.
$ErrorActionPreference = 'Stop'
$global:MockGcloudInvocations = [System.Collections.Generic.List[object]]::new()
function global:gcloud {
    $global:MockGcloudInvocations.Add(@($args))
    $global:LASTEXITCODE = 0
}

$scriptPath = Join-Path $PSScriptRoot 'pilot.ps1'
$sha = '0123456789abcdef0123456789abcdef01234567'
$image = 'europe-west3-docker.pkg.dev/pilot-project-1/app/api@sha256:' + ('a' * 64)
$base = @{
    Action = 'DeployJobs'
    ProjectId = 'pilot-project-1'
    Region = 'europe-west3'
    Image = $image
    RuntimeServiceAccount = 'runtime@pilot-project-1.iam.gserviceaccount.com'
    DatabaseHost = 'ep-cool-mouse-a1b2c3.eu-central-1.aws.neon.tech'
    DatabaseName = 'alfarid'
    DatabaseUser = 'alfarid'
    AppKeySecret = 'app-key:1'
    DatabasePasswordSecret = 'database-password:2'
}

function Assert-True([bool]$condition, [string]$message) {
    if (-not $condition) { throw $message }
}

function Get-InvocationText {
    return (($global:MockGcloudInvocations | ForEach-Object { $_ -join ' ' }) -join "`n")
}

function Reset-Invocations {
    $global:MockGcloudInvocations.Clear()
    $global:LASTEXITCODE = 0
}

Reset-Invocations
& $scriptPath @base -SentryDsnSecret 'sentry-laravel-dsn:7' -SentryEnvironment 'staging' -SentryRelease $sha -SentryTraceSampleRate 1.0
Assert-True ($global:MockGcloudInvocations.Count -eq 2) 'DeployJobs should invoke gcloud twice.'
$deployText = Get-InvocationText
Assert-True ($deployText.Contains('SENTRY_LARAVEL_DSN=sentry-laravel-dsn:7')) 'Pinned Laravel DSN secret is missing.'
Assert-True ($deployText.Contains("SENTRY_ENVIRONMENT=staging")) 'Explicit staging environment is missing.'
Assert-True ($deployText.Contains("SENTRY_RELEASE=$sha")) 'Full release SHA is missing.'
Assert-True ($deployText.Contains('SENTRY_TRACES_SAMPLE_RATE=1')) 'Explicit staging sample rate is missing.'
Assert-True ($deployText.Contains('SENTRY_SERVICE=queue')) 'Queue service tag is missing.'
Assert-True ($deployText.Contains('SENTRY_SERVICE=scheduler')) 'Scheduler service tag is missing.'
Assert-True ($deployText.Contains('APP_ENV=production')) 'Existing APP_ENV must be preserved.'
Assert-True ($deployText.Contains('DB_DATABASE=alfarid')) 'Existing database settings must be preserved.'

Reset-Invocations
& $scriptPath @base
Assert-True ($global:MockGcloudInvocations.Count -eq 2) 'Unmonitored DeployJobs should remain available.'
Assert-True (-not (Get-InvocationText).Contains('SENTRY_LARAVEL_DSN')) 'A DSN binding must be absent when omitted.'

Reset-Invocations
$invalidSecretRejected = $false
try { & $scriptPath @base -SentryDsnSecret 'sentry-laravel-dsn:latest' -SentryRelease $sha } catch { $invalidSecretRejected = $true }
Assert-True $invalidSecretRejected 'An unpinned Sentry secret should be rejected.'
Assert-True ($global:MockGcloudInvocations.Count -eq 0) 'Invalid Sentry settings must fail before a deploy call.'

Reset-Invocations
$invalidReleaseRejected = $false
try { & $scriptPath @base -SentryDsnSecret 'sentry-laravel-dsn:7' -SentryRelease 'short' } catch { $invalidReleaseRejected = $true }
Assert-True $invalidReleaseRejected 'A short release identifier should be rejected.'
Assert-True ($global:MockGcloudInvocations.Count -eq 0) 'Invalid release settings must fail before a deploy call.'

Reset-Invocations
$invalidRateRejected = $false
try { & $scriptPath @base -SentryDsnSecret 'sentry-laravel-dsn:7' -SentryRelease $sha -SentryTraceSampleRate 1.1 } catch { $invalidRateRejected = $true }
Assert-True $invalidRateRejected 'An out-of-range trace rate should be rejected.'
Assert-True ($global:MockGcloudInvocations.Count -eq 0) 'Invalid rates must fail before a deploy call.'

Reset-Invocations
$apiParams = $base.Clone()
$apiParams.Action = 'DeployApi'
$apiParams.ProxyClientIpSecret = 'proxy-client-ip:3'
& $scriptPath @apiParams -SentryDsnSecret 'sentry-laravel-dsn:7' -SentryEnvironment 'production' -SentryRelease $sha
Assert-True ($global:MockGcloudInvocations.Count -eq 1) 'DeployApi should invoke gcloud once.'
$apiText = Get-InvocationText
Assert-True ($apiText.Contains('SENTRY_SERVICE=api')) 'API service tag is missing.'
Assert-True ($apiText.Contains('PROXY_CLIENT_IP_SECRET=proxy-client-ip:3')) 'Existing proxy signing secret must be preserved.'

Reset-Invocations
$aiParams = $base.Clone()
$aiParams.Action = 'DeployAi'
$aiParams.GeminiApiKeySecret = 'gemini-api-key:4'
$aiParams.GeminiModel = 'gemini-test-model'
$aiParams.ProxyClientIpSecret = 'proxy-client-ip:3'
& $scriptPath @aiParams -SentryDsnSecret 'sentry-laravel-dsn:7' -SentryEnvironment 'staging' -SentryRelease $sha -SentryTraceSampleRate 1.0
Assert-True ($global:MockGcloudInvocations.Count -eq 1) 'DeployAi should invoke gcloud once.'
$aiText = Get-InvocationText
Assert-True ($aiText.Contains('SENTRY_SERVICE=ai')) 'AI service tag is missing.'
Assert-True ($aiText.Contains('GEMINI_API_KEY=gemini-api-key:4')) 'Existing Gemini secret must be preserved.'
Assert-True ($aiText.Contains('PROXY_CLIENT_IP_SECRET=proxy-client-ip:3')) 'AI proxy signing secret must be preserved.'
Assert-True ($aiText.Contains('SENTRY_LARAVEL_DSN=sentry-laravel-dsn:7')) 'AI Laravel DSN binding is missing.'

Reset-Invocations
Write-Output 'Sentry pilot argument checks passed (mocked gcloud only).'
