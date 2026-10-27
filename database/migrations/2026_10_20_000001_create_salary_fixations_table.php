<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_fixations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prepared_by')->constrained('users');
            $table->string('pay_scale_reference', 64)->default('2026-SRO-347');
            $table->date('effective_date')->default('2026-07-01');
            $table->unsignedTinyInteger('grade_number');
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_record_id')->nullable();
            $table->string('source_sha256', 64);
            $table->decimal('old_basic', 14, 2);
            $table->decimal('personal_pay', 14, 2)->default(0);
            $table->json('calculation');
            $table->json('warnings')->nullable();
            $table->string('status', 32)->default('pending_review');
            $table->timestamps();
            $table->index(['user_id', 'effective_date', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_fixations');
    }
};
