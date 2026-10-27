KAU EMS - targeted salary calculator patch
Source: app(9).zip
Modified: resources/js/Pages/Salary/Calculator/Entry.vue

This patch visibly collapses verbose formula/source explanations, flags
legacy Basic without approved revised fixation, and validates refreshed
component identities. It never invents missing historical amounts.

APPLY (PowerShell):
cd "D:\Projects\KAU EMS\Kau-cline"
Copy-Item "resources\js\Pages\Salary\Calculator\Entry.vue" "resources\js\Pages\Salary\Calculator\Entry.vue.before_patch"
# Extract ZIP into project root, preserving directories.
npm run build
php artisan optimize:clear
# Hard-refresh browser Ctrl+Shift+R.

This patch changes no DB records. Do NOT generate/approve salary until
required revised fixation and historical entitlement/payment evidence exist.
