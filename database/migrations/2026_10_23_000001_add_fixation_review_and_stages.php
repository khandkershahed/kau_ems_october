<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('pay_scale_stages')) {
            Schema::create('pay_scale_stages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('grade_id')->constrained('grades')->cascadeOnDelete();
                $table->unsignedSmallInteger('stage_number');
                $table->decimal('basic_amount', 14, 2);
                $table->timestamps();
                $table->unique(['grade_id', 'stage_number']);
            });
        }
        if (!Schema::hasTable('salary_fixation_reference_reports')) {
            Schema::create('salary_fixation_reference_reports', function (Blueprint $table) {
                $table->id();
                $table->string('employee_code', 32)->index();
                $table->string('report_file', 255);
                $table->string('category', 80);
                $table->decimal('old_basic', 14, 2);
                $table->decimal('increment_2026', 14, 2);
                $table->decimal('phase_2026_07', 14, 2);
                $table->decimal('phase_2027_01', 14, 2);
                $table->decimal('phase_2027_07', 14, 2);
                $table->timestamps();
                $table->unique(['employee_code', 'report_file'],'sfr_reports_emp_file_unique');
            });
        }
        if (!Schema::hasTable('salary_fixation_adjustments')) {
            Schema::create('salary_fixation_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->string('increment_2026', 16)->default('automatic');
                $table->string('increment_2027', 16)->default('automatic');
                $table->decimal('old_basic_override', 14, 2)->nullable();
                $table->decimal('personal_pay_override', 14, 2)->nullable();
                $table->unsignedTinyInteger('grade_override')->nullable();
                $table->json('additional_fields')->nullable();
                $table->string('evidence_reference', 255)->nullable();
                $table->text('reason')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users');
                $table->timestamps();
            });
        }
        // Add only absent columns: a previous MySQL migration attempt may have
        // created some columns without recording migration completion.
        $missing = [];
        foreach (['prepared_data', 'employee_reviewed_by', 'sent_at', 'employee_responded_at', 'employee_note', 'approved_by', 'approved_at'] as $column) {
            if (!Schema::hasColumn('salary_fixations', $column)) {
                $missing[] = $column;
            }
        }
        if ($missing !== []) {
            Schema::table('salary_fixations', function (Blueprint $table) use ($missing) {
                if (in_array('prepared_data', $missing, true)) $table->json('prepared_data')->nullable();
                if (in_array('employee_reviewed_by', $missing, true)) $table->foreignId('employee_reviewed_by')->nullable()->constrained('users');
                if (in_array('sent_at', $missing, true)) $table->timestamp('sent_at')->nullable();
                if (in_array('employee_responded_at', $missing, true)) $table->timestamp('employee_responded_at')->nullable();
                if (in_array('employee_note', $missing, true)) $table->text('employee_note')->nullable();
                if (in_array('approved_by', $missing, true)) $table->foreignId('approved_by')->nullable()->constrained('users');
                if (in_array('approved_at', $missing, true)) $table->timestamp('approved_at')->nullable();
            });
        }
        if (!Schema::hasTable('salary_fixation_pay_schedules')) {
            Schema::create('salary_fixation_pay_schedules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('salary_fixation_id')->constrained('salary_fixations')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->date('effective_date');
                $table->decimal('payable_basic', 14, 2);
                $table->unsignedTinyInteger('grade_number');
                $table->timestamps();
                $table->unique(['user_id', 'effective_date']);
            });
        }
    }
    public function down(): void
    {
        // This compatibility migration may adopt pre-existing payroll tables.
        // Automatic rollback cannot distinguish adopted tables from new tables.
        // Refuse destructive rollback; use a separately reviewed data-preserving
        // reversal migration if rollback is ever required.
        throw new \RuntimeException('Unsafe rollback blocked: this migration may reuse pre-existing payroll tables and records.');
    }
};
