<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Grade extends Model
{
    /** @use HasFactory<\Database\Factories\GradeFactory> */
    use HasFactory;

    protected $fillable = [
        "pay_scale_id",
        "grade_name",
        "grade_description",
        "isActive",
        "min",
        "max",
    ];

    public function payScale()
    {
        return $this->belongsTo(PayScale::class);
    }

    public function payrollGroups()
    {
        return $this->hasMany(PayrollGroup::class);
    }
}
