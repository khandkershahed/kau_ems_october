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
        Schema::create('general_provident_funds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('salary_generate_id')->nullable()->constrained('salary_generates')->nullOnDelete();
            $table->string("employeeId");
            $table->date("contributionDate");
            $table->date("generationDate")->nullable();
            $table->string("financialYear", 20);
            $table->unsignedTinyInteger("month");
            $table->unsignedSmallInteger("year");
            $table->decimal("openingBalance", 12, 2)->default(0.00);
            $table->decimal("contributionAmount", 12, 2)->default(0.00);
            $table->decimal("benefitPercentage", 5, 2)->default(0.00);
            $table->decimal("interestAmount", 12, 2)->default(0.00);
            $table->decimal("netDeposit", 12, 2)->default(0.00);
            $table->decimal("closingAmount", 12, 2)->default(0.00);
            $table->string("type", 30)->default("monthly_deduction");
            $table->boolean("isSettled")->default(false);
            $table->date("settlementDate")->nullable();
            $table->text("remarks")->nullable();
            $table->timestamps();

            $table->index(['employeeId', 'financialYear']);
            $table->index(['user_id', 'year', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('general_provident_funds');
    }
};
