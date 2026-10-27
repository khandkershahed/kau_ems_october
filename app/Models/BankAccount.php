<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    /** @use HasFactory<\Database\Factories\BankAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'routing_no',
        'account_no',
        'type',
        'address',
        'isSalaryAccount'
    ];
}
