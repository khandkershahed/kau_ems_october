<?php

declare(strict_types=1);

// Pure test: reads only bundled JSON. No database connection.
$file = dirname(__DIR__).'/database/seeders/data/step10_september_draft_recovery.json';
$data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$rows = $data['rows'] ?? [];
if (count($rows) !== 410) {
    throw new RuntimeException('Expected exactly 410 September pending drafts.');
}
$seen = [];
$recoveredZero = [];
foreach ($rows as $entry) {
    $r = $entry['row'];
    if ((int) $r['salaryGenerationYear'] !== 2026 || (string) $r['monthId'] !== '9' || $r['reviewStatus'] !== 'pending') {
        throw new RuntimeException('Non-pending or out-of-period September row.');
    }
    $key = $r['user_id'].'_'.$r['monthId'].'_'.$r['salaryGenerationYear'];
    if (isset($seen[$key])) throw new RuntimeException('Duplicate September user/month.');
    $seen[$key] = true;
    if (in_array((string) $r['employeeCode'], ['20057', '1003'], true)) {
        throw new RuntimeException('Unresolved conflict was inserted.');
    }
    if (abs((float) $r['totalAllowance'] - (float) $r['totalDeduction'] - (float) $r['salaryTotal']) > .009) {
        throw new RuntimeException('Unbalanced pending salary: '.$r['employeeCode']);
    }
    if (($entry['recovery_status'] ?? '') === 'AUGUST_COPY_CORRECTED_PENDING') {
        $recoveredZero[] = (string) $r['employeeCode'];
    }
}
sort($recoveredZero);
$required = ['40033', '40158', '10108', '10046', '20029'];
sort($required);
if ($recoveredZero !== $required) {
    throw new RuntimeException('Five specified zero rows not fully reconstructed.');
}
echo "PASS: 410 pending September drafts; five corrected from approved August; two unresolved records excluded.\n";
echo "PASS: Unique employee/month, arithmetic and non-approved status. DATABASE NOT OPENED.\n";
