<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('salary_fixation_adjustments', 'baseline_period')) {
            Schema::table('salary_fixation_adjustments', function (Blueprint $table) {
                $table->string('baseline_period', 7)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Do not erase employee-specific approved baseline evidence automatically.
        // A reversal requires a separately verified data migration.
        throw new \RuntimeException('Salary fixation baseline history must be reviewed before rollback.');
    }
};
