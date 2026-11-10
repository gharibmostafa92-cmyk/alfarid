[CmdletBinding()]
param(
    [Parameter(Mandatory)] [string] $ProjectId,
    [Parameter(Mandatory)] [string] $Image,
    [string] $Gcloud = 'gcloud'
)

$ErrorActionPreference = 'Stop'
$limit = [long][Math]::Floor(0.70 * 0.5 * 1GB)

$billingAccount = & $Gcloud billing projects describe $ProjectId '--format=value(billingAccountName)'
if ($LASTEXITCODE -ne 0 -or $billingAccount -notmatch '^billingAccounts/[^/]+$') {
    throw "Cannot verify the billing account for $ProjectId."
}
$billingId = $billingAccount -replace '^billingAccounts/', ''
$projectsJson = & $Gcloud billing projects list "--billing-account=$billingId" '--format=json(projectId,billingEnabled)'
if ($LASTEXITCODE -ne 0) { throw 'Cannot enumerate projects sharing the storage allowance.' }
$projects = @($projectsJson | Out-String | ConvertFrom-Json | Where-Object { $_.billingEnabled -eq $true })
if ($ProjectId -notin $projects.projectId) { throw "Cannot verify that $ProjectId is billed on this account." }

[long] $stored = 0
foreach ($project in $projects) {
    $reposJson = & $Gcloud artifacts repositories list "--project=$($project.projectId)" '--format=json(name,mode,sizeBytes)'
    if ($LASTEXITCODE -ne 0) { throw "Cannot measure registry storage in $($project.projectId)." }
    foreach ($repo in ($reposJson | Out-String | ConvertFrom-Json)) {
        if ($repo.mode -eq 'VIRTUAL_REPOSITORY') { continue }
        if ($null -eq $repo.sizeBytes) { throw "Missing storage measurement for $($repo.name)." }
        $stored += [long] $repo.sizeBytes
    }
}
$size = docker image inspect $Image --format '{{.Size}}'
if ($LASTEXITCODE -ne 0 -or $size -notmatch '^[1-9][0-9]*$') { throw "Cannot measure the local image $Image." }
$projected = $stored + [long] $size
Write-Host "Stored: $stored bytes; local image: $size bytes; projected: $projected bytes; pilot ceiling: $limit bytes."
if ($projected -gt $limit) {
    throw 'Registry storage gate failed. Shrink the image or verify old, unused versions before removing them.'
}
