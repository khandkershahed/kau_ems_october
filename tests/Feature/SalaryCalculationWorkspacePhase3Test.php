<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Run ONLY on an isolated kau_step*_test MySQL clone; no RefreshDatabase. */
final class SalaryCalculationWorkspacePhase3Test extends TestCase
{
    private function requireIsolated(): void
    {
        $database = strtolower((string) config('database.connections.mysql.database'));
        if (config('database.default') !== 'mysql'
            || !preg_match('/^kau_.*(?:test|staging)$/', $database)
            || app()->environment('production')) {
            $this->markTestSkipped('Requires isolated kau_*_test/staging MySQL clone.');
        }
        if (!Schema::hasTable('salary_calculation_versions')) {
            $this->markTestSkipped('Run Phase 3 migration on isolated clone first.');
        }
    }
    public function test_snapshot_draft_approval_does_not_create_bill_or_gpf(): void
    {
        $this->requireIsolated();
        $resolver = app(\App\Services\ApprovedFixationResolver::class);
        $user = User::query()->whereHas('employeeInfo')
            ->limit(30)->get()->first(function ($candidate) use ($resolver) {
                return \App\Models\EmployeeInfo::where('user_id', $candidate->id)->count() === 1
                    && $resolver->forUser($candidate->id, 2026, 10) === null;
            });
        if (!$user) $this->markTestSkipped('No unapproved unique-profile employee fixture.');
        DB::beginTransaction();
        try {
            $beforeBills = DB::table('salary_generates')->count();
            $beforeGpf = DB::table('general_provident_funds')->count();
            $base = [
                'year'=>2026,'month'=>10,'status'=>'draft','expected_version'=>0,
                'additions'=>[['name'=>'Basic Pay','calcType'=>'fixed','amount'=>28715]],
                'deductions'=>[],
            ];
            // Route can legitimately report 409 if cloned fixture already has a draft.
            $exists = DB::table('salary_calculation_versions')->where('user_id',$user->id)
                ->where('salary_year',2026)->where('salary_month',10)->max('version');
            $base['expected_version'] = (int) $exists;
            $this->actingAs($user)->postJson(route('salary-calculator.versions.store',['user'=>$user->id]), $base)
                ->assertStatus(200);
            $this->assertSame($beforeBills, DB::table('salary_generates')->count());
            $this->assertSame($beforeGpf, DB::table('general_provident_funds')->count());
        } finally { DB::rollBack(); }
    }
}
