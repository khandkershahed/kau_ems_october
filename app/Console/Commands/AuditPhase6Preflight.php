<?php

namespace App\Console\Commands;

use App\Models\EmployeeInfo;
use App\Support\SalaryArrearTemplateCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only preflight. It deliberately does not infer payment from the existence
 * of a salary bill; HR/bank discharge evidence is a separate requirement.
 */
final class AuditPhase6Preflight extends Command
{
    protected $signature = 'kau:audit-phase6-preflight {--year=2026} {--month=10}';
    protected $description = 'READ ONLY: template publication, historical payments, profile links, banking and bill posting preflight';

    public function handle(): int
    {
        $year = (int)$this->option('year');
        $month = (int)$this->option('month');
        if ($year < 2020 || $year > 2100 || $month < 1 || $month > 12) {
            $this->error('Invalid payroll month/year.'); return self::FAILURE;
        }
        foreach (['salary_arrear_rules','employee_infos','salary_generates','general_provident_funds','bank_accounts'] as $table) {
            if (!Schema::hasTable($table)) {
                $this->error('Missing schema: '.$table); return self::FAILURE;
            }
        }
        $prefix = storage_path('app/kau_phase6_'.sprintf('%04d_%02d',$year,$month));
        $csv=fopen($prefix.'_readiness_controls.csv','w');
        if (!$csv) { $this->error('Cannot write audit CSV: '.$prefix); return self::FAILURE; }
        fputcsv($csv,['control','value','classification','explanation']);
        $write=static function(string $name, int|string $value, string $status, string $note) use($csv): void {
            fputcsv($csv,[$name,$value,$status,$note]);
        };
        $rules = DB::table('salary_arrear_rules')->get(['id','code','published','archived']);
        $codes=$rules->pluck('code')->filter()->all();
        try { $missing = array_filter(SalaryArrearTemplateCatalog::plan($codes),fn($v)=>$v['action']==='INSERT_UNPUBLISHED'); }
        catch (\DomainException $e) { $write('duplicate_template_codes',1,'BLOCKED',$e->getMessage());$missing=[]; }
        $write('rule_templates_existing',$rules->count(),'INFO','Definitions in source database.');
        $write('rule_templates_missing',count($missing),'REVIEW','Run kau:seed-arrear-templates --apply only after verifying staging preview.');
        $published=$rules->where('published',1)->where('archived',0)->count();
        $write('rules_published',$published,$published?'REVIEW':'NOT_APPLIED','Unpublished templates never enter employee payable calculations.');
        $duplicateProfiles=DB::table('employee_infos')->select('user_id')
            ->groupBy('user_id')->havingRaw('COUNT(*) > 1')->get()->count();
        $write('duplicate_employee_user_links',$duplicateProfiles,$duplicateProfiles?'BLOCKED':'PASS','Requires HR identity reconciliation, never merge automatically.');
        $duplicateFlags=DB::table('bank_accounts')->select('user_id')
            ->where('isSalaryAccount',1)->groupBy('user_id')->havingRaw('COUNT(*) > 1')->get()->count();
        $write('users_with_multiple_salary_bank_flags',$duplicateFlags,$duplicateFlags?'REVIEW':'PASS','Review explicit employee bank link; flags alone are ambiguous.');
        $badAccounts=DB::table('bank_accounts')->whereRaw("account_no NOT REGEXP '^[0-9 ]{8,25}$'")->count();
        $write('bank_account_format_anomalies',$badAccounts,$badAccounts?'REVIEW':'PASS','Placeholder/non-numeric/implausible values. No bank details are exported.');
        $p=DB::table('employee_infos')->count();$write('employee_profiles',$p,'INFO','Profiles, not necessarily distinct employees.');
        foreach ([6,7,8,9,$month] as $m) {
            $cnt=DB::table('salary_generates')->where('salaryGenerationYear',$year)->where('monthId',$m)->count();
            $write('saved_salary_bills_'.sprintf('%04d_%02d',$year,$m),$cnt,$cnt?'SOURCE_EXISTS':'MISSING_RECORD',
                'Saved bill is NOT proof of actual bank payment.');
        }
        $currentCount=DB::table('salary_generates')->where('salaryGenerationYear',$year)->where('monthId',$month)->count();
        $write('current_period_saved_bill_count',$currentCount,'INFO','No writes are performed by this audit.');
        foreach (['salary_calculation_versions','salary_bill_source_links','salary_bill_arrear_entitlements','salary_bill_arrear_gpf_entries','salary_bulk_runs'] as $table) {
            $exists=Schema::hasTable($table);
            $write($table.'_row_count',$exists?DB::table($table)->count():'TABLE_MISSING',$exists?'INFO':'BLOCKED',
                'Zero rows mean Phase 5 posting has not been demonstrated by this database.');
        }
        $write('official_hr_and_payment_evidence','NOT_CERTIFIED','NO_GO','Required: employee approvals, original payment records, arrear policy and GPF/bank reconciliation.');
        fclose($csv);
        $this->info('READ ONLY preflight complete. No salary, arrear, bank or GPF rows changed.');
        $this->line($prefix.'_readiness_controls.csv');
        $this->warn('NO-GO until isolated posting/rollback tests, every employee and financial source have been certified.');
        return self::SUCCESS;
    }
}
