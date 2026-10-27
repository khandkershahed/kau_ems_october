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
        Schema::create('comment_general_provedent_funds', function (Blueprint $table) {
            $table->id();
            $table->foreignId("general_provident_fund_id")->constrained('general_provident_funds', 'id', 'cgpf_fund_foreign')->cascadeOnDelete();
            $table->foreignId("comment_id")->constrained('comments', 'id', 'cgpf_comment_foreign')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['general_provident_fund_id', 'comment_id'], 'cgpf_fund_comment_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comment_general_provedent_funds');
    }
};
