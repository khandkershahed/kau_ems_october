<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JoiningDate extends Model
{
    /** @use HasFactory<\Database\Factories\JoiningDateFactory> */
    use HasFactory;
    
    protected $fillable = [
        'user_id', 
        'joining_date'
        ];
}
