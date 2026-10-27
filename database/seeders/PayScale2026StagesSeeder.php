<?php
namespace Database\Seeders;

use App\Models\Grade;
use App\Models\PayScale;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Register all 2026 legal steps without activating the scale or reassigning employees. */
class PayScale2026StagesSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->call(PayScale2026Seeder::class);
            $scale = PayScale::where('scale_name', 'Govt. Pay Scale 2026')->firstOrFail();
            foreach (config('kau_fixation_2026', []) as $number => $row) {
                $grade = Grade::where('pay_scale_id', $scale->id)
                    ->where('grade_name', 'Grade '.$number)->firstOrFail();
                if ((float) $grade->min !== (float) $row['init2026'] ||
                    (float) $grade->max !== (float) $row['max2026']) {
                    throw new RuntimeException('Conflicting 2026 scale boundaries for Grade '.$number);
                }
                foreach ($row['stages2026'] as $index => $amount) {
                    $key = ['grade_id' => $grade->id, 'stage_number' => $index + 1];
                    $existing = DB::table('pay_scale_stages')->where($key)->first();
                    if ($existing && (float) $existing->basic_amount !== (float) $amount) {
                        throw new RuntimeException('2026 stage conflict at Grade '.$number.', stage '.($index + 1));
                    }
                    if (!$existing) {
                        DB::table('pay_scale_stages')->insert($key + [
                            'basic_amount' => $amount, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }
            // 2026 pay scale is intentionally inactive until validation and phased payroll approval.
        });
    }
}
