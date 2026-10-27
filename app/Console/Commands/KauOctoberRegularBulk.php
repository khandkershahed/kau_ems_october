<?php

namespace App\Console\Commands;

use App\Models\EmployeeInfo;
use App\Models\SalaryGenerate;
use App\Models\User;
use App\Services\GeneralProvidentFundService;
use App\Services\SalaryBankAccountResolver;
use App\Services\SalaryBillCompositionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use DomainException;
use Throwable;

/** October 2026 2015-scale regular salary only; no arrear entitlement is posted. */
final class KauOctoberRegularBulk extends Command
{
    protected $signature = 'kau:october-regular-bulk
        {--apply : Insert October regular-only salary bills and monthly GPF entries}
        {--employee= : Optional exact employee code for a one-employee trial}
        {--limit=0 : Optional maximum employees for trial}
        {--actor= : Existing HR user ID for audit attribution}
        {--output= : Directory for local CSV results}';
    protected $description = 'October 2026 regular-only payroll: dry-run default, employee failures reported separately';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $outdir = (string) ($this->option('output') ?: storage_path('app/kau-october-regular'));
        if (!is_dir($outdir) && !mkdir($outdir, 0770, true) && !is_dir($outdir)) {
            $this->error('Cannot create output directory.'); return self::FAILURE;
        }
        $file = rtrim($outdir, '/\\').DIRECTORY_SEPARATOR.'october_2026_'.($apply ? 'applied' : 'dry_run').'_'.date('Ymd_His').'.csv';
        $handle = fopen($file, 'wb');
        if (!$handle) { $this->error('Cannot write CSV.'); return self::FAILURE; }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['employee_code','user_id','result','bill_id','regular_gross','regular_deduction','regular_net','arrear_included','bank_account_id','source_hash','message']);
        $stats = ['ready'=>0,'generated'=>0,'existing'=>0,'needs_review'=>0];
        try {
            if (!Schema::hasTable('salary_bill_source_links') || !Schema::hasTable('salary_generates') || !Schema::hasTable('general_provident_funds')) {
                throw new DomainException('Salary source and GPF migrations required.');
            }
            $employee = trim((string) $this->option('employee'));
            $query = EmployeeInfo::query()->select(['user_id','employeeCode'])->orderBy('user_id');
            if ($employee !== '') $query->where('employeeCode', $employee);
            $limit = max(0,(int)$this->option('limit'));
            if ($limit) $query->limit($limit);
            $profiles = $query->get();
            if ($profiles->isEmpty()) throw new DomainException('No matching payroll profiles.');
            $seen=[];
            foreach ($profiles as $profile) {
                $uid=(int)$profile->user_id;
                $code=(string)$profile->employeeCode;
                if (isset($seen[$uid])) continue;
                $seen[$uid]=true;
                try {
                    // This preview never inserts an arrear or changes a fixation.
                    $source=app(SalaryBillCompositionService::class)->composeRegularOnly($uid,2026,10);
                    if (($source['status']??null)!=='READY') {
                        throw new DomainException(implode('; ', $source['blocking_reasons']??['Regular pay calculation unavailable']));
                    }
                    $bank=$this->bank($uid);
                    $gross=(float)$source['regular_gross'];
                    $ded=(float)$source['regular_deductions'];
                    $net=(float)$source['regular_net'];
                    if (!is_finite($net) || $net < 0 || abs(($gross-$ded)-$net)>0.011) throw new DomainException('Regular net cannot be reconciled.');
                    if (!$apply) {
                        $result=['status'=>'ready','bill_id'=>null];
                    } else {
                        $result=$this->postOne($uid,$source,$bank);
                    }
                    $status=$result['status'];
                    $stats[$status]++;
                    fputcsv($handle,[$code,$uid,$status,$result['bill_id'],$gross,$ded,$net,0,$bank->id,$source['calculation_source_hash'],'Regular salary only; July-September arrear excluded']);
                } catch (Throwable $e) {
                    $stats['needs_review']++;
                    fputcsv($handle,[$code,$uid,'needs_review',null,null,null,null,0,null,null,$this->safeMessage($e->getMessage())]);
                }
            }
        } catch (Throwable $e) {
            fclose($handle);
            $this->error($e->getMessage()); $this->line('CSV: '.$file);
            return self::FAILURE;
        }
        fclose($handle);
        $this->info(json_encode($stats));
        $this->line('CSV: '.$file);
        $this->warn($apply ? 'Bills GENERATED as pending; verify Bank Advice before release.' : 'DRY RUN ONLY. No salary or GPF records were created.');
        return self::SUCCESS;
    }

    private function bank(int $uid): object
    {
        $profile=EmployeeInfo::where('user_id',$uid)->firstOrFail();
        $accounts=DB::table('bank_accounts')->where('user_id',$uid)->get()->all();
        $linked=$profile->bank_account_id ? (int)$profile->bank_account_id : null;
        $resolved=SalaryBankAccountResolver::resolve($uid,$linked,$accounts);
        if ($resolved['errors']) throw new DomainException('Bank: '.implode(', ',$resolved['errors']));
        // Do not silently choose one of multiple accounts. Unique salary-marked
        // fallback is allowed for this urgent run, reported by resolver above.
        $bank=collect($accounts)->first(fn($a)=>(int)$a->id===(int)$resolved['selected_id']);
        if (!$bank || trim((string)$bank->account_no)==='' || strtoupper(trim((string)$bank->account_no))==='STAMP') {
            throw new DomainException('Bank account missing or placeholder.');
        }
        return $bank;
    }

    private function postOne(int $uid,array $preview,object $bank): array
    {
        return DB::transaction(function () use($uid,$preview,$bank) {
            $user=User::whereKey($uid)->lockForUpdate()->firstOrFail();
            if (!(bool)$user->isActive) throw new DomainException('Inactive employee.');
            $bills=SalaryGenerate::where('user_id',$uid)->where('salaryGenerationYear',2026)->where('monthId',10)->lockForUpdate()->get();
            if ($bills->count()>1) throw new DomainException('Multiple October bills.');
            if ($bills->count()===1) return ['status'=>'existing','bill_id'=>(int)$bills->first()->id];
            if (DB::table('salary_bill_source_links')->where('user_id',$uid)->where('salary_year',2026)->where('salary_month',10)->exists()) {
                throw new DomainException('October source link already exists.');
            }
            if (DB::table('general_provident_funds')->where('user_id',$uid)->where('year',2026)->where('month',10)->where('type','monthly_deduction')->exists()) {
                throw new DomainException('October GPF monthly ledger already exists.');
            }
            if (DB::table('general_provident_funds')->where('user_id',$uid)
                ->where(fn($q)=>$q->where('year','>',2026)->orWhere(fn($s)=>$s->where('year',2026)->where('month','>',10)))->exists()) {
                throw new DomainException('Downstream GPF ledger exists.');
            }
            $fresh=app(SalaryBillCompositionService::class)->composeRegularOnly($uid,2026,10);
            if (($fresh['status']??null)!=='READY' || !hash_equals((string)$preview['calculation_source_hash'],(string)($fresh['calculation_source_hash']??''))) {
                throw new DomainException('Regular source changed; regenerate preview.');
            }
            $currentBank=$this->bank($uid);
            if ((int)$currentBank->id!==(int)$bank->id) throw new DomainException('Bank selection changed.');
            $regular=$fresh['regular'];
            $profile=EmployeeInfo::with(['payScale','payrollGroup.grade','payrollGroup.payrollGroupType'])->where('user_id',$uid)->firstOrFail();
            $user->load(['age','joiningDate','phoneNumbers']);
            $date=CarbonImmutable::create(2026,10,1)->endOfMonth()->toDateString();
            $row=[
                'user_id'=>$uid,'reviewStatus'=>'pending','name'=>$user->name,
                'age'=>(string)($user->age?->age??'N/A'),'gender'=>(string)($user->gender??'N/A'),
                'payScale'=>(string)$fresh['pay_scale_source'],'grade'=>(string)($profile->payrollGroup?->grade?->grade_name??'N/A'),
                'designation'=>(string)($profile->payrollGroup?->payrollGroupType?->group_name??'N/A'),
                'employeeCode'=>(string)$profile->employeeCode,'bankAccountNo'=>(string)$bank->account_no,
                'bankAddress'=>(string)($bank->address??'N/A'),
                'employeePhoneNumber'=>(string)($user->phoneNumbers->first()?->phoneNumber??'N/A'),
                'joiningDate'=>$user->joiningDate?->joining_date??$date,'salaryGenerationDate'=>$date,
                'monthId'=>10,'salaryGenerationYear'=>2026,
                'salaryAllowanceCalcObject'=>$regular['allowances'],
                'salaryDeductionCalcObject'=>$regular['deductions'],
                'totalAllowance'=>$fresh['regular_gross'],'totalDeduction'=>$fresh['regular_deductions'],
                'taxDeduction'=>$regular['tax_deduction'],'gpfDeduction'=>$regular['gpf_deduction'],
                'salaryTotal'=>$fresh['regular_net'],
                'bonusAllowanceCalcObject'=>[],'bonusDeductionCalcObject'=>[],
                'bonusAllowance'=>0,'bonusDeduction'=>0,'bonusTaxDeduction'=>0,'bonusTotal'=>0,
                'loanInstallmentDeduction'=>0,'loanInstallmentCount'=>null,'loanInstallmentRemaining'=>null,
            ];
            $bill=SalaryGenerate::create($row);
            DB::table('salary_bill_source_links')->insert([
                'salary_generate_id'=>$bill->id,'user_id'=>$uid,'salary_year'=>2026,'salary_month'=>10,
                'salary_calculation_version_id'=>null,'fixation_id'=>$fresh['fixation_id']??null,
                'bank_account_id'=>$bank->id,'source_hash'=>$fresh['calculation_source_hash'],
                'regular_net'=>$fresh['regular_net'],'arrear_payable'=>0,'arrear_deduction'=>0,
                'net_payable'=>$fresh['regular_net'],
                'source_snapshot'=>json_encode($fresh,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                'created_by'=>($this->option('actor') ? (int)$this->option('actor') : null),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            if ((float)$regular['gpf_deduction']>0) {
                $gpf=app(GeneralProvidentFundService::class)->recordSalaryGpfContribution(
                    $user,$bill,(float)$regular['gpf_deduction'],10,2026,$date);
                if (!$gpf || (int)$gpf->salary_generate_id!==(int)$bill->id) throw new DomainException('GPF ledger linking failed.');
            }
            $allowSum=round(array_sum(array_map(fn($r)=>(float)($r['calculated_amount']??0),$regular['allowances'])),2);
            $dedSum=round(array_sum(array_map(fn($r)=>(float)($r['calculated_amount']??0),$regular['deductions'])),2);
            if (abs($allowSum-(float)$bill->totalAllowance)>0.011 || abs($dedSum-(float)$bill->totalDeduction)>0.011
                || abs(($allowSum-$dedSum)-(float)$bill->salaryTotal)>0.011) throw new DomainException('Bill totals mismatch.');
            return ['status'=>'generated','bill_id'=>(int)$bill->id];
        },3);
    }

    private function safeMessage(string $s): string
    {
        $s=preg_replace('/[\r\n\t]+/',' ',$s)??'Unknown error';
        return mb_substr($s,0,350);
    }
}
