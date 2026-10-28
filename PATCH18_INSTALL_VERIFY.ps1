$ErrorActionPreference = 'Stop'
$project = 'D:\Projects\KAU EMS\Kau-cline'
$patchDir = 'D:\Projects\KAU EMS\new patch'
$zip = Join-Path $patchDir 'KAU_EMS_PATCH_18_MASTER_PLAN_WORKLISTS_PARTIAL.zip'
Set-Location -LiteralPath $project
if (!(Test-Path '.\artisan') -or !(Test-Path '.\vendor\autoload.php')) { throw 'Wrong Laravel root or vendor missing.' }
if (!(Test-Path -LiteralPath $zip -PathType Leaf)) { throw "Patch ZIP not found: $zip" }
$confirm = Read-Host 'Is this an isolated staging clone with verified full source + DB backup? Type YES'
if ($confirm.ToUpperInvariant() -ne 'YES') { throw 'Staging backup required.' }
$backup = Join-Path $project ('_patch18_backup_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
$paths = @('app/Http/Controllers/SalaryEstimationWorkspaceController.php', 'resources/js/Pages/Salary/Estimation/Workspace.vue', 'tests/Feature/SalaryPatch18MasterPlanScreenTest.php')
$existed = @{}
foreach ($rel in $paths) {
  $src = Join-Path $project $rel
  $existed[$rel] = Test-Path -LiteralPath $src -PathType Leaf
  if ($existed[$rel]) { $dst=Join-Path $backup $rel; New-Item -ItemType Directory -Path (Split-Path -Parent $dst) -Force | Out-Null; Copy-Item -LiteralPath $src -Destination $dst -Force }
}
Expand-Archive -LiteralPath $zip -DestinationPath $project -Force
php -l 'app/Http/Controllers/SalaryEstimationWorkspaceController.php'
if ($LASTEXITCODE -ne 0) { throw 'Controller lint failed' }
php -l 'tests/Feature/SalaryPatch18MasterPlanScreenTest.php'
if ($LASTEXITCODE -ne 0) { throw 'Test lint failed' }
php artisan optimize:clear
if ($LASTEXITCODE -ne 0) { throw 'Cache clear failed' }
php artisan route:list --name=salary-estimation
if ($LASTEXITCODE -ne 0) { throw 'Estimation routes missing' }
php artisan test tests/Feature/SalaryPatch18MasterPlanScreenTest.php
if ($LASTEXITCODE -ne 0) { throw 'PATCH18 tests failed' }
php artisan test tests/Feature/SalaryEstimationPatch17Test.php
if ($LASTEXITCODE -ne 0) { throw 'PATCH17 regression failed' }
if (!(Test-Path '.\node_modules\.bin\vite.cmd')) { throw 'Vite executable missing' }
npm run build
if ($LASTEXITCODE -ne 0) { throw 'Vite build failed' }
php artisan optimize:clear
if ($LASTEXITCODE -ne 0) { throw 'Final cache clear failed' }
Write-Host "Backup: $backup"
Write-Warning 'UI smoke tests do not establish salary/GPF/arrear financial correctness; production NOT approved.'
