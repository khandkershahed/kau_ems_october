<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class BankAccount extends Model
{
    /** @use HasFactory<\Database\Factories\BankAccountFactory> */
    use HasFactory;

    /**
     * Prevent new or changed real bank numbers being assigned to a second
     * employee. Historical account rows are left untouched until reconciled.
     */
    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            if ($account->exists && !$account->isDirty(['account_no', 'user_id', 'isSalaryAccount'])) {
                return;
            }

            $number = trim((string) $account->account_no);
            $normalized = preg_replace('/[\s-]+/u', '', $number);
            $placeholder = $normalized === ''
                || in_array(strtoupper($normalized), ['N/A', 'STAMP', 'PENDINGACCOUNT'], true);

            // Retain the existing non-salary placeholder used by employee creation.
            if ($placeholder && !(bool) $account->isSalaryAccount) {
                return;
            }

            if ($placeholder || !preg_match('/^[0-9]{9,20}$/D', $normalized)) {
                throw ValidationException::withMessages([
                    'account_no' => 'A salary bank account must have a valid numeric account number.',
                ]);
            }

            if ($account->exists && !$account->isDirty(['account_no', 'user_id'])) {
                return;
            }

            $duplicate = self::query()
                ->where('user_id', '!=', $account->user_id)
                ->whereRaw("REPLACE(REPLACE(account_no, ' ', ''), '-', '') = ?", [$normalized]);
            if ($account->exists) {
                $duplicate->whereKeyNot($account->getKey());
            }
            if ($duplicate->exists()) {
                throw ValidationException::withMessages([
                    'account_no' => 'This bank account number is already registered to an employee.',
                ]);
            }
        });
    }

    protected $fillable = [
        'user_id',
        'routing_no',
        'account_no',
        'type',
        'address',
        'isSalaryAccount'
    ];
}
