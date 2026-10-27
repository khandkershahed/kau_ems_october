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
        Schema::create('salary_generates', function (Blueprint $table) {
            $table->id();
            $table->foreignId("user_id")->constrained();
            $table->enum("reviewStatus", ["approved", "pending", "review"])->default("pending"); 
            // employee info
            $table->string("name");
            $table->string("age");
            $table->string("gender");
            $table->string("payScale");
            $table->string("grade");
            $table->string("designation");
            $table->string("employeeCode");
            $table->string("bankAccountNo");
            $table->string("bankAddress");
            $table->string("employeePhoneNumber");
            $table->date("joiningDate");

            // Salary date related
            $table->date("salaryGenerationDate"); 
            $table->enum("monthId", [1,2,3,4,5,6,7,8,9,10,11,12]);
            $table->year("salaryGenerationYear");

            // Calculation data that used for salary
            $table->json("salaryAllowanceCalcObject"); 
            $table->json("salaryDeductionCalcObject"); 

            // for salary
            $table->decimal("totalAllowance", 10, 2); 
            $table->decimal("totalDeduction", 10, 2); 
            // for tax and GPF
            $table->decimal("taxDeduction", 10, 2);
            $table->decimal("gpfDeduction", 10, 2);

            $table->decimal("salaryTotal", 10, 2);


            // Calculation data that used for salary
            $table->json("bonusAllowanceCalcObject"); 
            $table->json("bonusDeductionCalcObject"); 

            // for bonus
            $table->decimal("bonusAllowance", 10, 2); 
            $table->decimal("bonusDeduction", 10, 2); 
            $table->decimal("bonusTaxDeduction", 10, 2); 
            $table->decimal("bonusTotal", 10, 2); 

            // For loan if any
            $table->decimal("loanInstallmentDeduction", 10, 2)->nullable();
            $table->integer("loanInstallmentCount")->nullable();
            $table->integer("loanInstallmentRemaining")->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salary_generates');
    }
};
