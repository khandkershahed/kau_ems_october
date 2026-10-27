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
        Schema::create('employee_infos', function (Blueprint $table) {
            $table->id();

            // Foreign ID to relate with other db tables
            $table->foreignId('user_id')->constrained(); 
            $table->foreignId('department_office_id')->constrained(); // as the main department id - first from pivot will be main
            $table->foreignId("phone_number_id")->constrained(); 
            $table->foreignId("address_id")->constrained(); 
            $table->foreignId("bank_account_id")->constrained(); 
            $table->foreignId("joining_date_id")->constrained(); 
            $table->foreignId("nid_id")->constrained(); 
            $table->foreignId("bid_id")->constrained(); 
            $table->foreignId("t_i_n_s_id")->constrained();
            $table->foreignId("employee_attribute_id")->nullable()->constrained(); // Because the user may not hold any special attributes
            // Payroll related fields
            $table->foreignId("pay_scale_id")->constrained(); 
            $table->foreignId("payroll_group_id")->constrained(); 


             
            $table->integer("employeeCode")->unique(); 
            $table->enum("jobStatus", ["permanent", "contract", "temporary", "others"]);
            $table->date("incrementDate");
            $table->boolean("holdIncrement")->default(true);


            // For salary calculation formula

            $table->json("additionObjects")->nullable();
            $table->json("substractionObjects")->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_infos');
    }
};
