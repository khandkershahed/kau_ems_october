<?php

namespace Tests\Feature;

use App\Services\SalaryArrearRuleService;
use Database\Seeders\SalaryArrearTemplatesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** All DB-writing tests refuse production, remote hosts and non-disposable DBs. */
final class SalaryArrearPhase6SeederIsolationTest extends TestCase
{
    private function requireIsolated(): void
    {
        $name=(string)config('database.connections.mysql.database');
        $host=strtolower((string)config('database.connections.mysql.host'));
        if(config('database.default')!=='mysql'
            || !preg_match('/^kau_.*_(test|staging)$/',$name)
            || !in_array($host,['127.0.0.1','localhost','::1'],true)
            || !in_array(app()->environment(),['local','testing'],true)) {
            $this->markTestSkipped('Dedicated disposable local kau_*_test/staging MySQL clone is required.');
        }
        if(!Schema::hasTable('salary_arrear_rules') || !Schema::hasTable('salary_rule_events')) {
            $this->markTestSkipped('Apply Phase 4 financial migrations in test clone first.');
        }
    }

    public function test_repeated_seeding_preserves_existing_rules_and_financial_tables(): void
    {
        $this->requireIsolated();
        DB::beginTransaction();
        try {
            $before=DB::table('salary_arrear_rules')->orderBy('id')->get()->toJson();
            $billCount=DB::table('salary_generates')->count();
            $gpfCount=DB::table('general_provident_funds')->count();
            $seed=new SalaryArrearTemplatesSeeder();
            $seed->run();
            $first=DB::table('salary_arrear_rules')->orderBy('id')->get()->toJson();
            $seed->run();
            $second=DB::table('salary_arrear_rules')->orderBy('id')->get()->toJson();
            $this->assertSame($first,$second,'Repeated seeding must be idempotent.');
            if(DB::table('salary_arrear_rules')->count()>=5){
                $this->assertSame($before,$second,'Existing source rule definitions may not change.');
            }
            $this->assertSame($billCount,DB::table('salary_generates')->count());
            $this->assertSame($gpfCount,DB::table('general_provident_funds')->count());
        } finally { DB::rollBack(); }
    }

    public function test_unpublished_templates_are_visible_but_excluded_from_payable_rules(): void
    {
        $this->requireIsolated();
        $user=(int)DB::table('employee_infos')->whereNotNull('user_id')->value('user_id');
        if(!$user) $this->markTestSkipped('No employee available.');
        $service=app(SalaryArrearRuleService::class);
        $drafts=$service->unpublishedTemplates($user);
        $live=$service->published($user);
        $this->assertTrue(is_array($drafts));
        $this->assertTrue(is_array($live));
        foreach($drafts as $rule){
            $this->assertSame('UNPUBLISHED_NOT_PAYABLE',$rule['status']);
            $this->assertNotContains($rule['id'],array_map(fn($r)=>(int)$r->id,$live));
        }
    }
}
