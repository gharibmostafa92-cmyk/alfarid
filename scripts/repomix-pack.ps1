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
        '5.1', 'ai-gateway', 'ai-gateway-core',
        '5.2', 'ai-tools', 'ai-domain-tools',
        '5.2.1', 'ai-tools-catalog', 'ai-tools-cat-inv',
        '5.2.2', 'ai-tools-finance', 'ai-tools-fin-price',
        'storefront', 'catalog', 'storefront-catalog',
        '6.1', 'storefront-client',
        '6.2', 'catalog-shipping',
        'finance', 'treasury', 'finance-treasury',
        'procurement', 'suppliers', 'procurement-suppliers',
        'security', 'auth', 'backoffice', 'security-backoffice',
        '9.1', 'security-auth', 'security-auth-rbac',
        '9.2', 'backoffice-portal', 'backoffice-ui', 'backoffice-notifications',
        '9.2.1', 'backoffice-admin', 'backoffice-admin-governance',
        '9.2.2', 'backoffice-operations', 'backoffice-operations-commerce',
        '9.2.3', 'backoffice-catalog', 'backoffice-catalog-inventory',
        '9.2.4', 'backoffice-assistant', 'backoffice-assistant-pos',
        '9.3', 'notifications-settings', 'notifications', 'settings',
        'infra', 'devops', 'infra-devops'
    )]
    [string]$Domain,

    [switch]$All,
    [switch]$Splits,
    [switch]$TokenTree,
    [switch]$Clean,
    [switch]$VerifyMigrations,
    [switch]$Sync,
    [switch]$Started
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

$SplitConfigs = [ordered]@{
    'ai-gateway-core'          = 'repomix.subsystem-05.1-ai-gateway-core.json'
    'ai-tools-cat-inv'         = 'repomix.subsystem-05.2.1-ai-catalog-inventory-tools.json'
    'ai-tools-fin-price'       = 'repomix.subsystem-05.2.2-ai-finance-pricing-tools.json'
    'storefront-client'        = 'repomix.subsystem-06.1-storefront-client.json'
    'catalog-shipping'         = 'repomix.subsystem-06.2-catalog-shipping.json'
    'backoffice-portal'        = 'repomix.subsystem-09.2-backoffice-portal.json'
    'backoffice-admin-gov'     = 'repomix.subsystem-09.2.1-backoffice-admin-governance.json'
    'backoffice-ops-comm'      = 'repomix.subsystem-09.2.2-backoffice-operations-commerce.json'
    'backoffice-cat-inv'       = 'repomix.subsystem-09.2.3-backoffice-catalog-inventory.json'
    'backoffice-asst-pos'      = 'repomix.subsystem-09.2.4-backoffice-assistant-pos.json'
    'notifications-settings'   = 'repomix.subsystem-09.3-notifications-settings.json'
}

$AllConfigs = [ordered]@{}
foreach ($k in $SubsystemConfigs.Keys) { $AllConfigs[$k] = $SubsystemConfigs[$k] }
foreach ($k in $SplitConfigs.Keys) { $AllConfigs[$k] = $SplitConfigs[$k] }

function Resolve-DomainKey([string]$key) {
    switch -Regex ($key) {
        '^(5\.1|ai-gateway|ai-gateway-core)$'               { return 'ai-gateway-core' }
        '^(5\.2\.1|ai-tools-catalog|ai-tools-cat-inv)$'     { return 'ai-tools-cat-inv' }
        '^(5\.2\.2|ai-tools-finance|ai-tools-fin-price)$'   { return 'ai-tools-fin-price' }
        '^(6\.1|storefront-client)$'                        { return 'storefront-client' }
        '^(6\.2|catalog-shipping)$'                         { return 'catalog-shipping' }
        '^(9\.1|security-auth|security-auth-rbac)$'         { return 'security-auth-rbac' }
        '^(9\.2\.1|backoffice-admin|backoffice-admin-governance)$' { return 'backoffice-admin-gov' }
        '^(9\.2\.2|backoffice-operations|backoffice-operations-commerce)$' { return 'backoffice-ops-comm' }
        '^(9\.2\.3|backoffice-catalog|backoffice-catalog-inventory)$' { return 'backoffice-cat-inv' }
        '^(9\.2\.4|backoffice-assistant|backoffice-assistant-pos)$' { return 'backoffice-asst-pos' }
        '^(9\.2|backoffice-portal|backoffice-ui|backoffice-notifications)$' { return 'backoffice-portal' }
        '^(9\.3|notifications-settings|notifications|settings)$' { return 'notifications-settings' }
        '^(pos|terminal|shifts)'                            { return 'pos-terminal' }
        '^(order|return|refund)'                            { return 'orders-returns' }
        '^(invent|stock|ledger)'                            { return 'inventory-ledger' }
        '^(wholesale|b2b|quotation)'                        { return 'wholesale-b2b' }
        '^(ai|whatsapp|trader|gateway)'                     { return 'ai-trader-gateway' }
        '^(store|catalog|cart)'                             { return 'storefront-catalog' }
        '^(finance|treas|expense)'                          { return 'finance-treasury' }
        '^(procure|suppl|purchase)'                         { return 'procurement-suppliers' }
        '^(security|auth|backoffice|staff|admin)'           { return 'security-backoffice' }
        '^(infra|devops|docker)'                            { return 'infra-devops' }
        default                                             { return $key }
    }
}

function Test-MigrationCoverage {
    Write-Host "`nAuditing database migration coverage across the 10 vertical subsystems..." -ForegroundColor Cyan
    $allMigrations = Get-ChildItem -Path $MigrationsDir -File | Select-Object -ExpandProperty Name
    $covered = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)

    foreach ($subKey in $SubsystemConfigs.Keys) {
        $cfgPath = Join-Path $ConfigsDir $SubsystemConfigs[$subKey]
        if (-not (Test-Path $cfgPath)) { continue }
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

if ($Sync) {
    Write-Host "Triggering automated Repomix synchronization..." -ForegroundColor Cyan
    & "$PSScriptRoot\repomix-started-sync.ps1"
    if (-not $Domain -and -not $All -and -not $Started) { return }
}

if ($Started) {
    Write-Host "Packing all 'started' audit bundles..." -ForegroundColor Green
    $startedFiles = Get-ChildItem -Path $ConfigsDir -Filter "repomix.*.json" | Where-Object {
        $c = Get-Content $_.FullName -Raw | ConvertFrom-Json
        $c.output.filePath -match 'started'
    }
    foreach ($sf in $startedFiles) {
        $cfgRel = "repomix/configs/$($sf.Name)"
        Write-Host "Packing started config: $($sf.Name)" -ForegroundColor Cyan
        npx repomix -c $cfgRel
    }
    Write-Host "All started audit bundles packed successfully!" -ForegroundColor Green
    if (-not $Domain -and -not $All) { return }
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
        $cfgPath = Join-Path $ConfigsDir $SubsystemConfigs[$key]
        if (Test-Path $cfgPath) {
            Invoke-RepomixDomain -key $key -configFile $SubsystemConfigs[$key]
        }
    }
    $stopwatch.Stop()
    Write-Host "`nAll 10 subsystem bundles successfully packed in $($stopwatch.Elapsed.TotalSeconds.ToString('F1')) seconds!" -ForegroundColor Green
    Test-MigrationCoverage
} elseif ($Splits) {
    Write-Host 'Starting batch repomix packing for all Split Subsystem slices (5.1, 5.2, 6.1, 6.2, 9.1, 9.2)...' -ForegroundColor Green
    $stopwatch = [System.Diagnostics.Stopwatch]::StartNew()
    foreach ($key in $SplitConfigs.Keys) {
        Invoke-RepomixDomain -key $key -configFile $SplitConfigs[$key]
    }
    $stopwatch.Stop()
    Write-Host "`nAll split subsystem bundles successfully packed in $($stopwatch.Elapsed.TotalSeconds.ToString('F1')) seconds!" -ForegroundColor Green
} elseif ($Domain) {
    if ($Domain -match '^(5\.2|ai-tools|ai-domain-tools)$') {
        Invoke-RepomixDomain -key 'ai-tools-cat-inv' -configFile $SplitConfigs['ai-tools-cat-inv']
        Invoke-RepomixDomain -key 'ai-tools-fin-price' -configFile $SplitConfigs['ai-tools-fin-price']
        return
    }
    $resolvedKey = Resolve-DomainKey $Domain
    if ($AllConfigs.Contains($resolvedKey)) {
        Invoke-RepomixDomain -key $resolvedKey -configFile $AllConfigs[$resolvedKey]
    } else {
        Write-Error "Unknown domain: $Domain"
    }
} else {
    Write-Host 'Alfarid Platform - Repomix Vertical Subsystems Runner' -ForegroundColor Cyan
    Write-Host 'Available core subsystems:'
    foreach ($key in $SubsystemConfigs.Keys) {
        Write-Host "  - $key" -ForegroundColor DarkCyan
    }
    Write-Host "`nAvailable split subsystem slices:"
    foreach ($key in $SplitConfigs.Keys) {
        Write-Host "  - $key ($($SplitConfigs[$key]))" -ForegroundColor DarkGreen
    }
    Write-Host "`nExamples:"
    Write-Host '  .\scripts\repomix-pack.ps1 -Domain 5.1'
    Write-Host '  .\scripts\repomix-pack.ps1 -Domain 5.2'
    Write-Host '  .\scripts\repomix-pack.ps1 -Domain 5.2.1'
    Write-Host '  .\scripts\repomix-pack.ps1 -Domain 5.2.2'
    Write-Host '  .\scripts\repomix-pack.ps1 -Domain 6.1'
    Write-Host '  .\scripts\repomix-pack.ps1 -Domain 6.2'
    Write-Host '  .\scripts\repomix-pack.ps1 -Domain 9.1'
    Write-Host '  .\scripts\repomix-pack.ps1 -Domain 9.2'
    Write-Host '  .\scripts\repomix-pack.ps1 -Splits'
    Write-Host '  .\scripts\repomix-pack.ps1 -All'
    Write-Host '  .\scripts\repomix-pack.ps1 -VerifyMigrations'
    Write-Host '  .\scripts\repomix-pack.ps1 -Clean'
    Write-Host '  .\scripts\repomix-pack.ps1 -Sync'
    Write-Host '  .\scripts\repomix-pack.ps1 -Started'
}
