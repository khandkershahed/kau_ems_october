<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Dormitory extends Model
{
    /** @use HasFactory<\Database\Factories\DormitoryFactory> */
    use HasFactory;
    
    protected $fillable = ['dorm_name', 'dorm_type', 'dorm_address'];
}
