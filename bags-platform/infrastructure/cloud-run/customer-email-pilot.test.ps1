# Mock-only checks for the isolated customer-email delivery Job. No gcloud call
# reaches the real executable, and this script never deploys a service.
$ErrorActionPreference = 'Stop'
$global:MockGcloudInvocations = [System.Collections.Generic.List[object]]::new()
function global:gcloud {
    $global:MockGcloudInvocations.Add(@($args))
    $global:LASTEXITCODE = 0
}

$scriptPath = Join-Path $PSScriptRoot 'pilot.ps1'
$base = @{
    Action = 'DeployJobs'
    ProjectId = 'pilot-project-1'
    Region = 'europe-west3'
    Image = 'europe-west3-docker.pkg.dev/pilot-project-1/app/api@sha256:' + ('a' * 64)
    RuntimeServiceAccount = 'runtime@pilot-project-1.iam.gserviceaccount.com'
    CustomerEmailServiceAccount = 'customer-email@pilot-project-1.iam.gserviceaccount.com'
    DatabaseHost = 'ep-cool-mouse-a1b2c3.eu-central-1.aws.neon.tech'
    DatabaseName = 'alfarid'
    DatabaseUser = 'alfarid'
    AppKeySecret = 'app-key:1'
    DatabasePasswordSecret = 'database-password:2'
    ResendApiKeySecret = 'resend-email-key:5'
    ResendFromAddress = 'orders@alfarid.example'
    ResendEnvironment = 'staging'
    CustomerEmailCaptureEnabled = 'true'
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

$runbook = Get-Content (Join-Path $PSScriptRoot 'README.md') -Raw
Assert-True ($runbook.Contains("ProxyClientIpSecret = 'proxy-client-ip:3'")) 'The staging DeployApi example must include its required proxy secret reference.'
Assert-True ($runbook.Contains('after executing the email Job')) 'Staging instructions must run the worker before checking provider acceptance.'
Assert-True ($runbook.Contains('verify arrival in the test inbox')) 'Staging instructions must verify actual mailbox arrival.'
Assert-True ($runbook.Contains('up to 71 hours')) 'The pilot runbook must disclose the worst-case delivery delay.'
Assert-True ($runbook.Contains('continuous customer schedule')) 'Production must require a schedule appropriate to customer delivery latency.'

Reset-Invocations
& $scriptPath @base
Assert-True ($global:MockGcloudInvocations.Count -eq 3) 'DeployJobs should deploy queue, general scheduler, and customer-email Jobs.'
$deployText = Get-InvocationText
Assert-True ($deployText.Contains('alfarid-pilot-customer-email')) 'Dedicated customer-email Job is missing.'
Assert-True ($deployText.Contains('CUSTOMER_EMAIL_DELIVERY_ENABLED=true')) 'Worker delivery must be enabled in its Job.'
Assert-True ($deployText.Contains('CUSTOMER_EMAIL_SCHEDULE_ENABLED=false')) 'The general schedule must not also trigger email delivery.'
Assert-True ($deployText.Contains('RESEND_API_KEY=resend-email-key:5')) 'Pinned Resend secret must be bound to the delivery Job.'
Assert-True ($deployText.Contains('customer-email@pilot-project-1.iam.gserviceaccount.com')) 'The worker must use its separate service account.'

$apiParams = $base.Clone()
$apiParams.Action = 'DeployApi'
$apiParams.ProxyClientIpSecret = 'proxy-client-ip:3'
Reset-Invocations
& $scriptPath @apiParams
$apiText = Get-InvocationText
Assert-True ($apiText.Contains('CUSTOMER_EMAIL_CAPTURE_ENABLED=true')) 'API capture flag is missing.'
Assert-True ($apiText.Contains('RESEND_FROM_ADDRESS=orders@alfarid.example')) 'API sender address is missing.'
Assert-True (-not $apiText.Contains('RESEND_API_KEY=')) 'The API must not receive the Resend API key.'

Reset-Invocations
& $scriptPath -Action EnableCustomerEmailDelivery -ProjectId 'pilot-project-1' -Region 'europe-west3' -SchedulerServiceAccount 'scheduler@pilot-project-1.iam.gserviceaccount.com'
$scheduleText = Get-InvocationText
Assert-True ($scheduleText.Contains('--schedule=2-59/10 10 * * 1-5')) 'The pilot should use its cost-bounded email cadence.'

Reset-Invocations
& $scriptPath -Action PauseCustomerEmailDelivery -ProjectId 'pilot-project-1' -Region 'europe-west3'
Assert-True ((Get-InvocationText).Contains('alfarid-pilot-customer-email')) 'The delivery trigger must have a dedicated pause action.'

Reset-Invocations
$invalidSecretRejected = $false
try { & $scriptPath @($base.Clone()) -ResendApiKeySecret 'resend-email-key:latest' } catch { $invalidSecretRejected = $true }
Assert-True $invalidSecretRejected 'An unpinned Resend secret should be rejected.'
Assert-True ($global:MockGcloudInvocations.Count -eq 0) 'Invalid Resend settings must fail before a deploy call.'

Reset-Invocations
$legacy = $base.Clone()
foreach ($name in @('CustomerEmailServiceAccount', 'ResendApiKeySecret', 'ResendFromAddress', 'ResendEnvironment', 'CustomerEmailCaptureEnabled', 'CustomerEmailCron')) {
    $legacy.Remove($name)
}
& $scriptPath @legacy
Assert-True ($global:MockGcloudInvocations.Count -eq 2) 'DeployJobs without email settings should preserve the queue and schedule deployments.'
Assert-True (-not (Get-InvocationText).Contains('alfarid-pilot-customer-email')) 'Email Job must remain optional when its settings are omitted.'

Reset-Invocations
$sameIdentity = $base.Clone()
$sameIdentity.CustomerEmailServiceAccount = $sameIdentity.RuntimeServiceAccount
$sameIdentityRejected = $false
try { & $scriptPath @sameIdentity } catch { $sameIdentityRejected = $true }
Assert-True $sameIdentityRejected 'The API and email worker must not share a service account.'
Assert-True ($global:MockGcloudInvocations.Count -eq 0) 'A shared API/email identity must be rejected before any deploy call.'

Reset-Invocations
$partialEmailSettings = $base.Clone()
$partialEmailSettings.Remove('ResendApiKeySecret')
$partialEmailRejected = $false
try { & $scriptPath @partialEmailSettings } catch { $partialEmailRejected = $true }
Assert-True $partialEmailRejected 'Partial email settings must be rejected.'
Assert-True ($global:MockGcloudInvocations.Count -eq 0) 'Partial email settings must fail before any deploy call.'

Reset-Invocations
$invalidCronRejected = $false
try { & $scriptPath -Action EnableCustomerEmailDelivery -ProjectId 'pilot-project-1' -Region 'europe-west3' -SchedulerServiceAccount 'scheduler@pilot-project-1.iam.gserviceaccount.com' -CustomerEmailCron '* * * * *;Remove-Item' } catch { $invalidCronRejected = $true }
Assert-True $invalidCronRejected 'Unexpected cron text should be rejected before invoking gcloud.'
Assert-True ($global:MockGcloudInvocations.Count -eq 0) 'Invalid cron settings must fail before a scheduler write.'

Reset-Invocations
Write-Output 'Customer-email pilot argument checks passed (mocked gcloud only).'
