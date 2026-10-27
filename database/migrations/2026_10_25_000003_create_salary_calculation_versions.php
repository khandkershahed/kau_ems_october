<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('salary_calculation_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('employee_info_id');
            $table->unsignedSmallInteger('salary_year');
            $table->unsignedTinyInteger('salary_month');
            $table->unsignedInteger('version');
            $table->enum('status', ['draft','approved']);
            $table->unsignedBigInteger('fixation_id')->nullable();
            $table->string('source', 60);
            $table->json('additions');
            $table->json('deductions');
            $table->json('gpf_override')->nullable();
            $table->json('calculated_result');
            $table->json('baseline_result')->nullable();
            $table->text('reason')->nullable();
            $table->string('evidence_reference', 500)->nullable();
            $table->char('snapshot_hash', 64);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'salary_year', 'salary_month', 'version'], 'salary_calc_user_month_version_unique');
            $table->index(['user_id', 'salary_year', 'salary_month', 'id'], 'salary_calc_latest_lookup');
        });
    }
    public function down(): void
    {
        // Deliberately protect financial calculation history from migrate:rollback.
        throw new RuntimeException('Salary calculation audit history cannot be automatically dropped.');
    }
};
