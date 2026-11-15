<#
.SYNOPSIS
    Automated synchronization of newly created/modified files into Repomix configs and execution of bundle packaging.

.DESCRIPTION
    Scans the repository for newly created, untracked, or recently committed files from audit tasks,
    determines the appropriate Repomix config (focusing on configs generating to repomix/outputs/started),
    safely updates the "include" array if not already covered by a glob pattern, and executes Repomix
    to regenerate the output bundles.

.PARAMETER Commit
    Optional git commit/range to inspect (e.g., HEAD, HEAD~1..HEAD). Defaults to checking uncommitted files (untracked & staged), or HEAD if working tree is clean.

.PARAMETER StartedOnly
    Prioritize/restrict updating and packing to configs targeting repomix/outputs/started (default: $false - maps across all relevant configs, but always repacks started bundles).

.PARAMETER Pack
    Automatically execute Repomix packing after updating configs (default: $true).

.PARAMETER DryRun
    Preview mappings and additions without modifying JSON config files or executing Repomix.

.PARAMETER Domain
    Optional specific domain or subsystem to target (e.g. pos, orders, inventory, database, procurement, overview).

.PARAMETER IncludeTests
    Allow including test files even if the target config typically ignores tests (default: $false, per platform audit rules).
#>

[CmdletBinding()]
param (
    [string]$Commit,
    [switch]$StartedOnly,
    [bool]$Pack = $true,
    [switch]$DryRun,
    [string]$Domain,
    [switch]$IncludeTests = $false,
    [switch]$OnlyModified = $false
)

$ErrorActionPreference = 'Stop'
$RootDir = Resolve-Path "$PSScriptRoot\.."
$ConfigsDir = Join-Path $RootDir 'repomix\configs'
$OutputsDir = Join-Path $RootDir 'repomix\outputs'
$StartedDir = Join-Path $OutputsDir 'started'

if (-not (Test-Path $StartedDir)) {
    New-Item -ItemType Directory -Path $StartedDir -Force | Out-Null
}

Write-Host "=================================================================" -ForegroundColor Cyan
Write-Host " Repomix Started Sync & Bundle Runner" -ForegroundColor Cyan
Write-Host " Root: $RootDir" -ForegroundColor DarkGray
Write-Host "=================================================================" -ForegroundColor Cyan

# 1. Discover New / Modified Files
$targetFiles = [System.Collections.Generic.List[string]]::new()

if ($Commit) {
    Write-Host "[1/4] Inspecting git commit / range: $Commit" -ForegroundColor Yellow
    $gitFiles = git diff-tree --no-commit-id --name-only -r $Commit
    foreach ($f in $gitFiles) {
        if (-not [string]::IsNullOrWhiteSpace($f)) {
            $targetFiles.Add(($f.Trim() -replace '\\', '/'))
        }
    }
} else {
    Write-Host "[1/4] Detecting uncommitted files (untracked & staged)..." -ForegroundColor Yellow
    $statusLines = git status --porcelain
    foreach ($line in $statusLines) {
        if ($line.Length -gt 3) {
            $code = $line.Substring(0, 2).Trim()
            $rawPath = $line.Substring(3).Trim().Trim('"')
            if ($rawPath -match ' -> (.+)$') {
                $rawPath = $Matches[1].Trim().Trim('"')
            }
            $filePath = $rawPath -replace '\\', '/'
            # Filter ?? (untracked), A (added), M (modified), R (renamed)
            if ($code -match '^(?:\?\?|A|M|R)') {
                $fullCandidate = Join-Path $RootDir $filePath
                if (Test-Path -Path $fullCandidate -PathType Container) {
                    $rootPrefixLen = $RootDir.Path.TrimEnd('\', '/').Length + 1
                    Get-ChildItem -Path $fullCandidate -File -Recurse | ForEach-Object {
                        $relChild = $_.FullName.Substring($rootPrefixLen)
                        $normChild = ($relChild -replace '^\.\\', '' -replace '^\./', '') -replace '\\', '/'
                        $targetFiles.Add($normChild)
                    }
                } else {
                    $targetFiles.Add($filePath)
                }
            }
        }
    }

    # If working tree is clean, inspect the latest commit (HEAD)
    if ($targetFiles.Count -eq 0) {
        Write-Host "  -> Working tree is clean. Auto-detecting changes from latest commit (HEAD)..." -ForegroundColor DarkGray
        $gitFiles = git diff-tree --no-commit-id --name-only -r HEAD
        foreach ($f in $gitFiles) {
            if (-not [string]::IsNullOrWhiteSpace($f)) {
                $targetFiles.Add(($f.Trim() -replace '\\', '/'))
            }
        }
    }
}

# Ignore unwanted paths & build artifacts
$filteredFiles = [System.Collections.Generic.List[string]]::new()
$ignorePatterns = @(
    '^node_modules/', '/node_modules/',
    '^vendor/', '/vendor/',
    '^\.git/', '/\.git/',
    '^storage/', '/storage/',
    '\.log$',
    '^repomix/outputs/',
    '^repomix/configs/',
    '^tasks/',
    '/tasks/',
    'phpunit.*\.xml$',
    '^_archive_unused/', '/_archive_unused/'
)

foreach ($file in $targetFiles) {
    if ([string]::IsNullOrWhiteSpace($file)) { continue }
    $ignored = $false
    foreach ($pat in $ignorePatterns) {
        if ($file -match $pat) {
            $ignored = $true
            break
        }
    }
    # Check if test file should be ignored
    if (-not $IncludeTests -and ($file -match '/tests/|\.test\.|\.spec\.')) {
        $ignored = $true
    }

    if (-not $ignored -and (Test-Path (Join-Path $RootDir $file))) {
        $filteredFiles.Add($file)
    }
}

Write-Host "  -> Found $($filteredFiles.Count) relevant candidate file(s)." -ForegroundColor Green

# 2. Load Configs
$allConfigs = @{}
$startedConfigs = @{}

Get-ChildItem -Path $ConfigsDir -Filter "repomix.*.json" | ForEach-Object {
    $cfgObj = Get-Content $_.FullName -Raw | ConvertFrom-Json
    $outFileName = Split-Path $cfgObj.output.filePath -Leaf
    $entry = @{
        Path = $_.FullName
        Name = $_.Name
        Config = $cfgObj
        IsStarted = ($cfgObj.output.filePath -match 'started' -or (Test-Path (Join-Path $StartedDir $outFileName)))
    }
    $allConfigs[$_.Name] = $entry
    if ($entry.IsStarted) {
        $startedConfigs[$_.Name] = $entry
    }
}

Write-Host "[2/4] Loaded $($allConfigs.Count) Repomix configs ($($startedConfigs.Count) configured for 'started' audits)." -ForegroundColor Yellow

# Helper: Test if a file path is already matched by an include pattern
function Test-PathMatchedByGlobs([string]$relPath, [array]$includePatterns) {
    $normPath = $relPath -replace '\\', '/'
    foreach ($pattern in $includePatterns) {
        $normPattern = $pattern -replace '\\', '/'
        
        # Exact match
        if ($normPath -eq $normPattern) { return $true }

        # Convert simple glob to regex: e.g. **/*.php or docs/** or *.php
        $regex = '^' + [regex]::Escape($normPattern)
        $regex = $regex -replace '\\\*\\\*/', '(?:.*/)?'
        $regex = $regex -replace '\\\*\\\*', '.*'
        $regex = $regex -replace '\\\*', '[^/]*'
        $regex = $regex -replace '\\\?', '.'
        $regex = $regex + '$'

        if ($normPath -match $regex) {
            return $true
        }
    }
    return $false
}

# 3. Domain Resolution Logic
function Get-MatchingConfigsForFile([string]$relPath, [hashtable]$configs, [bool]$startedOnly) {
    $p = ($relPath -replace '\\', '/').ToLower()
    $matchedConfigs = [System.Collections.Generic.HashSet[string]]::new([System.StringComparer]::OrdinalIgnoreCase)

    # 1. Database Schema & Migrations
    if ($p -match 'migrations/|seeders/|factories/|database\.php|pgbouncer|verifydatabaseschema') {
        if ($configs.ContainsKey('repomix.database-schema.json')) { [void]$matchedConfigs.Add('repomix.database-schema.json') }
        if ($configs.ContainsKey('repomix.subsystem-10-infra-devops.json')) { [void]$matchedConfigs.Add('repomix.subsystem-10-infra-devops.json') }
        
        # Domain-specific migrations
        if ($p -match 'procurement|supplier|purchase_order') {
            if ($configs.ContainsKey('repomix.procurement-suppliers.json')) { [void]$matchedConfigs.Add('repomix.procurement-suppliers.json') }
            if ($configs.ContainsKey('repomix.subsystem-08-procurement-suppliers.json')) { [void]$matchedConfigs.Add('repomix.subsystem-08-procurement-suppliers.json') }
        }
        if ($p -match 'order|return|refund|checkout') {
            if ($configs.ContainsKey('repomix.orders-returns.json')) { [void]$matchedConfigs.Add('repomix.orders-returns.json') }
            if ($configs.ContainsKey('repomix.subsystem-02-orders-returns.json')) { [void]$matchedConfigs.Add('repomix.subsystem-02-orders-returns.json') }
        }
        if ($p -match 'stock|inventory|movement|stocktake|transfer|branch|location|production_receipt|productionreceipt') {
            if ($configs.ContainsKey('repomix.subsystem-03-inventory-ledger.json')) { [void]$matchedConfigs.Add('repomix.subsystem-03-inventory-ledger.json') }
        }
        if ($p -match 'webhook|whatsapp|ai_') {
            if ($configs.ContainsKey('repomix.subsystem-05-ai-trader-gateway.json')) { [void]$matchedConfigs.Add('repomix.subsystem-05-ai-trader-gateway.json') }
        }
        if ($p -match 'financial|treasury|expense|precision|reconcil|number_sync|numbering|role_timeout') {
            if ($configs.ContainsKey('repomix.subsystem-07-finance-treasury.json')) { [void]$matchedConfigs.Add('repomix.subsystem-07-finance-treasury.json') }
        }
        if ($p -match 'pos|cashier|shift') {
            if ($configs.ContainsKey('repomix.subsystem-01-pos-terminal.json')) { [void]$matchedConfigs.Add('repomix.subsystem-01-pos-terminal.json') }
        }
        if ($p -match 'catalog|\bproduct\b|category|cart|coupon' -and $p -notmatch 'production') {
            if ($configs.ContainsKey('repomix.subsystem-06-storefront-catalog.json')) { [void]$matchedConfigs.Add('repomix.subsystem-06-storefront-catalog.json') }
        }
        if ($p -match 'statement_timeout|idle_transaction|hot_fk|drop_redundant|widen_text') {
            if ($configs.ContainsKey('repomix.subsystem-10-infra-devops.json')) { [void]$matchedConfigs.Add('repomix.subsystem-10-infra-devops.json') }
        }
    }

    # 2. Procurement & Suppliers
    if ($p -match 'supplier|procurement|purchaseorder|purchaserequest|goodsreceipt') {
        if ($configs.ContainsKey('repomix.procurement-suppliers.json')) { [void]$matchedConfigs.Add('repomix.procurement-suppliers.json') }
        if ($configs.ContainsKey('repomix.subsystem-08-procurement-suppliers.json')) { [void]$matchedConfigs.Add('repomix.subsystem-08-procurement-suppliers.json') }
    }

    # 3. Orders, Returns & Refunds
    if (($p -match 'returnservice|refund|returnrequest|/orders/|orderservice|paymob|orderitem|orderstatus|/models/order\.php|/models/orderitem\.php|operations/orders\.ts|checkoutreservation|tasktxtroadmapreturns|attachment|r2upload|migrateuploadstor2|pruneorphanmedia') -and $p -notmatch 'wholesale') {
        if ($configs.ContainsKey('repomix.orders-returns.json')) { [void]$matchedConfigs.Add('repomix.orders-returns.json') }
        if ($configs.ContainsKey('repomix.subsystem-02-orders-returns.json')) { [void]$matchedConfigs.Add('repomix.subsystem-02-orders-returns.json') }
    }

    # 4. Payment Webhook (shared between AI Trader, Orders & Treasury)
    if ($p -match 'paymentwebhook') {
        if ($configs.ContainsKey('repomix.subsystem-05-ai-trader-gateway.json')) { [void]$matchedConfigs.Add('repomix.subsystem-05-ai-trader-gateway.json') }
        if ($configs.ContainsKey('repomix.orders-returns.json')) { [void]$matchedConfigs.Add('repomix.orders-returns.json') }
        if ($configs.ContainsKey('repomix.subsystem-02-orders-returns.json')) { [void]$matchedConfigs.Add('repomix.subsystem-02-orders-returns.json') }
    }

    # 5. POS Terminal
    if ($p -match 'apps/pos/|packages/pos-core/|pos-core/|/pos/|posservice|cashier|shift|drawer|terminal|pos.*receipt|print.*receipt|packages/utils/' -and $p -notmatch 'productionreceipt') {
        if ($configs.ContainsKey('repomix.subsystem-01-pos-terminal.json')) { [void]$matchedConfigs.Add('repomix.subsystem-01-pos-terminal.json') }
        if ($configs.ContainsKey('repomix.pos-terminal.json')) { [void]$matchedConfigs.Add('repomix.pos-terminal.json') }
    }

    # 6. Inventory & Ledger
    if ($p -match 'transferservice|stocktake|inventoryledger|inventorymovement|branchstock|warehouse|inventoryservice|/inventory/|productionreceipt|production_receipt|inventory.*seeder|/branches/|/locations/|actions/branch|actions/location|inventoryanomalies|detectinventoryanomalies|stocklevel|stocktransfer|/models/stock|productvariant') {
        if ($configs.ContainsKey('repomix.subsystem-03-inventory-ledger.json')) { [void]$matchedConfigs.Add('repomix.subsystem-03-inventory-ledger.json') }
        if ($configs.ContainsKey('repomix.inventory-ledger.json')) { [void]$matchedConfigs.Add('repomix.inventory-ledger.json') }
    }

    # 7. Wholesale & B2B
    if ($p -match 'wholesale|quotation|paymentnotice|fifodebt|b2b|normalizescustomerinput|pricing|pricelist|rejectexecutable|customergroup|spokenquantity') {
        if ($configs.ContainsKey('repomix.subsystem-04-wholesale-b2b.json')) { [void]$matchedConfigs.Add('repomix.subsystem-04-wholesale-b2b.json') }
        if ($configs.ContainsKey('repomix.wholesale-b2b.json')) { [void]$matchedConfigs.Add('repomix.wholesale-b2b.json') }
    }

    # 8. AI Trader & WhatsApp Gateway
    if ($p -match 'ai-trader|whatsapp|baileys|gemini|agentorchestrator|toolregistry|outboundmessage|spokenquantity') {
        if ($configs.ContainsKey('repomix.subsystem-05-ai-trader-gateway.json')) { [void]$matchedConfigs.Add('repomix.subsystem-05-ai-trader-gateway.json') }
        if ($configs.ContainsKey('repomix.subsystem-05.1-ai-gateway-core.json') -and ($p -match 'wa-gateway|webhook|gemini|orchestrator|whatsapp')) {
            [void]$matchedConfigs.Add('repomix.subsystem-05.1-ai-gateway-core.json')
        }
        if ($configs.ContainsKey('repomix.subsystem-05.2-ai-domain-tools.json') -and ($p -match 'tool|spokenquantity|order|trader')) {
            [void]$matchedConfigs.Add('repomix.subsystem-05.2-ai-domain-tools.json')
        }
        if ($configs.ContainsKey('repomix.ai-trader-gateway.json')) { [void]$matchedConfigs.Add('repomix.ai-trader-gateway.json') }
    }

    # 9. Storefront & Catalog
    if ($p -match 'apps/storefront/|catalog|category|cart|coupon|promotion|\bproducts?\b|productvariant|customer.*auth|normalizescustomerinput' -and $p -notmatch 'production') {
        if ($configs.ContainsKey('repomix.subsystem-06-storefront-catalog.json')) { [void]$matchedConfigs.Add('repomix.subsystem-06-storefront-catalog.json') }
        if ($configs.ContainsKey('repomix.subsystem-06.1-storefront-client.json') -and ($p -match 'apps/storefront')) {
            [void]$matchedConfigs.Add('repomix.subsystem-06.1-storefront-client.json')
        }
        if ($configs.ContainsKey('repomix.subsystem-06.2-catalog-shipping.json') -and ($p -match 'catalog|category|cart|coupon|promotion|\bproducts?\b|productvariant')) {
            [void]$matchedConfigs.Add('repomix.subsystem-06.2-catalog-shipping.json')
        }
        if ($configs.ContainsKey('repomix.storefront-catalog.json')) { [void]$matchedConfigs.Add('repomix.storefront-catalog.json') }
    }

    # 10. Shipping & Logistics
    if (($p -match 'shipping|shipment|carrier|logistic' -or ($p -match 'delivery' -and $p -notmatch 'supplier|procurement')) -and $p -notmatch 'supplier|procurement') {
        if ($configs.ContainsKey('repomix.shipping-logistics.json')) { [void]$matchedConfigs.Add('repomix.shipping-logistics.json') }
    }

    # 11. Finance & Treasury
    if ($p -match 'treasury|doubleentry|expense|vault|financial|\bfinance\b|financeservice|wallet|budget|reportservice|statementbudget|reconcil') {
        if ($configs.ContainsKey('repomix.subsystem-07-finance-treasury.json')) { [void]$matchedConfigs.Add('repomix.subsystem-07-finance-treasury.json') }
        if ($configs.ContainsKey('repomix.finance-treasury.json')) { [void]$matchedConfigs.Add('repomix.finance-treasury.json') }
    }

    # 12. Security & Backoffice
    if (($p -match 'apps/backoffice/|permission|role|staff|throttle|sanctum|\bauth\b|/auth/|unifiedauth|serializesaccountemail|normalizescustomerinput|app/rules|/rules/|operations/http' -or ($p -match '\bguard\b' -and $p -notmatch 'procurement|order|supplier')) -and $p -notmatch 'procurement|supplier|customerauth|wholesale') {
        if ($configs.ContainsKey('repomix.subsystem-09-security-backoffice.json')) { [void]$matchedConfigs.Add('repomix.subsystem-09-security-backoffice.json') }
        if ($configs.ContainsKey('repomix.subsystem-09.1-security-auth-rbac.json') -and ($p -match 'auth|permission|role|sanctum|staff')) {
            [void]$matchedConfigs.Add('repomix.subsystem-09.1-security-auth-rbac.json')
        }
        if ($configs.ContainsKey('repomix.subsystem-09.2-backoffice-notifications.json') -and ($p -match 'backoffice|notification|operations/http')) {
            [void]$matchedConfigs.Add('repomix.subsystem-09.2-backoffice-notifications.json')
        }
        if ($configs.ContainsKey('repomix.backoffice-operations.json')) { [void]$matchedConfigs.Add('repomix.backoffice-operations.json') }
    }

    # 12b. Base Controllers & Core Framework Classes
    if ($p -match 'app/http/controllers/controller\.php$') {
        if ($configs.ContainsKey('repomix.subsystem-01-pos-terminal.json')) { [void]$matchedConfigs.Add('repomix.subsystem-01-pos-terminal.json') }
        if ($configs.ContainsKey('repomix.subsystem-09-security-backoffice.json')) { [void]$matchedConfigs.Add('repomix.subsystem-09-security-backoffice.json') }
        if ($configs.ContainsKey('repomix.backoffice-operations.json')) { [void]$matchedConfigs.Add('repomix.backoffice-operations.json') }
    }

    # 13. Infrastructure & DevOps
    if ($p -match 'infrastructure/|docker|k6/|haproxy|redis|cloud-run|\.env(\..+)?$|healthready|trustproxy|exceptions/|businessexception|maintenance/|r2upload|pruneorphanmedia') {
        if ($configs.ContainsKey('repomix.subsystem-10-infra-devops.json')) { [void]$matchedConfigs.Add('repomix.subsystem-10-infra-devops.json') }
    }

    # 14. Shared Model Concerns
    if ($p -match 'serializesaccountemail|models/concerns') {
        if ($configs.ContainsKey('repomix.subsystem-09-security-backoffice.json')) { [void]$matchedConfigs.Add('repomix.subsystem-09-security-backoffice.json') }
        if ($configs.ContainsKey('repomix.subsystem-06-storefront-catalog.json')) { [void]$matchedConfigs.Add('repomix.subsystem-06-storefront-catalog.json') }
        if ($configs.ContainsKey('repomix.subsystem-08-procurement-suppliers.json')) { [void]$matchedConfigs.Add('repomix.subsystem-08-procurement-suppliers.json') }
        if ($configs.ContainsKey('repomix.subsystem-10-infra-devops.json')) { [void]$matchedConfigs.Add('repomix.subsystem-10-infra-devops.json') }
    }

    # 14. System Overview & Core Documentation / Scripts
    if ($p -match 'readme|\.env|composer\.json|package\.json|turbo\.json|bootstrap/|middleware/|providers/|routes/|scripts/|docs/|healthready|claude\.md|api-client/src/endpoints/index\.ts|api-client/src/index\.ts') {
        if ($configs.ContainsKey('repomix.system-overview.json')) { [void]$matchedConfigs.Add('repomix.system-overview.json') }
        if ($configs.ContainsKey('repomix.subsystem-10-infra-devops.json')) { [void]$matchedConfigs.Add('repomix.subsystem-10-infra-devops.json') }
    }

    # Fallback to system-overview if nothing matched
    if ($matchedConfigs.Count -eq 0 -and $configs.ContainsKey('repomix.system-overview.json')) {
        [void]$matchedConfigs.Add('repomix.system-overview.json')
    }

    # If startedOnly, filter down to only started configs
    if ($startedOnly) {
        $filtered = @($matchedConfigs | Where-Object { $configs[$_].IsStarted })
        return $filtered
    }

    return @($matchedConfigs)
}

# 4. Map files and identify updates
Write-Host "[3/4] Mapping files to Repomix configs..." -ForegroundColor Yellow

$pendingAdditions = @{}
$allActiveConfigs = [System.Collections.Generic.HashSet[string]]::new()

foreach ($file in $filteredFiles) {
    $targetConfigKeys = Get-MatchingConfigsForFile -relPath $file -configs $allConfigs -startedOnly $StartedOnly
    if (-not $targetConfigKeys -or $targetConfigKeys.Count -eq 0) {
        Write-Warning "Could not find suitable config for file: $file"
        continue
    }

    foreach ($targetConfigKey in $targetConfigKeys) {
        [void]$allActiveConfigs.Add($targetConfigKey)
        $cfgEntry = $allConfigs[$targetConfigKey]
        $currentIncludes = @($cfgEntry.Config.include)

        # Check if already covered
        $isCovered = Test-PathMatchedByGlobs -relPath $file -includePatterns $currentIncludes
        if ($isCovered) {
            Write-Host "  [OK] Already covered: $file -> $targetConfigKey" -ForegroundColor DarkGray
        } else {
            Write-Host "  [+] Needs addition: $file -> $targetConfigKey" -ForegroundColor Green
            if (-not $pendingAdditions.ContainsKey($targetConfigKey)) {
                $pendingAdditions[$targetConfigKey] = [System.Collections.Generic.List[string]]::new()
            }
            if (-not $pendingAdditions[$targetConfigKey].Contains($file)) {
                $pendingAdditions[$targetConfigKey].Add($file)
            }
        }
    }
}

# 5. Apply Updates to Config Files
$modifiedConfigs = [System.Collections.Generic.HashSet[string]]::new()

foreach ($cfgKey in $pendingAdditions.Keys) {
    $cfgEntry = $allConfigs[$cfgKey]
    $filesToAdd = $pendingAdditions[$cfgKey]

    Write-Host "`nUpdating Config: $cfgKey (+ $($filesToAdd.Count) new files)" -ForegroundColor Cyan
    $cfgPath = $cfgEntry.Path
    $rawJson = Get-Content $cfgPath -Raw
    $parsed = $rawJson | ConvertFrom-Json

    $existingList = [System.Collections.Generic.List[string]]::new([string[]]$parsed.include)
    foreach ($newFile in $filesToAdd) {
        if (-not $existingList.Contains($newFile)) {
            $existingList.Add($newFile)
            Write-Host "  + Included: $newFile" -ForegroundColor Green
        }
    }
    $parsed.include = $existingList.ToArray()

    if (-not $DryRun) {
        $updatedJson = $parsed | ConvertTo-Json -Depth 10
        [System.IO.File]::WriteAllText($cfgPath, $updatedJson, [System.Text.Encoding]::UTF8)
        [void]$modifiedConfigs.Add($cfgKey)
        Write-Host "  -> Saved changes to $cfgPath" -ForegroundColor DarkCyan
    } else {
        Write-Host "  [DryRun] Would save changes to $cfgPath" -ForegroundColor Yellow
    }
}

# 6. Execute Repomix Packing ("وف الاخر هيشغل الملفات عشان اشوف الكود بعد التعديل")
if ($Pack -and -not $DryRun) {
    Write-Host "`n[4/4] Executing Repomix packing..." -ForegroundColor Yellow

    $configsToPack = [System.Collections.Generic.HashSet[string]]::new()

    if ($Domain) {
        $domainConfig = "repomix.$Domain.json"
        if ($allConfigs.ContainsKey($domainConfig)) {
            [void]$configsToPack.Add($domainConfig)
        } else {
            # Try searching by prefix
            $escapedDomain = [regex]::Escape($Domain)
            $matched = $allConfigs.Keys | Where-Object { $_ -match $escapedDomain }
            foreach ($m in $matched) { [void]$configsToPack.Add($m) }
        }
    } else {
        # Always pack any modified config
        foreach ($m in $modifiedConfigs) {
            [void]$configsToPack.Add($m)
        }
        # If not OnlyModified, also pack all configs affected by modified files & started configs
        if (-not $OnlyModified) {
            foreach ($a in $allActiveConfigs) {
                [void]$configsToPack.Add($a)
            }
            foreach ($s in $startedConfigs.Keys) {
                [void]$configsToPack.Add($s)
            }
        }
    }

    if ($configsToPack.Count -eq 0) {
        Write-Host "No configs require packing." -ForegroundColor Yellow
        return
    }

    Write-Host "Target configs to pack: $($configsToPack.Count)" -ForegroundColor Cyan
    $summaryResults = [System.Collections.Generic.List[PSCustomObject]]::new()

    foreach ($cName in $configsToPack) {
        $cfgObj = $allConfigs[$cName].Config
        $outPath = $cfgObj.output.filePath
        Write-Host "`n--------------------------------------------------------" -ForegroundColor DarkCyan
        Write-Host "Packing: $cName" -ForegroundColor Cyan
        Write-Host "Output:  $outPath" -ForegroundColor DarkGray
        Write-Host "--------------------------------------------------------" -ForegroundColor DarkCyan

        $cfgRel = "repomix/configs/$cName"
        npx repomix -c $cfgRel
        if ($LASTEXITCODE -ne 0) {
            Write-Error "Repomix packing failed for $cName with code $LASTEXITCODE"
        } else {
            $fullOut = Join-Path $RootDir $outPath
            $fileSizeKb = 0
            if (Test-Path $fullOut) {
                $fItem = Get-Item $fullOut
                $fileSizeKb = [math]::Round($fItem.Length / 1KB, 1)

                # Ensure fresh copy in repomix/outputs/started/
                $startedCopyPath = Join-Path $StartedDir (Split-Path $outPath -Leaf)
                Copy-Item -Path $fullOut -Destination $startedCopyPath -Force
            }
            $summaryResults.Add([PSCustomObject]@{
                Config = $cName
                Output = $outPath
                SizeKB = $fileSizeKb
                Status = "PACKED"
            })
            Write-Host "SUCCESS: Generated $outPath ($fileSizeKb KB)" -ForegroundColor Green
        }
    }

    Write-Host "`n=================================================================" -ForegroundColor Cyan
    Write-Host " Repomix Generation Summary" -ForegroundColor Cyan
    Write-Host "=================================================================" -ForegroundColor Cyan
    $summaryResults | Format-Table -AutoSize
} elseif ($DryRun) {
    Write-Host "`n[DryRun] Skipping Repomix execution." -ForegroundColor Yellow
}

Write-Host "Repomix Started Sync completed successfully!`n" -ForegroundColor Green
