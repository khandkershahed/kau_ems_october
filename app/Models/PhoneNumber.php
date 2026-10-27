<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PhoneNumber extends Model
{
    /** @use HasFactory<\Database\Factories\PhoneNumberFactory> */
    use HasFactory;

    protected $fillable = [
        "phoneNumber"
    ];
}
