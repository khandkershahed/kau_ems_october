<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('salary_october_gpf_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            $table->unsignedBigInteger('fixation_id');
            $table->unsignedSmallInteger('salary_year');
            $table->unsignedTinyInteger('salary_month');
            $table->decimal('verified_gpf_amount', 14, 2);
            $table->string('evidence_reference', 500);
            $table->text('verification_note');
            $table->unsignedBigInteger('verified_by');
            $table->timestamps();
            $table->unique(['user_id','salary_year','salary_month'], 'october_gpf_evidence_unique_period');
        });
    }
    public function down(): void {
        throw new RuntimeException('Financial evidence rollback requires documented retention/migration; no destructive down().');
    }
};
