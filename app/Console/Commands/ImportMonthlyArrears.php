<?php

namespace App\Console\Commands;

use App\Exceptions\HistoricalSalaryImmutableException;
use App\Models\PayrollComponentType;
use App\Models\SalaryMonthlyComponentAdjustment;
use App\Models\User;
use App\Services\SalaryGenerationGuards;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SplFileObject;

class ImportMonthlyArrears extends Command
{
    protected $signature = 'kau:import-monthly-arrears
        {--year=2026}
        {--month=11}
        {--file= : Path to CSV}
        {--apply : Persist changes (default is dry-run)}';

    protected $description = 'Reconcile and optionally import one-time monthly payroll arrears';

    public function handle(): int
    {
        $year = filter_var($this->option('year'), FILTER_VALIDATE_INT);
        $month = filter_var($this->option('month'), FILTER_VALIDATE_INT);
        if ($year === false || $year < 1 || $year > 9999 || $month === false || $month < 1 || $month > 12) {
            $this->error('Provide a valid payroll year and month.');

            return self::FAILURE;
        }

        try {
            SalaryGenerationGuards::assertMonthMutable($year, $month);
            $file = $this->option('file') ?? implode(DIRECTORY_SEPARATOR, [
                dirname(base_path(), 2), '03-Financial-Evidence', 'November', 'november_2026_solved_arrears.csv',
            ]);
            [$rows, $allowances, $deductions] = $this->readRows($file, $year, $month);
            $net = $allowances - $deductions;

            if ($this->option('file') === null && (count($rows) !== 44 || $net !== 14468850)) {
                throw new RuntimeException('The default arrears file must contain 44 rows with a net pay impact of 144,688.50.');
            }

            $changed = $this->option('apply') ? $this->persist($rows) : 0;
        } catch (HistoricalSalaryImmutableException | RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['rows_parsed', 'allowances_arrear_total', 'deductions_arrear_total', 'net_pay_impact', 'inserted_or_updated_count'],
            [[count($rows), $this->decimal($allowances), $this->decimal($deductions), $this->decimal($net), $changed]],
        );

        return self::SUCCESS;
    }

    private function readRows(string $path, int $year, int $month): array
    {
        try {
            $file = new SplFileObject($path, 'rb');
        } catch (RuntimeException $exception) {
            throw new RuntimeException("Cannot open arrears CSV: {$path}", 0, $exception);
        }
        $header = $file->fgetcsv(',', '"', '') ?: [];
        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }
        if (array_map('trim', $header) !== ['employee_code', 'component_name', 'arrear_amount', 'comment']) {
            throw new RuntimeException('Invalid arrears CSV header.');
        }

        $users = User::query()->join('employee_infos', 'users.id', '=', 'employee_infos.user_id')
            ->get(['users.id', 'employee_infos.employeeCode'])->groupBy('employeeCode');
        $components = PayrollComponentType::query()->with('payrollComponents')
            ->get()->groupBy(fn (PayrollComponentType $type) => mb_strtolower($type->name));
        $rows = [];
        $allowances = $deductions = 0;

        while (!$file->eof()) {
            $line = $file->key() + 1;
            $fields = $file->fgetcsv(',', '"', '');
            if ($fields === false || $fields === [null]) {
                continue;
            }
            if (count($fields) !== 4) {
                throw new RuntimeException("Expected four fields at CSV row {$line}.");
            }

            [$code, $name, $amount, $comment] = array_map('trim', $fields);
            $employees = $users->get($code, collect());
            if ($code === '' || $employees->count() !== 1) {
                throw new RuntimeException("Unknown or ambiguous employee code '{$code}' at CSV row {$line}.");
            }
            $matches = $components->get(mb_strtolower($name), collect());
            if ($name === '' || $matches->count() !== 1) {
                throw new RuntimeException("Unknown or ambiguous component '{$name}' at CSV row {$line}.");
            }

            $component = $matches->first();
            $key = $employees->first()->id . ':' . $component->id;
            if (isset($rows[$key])) {
                throw new RuntimeException("Duplicate arrear for employee '{$code}' and component '{$name}' at CSV row {$line}.");
            }
            if (!preg_match('/\A([+-]?)([0-9]{1,10})\.([0-9]{2})\z/', $amount, $parts)) {
                throw new RuntimeException("Expected a two-decimal arrear amount at CSV row {$line}.");
            }
            $cents = ((int) $parts[2] * 100 + (int) $parts[3]) * ($parts[1] === '-' ? -1 : 1);
            $directions = $component->payrollComponents
                ->map(fn ($entry) => strtolower(trim($entry->component_type)))->unique()->values()->all();
            if ($directions === ['allowance']) {
                $allowances += $cents;
            } elseif ($directions === ['deduction']) {
                $deductions += $cents;
            } else {
                throw new RuntimeException("Component '{$name}' must have one payroll direction (allowance or deduction).");
            }

            $rows[$key] = [
                'user_id' => $employees->first()->id,
                'year' => $year,
                'month' => $month,
                'payroll_component_type_id' => $component->id,
                'arrear_amount' => $this->decimal($cents),
                'comment' => $comment,
                'rule_override' => false,
            ];
        }

        return [array_values($rows), $allowances, $deductions];
    }

    private function persist(array $rows): int
    {
        return DB::transaction(function () use ($rows): int {
            $changed = 0;
            foreach ($rows as $row) {
                $key = array_intersect_key($row, array_flip(['user_id', 'year', 'month', 'payroll_component_type_id']));
                $values = array_diff_key($row, $key);
                $adjustment = SalaryMonthlyComponentAdjustment::query()->where($key)->lockForUpdate()->first();
                if ($adjustment !== null && !$adjustment->fill($values)->isDirty()) {
                    continue;
                }

                SalaryMonthlyComponentAdjustment::upsert([$row], array_keys($key), array_keys($values));
                $changed++;
            }

            return $changed;
        }, 3);
    }

    private function decimal(int $cents): string
    {
        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);
    }
}
