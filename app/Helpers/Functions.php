<?php

use App\Models\Address;
use App\Models\BankAccount;
use App\Models\Bid;
use App\Models\ChildCount;
use App\Models\DepartmentOffice;
use App\Models\EmployeeInfo;
use App\Models\Grade;
use App\Models\JoiningDate;
use App\Models\LeaveStatus;
use App\Models\Nid;
use App\Models\PayrollComponent;
use App\Models\PayrollComponentType;
use App\Models\PayrollGroup;
use App\Models\PayrollGroupType;
use App\Models\PayScale;
use App\Models\PhoneNumber;
use App\Models\Role;
use App\Models\TIN;
use App\Models\User;
use App\Models\UserCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * @param int $userId
 */
function userIsActive(int $userId): bool
{
    $user = User::find($userId);
    return $user ? (bool) $user->isActive : false;
}


/**
 * @param int $userId
 */
function userHasActiveLeaveRecord(int $userId): bool
{
    return LeaveStatus::where("user_id", $userId)->where("isActive", 1)->exists();
}


/**
 * @param int $userId
 */
function userIsOnLeave(int $userId): bool
{
    return LeaveStatus::where("user_id", $userId)->where('onLeave', 1)->exists();
}

/**
 * @param int $userId
 */
function userHasPayrollInfo(int $userId): bool
{
    return EmployeeInfo::where("user_id", $userId)
        ->whereNotNull("pay_scale_id")
        ->whereNotNull("payroll_group_id")
        ->exists();
}



/**
 * @param int|null $payrollGroupId 
 */
function hasBasicPayrollComponent(?int $payrollGroupId): bool
{
    if (!$payrollGroupId) {
        return false;
    }

    $basicTypeIds = PayrollComponentType::where("name", "like", "%basic%")->pluck('id');
    if ($basicTypeIds->isEmpty()) {
        return false;
    }

    return PayrollComponent::where('payroll_group_id', $payrollGroupId)
        ->whereIn('payroll_component_type_id', $basicTypeIds)
        ->exists();
}

/**
 * @param int $userId
 */
function updateUserSalaryCalculationObjects(int $userId): bool
{
    $employeeRecord = EmployeeInfo::where('user_id', $userId)->first();
    if (!$employeeRecord || !$employeeRecord->payroll_group_id) {
        return false;
    }

    if (!hasBasicPayrollComponent($employeeRecord->payroll_group_id)) {
        return false;
    }

    $allAllowances = PayrollComponent::with('payrollComponentType')
        ->where("payroll_group_id", $employeeRecord->payroll_group_id)
        ->where('component_type', 'allowance')
        ->get();
    $deductions = PayrollComponent::with('payrollComponentType')
        ->where("payroll_group_id", $employeeRecord->payroll_group_id)
        ->where('component_type', 'deduction')
        ->get();

    $employeeRecord->update([
        "additionObjects" => json_encode($allAllowances),
        "substractionObjects" => json_encode($deductions),
    ]);

    return true;
}



/**
 * Seed employee Info
 *
 * @param object|array $UserInfo
 * @return array
 */
function seedEmployeeRecord(object|array $UserInfo): array
{
    return (new \App\Http\Controllers\EmployeeAttributeController())->seedEmployee($UserInfo);
}




/** 
 * Test all composable conditions at one go with declarative error and status.
 * This is the main executor for the tasks in sequence and stop where error occures
 * and returns the step information
 * Always use Imperetive sentences in the conditions array key 
 * @param array $conditions - Associative array of function and expected boolean
 */

function testConditions(array $conditions, bool $test = false): array
{

    $response = [
        "status" => 000,
        "total" => count($conditions),
        "completed" => 0,
        "messages" => [],
        "errors" => [],
    ];

    $index = 1;
    $stop = false;
    // Iterate through the cases array
    foreach ($conditions as $case => $status) {
        if (!$stop) {
            // Test if all the conditions are given right amount of parameters 
            if (count($status) < 2) {
                $response["status"] = 500;
                array_push($response["errors"], "⚠️ Too few parameters: " . strtolower($case));
                $index++;
                $stop = true;
                break;
            }

            try {
                $actual = array_values($status)[0]();
            } catch (\Throwable $e) {
                $response["status"] = 500;
                array_push($response["errors"], "$index. 🚫 " . strtolower($case) . " (" . $e->getMessage() . ")");
                $index++;
                $stop = true;
                break;
            }

            // Test if expected value and test value are equal or not
            if ($actual !== array_values($status)[1]) {
                $response["status"] = 500;
                array_push($response["errors"], "$index. 🚫 " . strtolower($case));
                $index++;
                $stop = true;
                break;
            }

            $response["status"] = 200;
            array_push($response["messages"], "$index. ✅ " . strtolower($case));
            $index++;
        }
    }

    $response["completed"] = count($response["messages"]);
    return $response;
}
