<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Import source report values into a protected database table, without modifying salaries. */
class SalaryFixationReferenceReportsSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/KAU_Fixation_Reference_Records.csv');
        $file = fopen($path, 'rb');
        if (!$file) throw new RuntimeException('Original report reference CSV is unavailable.');
        $fields = fgetcsv($file);
        $expected = ['employee_code', 'report_file', 'category', 'old_basic',
            'increment_2026', 'phase_2026_07', 'phase_2027_01', 'phase_2027_07'];
        if ($fields !== $expected) throw new RuntimeException('Reference CSV header is invalid.');
        try {
            DB::transaction(function () use ($file, $fields) {
                while (($record = fgetcsv($file)) !== false) {
                    if (count($record) !== count($fields)) throw new RuntimeException('Invalid reference CSV line.');
                    $row = array_combine($fields, $record);
                    $key = ['employee_code' => $row['employee_code'], 'report_file' => $row['report_file']];
                    $existing = DB::table('salary_fixation_reference_reports')->where($key)->first();
                    if ($existing) {
                        foreach (['category', 'old_basic', 'increment_2026', 'phase_2026_07',
                            'phase_2027_01', 'phase_2027_07'] as $col) {
                            if ($col === 'category' ? (string) $existing->$col !== (string) $row[$col]
                                : abs((float) $existing->$col - (float) $row[$col]) >= .005) {
                                throw new RuntimeException('Source report conflict for '.$key['employee_code'].
                                    '; reconcile in database, do not overwrite automatically.');
                            }
                        }
                    } else {
                        DB::table('salary_fixation_reference_reports')->insert($row + [
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            });
        } finally {
            fclose($file);
        }
    }
}
