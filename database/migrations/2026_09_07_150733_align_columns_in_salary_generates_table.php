<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('salary_generates', function (Blueprint $table) {
            if (!Schema::hasColumn('salary_generates', 'reviewStatus')) {
                $table->enum('reviewStatus', ['approved', 'pending', 'review'])->default('pending')->after('user_id');
            }

            if (!Schema::hasColumn('salary_generates', 'salaryGenerationYear')) {
                if (Schema::hasColumn('salary_generates', 'year')) {
                    $table->renameColumn('year', 'salaryGenerationYear');
                } else {
                    $table->year('salaryGenerationYear')->after('monthId');
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salary_generates', function (Blueprint $table) {
            if (Schema::hasColumn('salary_generates', 'reviewStatus')) {
                $table->dropColumn('reviewStatus');
            }

            if (Schema::hasColumn('salary_generates', 'salaryGenerationYear') && !Schema::hasColumn('salary_generates', 'year')) {
                $table->renameColumn('salaryGenerationYear', 'year');
            }
        });
    }
};
