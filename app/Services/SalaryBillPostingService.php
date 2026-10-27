<?php
namespace App\Services;

use App\Models\SalaryGenerate;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Phase 5 atomic salary posting. Always recomputes financial sources server-side. */
final class SalaryBillPostingService
{
    public function preview(int $userId, int $year, int $month): array
    {
        return app(SalaryBillCompositionService::class)->compose($userId,$year,$month);
    }

    /** Pure read-only posting feasibility screen. The posting transaction still
     * performs all decisive duplicate/GPF/arrear checks under its employee lock.
     */
    public function previewForPosting(int $userId, int $year, int $month): array
    {
        $billCount=SalaryGenerate::where('user_id',$userId)->where('monthId',$month)
            ->where('salaryGenerationYear',$year)->count();
        if ($billCount > 0) throw new DomainException('Salary bill already generated for this employee and month.');
        if (DB::table('salary_bill_source_links')->where('user_id',$userId)
            ->where('salary_year',$year)->where('salary_month',$month)->exists()) {
            throw new DomainException('An existing payroll source link covers the selected month.');
        }
        if (DB::table('general_provident_funds')->where('user_id',$userId)->where('year',$year)
            ->where('month',$month)->where('type','monthly_deduction')->exists()) {
            throw new DomainException('Monthly GPF contribution already exists for the selected month.');
        }
        if (DB::table('general_provident_funds')->where('user_id',$userId)
            ->where(function($q)use($year,$month){$q->where('year','>',$year)
                ->orWhere(fn($sub)=>$sub->where('year',$year)->where('month','>',$month));})->exists()) {
            throw new DomainException('Later GPF ledger exists; back-dated generation needs reconciliation.');
        }
        $source=$this->preview($userId,$year,$month);
        if ($source['arrear_payable']>0 || $source['arrear_deduction']>0) {
            $months=[];
            foreach (array_merge($source['arrear_additions'],$source['arrear_deductions']) as $item) {
                if ((float)$item['calculated_amount']<=0) continue;
                foreach (($item['arrear_entitlement_months'] ?: [sprintf('%04d-%02d',$year,$month)]) as $key) $months[$key]=true;
            }
            $prior=DB::table('salary_arrear_disbursements as d')
                ->join('salary_arrear_periods as p','p.id','=','d.period_id')
                ->where('d.user_id',$userId)->where('d.status','posted')
                ->get(['p.entitlement_start','p.entitlement_end']);
            foreach ($prior as $posted) foreach (array_keys($months) as $key) {
                if ($key>=substr((string)$posted->entitlement_start,0,7)
                    && $key<=substr((string)$posted->entitlement_end,0,7)) {
                    throw new DomainException('Already posted arrear overlaps '.$key.'.');
                }
            }
        }
        return $source;
    }

    public function post(int $userId, int $year, int $month, ?int $actorId = null, ?int $expectedVersion = null, ?string $expectedSourceHash = null): array
    {
        if (!Schema::hasTable('salary_bill_source_links') || !Schema::hasTable('salary_bill_arrear_gpf_entries')) {
            throw new DomainException('Phase 5 source/link migrations have not been applied.');
        }
        return DB::transaction(function () use ($userId,$year,$month,$actorId,$expectedVersion,$expectedSourceHash) {
            $user=User::whereKey($userId)->lockForUpdate()->first();
            if (!$user) throw new DomainException('Unknown employee.');
            // Check browser's displayed workspace version under the same employee
            // row lock used for posting; prevents stale approval/version races.
            if ($expectedVersion !== null) {
                $latest=(int)(DB::table('salary_calculation_versions')->where('user_id',$userId)
                    ->where('salary_year',$year)->where('salary_month',$month)
                    ->max('version') ?? 0);
                if ($latest !== $expectedVersion) {
                    throw new DomainException('A newer salary calculation version exists; reload before generating this bill.');
                }
            }
            $bills=SalaryGenerate::where('user_id',$userId)->where('monthId',$month)
                ->where('salaryGenerationYear',$year)->lockForUpdate()->get();
            if ($bills->count()>1) throw new DomainException('Duplicate saved salary bills; manual reconciliation required.');
            if ($bills->count()===1) {
                // Never mutate an old bill in response to a repeated request.
                return ['result'=>'skipped_existing','bill_id'=>$bills->first()->id,'net_salary'=>(float)$bills->first()->salaryTotal];
            }
            if (DB::table('salary_bill_source_links')->where('user_id',$userId)->where('salary_year',$year)
                ->where('salary_month',$month)->exists()) throw new DomainException('A salary source has already been posted for this employee/month.');
            // Check old and manually-entered ledger rows BEFORE creating the bill.
            $existingGpf=DB::table('general_provident_funds')->where('user_id',$userId)
                ->where('year',$year)->where('month',$month)->where('type','monthly_deduction')->lockForUpdate()->get();
            if ($existingGpf->isNotEmpty()) throw new DomainException('Monthly GPF ledger already contains a contribution; reconcile before generating bill.');
            // A back-dated contribution would change a later settled closing
            // balance. Do not silently rewrite later financial-year history.
            if (DB::table('general_provident_funds')->where('user_id',$userId)
                ->where(function($q)use($year,$month){$q->where('year','>',$year)
                    ->orWhere(fn($sub)=>$sub->where('year',$year)->where('month','>',$month));})
                ->exists()) {
                throw new DomainException('Later GPF ledger rows already exist. Back-dated salary generation requires audited balance reconciliation.');
            }

            $source=$this->preview($userId,$year,$month);
            SalaryBulkSourceGuard::assertMatches($expectedSourceHash, (string) $source['source_hash']);
            if ($source['arrear_payable']>0 || $source['arrear_deduction']>0) {
                // Do not double-post a retrospective period already disbursed by
                // the older Step 4/5 arrear snapshot workflow.
                $old=DB::table('salary_arrear_disbursements as d')
                    ->join('salary_arrear_periods as p','p.id','=','d.period_id')
                    ->where('d.user_id',$userId)->where('d.status','posted')
                    ->get(['p.entitlement_start','p.entitlement_end']);
                $periods=[];
                foreach(array_merge($source['arrear_additions'],$source['arrear_deductions']) as $component){
                    if ((float)$component['calculated_amount']<=0)continue;
                    foreach(($component['arrear_entitlement_months'] ?: [sprintf('%04d-%02d',$year,$month)]) as $period){
                        $periods[$period]=true;
                    }
                }
                foreach($old as $posted){
                    $from=substr((string)$posted->entitlement_start,0,7);
                    $to=substr((string)$posted->entitlement_end,0,7);
                    foreach(array_keys($periods) as $period){
                        if($period>=$from && $period<=$to){
                            throw new DomainException('An earlier legacy arrear disbursement already covers '.$period.'. Reconcile before Phase 5 posting.');
                        }
                    }
                }
            }

            $date=CarbonImmutable::create($year,$month,1)->endOfMonth()->toDateString();
            $regular=$source['regular'];
            $additions=array_merge($source['regular_additions'],$source['arrear_additions']);
            $deductions=array_merge($source['regular_deductions'],$source['arrear_deductions']);
            $user->load(['age','joiningDate','phoneNumbers']);
            $data=[
                'user_id'=>$userId,'reviewStatus'=>'pending', 'name'=>$user->name,
                'age'=>(string)($user->age?->age??'N/A'), 'gender'=>(string)($user->gender??'N/A'),
                'payScale'=>$source['pay_scale'],'grade'=>(string)$source['grade'],
                'designation'=>(string)$source['designation'], 'employeeCode'=>(string)$source['employee_code'],
                'bankAccountNo'=>(string)$source['bank_account_no'],'bankAddress'=>(string)$source['bank_address'],
                'employeePhoneNumber'=>(string)($user->phoneNumbers->first()?->phoneNumber??'N/A'),
                'joiningDate'=>$user->joiningDate?->joining_date??$date,
                'salaryGenerationDate'=>$date,'monthId'=>$month,'salaryGenerationYear'=>$year,
                'salaryAllowanceCalcObject'=>$additions,'salaryDeductionCalcObject'=>$deductions,
                'totalAllowance'=>$source['gross'],'totalDeduction'=>$source['total_deduction'],
                'taxDeduction'=>$regular['tax_deduction'], 'gpfDeduction'=>$regular['gpf_deduction'],
                'salaryTotal'=>$source['net_payable'],
                'bonusAllowanceCalcObject'=>[],'bonusDeductionCalcObject'=>[],
                'bonusAllowance'=>0,'bonusDeduction'=>0,'bonusTaxDeduction'=>0,'bonusTotal'=>0,
                'loanInstallmentDeduction'=>0,'loanInstallmentCount'=>null,'loanInstallmentRemaining'=>null,
            ];
            $bill=SalaryGenerate::create($data);
            // Unique user/month and bill_id constraints prevent duplicate source posting.
            $linkId=DB::table('salary_bill_source_links')->insertGetId([
                'salary_generate_id'=>$bill->id,'user_id'=>$userId,'salary_year'=>$year,'salary_month'=>$month,
                'salary_calculation_version_id'=>$source['calculation_version_id'],
                'fixation_id'=>$source['fixation_id'],'bank_account_id'=>$source['bank_account_id'],
                'source_hash'=>$source['source_hash'],
                'regular_net'=>$regular['net_salary'],'arrear_payable'=>$source['arrear_payable'],
                'arrear_deduction'=>$source['arrear_deduction'],'net_payable'=>$source['net_payable'],
                'source_snapshot'=>json_encode($source,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                'created_by'=>$actorId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            if ((float)$regular['gpf_deduction']>0) {
                $entry=app(GeneralProvidentFundService::class)->recordSalaryGpfContribution($user,$bill,
                    (float)$regular['gpf_deduction'],$month,$year,$date);
                if (!$entry || (int)$entry->salary_generate_id !== (int)$bill->id) {
                    throw new DomainException('Regular GPF ledger contribution was not linked to the salary bill.');
                }
            }
            // One logical entitlement component/period may be posted only once,
            // even if a later month repeats the same published rule. Store the
            // aggregate row amount, NOT an invented allocation across months.
            foreach (array_merge($source['arrear_additions'],$source['arrear_deductions']) as $item) {
                if (abs((float)$item['calculated_amount'])<0.005) continue;
                $periods=$item['arrear_entitlement_months'] ?: [sprintf('%04d-%02d',$year,$month)];
                foreach(array_unique($periods) as $entitlementMonth) {
                    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',(string)$entitlementMonth)) {
                        throw new DomainException('Arrear entitlement period is invalid.');
                    }
                    $fingerprint=hash('sha256',json_encode([
                        'user_id'=>$userId,'rule_id'=>$item['arrear_rule_id'],
                        'title'=>$item['name'],'direction'=>$item['component_type'],
                        'entitlement_period'=>$entitlementMonth,
                        'evidence'=>$item['arrear_source_reference'],
                    ], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
                    if (DB::table('salary_bill_arrear_entitlements')
                        ->where('entitlement_fingerprint',$fingerprint)->exists()) {
                        throw new DomainException('This arrear component/period has already been posted; duplicate recovery prevented.');
                    }
                    if (!empty($item['arrear_rule_id']) && DB::table('salary_bill_arrear_entitlements')
                        ->where('user_id',$userId)->where('rule_id',(int)$item['arrear_rule_id'])
                        ->where('entitlement_month',$entitlementMonth)->exists()) {
                        throw new DomainException('Published arrear rule was already paid or recovered for '.$entitlementMonth.'.');
                    }
                    DB::table('salary_bill_arrear_entitlements')->insert([
                        'salary_bill_source_link_id'=>$linkId,'user_id'=>$userId,
                        'rule_id'=>$item['arrear_rule_id'],'entitlement_month'=>$entitlementMonth,
                        'title'=>$item['name'],'direction'=>$item['component_type'],
                        'aggregate_row_amount'=>(float)$item['calculated_amount'],
                        'entitlement_fingerprint'=>$fingerprint,'created_at'=>now(),'updated_at'=>now(),
                    ]);
                }
            }
            foreach ($source['arrear_deductions'] as $row) {
                if (($row['arrear_code']??null)!=='ADDITIONAL_GPF' || (float)$row['calculated_amount']===0.0) continue;
                // Separate immutable GPF journal. Do not invent allocation across
                // historical months or rewrite existing GPF closing balances.
                DB::table('salary_bill_arrear_gpf_entries')->insert([
                    'salary_bill_source_link_id'=>$linkId,'salary_generate_id'=>$bill->id,
                    'user_id'=>$userId,'pay_year'=>$year,'pay_month'=>$month,
                    'entitlement_months'=>json_encode($row['arrear_entitlement_months']??[],JSON_THROW_ON_ERROR),
                    'signed_amount'=>(float)$row['calculated_amount'],
                    'source_hash'=>hash('sha256',json_encode($row,JSON_THROW_ON_ERROR)),
                    'evidence_reference'=>$row['arrear_source_reference']??null,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            $sumAdd=round(array_sum(array_map(fn($r)=>(float)$r['calculated_amount'],$additions)),2);
            $sumDed=round(array_sum(array_map(fn($r)=>(float)$r['calculated_amount'],$deductions)),2);
            if (abs($sumAdd-(float)$bill->totalAllowance)>0.009 || abs($sumDed-(float)$bill->totalDeduction)>0.009
                || abs(($sumAdd-$sumDed)-(float)$bill->salaryTotal)>0.009) {
                throw new DomainException('Saved bill item totals do not reconcile to Net Payable.');
            }
            return ['result'=>'generated','bill_id'=>$bill->id,'source_link_id'=>$linkId,
                'source_hash'=>$source['source_hash'],'net_salary'=>$source['net_payable']];
        },3);
    }
}
