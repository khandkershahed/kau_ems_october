<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('salary_arrear_gpf_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('salary_arrear_snapshots');
            $table->foreignId('disbursement_id')->constrained('salary_arrear_disbursements');
            $table->foreignId('salary_generate_id')->constrained('salary_generates');
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedSmallInteger('entitlement_year');
            $table->unsignedTinyInteger('entitlement_month');
            $table->decimal('signed_amount', 14, 2);
            $table->string('entry_type', 24)->default('arrear_adjustment');
            $table->foreignId('reverses_entry_id')->nullable();
            $table->string('source_hash', 64);
            $table->string('evidence_reference', 500);
            $table->timestamps();
            $table->unique(['snapshot_id','entitlement_year','entitlement_month'], 'salary_arrear_gpf_one_adjustment_per_month');
            $table->index(['user_id','entitlement_year','entitlement_month'], 'salary_arrear_gpf_employee_period_idx');
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Financial GPF adjustment history cannot be silently rolled back. Use an audited reversal migration.');
    }
};
