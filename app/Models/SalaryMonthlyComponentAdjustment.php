<?php

namespace App\Models;

use App\Services\SalaryGenerationGuards;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryMonthlyComponentAdjustment extends Model
{
    protected $table = 'salary_monthly_component_adjustments';

    protected $fillable = [
        'user_id',
        'year',
        'month',
        'payroll_component_type_id',
        'arrear_amount',
        'rule_override',
        'override_current_amount',
        'comment',
        'updated_by',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'year' => 'integer',
        'month' => 'integer',
        'payroll_component_type_id' => 'integer',
        'arrear_amount' => 'float',
        'rule_override' => 'boolean',
        'override_current_amount' => 'float',
        'updated_by' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payrollComponentType(): BelongsTo
    {
        return $this->belongsTo(PayrollComponentType::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected static function booted(): void
    {
        static::creating(function (self $adjustment): void {
            SalaryGenerationGuards::assertMonthMutable(
                (int) $adjustment->year,
                (int) $adjustment->month,
                'adjusted'
            );
        });

        static::updating(function (self $adjustment): void {
            SalaryGenerationGuards::assertMonthMutable(
                (int) $adjustment->getOriginal('year'),
                (int) $adjustment->getOriginal('month'),
                'adjusted'
            );
            SalaryGenerationGuards::assertMonthMutable(
                (int) $adjustment->year,
                (int) $adjustment->month,
                'adjusted'
            );
        });

        static::deleting(function (self $adjustment): void {
            SalaryGenerationGuards::assertMonthMutable(
                (int) $adjustment->year,
                (int) $adjustment->month,
                'deleted'
            );
        });
    }
}
