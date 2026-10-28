<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('salary_arrear_rules', 'payroll_group_id')) {
            Schema::table('salary_arrear_rules', function (Blueprint $table) {
                $table->unsignedBigInteger('payroll_group_id')->nullable();
            });
        }
        if (!Schema::hasTable('salary_workflow_source_signatures')) {
            Schema::create('salary_workflow_source_signatures', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('salary_monthly_workflow_id')->unique('swss_workflow_uq');
                $table->char('calculation_source_hash', 64);
                $table->char('ready_source_hash', 64)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Payroll approval source evidence must not be silently destroyed; use verified database restore.');
    }
};
