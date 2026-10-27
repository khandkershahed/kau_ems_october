<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('salary_component_employee_overrides')) {
            Schema::create('salary_component_employee_overrides', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users');
                $table->unsignedSmallInteger('salary_year');
                $table->unsignedTinyInteger('salary_month');
                $table->string('component_key', 100);
                $table->unsignedBigInteger('payroll_component_type_id')->nullable();
                $table->string('component_title', 160);
                $table->string('direction', 16);
                $table->string('mode', 16); // manual | disabled
                $table->string('calc_type', 16)->nullable(); // fixed | percentage
                $table->decimal('value', 14, 2)->nullable();
                $table->string('basis', 32)->nullable(); // payable_basic | legacy_basic
                $table->decimal('legacy_basic_reference', 14, 2)->nullable();
                $table->text('reason')->nullable();
                $table->string('evidence_reference', 500)->nullable();
                $table->unsignedBigInteger('changed_by');
                $table->unsignedInteger('revision')->default(1);
                $table->timestamps();
                $table->unique(['user_id', 'salary_year', 'salary_month', 'direction', 'component_key'], 'kau_emp_component_month_unique');
                $table->index(['user_id','salary_year','salary_month'], 'kau_emp_component_period_idx');
                $table->foreign('payroll_component_type_id', 'kau_emp_component_type_fk')->references('id')->on('payroll_component_types');
                $table->foreign('changed_by', 'kau_emp_component_actor_fk')->references('id')->on('users');
            });
        }
        if (!Schema::hasTable('salary_component_override_events')) {
            Schema::create('salary_component_override_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('override_id')->nullable();
                $table->unsignedBigInteger('user_id');
                $table->unsignedSmallInteger('salary_year');
                $table->unsignedTinyInteger('salary_month');
                $table->string('direction', 16);
                $table->string('component_key', 100);
                $table->string('action', 16);
                $table->json('before_state')->nullable();
                $table->json('after_state')->nullable();
                $table->unsignedBigInteger('actor_id');
                $table->timestamp('created_at')->useCurrent();
                $table->index(['user_id','salary_year','salary_month'], 'kau_component_event_period_idx');
            });
        }
        if (Schema::hasTable('salary_calculation_versions') && !Schema::hasColumn('salary_calculation_versions', 'component_override_hash')) {
            Schema::table('salary_calculation_versions', function (Blueprint $table): void {
                $table->char('component_override_hash', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Financial override history must be reversed through an approved data-preserving procedure.');
    }
};
