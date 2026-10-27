<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('salary_component_effective_rules')) {
            return;
        }

        Schema::create('salary_component_effective_rules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('payroll_group_id');
            $table->unsignedBigInteger('payroll_component_type_id');
            $table->string('direction', 16);
            $table->string('calc_type', 16);
            $table->decimal('value', 14, 2)->nullable();
            $table->string('basis', 32)->nullable();
            $table->string('frequency', 16);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('published')->default(false);
            $table->string('evidence_reference', 500);
            $table->char('source_hash', 64);
            $table->unsignedBigInteger('published_by');
            $table->timestamps();

            $table->unique(
                ['payroll_group_id', 'payroll_component_type_id', 'effective_from'],
                'kau_eff_comp_group_type_from_uq'
            );
            $table->index(['payroll_group_id', 'effective_from'], 'kau_eff_comp_group_date_idx');
            $table->index(['payroll_component_type_id', 'published'], 'kau_eff_comp_type_pub_idx');
            $table->foreign('payroll_group_id', 'kau_eff_comp_group_fk')
                ->references('id')->on('payroll_groups');
            $table->foreign('payroll_component_type_id', 'kau_eff_comp_type_fk')
                ->references('id')->on('payroll_component_types');
        });
    }

    public function down(): void
    {
        // A financial policy table must not be deleted by an automatic rollback.
        throw new RuntimeException('Use an approved, audited reversal to retire salary component rules.');
    }
};
