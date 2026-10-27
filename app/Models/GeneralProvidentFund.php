<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GeneralProvidentFund extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'salary_generate_id',
        'employeeId',
        'contributionDate',
        'generationDate',
        'financialYear',
        'month',
        'year',
        'openingBalance',
        'contributionAmount',
        'benefitPercentage',
        'interestAmount',
        'netDeposit',
        'closingAmount',
        'type',
        'isSettled',
        'settlementDate',
        'remarks',
    ];

    protected $casts = [
        'contributionDate' => 'date:Y-m-d',
        'generationDate' => 'date:Y-m-d',
        'settlementDate' => 'date:Y-m-d',
        'isSettled' => 'boolean',
        'month' => 'integer',
        'year' => 'integer',
        'openingBalance' => 'decimal:2',
        'contributionAmount' => 'decimal:2',
        'benefitPercentage' => 'decimal:2',
        'interestAmount' => 'decimal:2',
        'netDeposit' => 'decimal:2',
        'closingAmount' => 'decimal:2',
    ];

    protected static function booted()
    {
        static::saved(function ($model) {
            if ($model->user_id) {
                $model->users()->syncWithoutDetaching([$model->user_id]);
            }
        });
    }

    /**
     * The primary user associated with this GPF record.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Users associated with this GPF record via the pivot table.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'general_provident_fund_users', 'general_provident_fund_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Comments attached to this GPF record via pivot table.
     */
    public function comments()
    {
        return $this->belongsToMany(Comment::class, 'comment_general_provedent_funds', 'general_provident_fund_id', 'comment_id')
            ->withTimestamps();
    }

    /**
     * Associated salary generation bill record if generated during monthly salary.
     */
    public function salaryGenerate()
    {
        return $this->belongsTo(SalaryGenerate::class, 'salary_generate_id');
    }

    /**
     * Scope to filter records for a specific user ID.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where('user_id', $userId)
              ->orWhereHas('users', function ($sq) use ($userId) {
                  $sq->where('users.id', $userId);
              });
        });
    }

    /**
     * Scope to filter by financial year.
     */
    public function scopeFinancialYear($query, $financialYear)
    {
        return $query->where('financialYear', $financialYear);
    }

    /**
     * Scope to filter unsettled funds.
     */
    public function scopeUnsettled($query)
    {
        return $query->where('isSettled', false);
    }

    /**
     * Scope to filter settled funds.
     */
    public function scopeSettled($query)
    {
        return $query->where('isSettled', true);
    }
}
