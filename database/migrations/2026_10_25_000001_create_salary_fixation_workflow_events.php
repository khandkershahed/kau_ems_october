<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('salary_fixation_workflow_events')) return;
        Schema::create('salary_fixation_workflow_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('salary_fixation_id')->nullable()->constrained('salary_fixations');
            $table->foreignId('actor_id')->constrained('users');
            $table->string('action', 64);
            $table->string('previous_status', 32)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }
    public function down(): void
    {
        // Approval evidence must not be erased automatically by a migration rollback.
        throw new RuntimeException('Workflow history is immutable; reviewed data-preserving reversal is required.');
    }
};
