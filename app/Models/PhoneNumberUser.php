<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PhoneNumberUser extends Model
{
    /** @use HasFactory<\Database\Factories\PhoneNumberUserFactory> */
    use HasFactory;
protected $fillable = [
        "user_id", 
        "phone_number_id", 
    ];

}
