<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BonusGenerate extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'bonus_group_id',
        'reviewStatus',
        'name',
        'age',
        'gender',
        'payScale',
        'grade',
        'designation',
        'employeeCode',
        'bankAccountNo',
        'bankAddress',
        'employeePhoneNumber',
        'joiningDate',
        'religion',
        'bonusGenerationDate',
        'monthId',
        'bonusGenerationYear',
        'bonus_title',
        'bonusAllowanceCalcObject',
        'bonusDeductionCalcObject',
        'totalAllowance',
        'totalDeduction',
        'taxDeduction',
        'netBonus',
        'remarks',
    ];

    protected $casts = [
        'bonusAllowanceCalcObject' => 'array',
        'bonusDeductionCalcObject' => 'array',
        'bonusGenerationDate' => 'date',
        'joiningDate' => 'date',
        'totalAllowance' => 'decimal:2',
        'totalDeduction' => 'decimal:2',
        'taxDeduction' => 'decimal:2',
        'netBonus' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function bonusGroup()
    {
        return $this->belongsTo(BonusGroup::class);
    }
}
