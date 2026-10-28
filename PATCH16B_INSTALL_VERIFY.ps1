$ErrorActionPreference = 'Stop'
$project = 'D:\Projects\KAU EMS\Kau-cline'
$patchDir = 'D:\Projects\KAU EMS\new patch'
$zip = Join-Path $patchDir 'KAU_EMS_PATCH_16B_GPF_EVIDENCE_STAGING.zip'
Set-Location -LiteralPath $project
if (-not (Test-Path -LiteralPath $zip -PathType Leaf)) { throw "ZIP missing: $zip" }
if (-not (Test-Path '.\artisan') -or -not (Test-Path '.\vendor\autoload.php')) { throw 'Project or vendor missing' }
$answer = Read-Host 'Is this a backed-up ISOLATED staging database? Type YES'
if ($answer.ToUpperInvariant() -ne 'YES') { throw 'Only staging with verified backup.' }
$backup=Join-Path $project ('_patch16B_backup_'+(Get-Date -Format 'yyyyMMdd_HHmmss'))
New-Item -ItemType Directory -Path $backup -Force | Out-Null
$existing=@('routes\web.php','app\Services\SalaryArrearRuleService.php')
foreach($f in $existing) {
 $dest=Join-Path $backup $f
 New-Item -ItemType Directory -Path (Split-Path $dest) -Force | Out-Null
 Copy-Item -LiteralPath (Join-Path $project $f) -Destination $dest -Force
}
Expand-Archive -LiteralPath $zip -DestinationPath $project -Force
$phpFiles=@('routes/web.php','app/Services/SalaryArrearRuleService.php','app/Http/Controllers/SalaryOctoberGpfEvidenceController.php','database/migrations/2026_10_28_000004_create_october_gpf_evidence.php','tests/Feature/SalaryOctoberGpfEvidencePatch16Test.php')
foreach($f in $phpFiles) { & php -l $f; if($LASTEXITCODE -ne 0){throw "PHP syntax failed: $f"} }
& php artisan optimize:clear
if($LASTEXITCODE -ne 0){throw 'Laravel cache clear failed'}
& php artisan migrate:status
if($LASTEXITCODE -ne 0){throw 'Migration status failed'}
& php artisan migrate --force
if($LASTEXITCODE -ne 0){throw 'Staging migration failed'}
& php artisan route:list --name=salary-arrear-settings.october-gpf-evidence.store
if($LASTEXITCODE -ne 0){throw 'Route missing'}
& php artisan test tests/Feature/SalaryOctoberGpfEvidencePatch16Test.php
if($LASTEXITCODE -ne 0){throw 'Feature tests failed'}
& npm run build
if($LASTEXITCODE -ne 0){throw 'Vite build failed'}
& php artisan kau:audit-october-18 --json | Out-File -FilePath (Join-Path $backup 'october18_readiness.json') -Encoding utf8
if($LASTEXITCODE -ne 0){throw 'Read-only audit failed'}
Write-Host "Source backup and read-only report: $backup"
Write-Warning 'Document sources, 18 actual financial results and isolated MySQL posting tests remain unverified; DO NOT DEPLOY.'
