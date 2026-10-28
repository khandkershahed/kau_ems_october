$ErrorActionPreference = 'Stop'
$project = 'D:\Projects\KAU EMS\Kau-cline'
$patchDir = 'D:\Projects\KAU EMS\new patch'
$zip = Join-Path $patchDir 'KAU_EMS_PATCH_19_SAVED_REPORT_COMMON_EDIT_PARTIAL.zip'
if (!(Test-Path -LiteralPath $zip -PathType Leaf)) { throw "PATCH 19 ZIP missing: $zip" }
Set-Location -LiteralPath $project
if (!(Test-Path '.\artisan') -or !(Test-Path '.\vendor\autoload.php')) { throw 'Laravel root or vendor missing' }
$confirm = Read-Host 'Is this an isolated staging clone with verified SOURCE and DB backups? Type YES'
if ($confirm.ToUpperInvariant() -ne 'YES') { throw 'Staging verification required' }
$backup = Join-Path $project ('_patch19_backup_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [IO.Compression.ZipFile]::OpenRead($zip)
try {
 foreach ($entry in $archive.Entries) {
  if (!$entry.Name) { continue }
  $rel = $entry.FullName.Replace('/', '\')
  if ($rel.StartsWith('\') -or [IO.Path]::IsPathRooted($rel) -or $rel -match '(^|[\\/])\.\.([\\/]|$)') { throw "Unsafe archive entry: $rel" }
  $existing = Join-Path $project $rel
  if (Test-Path -LiteralPath $existing -PathType Leaf) {
   $dest = Join-Path $backup $rel
   New-Item -ItemType Directory -Path (Split-Path -Parent $dest) -Force | Out-Null
   Copy-Item -LiteralPath $existing -Destination $dest -Force
  }
 }
} finally { $archive.Dispose() }
Expand-Archive -LiteralPath $zip -DestinationPath $project -Force
foreach ($file in @('app/Http/Controllers/SalaryProvisionalBillController.php','tests/Feature/SalaryPatch19ProvisionalContractTest.php')) {
 php -l $file
 if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $file" }
}
php artisan optimize:clear
if ($LASTEXITCODE -ne 0) { throw 'Laravel optimize:clear failed' }
php artisan route:list --name=salary-provisional.show
if ($LASTEXITCODE -ne 0) { throw 'Provisional route missing' }
php artisan test tests/Feature/SalaryPatch19ProvisionalContractTest.php
if ($LASTEXITCODE -ne 0) { throw 'PATCH 19 route test failed' }
php artisan test tests/Feature/SalaryPatch18MasterPlanScreenTest.php
if ($LASTEXITCODE -ne 0) { throw 'PATCH 18 regression failed' }
if (!(Test-Path '.\node_modules\.bin\vite.cmd')) { throw 'Vite dependencies missing' }
npm run build
if ($LASTEXITCODE -ne 0) { throw 'Vite build failed' }
php artisan optimize:clear
if ($LASTEXITCODE -ne 0) { throw 'Final cache clear failed' }
Write-Host "Source backup: $backup"
Write-Warning 'Do not generate/pay salary: 18-employee financial parity and MariaDB integration remain unverified.'
