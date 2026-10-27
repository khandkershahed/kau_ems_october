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
        Schema::create('bonus_generates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bonus_group_id')->nullable()->constrained('bonus_groups')->nullOnDelete();
            $table->enum('reviewStatus', ['approved', 'pending', 'review'])->default('pending');

            // Employee snapshot information
            $table->string('name');
            $table->string('age')->nullable();
            $table->string('gender')->nullable();
            $table->string('payScale')->nullable();
            $table->string('grade')->nullable();
            $table->string('designation')->nullable();
            $table->string('employeeCode')->nullable();
            $table->string('bankAccountNo')->nullable();
            $table->string('bankAddress')->nullable();
            $table->string('employeePhoneNumber')->nullable();
            $table->date('joiningDate')->nullable();
            $table->string('religion')->nullable();

            // Bonus date and period details
            $table->date('bonusGenerationDate');
            $table->enum('monthId', [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]);
            $table->year('bonusGenerationYear');
            $table->string('bonus_title')->nullable();

            // Calculation objects and particulars
            $table->json('bonusAllowanceCalcObject')->nullable();
            $table->json('bonusDeductionCalcObject')->nullable();

            // Financial totals
            $table->decimal('totalAllowance', 10, 2)->default(0.00);
            $table->decimal('totalDeduction', 10, 2)->default(0.00);
            $table->decimal('taxDeduction', 10, 2)->default(0.00);
            $table->decimal('netBonus', 10, 2)->default(0.00);

            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bonus_generates');
    }
};
