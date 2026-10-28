$ErrorActionPreference = 'Stop'
$project = 'D:\Projects\KAU EMS\Kau-cline'
$patchDir = 'D:\Projects\KAU EMS\new patch'
$zip = Join-Path $patchDir 'KAU_EMS_PATCH_21_BULK_WORKFLOW_REMEDIATION_PARTIAL.zip'
if (-not (Test-Path -LiteralPath $zip)) { throw "ZIP not found: $zip" }
Set-Location -LiteralPath $project
if (-not (Test-Path '.\artisan') -or -not (Test-Path '.\vendor\autoload.php')) { throw 'Laravel dependencies missing' }
$ok = Read-Host 'Confirm isolated STAGING database AND independently verified source + database backups (YES)'
if ($ok -ne 'YES') { throw 'STAGING backups required' }
$backup = Join-Path $project ('_patch21_backup_'+(Get-Date -Format 'yyyyMMdd_HHmmss'))
$manifest = @('app/Http/Controllers/SalaryBulkCalculationPersistenceController.php','app/Http/Controllers/SalaryEstimationWorkspaceController.php','resources/js/Pages/Salary/Estimation/Workspace.vue','tests/Feature/SalaryPatch21BulkContractTest.php')
foreach ($file in $manifest) {
  $existing = Join-Path $project $file
  if (Test-Path -LiteralPath $existing) {
    $target = Join-Path $backup $file
    New-Item -ItemType Directory -Path (Split-Path -Parent $target) -Force | Out-Null
    Copy-Item -LiteralPath $existing -Destination $target -Force
  }
}
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [IO.Compression.ZipFile]::OpenRead($zip)
try {
  foreach ($file in $manifest) {
    $entry = $archive.GetEntry($file)
    if ($null -eq $entry) { throw "Missing ZIP entry: $file" }
    $target = Join-Path $project $file
    New-Item -ItemType Directory -Path (Split-Path -Parent $target) -Force | Out-Null
    $inputStream = $entry.Open()
    $outputStream = [IO.File]::Create($target)
    try { $inputStream.CopyTo($outputStream) }
    finally { $outputStream.Dispose(); $inputStream.Dispose() }
  }
} finally { $archive.Dispose() }
php -l app/Http/Controllers/SalaryBulkCalculationPersistenceController.php
if ($LASTEXITCODE -ne 0) { throw 'PHP lint failed' }
php -l app/Http/Controllers/SalaryEstimationWorkspaceController.php
if ($LASTEXITCODE -ne 0) { throw 'PHP lint failed' }
php -l tests/Feature/SalaryPatch21BulkContractTest.php
if ($LASTEXITCODE -ne 0) { throw 'Test lint failed' }
php artisan optimize:clear
if ($LASTEXITCODE -ne 0) { throw 'Cache failed' }
php artisan test tests/Feature/SalaryPatch21BulkContractTest.php
if ($LASTEXITCODE -ne 0) { throw 'Laravel tests failed' }
npm run build
if ($LASTEXITCODE -ne 0) { throw 'Vite build failed' }
Write-Host "Source backup: $backup"
Write-Warning 'Master Plan Phase 3 and financial reconciliation are NOT complete. Do not post salaries.'
