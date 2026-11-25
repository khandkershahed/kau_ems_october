<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('salary_monthly_component_adjustments')) {
            Schema::create('salary_monthly_component_adjustments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedSmallInteger('year');
                $table->unsignedTinyInteger('month');
                $table->unsignedBigInteger('payroll_component_type_id');
                $table->decimal('arrear_amount', 12, 2)->default(0.00);
                $table->boolean('rule_override')->default(false);
                $table->decimal('override_current_amount', 12, 2)->nullable();
                $table->text('comment')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->index('user_id', 'smca_user_id_index');
                $table->index('payroll_component_type_id', 'smca_component_type_id_index');
                $table->unique(
                    ['user_id', 'year', 'month', 'payroll_component_type_id'],
                    'smca_user_ym_component_unique'
                );
                $table->index(['year', 'month'], 'smca_year_month_index');
            });

            return;
        }

        $existing = collect(Schema::getIndexes('salary_monthly_component_adjustments'))
            ->pluck('name')
            ->all();

        Schema::table('salary_monthly_component_adjustments', function (Blueprint $table) use ($existing) {
            if (!in_array('smca_user_id_index', $existing, true)
                && !in_array('salary_monthly_component_adjustments_user_id_index', $existing, true)) {
                $table->index('user_id', 'smca_user_id_index');
            }
            if (!in_array('smca_component_type_id_index', $existing, true)) {
                $table->index('payroll_component_type_id', 'smca_component_type_id_index');
            }
            if (!in_array('smca_user_ym_component_unique', $existing, true)) {
                $table->unique(
                    ['user_id', 'year', 'month', 'payroll_component_type_id'],
                    'smca_user_ym_component_unique'
                );
            }
            if (!in_array('smca_year_month_index', $existing, true)) {
                $table->index(['year', 'month'], 'smca_year_month_index');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_monthly_component_adjustments');
    }
};
