<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('salary_estimation_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('salary_year');
            $table->unsignedTinyInteger('salary_month');
            $table->unsignedBigInteger('created_by');
            $table->string('status', 20)->default('running');
            $table->unsignedBigInteger('last_user_id')->default(0);
            $table->unsignedInteger('examined')->default(0);
            $table->unsignedInteger('estimated')->default(0);
            $table->unsignedInteger('blocked')->default(0);
            $table->timestamps();
        });
        Schema::create('salary_estimation_run_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('salary_estimation_run_id');
            $table->unsignedBigInteger('user_id');
            $table->string('result', 20);
            $table->text('error_message')->nullable();
            $table->char('source_hash', 64)->nullable();
            $table->json('preview_result')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['salary_estimation_run_id', 'user_id'], 'salary_estimation_once');
        });
    }
    public function down(): void
    {
        throw new RuntimeException('Estimation audit history is retained; restore verified staging backup for rollback.');
    }
};
