<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'purpose',
        'comment',
    ];

    protected $appends = [
        'author_name',
        'formatted_created_at',
    ];

    /**
     * Get author name of the comment.
     */
    public function getAuthorNameAttribute(): string
    {
        return $this->user?->name ?? 'System User';
    }

    /**
     * Get human-readable formatted created_at.
     */
    public function getFormattedCreatedAtAttribute(): string
    {
        return $this->created_at ? $this->created_at->format('d M Y, h:i A') : '';
    }

    /**
     * User who created or is associated with this comment.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * General Provident Fund records associated with this comment.
     */
    public function generalProvidentFunds()
    {
        return $this->belongsToMany(
            GeneralProvidentFund::class,
            'comment_general_provedent_funds',
            'comment_id',
            'general_provident_fund_id'
        )->withTimestamps();
    }
}
