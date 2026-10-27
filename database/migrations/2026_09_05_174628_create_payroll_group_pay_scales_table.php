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
        Schema::create('pay_scale_payroll_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId("pay_scale_id")->constrained(); 
            $table->foreignId("payroll_group_id")->constrained(); 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pay_scale_payroll_group');
    }
};
