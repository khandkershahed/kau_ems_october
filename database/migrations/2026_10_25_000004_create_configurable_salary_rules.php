<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * MySQL schema changes are not guaranteed to roll back on a failed migration.
     * Phase 4 originally failed while adding an auto-generated index (>64 chars).
     * Therefore every step must also be safe to resume against partially-created
     * tables, without deleting rules, employee overrides, or audit history.
     */
    public function up(): void
    {
        if (!Schema::hasTable('salary_component_publications')) {
            Schema::create('salary_component_publications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('payroll_component_type_id')->unique();
                $table->string('direction', 16);
                $table->boolean('published')->default(false);
                $table->string('default_calc_type', 16)->default('fixed');
                $table->decimal('default_amount', 14, 2)->nullable();
                $table->unsignedInteger('revision')->default(1);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('salary_arrear_rules')) {
            Schema::create('salary_arrear_rules', function (Blueprint $table) {
                $table->id();
                $table->string('code', 60)->nullable();
                $table->string('title', 160);
                $table->string('direction', 16);
                $table->string('mode', 16)->default('fixed');
                $table->decimal('amount', 14, 2)->nullable();
                $table->string('formula', 400)->nullable();
                $table->date('start_month')->nullable();
                $table->date('end_month')->nullable();
                $table->json('selected_months')->nullable();
                $table->boolean('recurring')->default(false);
                $table->string('scope', 16)->default('all');
                $table->json('employee_ids')->nullable();
                $table->boolean('published')->default(false);
                $table->unsignedInteger('display_order')->default(0);
                $table->unsignedInteger('revision')->default(1);
                $table->boolean('archived')->default(false);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('salary_rule_events')) {
            Schema::create('salary_rule_events', function (Blueprint $table) {
                $table->id();
                $table->string('rule_kind', 24);
                $table->unsignedBigInteger('rule_id');
                $table->string('action', 32);
                $table->json('before_value')->nullable();
                $table->json('after_value')->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->timestamps();
                $table->index(['rule_kind', 'rule_id'], 'sar_event_kind_rule_idx');
            });
        }
        if (!Schema::hasIndex('salary_rule_events', 'sar_event_kind_rule_idx')
            && !Schema::hasIndex('salary_rule_events', 'salary_rule_events_rule_kind_rule_id_index')) {
            Schema::table('salary_rule_events', function (Blueprint $table) {
                $table->index(['rule_kind', 'rule_id'], 'sar_event_kind_rule_idx');
            });
        }

        // Seed only missing definitions. A prior failed run may already have
        // inserted these rows; never duplicate or overwrite HR-edited values.
        $defaults = [
            ['BASIC_ARREAR_GROSS', 'Bokeya Prapyo / Basic Arrear', 'payable', 'SUM_MONTHS(REVISED_BASIC - JUNE_BASIC)'],
            ['BASIC_INCREMENT_ADJUSTMENT', 'Basic Difference / Old Increment', 'deduction', 'SUM_MONTHS(PAID_BASIC - JUNE_BASIC)'],
            ['SPECIAL_BENEFIT_RECOVERY', 'Special Benefit Recovery', 'deduction', 'SUM_MONTHS(PAID_SPECIAL_BENEFIT)'],
            ['HOUSE_RENT_RECOVERY', 'House Rent Difference', 'deduction', 'SUM_MONTHS(MAX(0, PAID_HOUSE_RENT - JUNE_HOUSE_RENT))'],
            ['ADDITIONAL_GPF', 'Additional GPF', 'deduction', 'SUM_MONTHS(MAX(0, REVISED_GPF - PAID_GPF))'],
        ];
        foreach ($defaults as $index => [$code, $title, $direction, $formula]) {
            if (DB::table('salary_arrear_rules')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('salary_arrear_rules')->insert([
                'code' => $code, 'title' => $title, 'direction' => $direction,
                'mode' => 'formula', 'formula' => $formula,
                'start_month' => '2026-07-01', 'end_month' => '2026-09-01',
                'selected_months' => json_encode([]), 'scope' => 'all',
                'employee_ids' => json_encode([]), 'published' => false,
                'display_order' => $index + 1,
                'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // A failed MySQL index DDL can leave this whole table present with
        // none of its indexes. Use explicit <=64-character index names and
        // create any missing indexes without dropping existing data.
        if (!Schema::hasTable('salary_arrear_employee_overrides')) {
            Schema::create('salary_arrear_employee_overrides', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('salary_calculation_version_id');
                $table->unsignedBigInteger('user_id');
                $table->unsignedSmallInteger('salary_year');
                $table->unsignedTinyInteger('salary_month');
                $table->unsignedBigInteger('rule_id')->nullable();
                $table->unsignedSmallInteger('row_number');
                $table->string('title', 160);
                $table->string('direction', 16);
                $table->decimal('automatic_amount', 14, 2)->nullable();
                $table->decimal('final_amount', 14, 2)->nullable();
                $table->string('formula', 400)->nullable();
                $table->text('reason')->nullable();
                $table->string('evidence_reference', 500)->nullable();
                $table->char('source_hash', 64);
                $table->unsignedBigInteger('created_by');
                $table->timestamps();
                $table->index('salary_calculation_version_id', 'sar_override_calc_version_idx');
                $table->index('user_id', 'sar_override_user_idx');
                $table->unique(['salary_calculation_version_id', 'row_number'], 'sar_override_version_row_uq');
            });
        }
        if (!Schema::hasIndex('salary_arrear_employee_overrides', 'sar_override_calc_version_idx')) {
            Schema::table('salary_arrear_employee_overrides', function (Blueprint $table) {
                $table->index('salary_calculation_version_id', 'sar_override_calc_version_idx');
            });
        }
        if (!Schema::hasIndex('salary_arrear_employee_overrides', 'sar_override_user_idx')
            && !Schema::hasIndex('salary_arrear_employee_overrides', 'salary_arrear_employee_overrides_user_id_index')) {
            Schema::table('salary_arrear_employee_overrides', function (Blueprint $table) {
                $table->index('user_id', 'sar_override_user_idx');
            });
        }
        if (!Schema::hasIndex('salary_arrear_employee_overrides', 'sar_override_version_row_uq')) {
            Schema::table('salary_arrear_employee_overrides', function (Blueprint $table) {
                $table->unique(['salary_calculation_version_id', 'row_number'], 'sar_override_version_row_uq');
            });
        }

        // An interrupted earlier attempt may already have added one column.
        // Add only the missing column(s); do not rewrite calculation snapshots.
        if (!Schema::hasColumn('salary_calculation_versions', 'arrear_snapshot')) {
            Schema::table('salary_calculation_versions', function (Blueprint $table) {
                $table->json('arrear_snapshot')->nullable();
            });
        }
        if (!Schema::hasColumn('salary_calculation_versions', 'arrear_source_hash')) {
            Schema::table('salary_calculation_versions', function (Blueprint $table) {
                $table->char('arrear_source_hash', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Historical rule definitions and employee snapshots are auditable
        // financial records; their deletion requires an explicit review.
        throw new RuntimeException('Financial rule history and snapshots require an audited reversal migration.');
    }
};
