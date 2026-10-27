<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Read-only integration preflight: never writes payroll data. */
final class SalaryArrearPostingSchemaTest extends TestCase
{
    public function test_posting_route_is_registered(): void
    {
        $this->assertTrue(Route::has('salary-arrears.post-approved'));
    }

    public function test_arrear_posting_tables_have_integrity_fields(): void
    {
        foreach (['salary_arrear_disbursements', 'salary_arrear_snapshots', 'salary_arrear_gpf_adjustments'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' is missing');
        }
        foreach (['snapshot_id', 'salary_generate_id', 'period_id', 'user_id', 'status'] as $column) {
            $this->assertTrue(Schema::hasColumn('salary_arrear_disbursements', $column), $column.' missing');
        }
        foreach (['snapshot_id', 'disbursement_id', 'entitlement_year', 'entitlement_month', 'signed_amount', 'source_hash'] as $column) {
            $this->assertTrue(Schema::hasColumn('salary_arrear_gpf_adjustments', $column), $column.' missing');
        }
    }
}
