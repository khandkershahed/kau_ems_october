<?php

/** Phase 1 — read-only: 20057 historical basic per month (June–Sept 2026). */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$svc = new App\Services\HistoricalSalaryImportService();
foreach ($svc->parse() as $r) {
    if ($r['employeeCode'] === '20057' && (int) $r['salaryGenerationYear'] === 2026 && in_array((int) $r['monthId'], [6, 7, 8, 9], true)) {
        $b = json_decode($r['salaryAllowanceCalcObject'], true);
        $basic = null;
        foreach ((array) $b as $c) {
            if (($c['name'] ?? '') === 'Basic Pay') {
                $basic = $c['calculated_amount'] ?? $c['amount'] ?? null;
            }
        }
        echo $r['monthId'].'-2026 basic='.$basic.' net='.$r['salaryTotal'].PHP_EOL;
    }
}
