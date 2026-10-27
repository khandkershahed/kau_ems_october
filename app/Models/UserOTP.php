<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserOTP extends Model
{
    /** @use HasFactory<\Database\Factories\UserOTPFactory> */
    use HasFactory;

    protected $fillable = [
        "otpCode",
        "isConfirmed",
        "user_id",
    ];
}
