<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('salary_monthly_workflows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedSmallInteger('salary_year');
            $table->unsignedTinyInteger('salary_month');
            $table->string('workflow_status', 32)->default('BLOCKED');
            $table->unsignedBigInteger('active_calculation_version_id')->nullable();
            $table->unsignedBigInteger('ready_calculation_version_id')->nullable();
            $table->unsignedBigInteger('posted_salary_generate_id')->nullable();
            $table->char('ready_snapshot_hash', 64)->nullable();
            $table->json('blocking_reasons')->nullable();
            $table->text('posting_error')->nullable();
            $table->unsignedBigInteger('calculation_approved_by')->nullable();
            $table->timestamp('calculation_approved_at')->nullable();
            $table->unsignedBigInteger('ready_approved_by')->nullable();
            $table->timestamp('ready_approved_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['user_id', 'salary_year', 'salary_month'], 'salary_workflow_employee_period_unique');
            $table->index(['salary_year', 'salary_month', 'workflow_status'], 'salary_workflow_period_status_idx');
        });
        Schema::create('salary_workflow_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('salary_monthly_workflow_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedSmallInteger('salary_year');
            $table->unsignedTinyInteger('salary_month');
            $table->string('action', 48);
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('reason')->nullable();
            $table->char('before_hash', 64)->nullable();
            $table->char('after_hash', 64)->nullable();
            $table->unsignedInteger('revision');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['salary_monthly_workflow_id', 'id'], 'salary_workflow_event_lookup');
        });
    }

    public function down(): void
    {
        // Financial approval history must not be silently destroyed by rollback.
        throw new RuntimeException('Payroll workflow history cannot be automatically dropped. Restore a verified database backup for rollback.');
    }
};
