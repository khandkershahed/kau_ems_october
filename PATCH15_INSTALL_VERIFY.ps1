# Run this entire file (not each line separately) from an isolated staging copy.
$ErrorActionPreference = 'Stop'
$project = 'D:\Projects\KAU EMS\Kau-cline'
$patchDir = 'D:\Projects\KAU EMS\new patch'
Set-Location -LiteralPath $project
if (-not (Test-Path '.\artisan') -or -not (Test-Path '.\vendor\autoload.php')) { throw 'Laravel project or Composer vendor missing.' }
$matches = @(Get-ChildItem -LiteralPath $patchDir -File -Filter 'KAU_EMS_PATCH_15_*.zip')
if ($matches.Count -ne 1) { throw "Expected exactly one PATCH 15 ZIP in $patchDir. Found $($matches.Count)." }
$zip = $matches[0].FullName
$answer = Read-Host 'Is this an isolated staging DB with verified source/DB backups? Type YES'
if ($answer.ToUpperInvariant() -ne 'YES') { throw 'Staging backup required.' }
$root = (Get-Location).Path
$backup = Join-Path $root ('_patch15_source_backup_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
New-Item -ItemType Directory -Path $backup -Force | Out-Null
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [IO.Compression.ZipFile]::OpenRead($zip)
try {
    foreach ($entry in $archive.Entries) {
        if (-not $entry.Name) { continue }
        $rel = $entry.FullName.Replace('/', '\')
        if ($rel.StartsWith('\') -or [IO.Path]::IsPathRooted($rel) -or $rel -split '\\' -contains '..') { throw "Unsafe ZIP entry: $rel" }
        $original = Join-Path $root $rel
        if (Test-Path -LiteralPath $original -PathType Leaf) {
            $dest = Join-Path $backup $rel
            New-Item -ItemType Directory -Path (Split-Path -Parent $dest) -Force | Out-Null
            Copy-Item -LiteralPath $original -Destination $dest -Force
        }
    }
} finally { $archive.Dispose() }
Expand-Archive -LiteralPath $zip -DestinationPath $root -Force
php -l 'tests/Feature/SalaryPatch15RosterRecoveryTest.php'
if ($LASTEXITCODE -ne 0) { throw 'PHP lint failed.' }
php artisan optimize:clear
if ($LASTEXITCODE -ne 0) { throw 'Cache clear failed.' }
php artisan route:list --name=salary-workflow.reconcile
if ($LASTEXITCODE -ne 0) { throw 'Reconcile route unavailable.' }
php artisan test tests/Feature/SalaryPatch15RosterRecoveryTest.php
if ($LASTEXITCODE -ne 0) { throw 'PATCH 15 feature tests failed.' }
if (-not (Test-Path '.\node_modules\.bin\vite.cmd')) { throw 'Vite missing.' }
npm run build
if ($LASTEXITCODE -ne 0) { throw 'Vite build failed.' }
$report = Join-Path $backup 'october18_readiness.json'
$evidence = & php artisan kau:audit-october-18 --json
if ($LASTEXITCODE -ne 0) { throw 'Financial readiness audit failed.' }
$evidence | Out-File -LiteralPath $report -Encoding utf8
php artisan optimize:clear
if ($LASTEXITCODE -ne 0) { throw 'Final cache clear failed.' }
Write-Host "Backup: $backup"
Write-Host "Read-only audit: $report"
Write-Warning 'Click Initialize October Roster in staging; do not post financial bills until full parity verification.'
