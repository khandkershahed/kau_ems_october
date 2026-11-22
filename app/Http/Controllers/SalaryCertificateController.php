<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\SalaryCertificateDocx;

/** Read-only Report Factory certificate export. No salary, approval or ledger writes. */
final class SalaryCertificateController extends Controller
{
    private const ALLOWANCES = [
        'basic pay' => 2,
        'house rent allowance' => 3,
        'medical allowance' => 4,
        'education allowance' => 5,
        'travel allowance' => 6,
        'tiffin allowance' => 7,
    ];

    private const DEDUCTIONS = [
        'revenue stamp' => 12,
        'revenue stamp - 1' => 12,
        'general provident fund' => 13,
        'general provident fund (gpf)' => 13,
        'gpf' => 13,
    ];

    private const ARREARS = [
        'BASIC_ARREAR_GROSS' => [8, 'payable'],
        'BASIC_INCREMENT_ADJUSTMENT' => [14, 'deduction'],
        'SPECIAL_BENEFIT_RECOVERY' => [15, 'deduction'],
        'HOUSE_RENT_RECOVERY' => [16, 'deduction'],
        'ADDITIONAL_GPF' => [17, 'deduction'],
    ];

    public function export(Request $request, SalaryCertificateDocx $word)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2020,2100'],
            'format' => ['required', 'in:json,docx'],
        ]);

        $users = User::query()->with([
            'employeeInfo.departmentOffice',
            'employeeInfo.payrollGroup.grade',
            'employeeInfo.payrollGroup.payrollGroupType',
            'employeeInfo.bankAccount',
        ])->whereIn('id', $data['ids'])->where('isActive', true)->get()->keyBy('id');

        $certificates = [];
        $rejections = [];
        foreach ($data['ids'] as $id) {
            $user = $users->get((int) $id);
            if (!$user || !$user->employeeInfo) {
                $rejections[] = ['user_id' => $id, 'reason' => 'Employee missing or inactive'];
                continue;
            }
            try {
                $certificates[] = $this->certificate($user, (int) $data['year'], (int) $data['month']);
            } catch (\DomainException $exception) {
                $rejections[] = [
                    'user_id' => $id,
                    'employee_code' => (string) $user->employeeInfo->employeeCode,
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        // A signed certificate must never silently omit a selected employee.
        if ($data['format'] === 'docx' && $rejections) {
            throw ValidationException::withMessages(['ids' =>
                'No Word file generated: '.count($rejections).' of '.count($data['ids'])
                .' selected employees have incomplete or non-template source components. '
                .implode('; ', array_map(static fn ($r) => ($r['employee_code'] ?? $r['user_id']).': '.$r['reason'],
                    array_slice($rejections, 0, 12)))
            ]);
        }
        if ($data['format'] === 'json') {
            return response()->json([
                'certificates' => $certificates,
                'rejections' => $rejections,
                'requested' => count($data['ids']),
                'ready' => count($certificates),
            ], 200, ['Cache-Control' => 'no-store, private']);
        }
        if (!$certificates) {
            throw ValidationException::withMessages(['ids' => 'No eligible Salary Certificate source data found.']);
        }
        $bytes = $word->build($certificates);
        $name = sprintf('KAU_Salary_Certificate_%04d_%02d_%d_Employees.docx',
            $data['year'], $data['month'], count($certificates));
        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function certificate(User $user, int $year, int $month): array
    {
        $employee = $user->employeeInfo;
        // Read the same employee-linked bank field shown by existing reports.
        // A certificate does not impose an additional bank-reference approval gate.
        $bank = $employee->bankAccount;

        $saved = DB::table('salary_calculation_versions')
            ->where('user_id', $user->id)->where('salary_year', $year)
            ->where('salary_month', $month)->orderByDesc('version')->orderByDesc('id')->first();

        if (!$saved) {
            throw new \DomainException('No saved salary calculation for requested month');
        }
        $result = json_decode((string) $saved->calculated_result, true);
        $arrear = json_decode((string) $saved->arrear_snapshot, true);
        if (!is_array($result) || !is_array($arrear)) {
            throw new \DomainException('Saved Salary Summary data unavailable');
        }
        if (!is_array($result['allowances'] ?? null) || !is_array($result['deductions'] ?? null)) {
            throw new \DomainException('Saved salary component details missing');
        }

        $cells = [];
        for ($i = 2; $i <= 19; $i++) {
            $cells[$i] = ['current' => 0, 'arrear' => 0, 'total' => 0];
        }
        $summaryAllowances = [];
        $summaryDeductions = [];
        $gross = 0;
        foreach ($result['allowances'] as $item) {
            $name = strtolower(trim((string) ($item['name'] ?? '')));
            $amount = $this->money($item['calculated_amount'] ?? null, 'allowance '.$name);
            $row = self::ALLOWANCES[$name] ?? null;
            if ($row) $cells[$row]['current'] += $amount;
            // The certificate copies Salary Summary's component particulars;
            // extra verified components must not be silently removed or renamed.
            $title = trim((string) ($item['name'] ?? ''));
            if ($title === '') throw new \DomainException('Allowance component name missing');
            $this->addSummaryRow($summaryAllowances, $title, $amount, 'current');
            $gross += $amount;
        }
        $deduct = 0;
        foreach ($result['deductions'] as $item) {
            $name = strtolower(trim((string) ($item['name'] ?? '')));
            $amount = $this->money($item['calculated_amount'] ?? null, 'deduction '.$name);
            $row = self::DEDUCTIONS[$name] ?? null;
            if ($row) $cells[$row]['current'] += $amount;
            $title = trim((string) ($item['name'] ?? ''));
            if ($title === '') throw new \DomainException('Deduction component name missing');
            $this->addSummaryRow($summaryDeductions, $title, $amount, 'current');
            $deduct += $amount;
        }
        $arrearPayable = 0;
        $arrearDeduct = 0;
        $titles = [
            'BASIC_ARREAR_GROSS' => 'Basic Arrear',
            'BASIC_INCREMENT_ADJUSTMENT' => 'Basic Difference / Old Increment',
            'SPECIAL_BENEFIT_RECOVERY' => 'Special Benefit Recovery',
            'HOUSE_RENT_RECOVERY' => 'House Rent Difference',
            'ADDITIONAL_GPF' => 'Additional Provident Fund',
        ];
        foreach (($arrear['rows'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $code = strtoupper(trim((string) ($item['code'] ?? '')));
            $direction = (string) ($item['direction'] ?? 'deduction');
            $amount = $this->money($item['final_amount'] ?? 0, $code);
            $row = self::ARREARS[$code][0] ?? null;
            if ($row !== null) $cells[$row]['arrear'] += $amount;
            $title = $titles[$code] ?? trim((string) ($item['name'] ?? str_replace('_', ' ', $code)));
            if ($direction === 'payable') {
                $arrearPayable += $amount;
                $this->addSummaryRow($summaryAllowances, $title, $amount, 'arrear');
            } else {
                $arrearDeduct += $amount;
                $this->addSummaryRow($summaryDeductions, $title, $amount, 'arrear');
            }
        }
        // Match Salary Summary: source component direction decides its column.
        // Do not block a certificate for additional approval or arrear-policy rules.
        $net = $gross - $deduct + $arrearPayable - $arrearDeduct;
        foreach ($cells as &$cell) $cell['total'] = $cell['current'] + $cell['arrear'];
        unset($cell);
        $cells[9] = ['current' => $gross, 'arrear' => $arrearPayable,
            'total' => $gross + $arrearPayable];
        $cells[18] = ['current' => $deduct, 'arrear' => $arrearDeduct,
            'total' => $deduct + $arrearDeduct];
        $cells[19] = ['current' => $gross - $deduct, 'arrear' => $arrearPayable - $arrearDeduct,
            'total' => $net];

        $fixationId = (int) ($result['fixation_id'] ?? 0);
        $fix = $fixationId > 0 ? DB::table('salary_fixations')->where('id', $fixationId)
            ->where('user_id', $user->id)->first() : null;
        $grade = (int) ($fix?->grade_number ?? $employee->payrollGroup?->grade?->grade_number ?? 0);
        $scaleYear = ($year * 100 + $month >= 202607) ? 2026 : 2015;
        $scaleConfig = config('kau_fixation_2026.'.$grade);
        $min = $scaleConfig['init'.$scaleYear] ?? null;
        $max = $scaleConfig['max'.$scaleYear] ?? null;
        $basic = $this->money($result['basic'] ?? null, 'approved Basic');
        $monthName = date('F', mktime(0, 0, 0, $month, 1, $year));
        $gender = strtolower((string) ($user->gender ?? ''));
        $prefix = $gender === 'female' ? 'Mrs.' : ($gender === 'male' ? 'Mr.' : 'Mr/Mrs.');
        $possessive = $gender === 'female' ? 'her' : ($gender === 'male' ? 'his' : 'their');
        $object = $gender === 'female' ? 'her' : ($gender === 'male' ? 'him' : 'them');
        $designation = trim((string) ($employee->payrollGroup?->payrollGroupType?->group_name ?? ''));
        $department = trim((string) ($employee->departmentOffice?->name ?? ''));
        return [
            'user_id' => $user->id,
            'employee_code' => (string) $employee->employeeCode,
            'name' => (string) $user->name,
            'designation' => $designation,
            'department' => $department,
            'bank_account' => trim((string) ($bank?->account_no ?? '')),
            'pay_scale' => 'Govt. Pay Scale '.$scaleYear.' (Grade '.$grade.')',
            'approved_basic' => $this->format($basic),
            'fixation' => $fixationId,
            'month' => $monthName.'-'.$year,
            'prefix' => $prefix,
            'possessive' => $possessive,
            'object_pronoun' => $object,
            'grade_range' => 'Grade '.$grade.(is_numeric($min) && is_numeric($max) ? ' (BDT '.number_format((float)$min, 0).' – '.number_format((float)$max, 0).')' : ''),
            'pay_scale_year' => $scaleYear,
            'in_words' => $this->inWords($net),
            'allowance_rows' => $this->summaryRows($summaryAllowances, true),
            'deduction_rows' => $this->summaryRows($summaryDeductions, false),
            'cells' => $cells,
            'source_type' => 'SAVED_CALCULATION',
            'source_id' => $saved->id,
            'net_paisa' => $net,
        ];
    }

    /** Same component grouping and ordering as the existing Salary Summary table. */
    private function addSummaryRow(array &$rows, string $name, int $amount, string $column): void
    {
        $name = trim($name);
        if ($name === '') throw new \DomainException('Unnamed salary component');
        $key = $column === 'arrear' && str_contains(strtolower($name), 'basic')
            && str_contains(strtolower($name), 'arrear') ? 'Basic Arrear' : $name;
        $rows[$key] ??= ['particulars' => $key, 'current' => 0, 'arrear' => 0];
        $rows[$key][$column] += $amount;
    }

    private function summaryRows(array $components, bool $allowances): array
    {
        $basic = null;
        if ($allowances) {
            foreach ($components as $key => $item) {
                if (preg_match('/\bbasic\b/i', $key)
                    && !preg_match('/\barrear\b|\bdifference\b|\bold increment\b/i', $key)) {
                    $basic ??= ['particulars' => 'Basic Pay', 'current' => 0, 'arrear' => 0];
                    $basic['current'] += $item['current'];
                    $basic['arrear'] += $item['arrear'];
                    unset($components[$key]);
                }
            }
        }
        uasort($components, static fn ($a, $b) => ($b['current'] + $b['arrear'])
            <=> ($a['current'] + $a['arrear']));
        if ($basic !== null) array_unshift($components, $basic);
        $output = [];
        foreach (array_values($components) as $index => $item) {
            $output[] = [
                'sl' => $index + 1,
                'particulars' => $item['particulars'],
                'current' => $item['current'],
                'arrear' => $item['arrear'],
                'total' => $item['current'] + $item['arrear'],
            ];
        }
        return $output;
    }

    private function money($value, string $field): int
    {
        // Salary Summary uses Number(value) || 0 for absent component amounts.
        return (int) round((is_numeric($value) && is_finite((float)$value) ? (float)$value : 0) * 100);
    }

    private function format(int $paisa): string
    {
        return number_format($paisa / 100, 2, '.', ',');
    }

    private function inWords(int $paisa): string
    {
        $taka = intdiv($paisa, 100);
        $remaining = $paisa % 100;
        $label = 'Taka '.$this->numberWords($taka);
        if ($remaining > 0) $label .= ' and '.$this->numberWords($remaining).' Paisa';
        return $label.' only';
    }

    private function numberWords(int $number): string
    {
        $small = ['zero','one','two','three','four','five','six','seven','eight','nine','ten',
            'eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];
        $tens = ['','','twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];
        if ($number < 20) return $small[$number];
        if ($number < 100) return $tens[intdiv($number, 10)].($number % 10 ? ' '.$small[$number % 10] : '');
        foreach ([10000000 => 'crore', 100000 => 'lakh', 1000 => 'thousand', 100 => 'hundred'] as $divisor => $label) {
            if ($number >= $divisor) {
                $major = intdiv($number, $divisor);
                $minor = $number % $divisor;
                return $this->numberWords($major).' '.$label.($minor ? ' '.$this->numberWords($minor) : '');
            }
        }
        return $small[$number];
    }
}
