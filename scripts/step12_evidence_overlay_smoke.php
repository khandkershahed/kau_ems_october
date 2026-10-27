<?php

declare(strict_types=1);

require_once __DIR__.'/../app/Services/SalaryPayScaleArrearEngine.php';
require_once __DIR__.'/../app/Services/SalaryStructuredArrearEvidenceOverlay.php';
require_once __DIR__.'/../app/Services/SalaryStructuredArrearService.php';

use App\Services\SalaryStructuredArrearEvidenceOverlay as Overlay;
use App\Services\SalaryStructuredArrearService as Engine;

$base = [
    'june_basic' => 18530, 'old_increment' => 930,
    'revised_basics' => array_fill_keys(['2026-07', '2026-08', '2026-09'], 28715),
    'paid_months' => [
        '2026-07' => ['paid_special_benefit'=>500, 'paid_house_rent'=>8500, 'paid_gpf'=>1853],
        '2026-08' => ['paid_special_benefit'=>500, 'paid_house_rent'=>8500, 'paid_gpf'=>1853],
    ],
    'financial_months' => [],
];
$row = static fn(int $month, string $field, $amount, int $certified = 1): array => [
    'id' => $month * 10 + strlen($field), 'year' => 2026, 'month' => $month,
    'field' => $field, 'amount' => $amount, 'certified' => $certified,
    'evidence_reference' => 'HR-2026-documented-evidence',
];
$check = static function (bool $ok, string $name): void {
    if (!$ok) { fwrite(STDERR, "FAIL: $name\n"); exit(1); }
    echo "PASS: $name\n";
};
$rows = [];
foreach ([7, 8, 9] as $month) {
    $rows[] = $row($month, 'revised_house_rent', 8500);
    $rows[] = $row($month, 'revised_gpf', 2872);
}
foreach (['paid_special_benefit'=>500,'paid_house_rent'=>8500,'paid_gpf'=>1853] as $field=>$amount) {
    $rows[] = $row(9, $field, $amount);
}
$verified = Engine::compute(Overlay::apply($base, $rows));
$check($verified['SPECIAL_BENEFIT_RECOVERY']['amount'] === 1500.0, 'Certified benefit for three months');
$check($verified['HOUSE_RENT_RECOVERY']['amount'] === 0.0, 'Certified equal House Rent yields verified zero');
$check($verified['ADDITIONAL_GPF']['amount'] === 3057.0, 'Certified GPF months preserve arithmetic');
$unverified = Engine::compute(Overlay::apply($base, [$row(9, 'paid_special_benefit', 0, 0)]));
$check($unverified['SPECIAL_BENEFIT_RECOVERY']['amount'] === null, 'Uncertified zero is not a payment source');
$conflicted = Overlay::apply($base, [$row(7, 'paid_house_rent', 9999)]);
$check(isset($conflicted['source_conflicts'][0]) && !isset($conflicted['paid_months']['2026-07']['paid_house_rent']), 'Approved bill conflicts fail closed');
$duplicates = Overlay::apply($base, [$row(7, 'revised_gpf', 2000), $row(7, 'revised_gpf', 2000)]);
$check(!isset($duplicates['financial_months']['2026-07']['required_gpf']), 'Duplicate references fail closed');
echo "Step 12 PURE smoke checks passed. No database opened.\n";
