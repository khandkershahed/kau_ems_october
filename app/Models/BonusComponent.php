<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BonusComponent extends Model
{
    /** @use HasFactory<\Database\Factories\BonusComponentFactory> */
    use HasFactory;

    protected $fillable = [
        'fromBasic',
        'isTaxable',
        'componentType',
        'calcType',
        'amount',
    ];

    protected $casts = [
        'fromBasic' => 'boolean',
        'isTaxable' => 'boolean',
        'amount' => 'decimal:2',
    ];

    protected $appends = [
        'display_name',
    ];

    public function getDisplayNameAttribute(): string
    {
        $type = ucfirst($this->componentType ?? '');
        $amountStr = $this->calcType === 'percentage'
            ? "{$this->amount}%"
            : number_format((float) $this->amount, 2) . " TK";
        $basis = $this->fromBasic ? " (Basic)" : "";

        return "#{$this->id} [{$type}] {$amountStr}{$basis}";
    }
}
