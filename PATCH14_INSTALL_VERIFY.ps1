# Run as ONE .ps1 script, NOT line by line. STAGING ONLY.
$ErrorActionPreference = 'Stop'
$project = 'D:\Projects\KAU EMS\Kau-cline'
$patchDir = 'D:\Projects\KAU EMS\new patch'
Set-Location -LiteralPath $project
if (-not (Test-Path '.\artisan') -or -not (Test-Path '.\vendor\autoload.php')) { throw 'Laravel project/vendor missing' }
$matches = @(Get-ChildItem -LiteralPath $patchDir -File -Filter 'KAU_EMS_PATCH_14_VUE_TEST_RECOVERY_AUDIT_PARTIAL.zip')
if ($matches.Count -ne 1) { throw "Expected exactly one PATCH 14 ZIP in $patchDir; found $($matches.Count)" }
$zip = $matches[0].FullName
Write-Host "Selected $zip"
$verified = Read-Host 'Staging database and source backup verified? YES'
if ($verified.ToUpperInvariant() -ne 'YES') { throw 'Backup required.' }
$root = (Get-Location).Path
$backup = Join-Path $root ('_patch14_source_backup_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
New-Item -ItemType Directory -Path $backup -Force | Out-Null
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [IO.Compression.ZipFile]::OpenRead($zip)
try {
    foreach ($entry in $archive.Entries) {
        if (-not $entry.Name) { continue }
        $rel = $entry.FullName.Replace('/', '\')
        if ($rel.StartsWith('\') -or $rel -match '(^|\)\.\.(\|$)' -or [IO.Path]::IsPathRooted($rel)) { throw "Unsafe ZIP path: $rel" }
        $existing = Join-Path $root $rel
        if (Test-Path -LiteralPath $existing -PathType Leaf) {
            $dest = Join-Path $backup $rel
            New-Item -ItemType Directory -Path (Split-Path -Parent $dest) -Force | Out-Null
            Copy-Item -LiteralPath $existing -Destination $dest -Force
        }
    }
} finally { $archive.Dispose() }
Expand-Archive -LiteralPath $zip -DestinationPath $root -Force
$phpPaths = @('tests/Feature/SalaryPatch13WorkflowRecoveryTest.php','app/Console/Commands/SalaryOctober18ReadinessAudit.php')
foreach ($file in $phpPaths) {
    php -l $file
    if ($LASTEXITCODE -ne 0) { throw "Lint failed: $file" }
}
php artisan optimize:clear
if ($LASTEXITCODE -ne 0) { throw 'Cache clear failed.' }
php artisan test tests/Feature/SalaryPatch13WorkflowRecoveryTest.php
if ($LASTEXITCODE -ne 0) { throw 'PATCH 13 test regression failed.' }
# Read-only audit; capture actual database evidence in file.
$report = Join-Path $backup 'october18_readiness.json'
php artisan kau:audit-october-18 --json | Out-File -FilePath $report -Encoding utf8
if ($LASTEXITCODE -ne 0) { throw 'Read-only October audit failed.' }
if (-not (Test-Path '.\node_modules\.bin\vite.cmd')) { throw 'Vite dependencies missing.' }
npm run build
if ($LASTEXITCODE -ne 0) { throw 'Vite build failed.' }
Write-Host "Backup and evidence: $backup"
Write-Warning 'This only fixes Vue/test regressions and inventories evidence; no financial approval or posting has been validated.'
