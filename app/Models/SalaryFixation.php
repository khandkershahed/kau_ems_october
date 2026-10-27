<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryFixation extends Model
{
    protected $fillable = [
        'user_id', 'prepared_by', 'pay_scale_reference', 'effective_date',
        'grade_number', 'source_type', 'source_record_id', 'source_sha256',
        'old_basic', 'personal_pay', 'calculation', 'warnings', 'status',
        'prepared_data', 'employee_reviewed_by', 'sent_at', 'employee_responded_at',
        'employee_note', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'old_basic' => 'decimal:2',
        'personal_pay' => 'decimal:2',
        'calculation' => 'array',
        'warnings' => 'array',
        'prepared_data' => 'array',
        'sent_at' => 'datetime',
        'employee_responded_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
