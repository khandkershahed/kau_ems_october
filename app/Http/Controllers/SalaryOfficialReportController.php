<?php

namespace App\Http\Controllers;

use App\Models\SalaryGenerate;
use App\Services\SalaryBillCompositionService;
use App\Services\SalaryOfficialReportSource;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\EmployeeInfo;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/**
 * Explicit GET-only, read-only reports. No HR-role blockade or approval flow.
 * Existing auth middleware protects routes. No ledger or payroll writes.
 */
final class SalaryOfficialReportController extends Controller
{
    public function index(Request $request, SalaryOfficialReportSource $source)
    {
        // Explicit 422 abort instead of validate(): a GET validation redirect
        // would silently bounce the Report Factory tab back with no error shown.
        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'string', 'max:5000'],
            'year' => ['required', 'integer', 'between:2020,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'kind' => ['required', 'in:bill,bills,bulk,arrear'],
            'per_page' => ['nullable', 'integer', 'between:1,25'],
            'format' => ['nullable', 'in:html,pdf,json,csv'],
            'embed' => ['nullable', 'in:0,1'],
            'mode' => ['nullable', 'in:preview'],
        ]);
        if ($validator->fails()) {
            abort(422, 'Salary report request rejected: '.$validator->errors()->first()
                .' — no report was generated and no data was changed.');
        }
        $validated = $validator->validated();
        $raw = array_map('trim', explode(',', $validated['ids']));
        if (!$raw || count($raw) > 500 || count($raw) !== count(array_unique($raw))) {
            abort(422, 'Select 1 to 500 distinct employees.');
        }
        foreach ($raw as $id) {
            if (!ctype_digit($id) || (int) $id < 1) abort(422, 'Invalid employee selection.');
        }
        $ids = array_map('intval', $raw);
        if (count($ids) !== count(array_unique($ids))) abort(422, 'Repeated employee IDs.');
        if ($validated['kind'] === 'bill' && count($ids) !== 1) {
            abort(422, 'Single salary bill requires exactly one employee.');
        }
        if (!Schema::hasTable('salary_generates')) abort(503, 'Salary Bill source not installed.');
        $bills = SalaryGenerate::query()->with(['user.employeeInfo.departmentOffice'])
            ->whereIn('user_id', $ids)
            ->where('salaryGenerationYear', (int) $validated['year'])
            ->where('monthId', (int) $validated['month'])->get()->groupBy('user_id');
        $draftPreview = ($validated['mode'] ?? null) === 'preview';
        $rows = [];
        foreach ($ids as $id) {
            $group = $bills->get($id);
            if ($group && $group->count() === 1) {
                try {
                    $rows[] = $source->fromPostedBill($group->first());
                    continue;
                } catch (DomainException $exception) {
                    Log::warning('Official payroll report source inconsistency', [
                        'employee_id' => $id, 'year' => $validated['year'],
                        'month' => $validated['month'], 'reason' => $exception->getMessage(),
                    ]);
                    return $this->reportError('Employee '.$id.' saved bill cannot be reconciled: '.$exception->getMessage());
                }
            }
            // mode=preview: ungenerated employees stay eligible as clearly marked
            // read-only draft rows. Saved-bill reconciliation is preserved for
            // posted bills and for non-preview mode.
            if ($draftPreview) {
                $savedRow = $this->savedCalculatorRow($id, (int) $validated['year'], (int) $validated['month']);
                // Unsaved employees need the legacy read-only regular estimate,
                // rather than misleading zero rows. Saved snapshots always win.
                // This never creates a salary bill or modifies financial records.
                $rows[] = ($savedRow['source'] ?? '') === 'NOT_SAVED'
                    ? $this->draftRow($id, (int) $validated['year'], (int) $validated['month'])
                    : $savedRow;
                continue;
            }
            return $this->reportError('Employee ID '.$id.' has no unique saved bill for the requested month/year. Generate or reconcile that employee first.');
        }
        // Bulk preview must never print an incomplete or unitemized salary as payable.
        // Other report types retain their existing selection and reconciliation behavior.
        // Provisional horizontal preview is intentionally allowed to contain
        // unposted and incomplete employee rows. The Blade report renders
        // missing monetary values as UNKNOWN and labels such rows DRAFT.
        // Never silently discard selected employees from a preview.
        // Official/non-preview reports retain the strict reconciliation filter.
        if ($validated['kind'] === 'bulk' && !$draftPreview) {
            $rows = array_values(array_filter($rows, static function (array $row): bool {
                if (($row['source'] ?? '') === 'DRAFT_REGULAR_ONLY_PREVIEW'
                    || !($row['details_complete'] ?? false)
                    || !is_numeric($row['basic'] ?? null)
                    || !is_numeric($row['net'] ?? null)
                    || !is_numeric($row['current_gross'] ?? null)
                    || !is_numeric($row['current_deductions'] ?? null)) {
                    return false;
                }
                $canonical = [
                    'BASIC_ARREAR_GROSS' => 'payable',
                    'BASIC_INCREMENT_ADJUSTMENT' => 'deduction',
                    'SPECIAL_BENEFIT_RECOVERY' => 'deduction',
                    'HOUSE_RENT_RECOVERY' => 'deduction',
                    'ADDITIONAL_GPF' => 'deduction',
                ];
                $payable = (float) ($row['arrear_payable'] ?? 0);
                $deduct = (float) ($row['arrear_deductions'] ?? 0);
                $arrearLines = $row['arrear_rows'] ?? [];
                if (abs($payable) < 0.005 && abs($deduct) < 0.005 && !$arrearLines) {
                    return true; // A genuinely regular-only saved bill needs no arrear entries.
                }
                if (!is_array($arrearLines)) return false;
                $found = [];
                foreach ($arrearLines as $item) {
                    if (!is_array($item)) return false;
                    $code = strtoupper(trim((string) ($item['code'] ?? '')));
                    $amount = $item['final_amount'] ?? $item['amount'] ?? $item['total'] ?? null;
                    if (!isset($canonical[$code]) || isset($found[$code])
                        || ($item['direction'] ?? '') !== $canonical[$code]
                        || !is_numeric($amount) || !is_finite((float) $amount) || (float) $amount < 0) return false;
                    $found[$code] = round((float) $amount, 2);
                }
                if (count($found) !== count($canonical)) return false;
                $sumDed = $found['BASIC_INCREMENT_ADJUSTMENT']
                    + $found['SPECIAL_BENEFIT_RECOVERY']
                    + $found['HOUSE_RENT_RECOVERY'] + $found['ADDITIONAL_GPF'];
                return abs($found['BASIC_ARREAR_GROSS'] - $payable) < 0.011
                    && abs($sumDed - $deduct) < 0.011;
            }));
        }
        if ($validated['kind'] === 'bulk' && !$draftPreview && count($rows) === 0) {
            return $this->reportError('No fully reconciled saved salary bill is eligible for this horizontal report. Incomplete/unverified salary rows were excluded.');
        }
        $kind = $validated['kind'];
        $perPage = (int) ($validated['per_page'] ?? 15);
        $pages = $kind === 'bulk' ? array_chunk($rows, $perPage) : array_map(static fn ($row) => [$row], $rows);
        $totalNet = round(array_sum(array_column($rows, 'net')), 2);
        if (($validated['format'] ?? 'html') === 'json') {
            return response()->json(['records' => $rows, 'summary' => ['total_records' => count($rows),
                'grand_net' => $totalNet], 'source' => 'SAVED_POSTED_BILL_ONLY'])
                ->header('Cache-Control', 'no-store, private');
        }
        if (($validated['format'] ?? 'html') === 'csv') {
            $headers = ['Code','Employee','Designation','Grade','Basic','Current Gross','Current Deductions',
                'Current Net','Arrear Payable','Arrear Deductions','Net Arrear','Final Net','Source'];
            $csv = "\xEF\xBB\xBF";
            $escape = static function ($value): string {
                $string = $value === null ? 'UNKNOWN' : (string) $value;
                if (preg_match('/^[=+@-]/u', $string) && !is_numeric($string)) $string = "'".$string;
                return '"'.str_replace('"', '""', $string).'"';
            };
            $csv .= implode(',', array_map($escape, $headers))."\r\n";
            foreach ($rows as $r) {
                $csv .= implode(',', array_map($escape, [
                    $r['employee_code'], $r['name'], $r['designation'], $r['grade'],
                    $r['basic'], $r['current_gross'], $r['current_deductions'],
                    $r['current_net'], $r['arrear_payable'], $r['arrear_deductions'],
                    $r['net_arrear'], $r['net'], $r['source'],
                ]))."\r\n";
            }
            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="KAU_Official_'.strtoupper($kind).'_'.$validated['year'].'_'.$validated['month'].'.csv"',
                'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
            ]);
        }
        $html = view('salary.official-report', [
            'rows' => $rows, 'pages' => $pages, 'kind' => $kind,
            'year' => (int) $validated['year'], 'month' => (int) $validated['month'],
            'grandTotal' => $totalNet, 'perPage' => $perPage,
            'pdfAvailable' => filled(config('kau_official_reports.chromium_binary')),
            'logoData' => $this->embeddedLogo(),
            'embed' => ($validated['embed'] ?? '0') === '1',
        ])->render();
        if (($validated['format'] ?? 'html') === 'pdf') {
            return $this->renderPdf($html, $kind, (int) $validated['year'], (int) $validated['month']);
        }
        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private']);
    }

    /** Human-readable response while preserving HTTP 422 and financial source checks. */
    private function reportError(string $message)
    {
        $safe = e($message);
        return response('<!doctype html><html><head><meta charset="utf-8"><title>Salary Report Reconciliation</title></head>'
            .'<body style="font:16px Arial,sans-serif;padding:35px;max-width:900px;margin:auto">'
            .'<h2>Salary Report Needs Reconciliation</h2><p>'.$safe.'</p>'
            .'<p>No financial records were modified. Correct this saved bill or adjust the selection, then retry.</p>'
            .'<button onclick="history.back()">Go Back</button></body></html>', 422,
            ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    /** Read-only draft row for an employee without a posted bill this month.
     * Regular amounts come from the read-only regular-only preview service;
     * arrear amounts stay null (never estimated). Never writes.
     */
    /**
     * Read the exact latest version saved in Salary Calculator. Never recompute
     * it using changed rules, never replace missing source amounts with zero.
     * An explicit NOT_SAVED row makes gaps visible without fabricating salaries.
     */
    private function savedCalculatorRow(int $userId, int $year, int $month): array
    {
        $profile = EmployeeInfo::query()->with(['payrollGroup.grade', 'departmentOffice'])
            ->where('user_id', $userId)->orderBy('id')->first();
        $employee = User::query()->find($userId);
        $code = $profile?->employeeCode ?: null;
        $name = $employee?->name ?: null;
        $grade = $profile?->payrollGroup?->grade?->grade_name ?: 'NOT RECORDED';
        $department = $profile?->departmentOffice?->name ?? 'NOT RECORDED';
        $designation = 'NOT RECORDED';

        $base = [
            'id' => null, 'user_id' => $userId,
            'employee_code' => $code ?: 'USER-'.$userId,
            'name' => $name ?: 'EMPLOYEE NAME NOT RECORDED',
            'designation' => $designation, 'department' => $department,
            'department_is_current' => true, 'grade' => $grade,
            'pay_scale' => 'NOT RECORDED', 'bank_account_no' => 'NOT RECORDED',
            'bank_address' => 'NOT RECORDED', 'year' => $year, 'month' => $month,
            'basic' => null, 'allowances' => [], 'deductions' => [],
            'arrear_rows' => [], 'current_gross' => null,
            'current_deductions' => null, 'current_net' => null,
            'arrear_payable' => null, 'arrear_deductions' => null,
            'net_arrear' => null, 'gross' => null,
            'total_deductions' => null, 'net' => null,
            'source' => 'NOT_SAVED', 'source_hash' => null,
            'details_complete' => false,
            'remarks' => 'No saved Salary Calculator version for this employee/month. Salary NOT VERIFIED.',
        ];
        if (!Schema::hasTable('salary_calculation_versions')) return $base;
        $version = DB::table('salary_calculation_versions')
            ->where('user_id', $userId)->where('salary_year', $year)
            ->where('salary_month', $month)->orderByDesc('version')->orderByDesc('id')->first();
        if (!$version) return $base;
        $calculated = json_decode((string) ($version->calculated_result ?? ''), true);
        $arrear = json_decode((string) ($version->arrear_snapshot ?? ''), true);
        if (!is_array($calculated) || !is_array($arrear)) {
            $base['remarks'] = 'Saved calculation version #'.$version->id.' is incomplete or unreadable. Needs reconciliation.';
            return $base;
        }
        $amount = static fn ($v) => is_numeric($v) && is_finite((float) $v) ? round((float) $v, 2) : null;
        $component = static function ($item, $direction) use ($amount): array {
            $value = $amount($item['calculated_amount'] ?? null);
            return [
                'title' => (string) ($item['name'] ?? 'Unnamed component'),
                'direction' => $direction, 'current' => $value,
                'arrear' => null, 'total' => $value,
                'code' => null, 'months' => [],
                'evidence' => $item['manual_evidence_reference'] ?? null,
                'unverified' => $value === null,
            ];
        };
        foreach (($calculated['allowances'] ?? []) as $item) {
            if (is_array($item)) $base['allowances'][] = $component($item, 'allowance');
        }
        foreach (($calculated['deductions'] ?? []) as $item) {
            if (is_array($item)) $base['deductions'][] = $component($item, 'deduction');
        }
        $validArrear = true;
        $payable = 0.0;
        $deduct = 0.0;
        foreach (($arrear['rows'] ?? []) as $item) {
            if (!is_array($item)) { $validArrear = false; continue; }
            $value = $amount($item['final_amount'] ?? null);
            if ($value === null) $validArrear = false;
            $direction = (string) ($item['direction'] ?? '');
            if ($direction === 'payable' && $value !== null) $payable += $value;
            elseif ($direction === 'deduction' && $value !== null) $deduct += $value;
            else $validArrear = false;
            $base['arrear_rows'][] = [
                'code' => $item['code'] ?? null,
                'title' => (string) ($item['title'] ?? 'Arrear component'),
                'direction' => $direction, 'final_amount' => $value,
                'amount' => $value, 'months' => $item['months'] ?? [],
                'evidence' => $item['evidence_reference'] ?? null,
                'unverified' => $value === null,
            ];
        }
        $payable = round($payable, 2);
        $deduct = round($deduct, 2);
        $basic = $amount($calculated['basic'] ?? null);
        $gross = $amount($calculated['total_allowance'] ?? null);
        $regularDed = $amount($calculated['total_deduction'] ?? null);
        $regularNet = $amount($calculated['net_salary'] ?? null);
        $snapshotNetArrear = $amount($arrear['net_arrear'] ?? null);
        // Prevent broken partial snapshots from yielding apparently final pay.
        $complete = $validArrear && $basic !== null && $gross !== null
            && $regularDed !== null && $regularNet !== null
            && $snapshotNetArrear !== null
            && abs($snapshotNetArrear - ($payable - $deduct)) < .011
            && abs($regularNet - ($gross - $regularDed)) < .011;
        $base['basic'] = $basic;
        $base['current_gross'] = $gross;
        $base['current_deductions'] = $regularDed;
        $base['current_net'] = $regularNet;
        $base['arrear_payable'] = $validArrear ? $payable : null;
        $base['arrear_deductions'] = $validArrear ? $deduct : null;
        $base['net_arrear'] = $validArrear ? $snapshotNetArrear : null;
        $base['gross'] = $complete ? round($gross + $payable, 2) : null;
        $base['total_deductions'] = $complete ? round($regularDed + $deduct, 2) : null;
        $base['net'] = $complete ? round($regularNet + $snapshotNetArrear, 2) : null;
        $base['source'] = $version->status === 'approved'
            ? 'APPROVED_CALCULATOR_SNAPSHOT' : 'DRAFT_CALCULATOR_SNAPSHOT';
        $base['source_hash'] = $version->snapshot_hash ?? null;
        $base['details_complete'] = $complete;
        $base['remarks'] = 'Saved calculator version #'.$version->version.' ('.$version->status.')'
            .($complete ? '; provisional, not a posted bill.' : '; INCOMPLETE: do not approve this report.');
        return $base;
    }

    private function draftRow(int $userId, int $year, int $month): array
    {
        $service = app(SalaryBillCompositionService::class);
        try {
            $draft = $service->composeRegularOnly($userId, $year, $month);
        } catch (DomainException $e) {
            $draft = ['status' => 'BLOCKED', 'blocking_reasons' => [$e->getMessage()],
                'employee_code' => null, 'name' => null, 'regular' => null,
                'regular_gross' => null, 'regular_deductions' => null, 'regular_net' => null,
                'arrear_blocking_reasons' => [$e->getMessage()], 'calculation_source_hash' => null];
        }
        $base = [
            'id' => null, 'user_id' => $userId, 'employee_code' => $draft['employee_code'] ?? null,
            'name' => $draft['name'] ?? null, 'designation' => '—', 'department' => '—',
            'department_is_current' => true, 'grade' => '—', 'pay_scale' => '—',
            'bank_account_no' => '—', 'bank_address' => '—', 'year' => $year, 'month' => $month,
            'basic' => null,
            'allowances' => [], 'deductions' => [], 'arrear_rows' => [],
            'current_gross' => null, 'current_deductions' => null, 'current_net' => null,
            'arrear_payable' => null, 'arrear_deductions' => null, 'net_arrear' => null,
            'gross' => null, 'total_deductions' => null, 'net' => null,
            'source' => 'DRAFT_REGULAR_ONLY_PREVIEW', 'source_hash' => null,
            'details_complete' => false, 'remarks' => '',
        ];
        if (($draft['status'] ?? 'BLOCKED') !== 'READY') {
            $base['remarks'] = 'DRAFT unavailable: '.implode('; ', $draft['blocking_reasons'] ?? ['unknown']);
            return $base;
        }
        $component = static fn (array $r, string $direction): array => [
            'title' => (string) ($r['name'] ?? ''), 'direction' => $direction,
            'current' => is_numeric($r['calculated_amount'] ?? null) ? round((float) $r['calculated_amount'], 2) : null,
            'arrear' => 0.0,
            'total' => is_numeric($r['calculated_amount'] ?? null) ? round((float) $r['calculated_amount'], 2) : null,
            'code' => null, 'months' => [],
            'evidence' => $r['manual_evidence_reference'] ?? null,
            'unverified' => !is_numeric($r['calculated_amount'] ?? null),
        ];
        foreach (($draft['regular']['allowances'] ?? []) as $r) $base['allowances'][] = $component($r, 'allowance');
        foreach (($draft['regular']['deductions'] ?? []) as $r) $base['deductions'][] = $component($r, 'deduction');
        $base['basic'] = $draft['regular']['basic'] ?? null;
        $base['current_gross'] = $draft['regular_gross'];
        $base['current_deductions'] = $draft['regular_deductions'];
        $base['current_net'] = $draft['regular_net'];
        $base['gross'] = $draft['regular_gross'];
        $base['total_deductions'] = $draft['regular_deductions'];
        $base['net'] = $draft['regular_net'];
        $base['source_hash'] = $draft['calculation_source_hash'] ?? null;
        $base['details_complete'] = true;
        $base['remarks'] = 'DRAFT regular-only preview (no posted bill). Arrear unverified: '
            .(implode('; ', $draft['arrear_blocking_reasons'] ?? []) ?: 'no arrear rules returned amounts.');
        return $base;
    }

    private function embeddedLogo(): ?string
    {
        $file = public_path('logo.png');
        if (!is_file($file) || filesize($file) > 250000) return null;
        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($file));
    }

    /** Configured renderer only; no server browser dependency assumed. */
    private function renderPdf(string $html, string $kind, int $year, int $month)
    {
        $binary = (string) config('kau_official_reports.chromium_binary', '');
        if ($binary === '' || !is_file($binary)) {
            abort(503, 'Direct PDF renderer is not configured on this server. Open HTML and use Print / Save as PDF.');
        }
        $dir = storage_path('app/kau-official-report-temp');
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            abort(503, 'Private PDF temporary directory is unavailable.');
        }
        $token = bin2hex(random_bytes(16));
        $htmlFile = $dir.'/'.$token.'.html';
        $pdfFile = $dir.'/'.$token.'.pdf';
        $profileDir = $dir.'/'.$token.'-profile';
        try {
            if (file_put_contents($htmlFile, $html, LOCK_EX) === false) {
                throw new DomainException('Could not create private report HTML.');
            }
            $localFileUri = 'file:///'.str_replace('\\', '/', ltrim($htmlFile, '/'));
            if (DIRECTORY_SEPARATOR === '/' ) $localFileUri = 'file://'.$htmlFile;
            $process = new Process([
                $binary, '--headless', '--disable-gpu', '--disable-dev-shm-usage',
                '--no-first-run', '--no-default-browser-check', '--no-pdf-header-footer',
                '--user-data-dir='.$profileDir,
                '--print-to-pdf='.$pdfFile, $localFileUri,
            ]);
            $process->setTimeout(75);
            $process->run();
            if (!$process->isSuccessful() || !is_file($pdfFile) || filesize($pdfFile) < 1000) {
                throw new DomainException('Configured PDF renderer failed: '.substr($process->getErrorOutput(), 0, 500));
            }
            $bytes = file_get_contents($pdfFile);
            $name = sprintf('KAU_%s_%04d_%02d.pdf', ucfirst($kind), $year, $month);
            return response($bytes, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$name.'"',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (\Throwable $exception) {
            Log::error('Configured official report PDF conversion failed', ['reason' => $exception->getMessage()]);
            abort(503, 'PDF conversion unavailable. Please use HTML Print / Save as PDF.');
        } finally {
            @unlink($htmlFile);
            @unlink($pdfFile);
            // Only remove this request's private temporary browser profile.
            if (is_dir($profileDir)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($profileDir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($iterator as $entry) {
                    $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
                }
                @rmdir($profileDir);
            }
        }
    }
}
