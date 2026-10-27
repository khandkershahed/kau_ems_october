<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TIN extends Model
{
    /** @use HasFactory<\Database\Factories\TINFactory> */
    use HasFactory;
    
    protected $fillable = ['user_id', 'tin_no', 'tin_img'];
}
