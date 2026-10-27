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
        Schema::create('religions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum("religion", [
                'Islam',
                'Christianity',
                'Hinduism',
                'Buddhism',
                'Sikhism',
                'Judaism',
                'Jainism',
                'Shinto',
                'Taoism',
                'Zoroastrianism',
                'Atheism',
                'Agnosticism',
                'Other'
            ]);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('religions');
    }
};
