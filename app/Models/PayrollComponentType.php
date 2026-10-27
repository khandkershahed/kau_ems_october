<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollComponentType extends Model
{
    /** @use HasFactory<\Database\Factories\PayrollComponentTypeFactory> */
    use HasFactory;

    protected $fillable = [
        "name",
        "component_description",
    ];

    public function payrollComponents()
    {
        return $this->hasMany(PayrollComponent::class);
    }
}
