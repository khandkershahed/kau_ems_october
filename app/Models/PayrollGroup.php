<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollGroup extends Model
{
    /** @use HasFactory<\Database\Factories\PayrollGroupFactory> */
    use HasFactory;

    protected $fillable = [
        "grade_id",
        "payroll_group_type_id",
        "isActive",
    ];

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function payrollGroupType()
    {
        return $this->belongsTo(PayrollGroupType::class);
    }


    public function payrollScales()
    {
        return $this->belongsToMany(PayScale::class);
    }

    public function payrollComponents()
    {
        return $this->hasMany(PayrollComponent::class);
    }
}
