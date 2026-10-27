<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChildCount extends Model
{
    /** @use HasFactory<\Database\Factories\ChildCountFactory> */
    use HasFactory;
    
    protected $fillable = ['user_id', 'childCount', 'boy', 'girl'];
}
