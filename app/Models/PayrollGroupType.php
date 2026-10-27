<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollGroupType extends Model
{
    /** @use HasFactory<\Database\Factories\PayrollGroupTypeFactory> */
    use HasFactory;

    protected $fillable = [
        "group_name",
        "group_description",
    ];

    public function payrollGroups()
    {
        return $this->hasMany(PayrollGroup::class);
    }
}
