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
        Schema::table('employee_infos', function (Blueprint $table) {
            $table->foreignId('bonus_group_id')->nullable()->after('payroll_group_id')->constrained('bonus_groups')->nullOnDelete();
            $table->json('bonusAdditionObjects')->nullable()->after('substractionObjects');
            $table->json('bonusSubstractionObjects')->nullable()->after('bonusAdditionObjects');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_infos', function (Blueprint $table) {
            $table->dropForeign(['bonus_group_id']);
            $table->dropColumn(['bonus_group_id', 'bonusAdditionObjects', 'bonusSubstractionObjects']);
        });
    }
};
