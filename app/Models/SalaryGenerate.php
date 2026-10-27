<?php

namespace App\Models;

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
}
