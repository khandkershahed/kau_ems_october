<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DepartmentOffice extends Model
{
    /** @use HasFactory<\Database\Factories\DepartmentOfficeFactory> */
    use HasFactory;

    protected $fillable = [
        'type',
        'description',
        'name',
        'isActive',
    ];

    public function employeeInfos()
    {
        return $this->hasMany(EmployeeInfo::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'department_office_users');
    }
}
