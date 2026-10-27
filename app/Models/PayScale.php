<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayScale extends Model
{
    /** @use HasFactory<\Database\Factories\PayScaleFactory> */
    use HasFactory;

    protected $fillable = [
        "scale_name",
        "scale_description",
        "isActive",
    ];


    public function payrollGroups(){
        return $this->belongsToMany(PayrollGroup::class);
    }

    public function grades()
    {
        return $this->hasMany(Grade::class);
    }

    public function employeeInfos()
    {
        return $this->hasMany(EmployeeInfo::class);
    }

    public function promotions()
    {
        return $this->hasMany(Promotion::class);
    }
}
