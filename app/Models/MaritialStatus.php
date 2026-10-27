<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaritialStatus extends Model
{
    /** @use HasFactory<\Database\Factories\MaritialStatusFactory> */
    use HasFactory;
    
    protected $fillable = ['status'];
}
