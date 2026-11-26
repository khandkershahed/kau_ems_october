<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_infos', function (Blueprint $table) {
            $table->string('jobStatus', 64)->default('permanent')->change();
        });
    }

    public function down(): void
    {
        Schema::table('employee_infos', function (Blueprint $table) {
            $table->enum('jobStatus', ['permanent', 'contract', 'temporary', 'others'])->default('permanent')->change();
        });
    }
};
