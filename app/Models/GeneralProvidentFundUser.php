<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GeneralProvidentFundUser extends Model
{
    use HasFactory;

    protected $table = 'general_provident_fund_users';

    protected $fillable = [
        'general_provident_fund_id',
        'user_id',
    ];

    public function generalProvidentFund()
    {
        return $this->belongsTo(GeneralProvidentFund::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
