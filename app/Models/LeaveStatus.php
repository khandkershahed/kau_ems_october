<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaveStatus extends Model
{
    /** @use HasFactory<\Database\Factories\LeaveStatusFactory> */
    use HasFactory;
    
    protected $fillable = ['onLeave', 'reason', 'isPaid', 'isActive', 'duration'];
}
