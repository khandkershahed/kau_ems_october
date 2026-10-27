<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeInfo extends Model
{
    /** @use HasFactory<\Database\Factories\EmployeeInfoFactory> */
    use HasFactory;
    protected $fillable = [
        'user_id',
        'department_office_id',
        'phone_number_id',
        'address_id',
        'bank_account_id',
        'joining_date_id',
        'nid_id',
        'bid_id',
        't_i_n_s_id',
        'employee_attribute_id',
        'pay_scale_id',
        'payroll_group_id',
        'employeeCode',
        'jobStatus',
        'incrementDate',
        'holdIncrement',
        'additionObjects',
        'substractionObjects',
        'bonus_group_id',
        'bonusAdditionObjects',
        'bonusSubstractionObjects',
    ];

    protected $casts = [
        'additionObjects' => 'array',
        'substractionObjects' => 'array',
        'bonusAdditionObjects' => 'array',
        'bonusSubstractionObjects' => 'array',
        'holdIncrement' => 'boolean',
    ];

    public function bonusGroup()
    {
        return $this->belongsTo(BonusGroup::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function departmentOffice()
    {
        return $this->belongsTo(DepartmentOffice::class);
    }

    public function payScale()
    {
        return $this->belongsTo(PayScale::class);
    }

    public function payrollGroup()
    {
        return $this->belongsTo(PayrollGroup::class);
    }

    public function joiningDate()
    {
        return $this->belongsTo(JoiningDate::class);
    }

    public function nid()
    {
        return $this->belongsTo(Nid::class);
    }

    public function bid()
    {
        return $this->belongsTo(Bid::class);
    }

    public function tin()
    {
        return $this->belongsTo(TIN::class, 't_i_n_s_id');
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function phoneNumber()
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    public function address()
    {
        return $this->belongsTo(Address::class);
    }

    public function employeeAttribute()
    {
        return $this->belongsTo(EmployeeAttribute::class);
    }

    /**
     * Check if employee has an active GPF / Provident Fund deduction component.
     */
    public function hasGpfSubscription(): bool
    {
        return $this->gpf_component !== null;
    }

    /**
     * Get the GPF component from deduction components if present.
     */
    public function getGpfComponentAttribute(): ?array
    {
        $deductions = is_string($this->substractionObjects)
            ? json_decode($this->substractionObjects, true)
            : ($this->substractionObjects ?? []);

        if (!is_array($deductions)) {
            return null;
        }

        foreach ($deductions as $item) {
            $name = strtolower($item['name'] ?? $item['payroll_component_type']['name'] ?? '');
            $type = strtolower($item['component_type'] ?? '');
            if (str_contains($name, 'gpf') || str_contains($name, 'provident') || str_contains($name, 'pf') || str_contains($type, 'provident')) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Get Basic Pay amount from allowances.
     */
    public function getBasicPayAmountAttribute(): float
    {
        $additions = is_string($this->additionObjects)
            ? json_decode($this->additionObjects, true)
            : ($this->additionObjects ?? []);

        if (!is_array($additions)) {
            return 0.0;
        }

        foreach ($additions as $item) {
            $name = strtolower($item['name'] ?? $item['payroll_component_type']['name'] ?? '');
            if (str_contains($name, 'basic')) {
                return (float) ($item['amount'] ?? 0);
            }
        }

        return 0.0;
    }
}
