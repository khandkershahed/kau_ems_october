<?php

namespace App\Services;

use App\Models\EmployeeInfo;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative, read-only employee/month bill source. No financial writes.
 * A draft calculation may be posted directly, but never trusted without
 * server-side recomputation, current fixation, rule and source verification.
 */
final class SalaryBillCompositionService
{
    private static function cents(mixed $value): int
    {
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new DomainException('Invalid monetary amount in salary source.');
        }
        return (int) round((float) $value * 100, 0, PHP_ROUND_HALF_UP);
    }

    /** @return array<string,mixed> */
    public function compose(int $userId, int $year, int $month): array
    {
        if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
            throw new DomainException('Invalid salary year or month.');
        }
        if (!Schema::hasTable('salary_calculation_versions') || !Schema::hasTable('salary_arrear_rules')) {
            throw new DomainException('Phase 3/4 financial migrations are required.');
        }
        $user = User::findOrFail($userId);
        if (!(bool) $user->isActive) throw new DomainException('Employee is inactive; reconcile employment status.');
        $profiles = EmployeeInfo::with(['payScale', 'payrollGroup.grade','payrollGroup.payrollGroupType'])
            ->where('user_id', $userId)->limit(2)->get();
        if ($profiles->count() !== 1) throw new DomainException('Exactly one employee payroll profile is required.');
        $profile = $profiles->first();
        $fixation = app(ApprovedFixationResolver::class)->forUser($userId, $year, $month);
        if ($fixation === null) {
            SalaryGenerationGuards::assertLegacyGradeScale(
                $profile->pay_scale_id ? (int)$profile->pay_scale_id : null,
                $profile->payrollGroup?->grade?->pay_scale_id ? (int)$profile->payrollGroup->grade->pay_scale_id : null,
                $profile->payScale?->scale_name
            );
        }
        $add = SalaryComponentNormalizer::rows($profile->additionObjects);
        $ded = SalaryComponentNormalizer::rows($profile->substractionObjects);
        if (Schema::hasTable('salary_manual_adjustments')) {
            foreach (DB::table('salary_manual_adjustments')->where('user_id',$userId)
                ->where('salary_year',$year)->where('salary_month',$month)->orderBy('id')->get() as $item) {
                $row=['name'=>$item->title,'calcType'=>'fixed','amount'=>(float)$item->amount,
                    'manual_adjustment_id'=>(int)$item->id, 'manual_reason'=>$item->reason,
                    'manual_evidence_reference'=>$item->evidence_reference];
                if ($item->direction === 'addition') $add[]=$row;
                else $ded[]=$row;
            }
        }
        [$add,$ded] = app(SalaryComponentCatalog::class)->forEmployee($profile,$add,$ded,$year,$month,$fixation);
        $gpfOverride = collect($ded)->contains(fn ($v)=>SalaryCalculationService::isGpf($v))
            ? null : ['type'=>'disabled','amount'=>0];
        $saved = DB::table('salary_calculation_versions')->where('user_id',$userId)
            ->where('salary_year',$year)->where('salary_month',$month)->orderByDesc('version')->first();
        $savedArrear = null;
        $currentOverrideHash = app(SalaryEmployeeComponentOverrideService::class)->signature($userId,$year,$month);
        if ($saved && (app(SalaryEmployeeComponentOverrideService::class)->rows($userId,$year,$month) || ($saved->component_override_hash ?? null) !== null)
            && !hash_equals($currentOverrideHash, (string) ($saved->component_override_hash ?? ''))) {
            throw new DomainException('Employee component overrides changed since the saved calculation. Reopen and save a new version before posting.');
        }
        if ($saved) {
            if ((int)($saved->fixation_id ?? 0) !== (int)($fixation['salary_fixation_id'] ?? 0)) {
                throw new DomainException('Saved calculation uses a superseded/rejected fixation; reopen workspace and save a current version.');
            }
            $savedAdds=json_decode((string)$saved->additions,true);
            $savedDeds=json_decode((string)$saved->deductions,true);
            $savedCalc=json_decode((string)$saved->calculated_result,true);
            $savedArrear=json_decode((string)$saved->arrear_snapshot,true);
            $savedBase=json_decode((string)$saved->baseline_result,true);
            $savedGpf=$saved->gpf_override ? json_decode($saved->gpf_override,true) : null;
            if (!is_array($savedAdds)||!is_array($savedDeds)||!is_array($savedCalc)||!is_array($savedArrear)||!is_array($savedBase)) {
                throw new DomainException('Saved workspace calculation source is malformed.');
            }
            $storedHash=hash('sha256',json_encode([
                'user_id'=>$userId,'year'=>$year,'month'=>$month,
                'fixation_id'=>(int)($fixation['salary_fixation_id']??0),
                'additions'=>$savedAdds,'deductions'=>$savedDeds,
                'result'=>$savedCalc,'arrear'=>$savedArrear,
            ], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            if (!hash_equals((string)$saved->snapshot_hash,$storedHash)) {
                throw new DomainException('Saved calculation snapshot hash mismatch; cannot post a modified source.');
            }
            $add=$savedAdds; $ded=$savedDeds; $gpfOverride=$savedGpf;
        }
        if (!$gpfOverride && !collect($ded)->contains(fn($v)=>SalaryCalculationService::isGpf($v))) {
            $gpfOverride=['type'=>'disabled','amount'=>0];
        }
        $regular=app(SalaryCalculationService::class)->calculate($add,$ded,$fixation,$gpfOverride);
        if ($saved) {
            foreach (['basic','total_allowance','total_deduction','gpf_deduction','net_salary'] as $field) {
                if (self::cents($regular[$field]) !== self::cents($savedCalc[$field] ?? null)) {
                    throw new DomainException('Saved workspace '.$field.' no longer matches authoritative salary calculation.');
                }
            }
        }
        $live=app(SalaryArrearRuleService::class)->preview($userId,$year,$month);
        $liveById=collect($live)->keyBy('rule_id');
        $finalRows=[];
        if ($saved) {
            $used=[];
            foreach (($savedArrear['rows']??[]) as $row) {
                $ruleId=(int)($row['rule_id']??0);
                if ($ruleId) {
                    if (isset($used[$ruleId])) throw new DomainException('Repeated arrear rule within saved snapshot.');
                    $used[$ruleId]=true;
                    $current=$liveById->get($ruleId);
                    if (!$current) throw new DomainException('A saved arrear rule was unpublished or removed. Save a new workspace version.');
                    foreach (['title','direction','mode','formula'] as $key) {
                        if ((string)($row[$key]??'') !== (string)($current[$key]??'')) {
                            throw new DomainException('Published arrear rule changed: '.$current['title'].'. Recalculate and save.');
                        }
                    }
                    if (array_values($row['months']??[]) !== array_values($current['months']??[])
                        || ($row['calculated_amount']===null) !== ($current['calculated_amount']===null)
                        || ($row['calculated_amount']!==null && self::cents($row['calculated_amount']) !== self::cents($current['calculated_amount']))) {
                        throw new DomainException('Historical arrear sources or amounts changed: '.$current['title'].'. Reconcile before posting.');
                    }
                    $row['code']=$current['code'];
                } else {
                    $row['code']=null;
                }
                $finalRows[]=$row;
            }
            if (count($used) !== count($live)) {
                throw new DomainException('Published arrear rule set changed since calculation was saved; reopen workspace.');
            }
            $sourceVersion=(int)$saved->id;
        } else {
            foreach ($live as $r) {
                $finalRows[]=[
                    'rule_id'=>$r['rule_id'],'code'=>$r['code'],'title'=>$r['title'],
                    'direction'=>$r['direction'],'mode'=>$r['mode'],'formula'=>$r['formula'],
                    'months'=>$r['months']??[],'calculated_amount'=>$r['calculated_amount'],
                    'final_amount'=>$r['calculated_amount'], 'reason'=>null, 'evidence_reference'=>null,
                ];
            }
            $sourceVersion=null;
        }
        $arrearAdds=[]; $arrearDeds=[];
        $a=0; $d=0; $arrearGpf=0;
        foreach ($finalRows as $row) {
            if (!in_array($row['direction']??null,['payable','deduction'],true)) throw new DomainException('Invalid arrear direction.');
            if (!is_numeric($row['final_amount']??null) || (float)$row['final_amount']<0) {
                throw new DomainException('Arrear source UNKNOWN or invalid: '.($row['title']??'unnamed').'.');
            }
            $old=$row['calculated_amount']??null;
            $override=$old===null || self::cents($old)!==self::cents($row['final_amount']);
            if ($override && (!trim((string)($row['reason']??'')) || !trim((string)($row['evidence_reference']??'')))) {
                throw new DomainException('Arrear override requires evidence and reason: '.$row['title']);
            }
            $amount=self::cents($row['final_amount'])/100;
            // A legacy arrear posting source cannot be silently re-posted via Phase 5.
            if (($row['code']??null)==='BASIC_INCREMENT_ADJUSTMENT') {
                foreach ($finalRows as $candidate) {
                    if (($candidate['direction']??null)==='payable' && preg_match('/REVISED_BASIC\s*-\s*PAID_BASIC/i',(string)($candidate['formula']??''))) {
                        throw new DomainException('Old increment adjustment already included in actual-paid Basic difference.');
                    }
                }
            }
            $item=['name'=>$row['title'],'calcType'=>'fixed','amount'=>$amount,
                'calculated_amount'=>$amount, 'component_type'=>$row['direction']==='payable'?'allowance':'deduction',
                'arrear_phase5'=>true,'arrear_rule_id'=>$row['rule_id']??null,
                'arrear_code'=>$row['code']??null,'arrear_entitlement_months'=>$row['months']??[],
                'arrear_source_reference'=>$row['evidence_reference']??null,
                'arrear_calculated_amount'=>$old,'arrear_reason'=>$row['reason']??null];
            if ($row['direction']==='payable') { $a+=self::cents($amount); $arrearAdds[]=$item; }
            else { $d+=self::cents($amount); $arrearDeds[]=$item; }
            if (($row['code']??null)==='ADDITIONAL_GPF') {
                if ($row['direction']!=='deduction') throw new DomainException('Additional GPF must be a deduction.');
                $arrearGpf+=self::cents($amount);
            }
        }
        $gross=self::cents($regular['total_allowance'])+$a;
        $deduction=self::cents($regular['total_deduction'])+$d;
        if ($gross<$deduction) throw new DomainException('Total salary deductions exceed gross including arrears.');
        $bankResult=SalaryBankAccountResolver::resolve($userId,
            $profile->bank_account_id? (int)$profile->bank_account_id:null,
            $user->bankAccounts()->get()->all());
        if ($bankResult['errors']) throw new DomainException('Bank account: '.implode(', ',$bankResult['errors']));
        if (!$profile->bank_account_id || (int)$bankResult['selected_id'] !== (int)$profile->bank_account_id) {
            throw new DomainException('Explicit verified employee bank-account link is required before bill generation; no fallback bank account is authorized.');
        }
        $bank=$user->bankAccounts()->find($bankResult['selected_id']);
        if (!$bank || !trim((string)$bank->account_no)) throw new DomainException('Verified bank account number is unavailable.');
        $result=[
            'user_id'=>$userId,'year'=>$year,'month'=>$month,'employee_info_id'=>$profile->id,
            'employee_code'=>$profile->employeeCode,'name'=>$user->name,
            'pay_scale'=>$fixation ? 'Govt. Pay Scale 2026' : ($profile->payScale?->scale_name??'N/A'),
            'grade'=>$fixation['grade']??($profile->payrollGroup?->grade?->grade_name??'N/A'),
            'designation'=>$profile->payrollGroup?->payrollGroupType?->group_name??'N/A',
            'fixation_id'=>$fixation['salary_fixation_id']??null,'calculation_version_id'=>$sourceVersion,
            'bank_account_id'=>(int)$bank->id,'bank_account_no'=>$bank->account_no,
            'bank_address'=>$bank->address??($bank->bank_name??'N/A'),
            'regular'=>$regular,'arrear_rows'=>$finalRows,'regular_additions'=>$regular['allowances'],
            'regular_deductions'=>$regular['deductions'],'arrear_additions'=>$arrearAdds,
            'arrear_deductions'=>$arrearDeds,'arrear_payable'=>$a/100,'arrear_deduction'=>$d/100,
            'arrear_gpf'=>$arrearGpf/100,'gross'=>$gross/100,'total_deduction'=>$deduction/100,
            'net_payable'=>($gross-$deduction)/100,
        ];
        $result['source_hash']=hash('sha256',json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        // Statement is derived AFTER source_hash: no change to posting identity.
        $result['statement'] = SalaryStatementBreakdown::build($regular, $finalRows);
        return $result;
    }
}
