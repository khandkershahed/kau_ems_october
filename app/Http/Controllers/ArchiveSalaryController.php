<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\BankAccount;
use App\Models\Bid;
use App\Models\DepartmentOffice;
use App\Models\EmployeeInfo;
use App\Models\Grade;
use App\Models\JoiningDate;
use App\Models\Nid;
use App\Models\PayScale;
use App\Models\PayrollComponent;
use App\Models\PayrollComponentType;
use App\Models\PayrollGroup;
use App\Models\PayrollGroupType;
use App\Models\PhoneNumber;
use App\Models\Role;
use App\Models\SalaryGenerate;
use App\Models\TIN;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\GeneralProvidentFundService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ArchiveSalaryController extends Controller
{
    /**
     * Display the Archive Import and Preview page.
     */
    public function index(Request $request)
    {
        $archiveFiles = $this->getAvailableArchiveFiles();

        return Inertia::render('Archive/ImportPreview', [
            'archiveFiles' => $archiveFiles,
            'payrollComponentTypes' => PayrollComponentType::all(),
            'departments' => DepartmentOffice::orderBy('name')->get(),
            'payrollGroupTypes' => PayrollGroupType::orderBy('group_name')->get(),
            'grades' => Grade::orderBy('id')->get(),
            'payScales' => PayScale::all(),
        ]);
    }

    /**
     * Load an archive salary JSON file by year and month or filename.
     */
    public function loadFile(Request $request)
    {
        $request->validate([
            'year' => 'nullable|integer',
            'month' => 'nullable|integer',
            'filename' => 'nullable|string',
        ]);

        $year = (int) $request->input('year');
        $month = (int) $request->input('month');
        $filename = $request->input('filename');

        $baseDir = database_path('seeders/archive');

        $filePath = null;
        if ($filename) {
            // Find file by name
            $allFiles = glob($baseDir . '/*/*/' . basename($filename));
            if (!empty($allFiles)) {
                $filePath = $allFiles[0];
            }
        } elseif ($year && $month) {
            $monthName = strtolower(Carbon::createFromDate($year, $month, 1)->format('F'));
            $candidate = "{$baseDir}/{$year}/salary/salary_records_{$monthName}_{$year}.json";
            if (file_exists($candidate)) {
                $filePath = $candidate;
            }
        }

        if (!$filePath || !file_exists($filePath)) {
            return response()->json([
                'status' => 404,
                'message' => 'Archive salary file not found.',
            ], 404);
        }

        $rawContent = file_get_contents($filePath);
        $data = json_decode($rawContent, true);

        if (!is_array($data)) {
            return response()->json([
                'status' => 422,
                'message' => 'Invalid JSON structure in archive file.',
            ], 422);
        }

        // Detect month and year from file or first record
        if (!$year || !$month) {
            $firstMonthStr = $data[0]['salary_components']['month'] ?? '';
            if ($firstMonthStr && preg_match('/^(\d{4})-(\d{1,2})/', $firstMonthStr, $m)) {
                $year = (int) $m[1];
                $month = (int) $m[2];
            } else {
                $year = $year ?: 2025;
                $month = $month ?: 7;
            }
        }

        $processed = $this->processArchiveRecords($data, $year, $month);

        return response()->json([
            'status' => 200,
            'year' => $year,
            'month' => $month,
            'filename' => basename($filePath),
            'records' => $processed['records'],
            'summary' => $processed['summary'],
        ]);
    }

    /**
     * Handle user upload of custom structured salary JSON file.
     */
    public function uploadFile(Request $request)
    {
        $request->validate([
            'file' => 'required|file',
        ]);

        $file = $request->file('file');
        $rawContent = file_get_contents($file->getRealPath());
        $data = json_decode($rawContent, true);

        if (!is_array($data) || empty($data)) {
            return response()->json([
                'status' => 422,
                'message' => 'The uploaded file does not contain a valid JSON array.',
            ], 422);
        }

        // Validate structure of first item
        $first = $data[0];
        if (!isset($first['employee_info']) || !isset($first['salary_components'])) {
            return response()->json([
                'status' => 422,
                'message' => 'The uploaded JSON file is not in the required salary archive format (missing employee_info or salary_components).',
            ], 422);
        }

        $firstMonthStr = $first['salary_components']['month'] ?? '';
        $year = (int) date('Y');
        $month = (int) date('n');

        if ($firstMonthStr && preg_match('/^(\d{4})-(\d{1,2})/', $firstMonthStr, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
        }

        $processed = $this->processArchiveRecords($data, $year, $month);

        return response()->json([
            'status' => 200,
            'year' => $year,
            'month' => $month,
            'filename' => $file->getClientOriginalName(),
            'records' => $processed['records'],
            'summary' => $processed['summary'],
        ]);
    }

    /**
     * Write a single archive salary record using firstOrCreate to softly update if already existing.
     */
    public function writeSingleRecord(Request $request)
    {
        $request->validate([
            'record' => 'required|array',
            'year' => 'required|integer',
            'month' => 'required|integer|between:1,12',
        ]);

        $record = $request->input('record');
        $year = (int) $request->input('year');
        $month = (int) $request->input('month');

        DB::beginTransaction();
        try {
            $result = $this->processAndSaveRecord($record, $year, $month);
            DB::commit();

            return response()->json([
                'status' => 200,
                'action' => $result['action'],
                'message' => $result['message'],
                'employee_name' => $result['employee_name'],
                'employee_code' => $result['employee_code'],
                'net_payable' => $result['net_payable'],
                'record_id' => $record['id'] ?? null,
                'salary_generate_id' => $result['salary_generate_id'],
                'log' => $result['log'],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            $empName = $record['employee_info']['name'] ?? 'Unknown';
            $empCode = $record['employee_info']['service_id'] ?? 'N/A';

            return response()->json([
                'status' => 500,
                'action' => 'error',
                'employee_name' => $empName,
                'employee_code' => $empCode,
                'message' => 'Failed to write record: ' . $e->getMessage(),
                'record_id' => $record['id'] ?? null,
                'log' => "Error processing {$empName} (ID: {$empCode}): " . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Write single or bulk selected salary calculations to the salary_generates table.
     */
    public function writeRecords(Request $request)
    {
        $request->validate([
            'records' => 'required|array|min:1',
            'year' => 'required|integer',
            'month' => 'required|integer|between:1,12',
        ]);

        $records = $request->input('records');
        $year = (int) $request->input('year');
        $month = (int) $request->input('month');

        $createdCount = 0;
        $updatedCount = 0;
        $logs = [];

        DB::beginTransaction();
        try {
            foreach ($records as $record) {
                $res = $this->processAndSaveRecord($record, $year, $month);
                if ($res['action'] === 'updated') {
                    $updatedCount++;
                } else {
                    $createdCount++;
                }
                $logs[] = $res['log'];
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status' => 500,
                'message' => 'Failed to write salary calculations: ' . $e->getMessage(),
            ], 500);
        }

        $totalWritten = $createdCount + $updatedCount;

        return response()->json([
            'status' => 200,
            'message' => "Successfully processed {$totalWritten} salary record(s) ({$createdCount} created, {$updatedCount} softly updated) for " . Carbon::createFromDate($year, $month, 1)->format('F Y') . '.',
            'written_count' => $totalWritten,
            'created_count' => $createdCount,
            'updated_count' => $updatedCount,
            'logs' => $logs,
        ]);
    }

    /**
     * Process provisioning and write/softly update a single salary calculation record.
     */
    private function processAndSaveRecord(array $record, int $year, int $month): array
    {
        // Ensure default PayScale exists
        $defaultPayScale = PayScale::firstOrCreate(
            ['scale_name' => 'National Pay Scale 2015'],
            ['scale_description' => 'National Pay Scale 2015', 'isActive' => true]
        );

        $defaultRole = Role::firstOrCreate(
            ['role_name' => 'user'],
            ['role_description' => 'User Role', 'isActive' => true]
        );

        $allGrades = Grade::all();
        $salaryDate = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

        $empInfo = $record['employee_info'] ?? [];
        $salComp = $record['salary_components'] ?? [];

        $name = trim($empInfo['name'] ?? '');
        $serviceId = trim((string) ($empInfo['service_id'] ?? ''));

        if (!$name) {
            throw new \InvalidArgumentException('Employee name is missing.');
        }

        // 1. Match or provision User
        $user = null;
        if ($serviceId) {
            $existingEmployeeInfo = EmployeeInfo::where('employeeCode', $serviceId)->first();
            if ($existingEmployeeInfo) {
                $user = User::find($existingEmployeeInfo->user_id);
            }
        }

        // Match by bank account if serviceId didn't find the user
        $bankAccountNo = trim((string) ($empInfo['bank_account'] ?? ''));
        if (!$user && $bankAccountNo) {
            $existingBank = BankAccount::where('account_no', $bankAccountNo)->first();
            if ($existingBank && $existingBank->user) {
                $user = $existingBank->user;
            }
        }

        // Only fall back to name match if serviceId is empty AND user doesn't belong to another service_id
        if (!$user && !$serviceId) {
            $candidateUser = User::where('name', $name)->first();
            if ($candidateUser && (!$candidateUser->employeeInfo || empty($candidateUser->employeeInfo->employeeCode))) {
                $user = $candidateUser;
            }
        }

        if (!$user) {
            // Create User
            $emailPrefix = $serviceId ? "emp_{$serviceId}" : Str::slug($name);
            $email = "{$emailPrefix}@kau.ac.bd";
            
            // Guarantee unique email
            $counter = 1;
            while (User::where('email', $email)->exists()) {
                $email = "{$emailPrefix}_{$counter}@kau.ac.bd";
                $counter++;
            }

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'gender' => 'male',
                'isActive' => true,
                'password' => Hash::make('password123'),
            ]);
        }

        // ALWAYS attach default user role to ensure visibility on Salary Setup / Estimation page
        $user->roles()->syncWithoutDetaching([$defaultRole->id]);

        // 2. Department / Office
        $deptName = trim($empInfo['department'] ?? 'General Administration');
        if (strtolower($deptName) === 'invalid' || empty($deptName)) {
            $desLower = strtolower(trim($empInfo['designation'] ?? ''));
            if (str_contains($desLower, 'car helper') || str_contains($desLower, 'driver')) {
                $deptName = 'Transport pool';
            } else {
                $deptName = 'General Administration';
            }
        }
        $deptOffice = DepartmentOffice::firstOrCreate(
            ['name' => $deptName],
            ['type' => 'office', 'description' => $deptName, 'isActive' => true]
        );

        // 3. Grade & PayScale
        $scaleStr = trim($empInfo['pay_scale'] ?? '');
        $gradeNum = 9;
        if ($scaleStr && preg_match('/Grade\s*0?(\d+)/i', $scaleStr, $gm)) {
            $gradeNum = (int) $gm[1];
        }

        $grade = $allGrades->first(function ($g) use ($gradeNum) {
            $gName = strtolower($g->grade_name);
            return $gName === "grade {$gradeNum}" || $gName === sprintf("grade %02d", $gradeNum);
        });

        if (!$grade) {
            $grade = Grade::create([
                'pay_scale_id' => $defaultPayScale->id,
                'grade_name' => "Grade {$gradeNum}",
                'grade_description' => "Grade {$gradeNum}",
                'min' => 10000,
                'max' => 50000,
                'isActive' => true,
            ]);
            $allGrades = Grade::all();
        }

        // 4. Designation & PayrollGroup
        $designation = trim($empInfo['designation'] ?? 'Staff');
        $payrollGroupType = PayrollGroupType::firstOrCreate(
            ['group_name' => $designation],
            ['group_description' => "Post of {$designation}"]
        );

        $payrollGroup = PayrollGroup::firstOrCreate(
            [
                'payroll_group_type_id' => $payrollGroupType->id,
                'grade_id' => $grade->id,
            ],
            [
                'isActive' => true,
            ]
        );

        // Assign User Category based on grade / designation
        $categoryName = 'Staff';
        if ($gradeNum <= 9) {
            $categoryName = 'Officer';
        } elseif ($gradeNum <= 16) {
            $categoryName = 'Class 3';
        } else {
            $categoryName = 'Class 4';
        }
        $desLower = strtolower($designation);
        if (str_contains($desLower, 'teacher') || str_contains($desLower, 'professor') || str_contains($desLower, 'lecturer')) {
            $categoryName = 'Teacher';
        }

        $userCategory = UserCategory::firstOrCreate(
            ['name' => $categoryName],
            ['description' => "User Category: {$categoryName}"]
        );
        $user->userCategories()->syncWithoutDetaching([$userCategory->id]);

        // 5. Bank Account & Profile entities
        $bankAccountNo = trim((string) ($empInfo['bank_account'] ?? ''));
        if (!$bankAccountNo) {
            $bankAccountNo = '205011' . sprintf('%08d', $user->id);
        }
        $bankAccount = BankAccount::firstOrCreate(
            [
                'user_id' => $user->id,
                'account_no' => $bankAccountNo,
            ],
            [
                'routing_no' => '010270860',
                'type' => 'Savings',
                'address' => 'Agrani Bank, KAU Branch',
                'isSalaryAccount' => true,
            ]
        );

        // DepartmentOffice user relation
        $user->departmentOffices()->syncWithoutDetaching([$deptOffice->id]);

        // Phone Number
        $phone = $user->phoneNumbers()->first();
        if (!$phone) {
            $phone = PhoneNumber::firstOrCreate([
                'phoneNumber' => '+88017' . sprintf('%08d', 10000000 + $user->id),
            ]);
            $user->phoneNumbers()->syncWithoutDetaching([$phone->id]);
        }

        // Address
        $address = $user->addresses()->first();
        if (!$address) {
            $address = Address::firstOrCreate([
                'address' => 'KAU Campus, Khulna',
            ]);
            $user->addresses()->syncWithoutDetaching([$address->id]);
        }

        // Joining Date
        $jDate = $user->joiningDate;
        if (!$jDate) {
            $jDate = $user->joiningDate()->create([
                'joining_date' => now()->toDateString(),
            ]);
        }

        // NID
        $nid = $user->nid;
        if (!$nid) {
            $nid = $user->nid()->create([
                'nid_no' => sprintf('19%02d%013d', 90, $user->id),
                'nid_img' => null,
            ]);
        }

        // BID
        $bid = $user->bid;
        if (!$bid) {
            $bid = $user->bid()->create([
                'bid_no' => sprintf('20%02d%013d', 0, $user->id),
                'date_of_birth' => '1990-01-01',
                'bidImg' => null,
            ]);
        }

        // TIN
        $tin = $user->tin;
        if (!$tin) {
            $tin = $user->tin()->create([
                'tin_no' => sprintf('%012d', 100000000000 + $user->id),
                'tin_img' => null,
            ]);
        }

        // 6. EmployeeInfo
        $employeeInfo = EmployeeInfo::firstOrNew(['user_id' => $user->id]);
        $employeeInfo->department_office_id = $deptOffice->id;
        $employeeInfo->payroll_group_id = $payrollGroup->id;
        $employeeInfo->pay_scale_id = $grade->pay_scale_id ?: $defaultPayScale->id;
        $employeeInfo->bank_account_id = $bankAccount->id;
        $employeeInfo->phone_number_id = $phone->id;
        $employeeInfo->address_id = $address->id;
        $employeeInfo->joining_date_id = $jDate->id;
        $employeeInfo->nid_id = $nid->id;
        $employeeInfo->bid_id = $bid->id;
        $employeeInfo->t_i_n_s_id = $tin->id;

        // Employee Code
        $code = null;
        if ($serviceId && is_numeric($serviceId)) {
            $code = (int) $serviceId;
        }
        if (!$code) {
            $code = $employeeInfo->employeeCode ?: ((EmployeeInfo::max('employeeCode') ?? 10000) + 1);
        }
        while (EmployeeInfo::where('employeeCode', $code)->where('user_id', '!=', $user->id)->exists()) {
            $code++;
        }
        $employeeInfo->employeeCode = $code;

        $employeeInfo->jobStatus = $employeeInfo->jobStatus ?: 'permanent';
        $employeeInfo->incrementDate = $employeeInfo->incrementDate ?: '2026-07-01';
        if ($employeeInfo->holdIncrement === null) {
            $employeeInfo->holdIncrement = false;
        }

        // 7. Format allowances and deductions arrays
        $allowancesRaw = $salComp['allowances'] ?? [];
        $deductionsRaw = $salComp['deductions'] ?? [];

        $allComponentTypes = PayrollComponentType::all();
        $existingGroupComponents = PayrollComponent::where('payroll_group_id', $payrollGroup->id)->get();

        $allowanceObjects = [];
        $totalAllowance = 0.0;
        foreach ($allowancesRaw as $k => $v) {
            $amt = (float) (is_array($v) ? ($v['amount'] ?? 0) : $v);
            $compName = is_array($v) && !empty($v['name']) ? $v['name'] : $this->componentKeyToName($k);

            $matchedType = $allComponentTypes->first(function ($t) use ($compName, $k) {
                return strtolower(trim($t->name)) === strtolower(trim($compName))
                    || str_contains(strtolower($t->name), strtolower(str_replace('_', ' ', $k)));
            });
            $compTypeId = $matchedType ? $matchedType->id : null;
            $pgComp = $compTypeId ? $existingGroupComponents->firstWhere('payroll_component_type_id', $compTypeId) : null;

            $allowanceObjects[] = [
                'id' => $pgComp ? $pgComp->id : null,
                'payroll_group_id' => $payrollGroup->id,
                'payroll_component_type_id' => $compTypeId,
                'name' => $compName,
                'amount' => (string) $amt,
                'default_amount' => (string) $amt,
                'component_type' => 'allowance',
                'calcType' => 'fixed',
                'default_calc_type' => 'fixed',
                'is_taxable' => 0,
                'isActive' => 1,
                'ruleOver' => false,
                'calculated_amount' => $amt,
            ];
            $totalAllowance += $amt;
        }

        $deductionObjects = [];
        $totalDeduction = 0.0;
        $taxDeduction = 0.0;
        $gpfDeduction = 0.0;

        foreach ($deductionsRaw as $k => $v) {
            $amt = (float) (is_array($v) ? ($v['amount'] ?? 0) : $v);
            $compName = is_array($v) && !empty($v['name']) ? $v['name'] : $this->componentKeyToName($k);

            $matchedType = $allComponentTypes->first(function ($t) use ($compName, $k) {
                return strtolower(trim($t->name)) === strtolower(trim($compName))
                    || str_contains(strtolower($t->name), strtolower(str_replace('_', ' ', $k)));
            });
            $compTypeId = $matchedType ? $matchedType->id : null;
            $pgComp = $compTypeId ? $existingGroupComponents->firstWhere('payroll_component_type_id', $compTypeId) : null;

            $deductionObjects[] = [
                'id' => $pgComp ? $pgComp->id : null,
                'payroll_group_id' => $payrollGroup->id,
                'payroll_component_type_id' => $compTypeId,
                'name' => $compName,
                'amount' => (string) $amt,
                'default_amount' => (string) $amt,
                'component_type' => 'deduction',
                'calcType' => 'fixed',
                'default_calc_type' => 'fixed',
                'is_taxable' => 0,
                'isActive' => 1,
                'ruleOver' => false,
                'calculated_amount' => $amt,
            ];
            $totalDeduction += $amt;

            $lowerName = strtolower($compName);
            if (str_contains($lowerName, 'tax') || str_contains($k, 'tax')) {
                $taxDeduction += $amt;
            } elseif (str_contains($lowerName, 'gpf') || str_contains($lowerName, 'provident') || str_contains($k, 'provident')) {
                $gpfDeduction += $amt;
            }
        }

        $salaryTotal = $totalAllowance - $totalDeduction;

        $employeeInfo->additionObjects = $allowanceObjects;
        $employeeInfo->substractionObjects = $deductionObjects;
        $employeeInfo->save();

        // 8. Use firstOrCreate to softly update if record already exists
        $searchCriteria = [
            'user_id' => $user->id,
            'monthId' => $month,
            'salaryGenerationYear' => $year,
        ];

        $salaryData = [
            'reviewStatus' => 'approved',
            'name' => $user->name,
            'age' => '35',
            'gender' => $user->gender ?? 'male',
            'payScale' => $scaleStr ?: 'National Pay Scale 2015',
            'grade' => $grade ? $grade->grade_name : "Grade {$gradeNum}",
            'designation' => $designation,
            'employeeCode' => $serviceId ?: (string) $code,
            'bankAccountNo' => $bankAccountNo ?: 'N/A',
            'bankAddress' => 'Agrani Bank, KAU Branch',
            'employeePhoneNumber' => $phone->phoneNumber ?? 'N/A',
            'joiningDate' => $jDate->joining_date ?? now()->toDateString(),
            'salaryGenerationDate' => $salaryDate,
            'salaryAllowanceCalcObject' => $allowanceObjects,
            'salaryDeductionCalcObject' => $deductionObjects,
            'totalAllowance' => $totalAllowance,
            'totalDeduction' => $totalDeduction,
            'taxDeduction' => $taxDeduction,
            'gpfDeduction' => $gpfDeduction,
            'salaryTotal' => $salaryTotal,
            'bonusAllowanceCalcObject' => [],
            'bonusDeductionCalcObject' => [],
            'bonusAllowance' => 0.00,
            'bonusDeduction' => 0.00,
            'bonusTaxDeduction' => 0.00,
            'bonusTotal' => 0.00,
            'loanInstallmentDeduction' => 0.00,
            'loanInstallmentCount' => null,
            'loanInstallmentRemaining' => null,
        ];

        $salaryGenerate = SalaryGenerate::firstOrCreate($searchCriteria, $salaryData);

        if (!$salaryGenerate->wasRecentlyCreated) {
            // Record already exists -> softly update calculations while preserving existing reviewStatus
            if (!empty($salaryGenerate->reviewStatus)) {
                $salaryData['reviewStatus'] = $salaryGenerate->reviewStatus;
            }
            $salaryGenerate->update($salaryData);
            $action = 'updated';
            $log = "Record already exists for {$user->name} (ID: " . ($serviceId ?: $code) . "). Softly updated salary calculations (Net: ৳" . number_format($salaryTotal, 2) . ", GPF: ৳" . number_format($gpfDeduction, 2) . ").";
            $message = "Softly updated existing record for {$user->name}.";
        } else {
            $action = 'created';
            $log = "Created new salary record for {$user->name} (ID: " . ($serviceId ?: $code) . ") (Net: ৳" . number_format($salaryTotal, 2) . ", GPF: ৳" . number_format($gpfDeduction, 2) . ").";
            $message = "Created new salary record for {$user->name}.";
        }

        // 9. Sync GPF contribution
        if ($gpfDeduction > 0) {
            $gpfService = app(GeneralProvidentFundService::class);
            $gpfService->recordSalaryGpfContribution(
                $user,
                $salaryGenerate,
                $gpfDeduction,
                $month,
                $year,
                $salaryDate
            );
            $gpfService->recalculateUserBalances($user->id);
        }

        return [
            'action' => $action,
            'message' => $message,
            'employee_name' => $user->name,
            'employee_code' => $serviceId ?: (string) $code,
            'net_payable' => $salaryTotal,
            'salary_generate_id' => $salaryGenerate->id,
            'log' => $log,
        ];
    }

    /**
     * Gather available archive JSON files from the filesystem.
     */
    private function getAvailableArchiveFiles(): array
    {
        $baseDir = database_path('seeders/archive');
        $files = glob($baseDir . '/*/*/*.json');

        $result = [];
        $monthsMap = [
            'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
            'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
            'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12
        ];

        foreach ($files as $filePath) {
            $filename = basename($filePath);
            // Example: salary_records_july_2025.json
            if (preg_match('/salary_records_([a-z]+)_(\d{4})\.json/i', $filename, $matches)) {
                $monthName = strtolower($matches[1]);
                $year = (int) $matches[2];
                $month = $monthsMap[$monthName] ?? 1;

                $data = json_decode(@file_get_contents($filePath), true);
                $recordsCount = is_array($data) ? count($data) : 0;

                // Check how many are currently written to salary_generates
                $writtenCount = SalaryGenerate::where('salaryGenerationYear', $year)
                    ->where('monthId', $month)
                    ->count();

                $result[] = [
                    'filename' => $filename,
                    'year' => $year,
                    'month' => $month,
                    'month_name' => ucfirst($monthName),
                    'label' => ucfirst($monthName) . ' ' . $year,
                    'relative_path' => str_replace('\\', '/', str_replace($baseDir . '/', '', $filePath)),
                    'records_count' => $recordsCount,
                    'written_count' => $writtenCount,
                    'is_complete' => $recordsCount > 0 && $writtenCount >= $recordsCount,
                ];
            }
        }

        // Sort chronologically (year ascending, month ascending)
        usort($result, function ($a, $b) {
            if ($a['year'] === $b['year']) {
                return $a['month'] <=> $b['month'];
            }
            return $a['year'] <=> $b['year'];
        });

        return $result;
    }

    /**
     * Process archive records and cross-reference with database write status.
     */
    private function processArchiveRecords(array $data, int $year, int $month): array
    {
        // Preload written user records for this month and year
        $writtenUserMap = SalaryGenerate::where('salaryGenerationYear', $year)
            ->where('monthId', $month)
            ->get()
            ->keyBy(function ($item) {
                return strtolower(trim($item->name));
            });

        $writtenCodeMap = SalaryGenerate::where('salaryGenerationYear', $year)
            ->where('monthId', $month)
            ->whereNotNull('employeeCode')
            ->get()
            ->keyBy('employeeCode');

        $records = [];
        $totalGross = 0.0;
        $totalDeductions = 0.0;
        $totalNet = 0.0;
        $totalBasic = 0.0;
        $totalGpf = 0.0;
        $totalTax = 0.0;
        $writtenCount = 0;

        foreach ($data as $index => $item) {
            $empInfo = $item['employee_info'] ?? [];
            $salComp = $item['salary_components'] ?? [];

            $name = trim($empInfo['name'] ?? 'Unknown');
            $serviceId = trim((string) ($empInfo['service_id'] ?? ''));

            // Check write status
            $isWritten = false;
            $sgId = null;

            if ($serviceId) {
                if (isset($writtenCodeMap[$serviceId])) {
                    $isWritten = true;
                    $sgId = $writtenCodeMap[$serviceId]->id;
                }
            } else {
                if (isset($writtenUserMap[strtolower($name)])) {
                    $isWritten = true;
                    $sgId = $writtenUserMap[strtolower($name)]->id;
                }
            }

            if ($isWritten) {
                $writtenCount++;
            }

            // Standardize allowances & deductions objects
            $allowances = $salComp['allowances'] ?? [];
            $deductions = $salComp['deductions'] ?? [];

            $formattedAllowances = [];
            $itemGross = 0.0;
            $basicAmount = (float) ($salComp['basic_pay'] ?? ($allowances['basic_pay'] ?? 0));

            foreach ($allowances as $k => $v) {
                $amt = (float) (is_array($v) ? ($v['amount'] ?? 0) : $v);
                $compName = is_array($v) && !empty($v['name']) ? $v['name'] : $this->componentKeyToName($k);
                $formattedAllowances[] = [
                    'name' => $compName,
                    'amount' => $amt,
                    'key' => $k,
                ];
                $itemGross += $amt;
            }

            $formattedDeductions = [];
            $itemDeductions = 0.0;
            foreach ($deductions as $k => $v) {
                $amt = (float) (is_array($v) ? ($v['amount'] ?? 0) : $v);
                $compName = is_array($v) && !empty($v['name']) ? $v['name'] : $this->componentKeyToName($k);
                $formattedDeductions[] = [
                    'name' => $compName,
                    'amount' => $amt,
                    'key' => $k,
                ];
                $itemDeductions += $amt;

                if (str_contains($k, 'tax')) {
                    $totalTax += $amt;
                } elseif (str_contains($k, 'provident')) {
                    $totalGpf += $amt;
                }
            }

            $itemNet = $itemGross - $itemDeductions;

            $totalBasic += $basicAmount;
            $totalGross += $itemGross;
            $totalDeductions += $itemDeductions;
            $totalNet += $itemNet;

            $records[] = [
                'id' => $index + 1,
                'is_written' => $isWritten,
                'salary_generate_id' => $sgId,
                'employee_info' => [
                    'name' => $name,
                    'service_id' => $serviceId,
                    'designation' => $empInfo['designation'] ?? 'N/A',
                    'department' => $empInfo['department'] ?? 'N/A',
                    'pay_scale' => $empInfo['pay_scale'] ?? 'N/A',
                    'bank_account' => $empInfo['bank_account'] ?? 'N/A',
                ],
                'salary_components' => [
                    'month' => sprintf('%04d-%02d', $year, $month),
                    'basic_pay' => $basicAmount,
                    'allowances' => $allowances,
                    'formatted_allowances' => $formattedAllowances,
                    'gross_salary' => $itemGross,
                    'deductions' => $deductions,
                    'formatted_deductions' => $formattedDeductions,
                    'total_deductions' => $itemDeductions,
                    'net_payable' => $itemNet,
                ],
            ];
        }

        $summary = [
            'total_employees' => count($records),
            'total_basic_pay' => $totalBasic,
            'total_gross' => $totalGross,
            'total_deductions' => $totalDeductions,
            'total_net_payable' => $totalNet,
            'total_gpf' => $totalGpf,
            'total_tax' => $totalTax,
            'written_count' => $writtenCount,
            'pending_count' => count($records) - $writtenCount,
        ];

        return [
            'records' => $records,
            'summary' => $summary,
        ];
    }

    /**
     * Map snake_case keys in JSON to standard Title Case component names.
     */
    private function componentKeyToName(string $key): string
    {
        $map = [
            'basic_pay' => 'Basic Pay',
            'house_rent_allowance' => 'House Rent Allowance',
            'medical_allowance' => 'Medical Allowance',
            'special_benefit' => 'Special benefit',
            'education_allowance' => 'Education Allowance',
            'charge_allowance' => 'Charge Allowance',
            'mobile_allowance' => 'Mobile Allowance',
            'telephone_call_allowance' => 'Telephone call allowance',
            'others_allowance' => 'Others Allowance',
            'travel_allowance' => 'Travel allowance',
            'tiffin_allowance' => 'Tiffin Allowance',
            'festival_allowance' => 'Festival Allowance',
            'bangla_new_year_allowance' => 'Bangla New Year Allowance',
            'book_allowance' => 'Book Allowance',
            'general_provident_fund' => 'General Provident Fund',
            'income_tax' => 'Income Tax',
            'revenue_stamp' => 'Revenue Stamp',
            'others_deduction' => 'Others Deduction',
            'deduction_for_use_of_office_vehicles' => 'Deduction (for use of office vehicles)',
        ];

        return $map[$key] ?? Str::title(str_replace('_', ' ', $key));
    }
}
