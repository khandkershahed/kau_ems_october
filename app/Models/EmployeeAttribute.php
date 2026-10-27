<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeAttribute extends Model
{
    /** @use HasFactory<\Database\Factories\EmployeeAttributeFactory> */
    use HasFactory;
    
    protected $fillable = [ 'user_id', 'name', 'description', 'isActive'];
}
