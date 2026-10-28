$ErrorActionPreference = 'Stop'
$project = 'D:\Projects\KAU EMS\Kau-cline'
$patchDir = 'D:\Projects\KAU EMS\new patch'
$zip = Join-Path $patchDir 'KAU_EMS_PATCH_20_SAVED_HORIZONTAL_BILL_PARTIAL.zip'
Set-Location -LiteralPath $project
if (!(Test-Path '.\artisan') -or !(Test-Path '.\vendor\autoload.php')) { throw 'Laravel or vendor missing.' }
if (!(Test-Path -LiteralPath $zip -PathType Leaf)) { throw "ZIP not found: $zip" }
$ok = Read-Host 'Is this an isolated staging clone with VERIFIED source and DB backup? Type YES'
if ($ok -cne 'YES') { throw 'Isolated staging and verified backup required.' }
$backup = Join-Path $project ('_patch20_backup_' + (Get-Date -Format 'yyyyMMdd_HHmmss'))
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [IO.Compression.ZipFile]::OpenRead($zip)
try {
    foreach ($entry in $archive.Entries) {
        if (!$entry.Name) { continue }
        $rel = $entry.FullName.Replace('/', '\')
        if ([IO.Path]::IsPathRooted($rel) -or $rel.StartsWith('\') -or $rel -match '(^|\\)\.\.(\\|$)') { throw "Unsafe path: $rel" }
        $existing = Join-Path $project $rel
        if (Test-Path -LiteralPath $existing -PathType Leaf) {
            $target = Join-Path $backup $rel
            New-Item -ItemType Directory -Path (Split-Path -Parent $target) -Force | Out-Null
            Copy-Item -LiteralPath $existing -Destination $target -Force
        }
    }
} finally { $archive.Dispose() }
Expand-Archive -LiteralPath $zip -DestinationPath $project -Force
foreach ($f in @('routes/web.php','app/Http/Controllers/SalaryProvisionalHorizontalBatchController.php','tests/Feature/SalaryPatch20HorizontalContractTest.php')) {
    php -l $f
    if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $f" }
}
php artisan optimize:clear
if ($LASTEXITCODE -ne 0) { throw 'optimize:clear failed' }
php artisan route:list --name=salary-estimation.horizontal-bill
if ($LASTEXITCODE -ne 0) { throw 'Report route unavailable' }
php artisan test tests/Feature/SalaryPatch20HorizontalContractTest.php
if ($LASTEXITCODE -ne 0) { throw 'Report tests failed' }
npm run build
if ($LASTEXITCODE -ne 0) { throw 'Vite build failed' }
php artisan optimize:clear
if ($LASTEXITCODE -ne 0) { throw 'Final cache clear failed' }
Write-Host "Source backup: $backup"
Write-Warning 'Financial parity and MySQL integration tests remain incomplete. Do NOT deploy to production.'
