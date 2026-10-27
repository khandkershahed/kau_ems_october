<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class SalaryFixationAdjustment extends Model
{
    protected $fillable = [
        'user_id', 'increment_2026', 'increment_2027', 'old_basic_override',
        'personal_pay_override', 'grade_override', 'baseline_period', 'additional_fields',
        'evidence_reference', 'reason', 'updated_by',
    ];
    protected $casts = [
        'old_basic_override' => 'decimal:2',
        'personal_pay_override' => 'decimal:2',
        'additional_fields' => 'array',
    ];
}
