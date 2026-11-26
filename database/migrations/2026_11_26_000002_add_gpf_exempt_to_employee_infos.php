<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_infos', function (Blueprint $table) {
            $table->boolean('gpf_exempt')->default(false)->after('holdIncrement');
        });
    }

    public function down(): void
    {
        Schema::table('employee_infos', function (Blueprint $table) {
            $table->dropColumn('gpf_exempt');
        });
    }
};
