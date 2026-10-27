<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollGroupPayScale extends Model
{
    /** @use HasFactory<\Database\Factories\PayrollGroupPayScaleFactory> */
    use HasFactory;

    protected $table ="pay_scale_payroll_group";

    protected $fillable = [
        "payroll_group_id",
        "pay_scale_id",

    ];
}
