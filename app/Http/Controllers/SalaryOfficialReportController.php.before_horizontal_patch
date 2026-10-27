<?php

namespace App\Http\Controllers;

use App\Models\SalaryGenerate;
use App\Services\SalaryOfficialReportSource;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $validated = $request->validate([
            'ids' => ['required', 'string', 'max:5000'],
            'year' => ['required', 'integer', 'between:2020,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'kind' => ['required', 'in:bill,bills,bulk,arrear'],
            'per_page' => ['nullable', 'integer', 'between:1,25'],
            'format' => ['nullable', 'in:html,pdf,json,csv'],
            'embed' => ['nullable', 'in:0,1'],
        ]);
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
        $rows = [];
        foreach ($ids as $id) {
            $group = $bills->get($id);
            if (!$group || $group->count() !== 1) {
                abort(422, 'Exactly one POSTED Salary Bill must exist for employee ID '.$id.' and this month.');
            }
            try {
                $rows[] = $source->fromPostedBill($group->first());
            } catch (DomainException $exception) {
                Log::warning('Official payroll report source inconsistency', [
                    'employee_id' => $id, 'year' => $validated['year'],
                    'month' => $validated['month'], 'reason' => $exception->getMessage(),
                ]);
                abort(422, 'Employee '.$id.' saved bill cannot be reconciled: '.$exception->getMessage());
            }
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
