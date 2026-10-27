<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Financial writes are tested ONLY within a rollback transaction on an isolated MySQL clone. */
final class SalaryEmployeeComponentOverrideStep03FeatureTest extends TestCase
{
    private function requireIsolated(): void
    {
        $database = strtolower((string) config('database.connections.mysql.database'));
        if (config('database.default') !== 'mysql' || !preg_match('/^kau_.*(?:test|staging)$/', $database)
            || app()->environment('production')) {
            $this->markTestSkipped('An isolated kau_*_test/staging MySQL clone is required.');
        }
        if (!Schema::hasTable('salary_component_employee_overrides') || !Schema::hasTable('salary_component_override_events')) {
            $this->markTestSkipped('Apply Step 03 migration on the disposable MySQL clone.');
        }
    }

    public function test_non_payroll_employee_cannot_write_override(): void
    {
        $this->requireIsolated();
        $actor=User::query()->whereHas('roles',fn($q)=>$q->where('role_name','user'))
            ->whereDoesntHave('roles',fn($q)=>$q->whereIn('role_name',['superadmin','payroll_hr']))
            ->where('isActive',true)->first();
        if (!$actor) $this->markTestSkipped('No ordinary active employee identity.');
        $this->actingAs($actor)->postJson(route('salary-calculator.component-override',['user'=>$actor->id]),[
            'year'=>2026,'month'=>10,'component_name'=>'Temporary Adjustment','direction'=>'addition',
            'custom'=>true,'mode'=>'manual','calc_type'=>'fixed','value'=>100,'basis'=>'none','expected_revision'=>0,
        ])->assertStatus(403);
    }

    public function test_monthly_override_is_isolated_audited_and_no_salary_or_gpf_is_posted(): void
    {
        $this->requireIsolated();
        $actor=User::query()->whereHas('roles',fn($q)=>$q->where('role_name','superadmin'))
            ->where('isActive',true)->first();
        $employee=User::query()->whereHas('employeeInfo')->first();
        if (!$actor || !$employee) $this->markTestSkipped('No superadmin or employee test fixture.');
        DB::beginTransaction();
        try {
            $beforeBills=DB::table('salary_generates')->count();
            $beforeGpf=DB::table('general_provident_funds')->count();
            $payload=[
                'year'=>2026,'month'=>10,'component_name'=>'Step03 QA Temporary Allowance',
                'direction'=>'addition','custom'=>true,'mode'=>'manual',
                'calc_type'=>'fixed','value'=>125,'basis'=>'none','expected_revision'=>0,
            ];
            // If an identical test fixture was committed previously, skip rather than rewrite real data.
            $key=\App\Services\SalaryEmployeeComponentOverrideService::key(null,$payload['component_name'],true);
            if (DB::table('salary_component_employee_overrides')->where('user_id',$employee->id)
                ->where('salary_year',2026)->where('salary_month',10)->where('direction','addition')
                ->where('component_key',$key)->exists()) {
                $this->markTestSkipped('Matching override fixture already exists.');
            }
            $this->actingAs($actor)->postJson(route('salary-calculator.component-override',['user'=>$employee->id]),$payload)
                ->assertOk();
            $this->assertSame(1,DB::table('salary_component_employee_overrides')->where('user_id',$employee->id)
                ->where('salary_year',2026)->where('salary_month',10)->where('component_key',$key)->count());
            $this->assertSame(0,DB::table('salary_component_employee_overrides')->where('user_id',$employee->id)
                ->where('salary_year',2026)->where('salary_month',11)->where('component_key',$key)->count());
            $this->actingAs($actor)->postJson(route('salary-calculator.component-override',['user'=>$employee->id]),$payload)
                ->assertStatus(409);
            $this->assertSame($beforeBills,DB::table('salary_generates')->count());
            $this->assertSame($beforeGpf,DB::table('general_provident_funds')->count());
        } finally { DB::rollBack(); }
    }
}
