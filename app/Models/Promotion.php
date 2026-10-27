<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    /** @use HasFactory<\Database\Factories\PromotionFactory> */
    use HasFactory;
    
    protected $fillable = ['promoted_to', 'pay_scale_id', 'payroll_group_id', 'user_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payScale()
    {
        return $this->belongsTo(PayScale::class, 'pay_scale_id');
    }

    public function payrollGroup()
    {
        return $this->belongsTo(PayrollGroup::class, 'payroll_group_id');
    }
}
