# ==============================================================================
# Alfarid Platform - Modular Repomix CLI Runner (10 End-to-End Vertical Subsystems)
# ==============================================================================

[CmdletBinding()]
param (
    [Parameter(Position = 0)]
    [ValidateSet(
        'pos', 'pos-terminal',
        'orders', 'returns', 'orders-returns',
        'inventory', 'stock', 'inventory-ledger',
        'wholesale', 'b2b', 'wholesale-b2b',
        'ai', 'trader', 'whatsapp', 'ai-trader-gateway',
        'storefront', 'catalog', 'storefront-catalog',
        'finance', 'treasury', 'finance-treasury',
        'procurement', 'suppliers', 'procurement-suppliers',
        'security', 'auth', 'backoffice', 'security-backoffice',
        'infra', 'devops', 'infra-devops'
    )]
    [string]$Domain,

    [switch]$All,
    [switch]$TokenTree,
    [switch]$Clean,
    [switch]$VerifyMigrations
)

$ErrorActionPreference = 'Stop'

$RootDir = Resolve-Path "$PSScriptRoot\.."
$ConfigsDir = Join-Path $RootDir 'repomix\configs'
$OutputsDir = Join-Path $RootDir 'repomix\outputs'
$MigrationsDir = Join-Path $RootDir 'bags-platform\backend\database\migrations'

if (-not (Test-Path $OutputsDir)) {
    New-Item -ItemType Directory -Path $OutputsDir -Force | Out-Null
}

$SubsystemConfigs = [ordered]@{
    'pos-terminal'          = 'repomix.subsystem-01-pos-terminal.json'
    'orders-returns'        = 'repomix.subsystem-02-orders-returns.json'
    'inventory-ledger'      = 'repomix.subsystem-03-inventory-ledger.json'
    'wholesale-b2b'         = 'repomix.subsystem-04-wholesale-b2b.json'
    'ai-trader-gateway'     = 'repomix.subsystem-05-ai-trader-gateway.json'
    'storefront-catalog'    = 'repomix.subsystem-06-storefront-catalog.json'
    'finance-treasury'      = 'repomix.subsystem-07-finance-treasury.json'
    'procurement-suppliers' = 'repomix.subsystem-08-procurement-suppliers.json'
    'security-backoffice'   = 'repomix.subsystem-09-security-backoffice.json'
    'infra-devops'          = 'repomix.subsystem-10-infra-devops.json'
}

function Resolve-DomainKey([string]$key) {
    switch -Regex ($key) {
        '^(pos|terminal|shifts)'        { return 'pos-terminal' }
        '^(order|return|refund)'        { return 'orders-returns' }
        '^(invent|stock|ledger)'        { return 'inventory-ledger' }
        '^(wholesale|b2b|quotation)'    { return 'wholesale-b2b' }
        '^(ai|whatsapp|trader|gateway)' { return 'ai-trader-gateway' }
        '^(store|catalog|cart)'         { return 'storefront-catalog' }
        '^(finance|treas|expense)'      { return 'finance-treasury' }
        '^(procure|suppl|purchase)'     { return 'procurement-suppliers' }
        '^(security|auth|backoffice|staff|admin)' { return 'security-backoffice' }
        '^(infra|devops|docker)'        { return 'infra-devops' }
        default                         { return $key }
    }
}

function Test-MigrationCoverage {
    Write-Host "`nAuditing database migration coverage across the 10 vertical subsystems..." -ForegroundColor Cyan
    $allMigrations = Get-ChildItem -Path $MigrationsDir -File | Select-Object -ExpandProperty Name
    $covered = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)

    foreach ($subKey in $SubsystemConfigs.Keys) {
        $cfgPath = Join-Path $ConfigsDir $SubsystemConfigs[$subKey]
        $json = Get-Content $cfgPath -Raw | ConvertFrom-Json
        $patterns = $json.include | Where-Object { $_ -match "migrations" }
        foreach ($pat in $patterns) {
            $glob = Split-Path $pat -Leaf
            $matched = Get-ChildItem -Path $MigrationsDir -Filter $glob | Select-Object -ExpandProperty Name
            foreach ($m in $matched) {
                [void]$covered.Add($m)
            }
        }
    }

    $coveredCount = $covered.Count
    $totalCount = $allMigrations.Count
    $percent = [math]::Round(($coveredCount / $totalCount) * 100, 1)

    $color = if ($coveredCount -eq $totalCount) { 'Green' } else { 'Yellow' }
    Write-Host "Migration Coverage: $coveredCount / $totalCount ($percent%)" -ForegroundColor $color

    $uncovered = $allMigrations | Where-Object { -not $covered.Contains($_) }
    if ($uncovered.Count -gt 0) {
        Write-Warning "Uncovered migrations ($($uncovered.Count)):"
        $uncovered | ForEach-Object { Write-Host " - $_" -ForegroundColor Yellow }
        return $false
    } else {
        Write-Host "SUCCESS: 100% of all $totalCount migrations are included in the subsystems!" -ForegroundColor Green
        return $true
    }
}

if ($VerifyMigrations) {
    Test-MigrationCoverage
    if (-not $Domain -and -not $All) { return }
}

if ($Clean) {
    Write-Host "Cleaning generated repomix outputs in: $OutputsDir" -ForegroundColor Yellow
    Get-ChildItem -Path $OutputsDir -Exclude '.gitkeep' | Remove-Item -Force
    Write-Host 'Cleanup completed successfully!' -ForegroundColor Green
    if (-not $Domain -and -not $All) { return }
}

function Invoke-RepomixDomain([string]$key, [string]$configFile) {
    $configPath = Join-Path $ConfigsDir $configFile
    if (-not (Test-Path $configPath)) {
        Write-Error "Configuration file not found: $configPath"
        return
    }

    Write-Host "`n========================================================" -ForegroundColor Cyan
    Write-Host "Packing Subsystem: $key" -ForegroundColor Cyan
    Write-Host "Config: $configFile" -ForegroundColor DarkGray
    Write-Host "========================================================" -ForegroundColor Cyan

    $cfgRel = "repomix/configs/$configFile"
    if ($TokenTree) {
        npx repomix -c $cfgRel --token-count-tree
    } else {
        npx repomix -c $cfgRel
    }
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Repomix packing failed for subsystem: $key with exit code $LASTEXITCODE"
    }
}

if ($All) {
    Write-Host 'Starting batch repomix packing for ALL 10 End-to-End Vertical Subsystems...' -ForegroundColor Green
    $stopwatch = [System.Diagnostics.Stopwatch]::StartNew()
    foreach ($key in $SubsystemConfigs.Keys) {
        Invoke-RepomixDomain -key $key -configFile $SubsystemConfigs[$key]
    }
    $stopwatch.Stop()
    Write-Host "`nAll 10 subsystem bundles successfully packed in $($stopwatch.Elapsed.TotalSeconds.ToString('F1')) seconds!" -ForegroundColor Green
    Test-MigrationCoverage
} elseif ($Domain) {
    $resolvedKey = Resolve-DomainKey $Domain
    if ($SubsystemConfigs.Contains($resolvedKey)) {
        Invoke-RepomixDomain -key $resolvedKey -configFile $SubsystemConfigs[$resolvedKey]
    } else {
        Write-Error "Unknown domain: $Domain"
    }
} else {
    Write-Host 'Alfarid Platform - Repomix Vertical Subsystems Runner' -ForegroundColor Cyan
    Write-Host 'Available subsystems:'
    foreach ($key in $SubsystemConfigs.Keys) {
        Write-Host "  - $key" -ForegroundColor DarkCyan
    }
    Write-Host "`nExamples:"
    Write-Host '  .\scripts\repomix-pack.ps1 -Domain pos'
    Write-Host '  .\scripts\repomix-pack.ps1 -Domain orders'
    Write-Host '  .\scripts\repomix-pack.ps1 -Domain inventory -TokenTree'
    Write-Host '  .\scripts\repomix-pack.ps1 -VerifyMigrations'
    Write-Host '  .\scripts\repomix-pack.ps1 -All'
    Write-Host '  .\scripts\repomix-pack.ps1 -Clean'
}
