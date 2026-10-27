<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 1: provenance + rollback tables for the historical salary importer. No existing tables are modified. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('salary_import_runs')) {
            Schema::create('salary_import_runs', function (Blueprint $table) {
                $table->id();
                $table->string('source_file', 255);
                $table->string('source_sha256', 64);
                $table->string('mode', 20); // dry_run | apply | replace
                $table->string('status', 20); // completed | rolled_back
                $table->json('summary')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('salary_import_records')) {
            Schema::create('salary_import_records', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('run_id')->index();
                $table->unsignedBigInteger('source_row_id')->nullable();
                $table->unsignedBigInteger('target_salary_generate_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('employee_code', 50)->nullable();
                $table->string('month_id', 3);
                $table->string('year', 5);
                $table->string('action', 20); // inserted | skipped | conflict | replaced
                $table->string('source_review_status', 20)->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('salary_import_backups')) {
            Schema::create('salary_import_backups', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('run_id')->index();
                $table->unsignedBigInteger('salary_generate_id')->index();
                $table->json('backup_payload');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_import_backups');
        Schema::dropIfExists('salary_import_records');
        Schema::dropIfExists('salary_import_runs');
    }
};
