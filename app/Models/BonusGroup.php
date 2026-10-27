<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BonusGroup extends Model
{
    /** @use HasFactory<\Database\Factories\BonusGroupFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'bonusAllowanceObjects',
        'bonusDeductionObjects',
    ];

    protected $casts = [
        'bonusAllowanceObjects' => 'array',
        'bonusDeductionObjects' => 'array',
    ];

    public function employeeInfos()
    {
        return $this->hasMany(EmployeeInfo::class);
    }

    public function bonusGenerates()
    {
        return $this->hasMany(BonusGenerate::class);
    }
}
