<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommentGeneralProvedentFund extends Model
{
    use HasFactory;

    protected $table = 'comment_general_provedent_funds';

    protected $fillable = [
        'general_provident_fund_id',
        'comment_id',
    ];

    public function generalProvidentFund()
    {
        return $this->belongsTo(GeneralProvidentFund::class);
    }

    public function comment()
    {
        return $this->belongsTo(Comment::class);
    }
}
