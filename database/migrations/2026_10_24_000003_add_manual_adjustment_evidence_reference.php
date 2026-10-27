<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('salary_manual_adjustments')
            && !Schema::hasColumn('salary_manual_adjustments', 'evidence_reference')) {
            Schema::table('salary_manual_adjustments', function (Blueprint $table) {
                $table->string('evidence_reference', 500)->nullable()->after('reason');
            });
        }
    }

    public function down(): void
    {
        // Evidence and manual payroll adjustments are financial audit records.
        // Do not remove this column automatically on rollback.
        throw new \RuntimeException('Export manual salary adjustment evidence and review before rollback.');
    }
};
