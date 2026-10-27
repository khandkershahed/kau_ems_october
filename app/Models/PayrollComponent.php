<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollComponent extends Model
{
    /** @use HasFactory<\Database\Factories\PayrollComponentFactory> */
    use HasFactory;

    protected $fillable = [
        "payroll_group_id",
        "payroll_component_type_id",
        "component_type",
        "calcType",
        "is_taxable",
        "isActive",
        "amount",
    ];

    public function payrollGroup()
    {
        return $this->belongsTo(PayrollGroup::class);
    }

    public function payrollComponentType()
    {
        return $this->belongsTo(PayrollComponentType::class);
    }
}
