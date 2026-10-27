<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'isActive',
        'gender',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted()
    {
        static::deleting(function ($user) {
            // Detach many-to-many relationships
            $user->roles()->detach();
            $user->phoneNumbers()->detach();
            $user->addresses()->detach();
            $user->departmentOffices()->detach();
            $user->userCategories()->detach();
            $user->generalProvidentFunds()->detach();

            // Delete employeeInfo first since it holds foreign keys to nid, bid, tin, etc.
            $user->employeeInfo()->delete();

            // Delete hasOne/hasMany relationships
            $user->nid()->delete();
            $user->bid()->delete();
            $user->childCount()->delete();
            $user->maritialStatus()->delete();
            $user->religion()->delete();
            $user->joiningDate()->delete();
            $user->age()->delete();
            $user->dormitory()->delete();
            $user->employeeAttribute()->delete();
            $user->tin()->delete();
            $user->bankAccounts()->delete();
            $user->leaveStatuses()->delete();
            $user->promotions()->delete();
            $user->comments()->delete();
        });
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function nid()
    {
        return $this->hasOne(Nid::class);
    }

    public function bid()
    {
        return $this->hasOne(Bid::class);
    }

    public function childCount()
    {
        return $this->hasOne(ChildCount::class);
    }

    public function maritialStatus()
    {
        return $this->hasOne(MaritialStatus::class);
    }

    public function religion()
    {
        return $this->hasOne(Religion::class);
    }

    public function joiningDate()
    {
        return $this->hasOne(JoiningDate::class);
    }

    public function age()
    {
        return $this->hasOne(Age::class);
    }

    public function dormitory()
    {
        return $this->hasOne(Dormitory::class);
    }

    public function employeeAttribute()
    {
        return $this->hasOne(EmployeeAttribute::class);
    }

    public function tin()
    {
        return $this->hasOne(TIN::class);
    }

    public function bankAccounts()
    {
        return $this->hasMany(BankAccount::class);
    }

    public function leaveStatuses()
    {
        return $this->hasMany(LeaveStatus::class);
    }


    public function promotions()
    {
        return $this->hasMany(Promotion::class);
    }

    public function phoneNumbers()
    {
        return $this->belongsToMany(PhoneNumber::class, 'phone_number_users');
    }

    public function addresses()
    {
        return $this->belongsToMany(Address::class, 'address_users');
    }

    public function employeeInfo()
    {
        return $this->hasOne(EmployeeInfo::class);
    }

    public function departmentOffices()
    {
        return $this->belongsToMany(DepartmentOffice::class, 'department_office_users');
    }

    public function userCategories()
    {
        return $this->belongsToMany(UserCategory::class, 'user_user_categories');
    }

    public function salaryGenerates()
    {
        return $this->hasMany(SalaryGenerate::class);
    }

    public function bonusGenerates()
    {
        return $this->hasMany(BonusGenerate::class);
    }

    public function generalProvidentFunds()
    {
        return $this->belongsToMany(GeneralProvidentFund::class, 'general_provident_fund_users', 'user_id', 'general_provident_fund_id')
            ->withTimestamps();
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }
}
