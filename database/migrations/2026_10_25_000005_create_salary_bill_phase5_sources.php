<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('salary_bill_source_links')) {
            Schema::create('salary_bill_source_links',function(Blueprint $table){
                $table->id();
                $table->unsignedBigInteger('salary_generate_id')->unique('sb5_bill_uq');
                $table->unsignedBigInteger('user_id');
                $table->unsignedSmallInteger('salary_year');
                $table->unsignedTinyInteger('salary_month');
                $table->unsignedBigInteger('salary_calculation_version_id')->nullable();
                $table->unsignedBigInteger('fixation_id')->nullable();
                $table->unsignedBigInteger('bank_account_id');
                $table->char('source_hash',64);
                $table->decimal('regular_net',14,2);
                $table->decimal('arrear_payable',14,2);
                $table->decimal('arrear_deduction',14,2);
                $table->decimal('net_payable',14,2);
                $table->longText('source_snapshot');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->unique(['user_id','salary_year','salary_month'],'sb5_user_period_uq');
            });
        }
        if (!Schema::hasTable('salary_bill_arrear_gpf_entries')) {
            Schema::create('salary_bill_arrear_gpf_entries',function(Blueprint $table){
                $table->id();
                $table->unsignedBigInteger('salary_bill_source_link_id')->unique('sb5_supp_gpf_source_uq');
                $table->unsignedBigInteger('salary_generate_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedSmallInteger('pay_year');
                $table->unsignedTinyInteger('pay_month');
                $table->json('entitlement_months');
                $table->decimal('signed_amount',14,2);
                $table->char('source_hash',64);
                $table->string('evidence_reference',500)->nullable();
                $table->unsignedBigInteger('reverses_entry_id')->nullable();
                $table->timestamps();
                $table->index(['user_id','pay_year','pay_month'],'sb5_supp_gpf_user_period_idx');
            });
        }
        if (!Schema::hasTable('salary_bill_arrear_entitlements')) {
            Schema::create('salary_bill_arrear_entitlements',function(Blueprint $table){
                $table->id();
                $table->unsignedBigInteger('salary_bill_source_link_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('rule_id')->nullable();
                $table->string('entitlement_month',7);
                $table->string('title',160);
                $table->string('direction',16);
                $table->decimal('aggregate_row_amount',14,2);
                $table->char('entitlement_fingerprint',64)->unique('sb5_entitlement_fingerprint_uq');
                $table->timestamps();
                $table->unique(['user_id','rule_id','entitlement_month'],'sb5_entitlement_month_rule_uq');
            });
        }
        if (!Schema::hasTable('salary_bulk_runs')) {
            Schema::create('salary_bulk_runs',function(Blueprint $table){
                $table->id();
                $table->uuid('run_token')->unique('sb5_bulk_run_token_uq');
                $table->unsignedSmallInteger('salary_year');
                $table->unsignedTinyInteger('salary_month');
                $table->string('status',24)->default('prepared');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('salary_bulk_run_items')) {
            Schema::create('salary_bulk_run_items',function(Blueprint $table){
                $table->id();
                $table->unsignedBigInteger('salary_bulk_run_id');
                $table->unsignedBigInteger('user_id');
                $table->string('status',24)->default('queued');
                $table->unsignedBigInteger('salary_generate_id')->nullable();
                $table->decimal('net_payable',14,2)->nullable();
                $table->string('source_hash',64)->nullable();
                $table->text('message')->nullable();
                $table->timestamps();
                $table->unique(['salary_bulk_run_id','user_id'],'sb5_bulk_run_employee_uq');
                $table->index(['salary_bulk_run_id','status'],'sb5_bulk_run_status_idx');
            });
        }
    }
    public function down(): void
    {
        throw new RuntimeException('Salary bill and GPF source history requires an audited reversal, not schema rollback.');
    }
};
