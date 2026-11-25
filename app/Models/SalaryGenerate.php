<?php

namespace App\Models;

use App\Services\SalaryGenerationGuards;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalaryGenerate extends Model
{
    /** @use HasFactory<\Database\Factories\SalaryGenerateFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'salaryAllowanceCalcObject' => 'array',
        'salaryDeductionCalcObject' => 'array',
        'bonusAllowanceCalcObject' => 'array',
        'bonusDeductionCalcObject' => 'array',
        'salaryGenerationDate' => 'date',
        'joiningDate' => 'date',
        'totalAllowance' => 'float',
        'totalDeduction' => 'float',
        'taxDeduction' => 'float',
        'gpfDeduction' => 'float',
        'salaryTotal' => 'float',
        'bonusAllowance' => 'float',
        'bonusDeduction' => 'float',
        'bonusTaxDeduction' => 'float',
        'bonusTotal' => 'float',
        'loanInstallmentDeduction' => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Finalized salary (through 2026-10) is immutable: no financial update or delete.
     * Only the review workflow status may change on a locked bill.
     */
    protected static function booted(): void
    {
        static::updating(function (self $bill) {
            $dirty = array_diff(array_keys($bill->getDirty()), ['reviewStatus', 'updated_at']);
            if (!$dirty) return;
            $year = (int) $bill->getOriginal('salaryGenerationYear');
            $month = (int) $bill->getOriginal('monthId');
            SalaryGenerationGuards::assertMonthMutable($year, $month, 'overwritten');
        });
        static::deleting(function (self $bill) {
            SalaryGenerationGuards::assertMonthMutable(
                (int) $bill->salaryGenerationYear, (int) $bill->monthId, 'deleted');
        });
    }
}
