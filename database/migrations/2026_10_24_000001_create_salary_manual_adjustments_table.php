<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('salary_manual_adjustments')) {
            Schema::create('salary_manual_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users');
                $table->unsignedSmallInteger('salary_year');
                $table->unsignedTinyInteger('salary_month');
                $table->string('direction', 16);
                $table->string('title', 120);
                $table->decimal('amount', 14, 2);
                $table->text('reason');
                $table->foreignId('created_by')->nullable()->constrained('users');
                $table->timestamps();
                $table->index(['user_id', 'salary_year', 'salary_month'], 'salary_manual_employee_period_idx');
            });
        }
    }

    public function down(): void
    {
        // Never drop payroll adjustments automatically when migration history
        // may be out of sync with the actual MySQL schema.
        throw new \RuntimeException('Review salary_manual_adjustments and export its rows before rollback.');
    }
};
