<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class SalaryFixationPaySchedule extends Model
{
    protected $fillable = ['salary_fixation_id', 'user_id', 'effective_date', 'payable_basic', 'grade_number'];
    public function fixation() { return $this->belongsTo(SalaryFixation::class, 'salary_fixation_id'); }
    protected $casts = ['effective_date' => 'date', 'payable_basic' => 'decimal:2'];
}
