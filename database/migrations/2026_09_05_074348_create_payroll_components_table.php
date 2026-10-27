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
        Schema::create('payroll_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId("payroll_group_id")->constrained();
            $table->foreignId("payroll_component_type_id")->constrained();
            $table->enum("component_type", ["allowance", "deduction", "tax"])->default("allowance");
            $table->enum("calcType", ["fixed", "percentage"])->default("fixed");
            $table->boolean("is_taxable")->default(false);
            $table->boolean("isActive")->default(false);
            $table->decimal("amount")->default(0.00);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_components');
    }
};
