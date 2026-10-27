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
        Schema::create('bonus_components', function (Blueprint $table) {
            $table->id();
            $table->boolean("fromBasic")->default(true); 
            $table->boolean("isTaxable")->default(false); 
            $table->enum("componentType", ["allowance", "deduction"]); 
            $table->enum("calcType", ["fixed", "percentage"]); 
            $table->decimal("amount", 8, 2); 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bonus_components');
    }
};
