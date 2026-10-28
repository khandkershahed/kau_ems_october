$ErrorActionPreference = 'Stop'
$project = 'D:\Projects\KAU EMS\Kau-cline'
$zip = 'D:\Projects\KAU EMS\new patch\KAU_EMS_PATCH_17_SALARY_ESTIMATION_UI_RECOVERY.zip'
Set-Location -LiteralPath $project
if (!(Test-Path -LiteralPath $zip -PathType Leaf)) { throw "ZIP missing: $zip" }
if (!(Test-Path '.\artisan')) { throw 'Laravel project not found.' }
$confirmation = Read-Host 'Are source and isolated staging database backups verified? Type YES'
if ($confirmation.ToUpperInvariant() -ne 'YES') { throw 'Backup required.' }
$backup = Join-Path $project ('_patch17_backup_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
$paths = @('app/Http/Controllers/SalaryEstimationWorkspaceController.php','resources/js/Pages/Salary/Estimation/Workspace.vue')
foreach ($path in $paths) {
 $source = Join-Path $project $path; $dest = Join-Path $backup $path
 if (Test-Path -LiteralPath $source) { New-Item -ItemType Directory -Force -Path (Split-Path -Parent $dest) | Out-Null; Copy-Item -LiteralPath $source -Destination $dest }
}
Expand-Archive -LiteralPath $zip -DestinationPath $project -Force
php -l app/Http/Controllers/SalaryEstimationWorkspaceController.php
if ($LASTEXITCODE -ne 0) { throw 'Controller PHP lint failed' }
php -l tests/Feature/SalaryEstimationPatch17Test.php
if ($LASTEXITCODE -ne 0) { throw 'Test PHP lint failed' }
php artisan optimize:clear
if ($LASTEXITCODE -ne 0) { throw 'Cache clear failed' }
php artisan test tests/Feature/SalaryEstimationPatch17Test.php
if ($LASTEXITCODE -ne 0) { throw 'Route/auth tests failed' }
php artisan test tests/Feature/SalaryEstimationPatch05Test.php
if ($LASTEXITCODE -ne 0) { throw 'Existing estimation tests failed' }
if (!(Test-Path 'node_modules/.bin/vite.cmd')) { throw 'Vite dependencies unavailable' }
npm run build
if ($LASTEXITCODE -ne 0) { throw 'Vite build failed' }
php artisan optimize:clear
if ($LASTEXITCODE -ne 0) { throw 'Final cache clear failed' }
Write-Host "Source backup: $backup"
Write-Warning 'Manually verify October employee counts and financial data against isolated MariaDB. Do not post salaries.'
