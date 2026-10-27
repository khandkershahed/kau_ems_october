<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('salary_arrear_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->date('entitlement_start');
            $table->date('entitlement_end');
            $table->date('regular_scale_activation')->default('2027-07-01');
            $table->date('planned_payment_month')->nullable();
            $table->string('policy_status', 24)->default('draft');
            $table->string('special_benefit_policy', 24)->default('unconfirmed');
            $table->string('house_rent_policy', 24)->default('unconfirmed');
            $table->string('gpf_policy', 24)->default('unconfirmed');
            $table->string('gpf_rounding', 24)->default('unconfirmed');
            $table->decimal('gpf_rate_percent', 6, 3)->nullable();
            $table->string('policy_evidence', 500)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });
        Schema::create('salary_arrear_payment_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('salary_arrear_periods');
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->foreignId('salary_generate_id')->constrained('salary_generates');
            $table->string('evidence_reference', 500);
            $table->text('reason');
            $table->foreignId('certified_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['period_id', 'user_id', 'year', 'month'], 'salary_arrear_paid_month_certified_unique');
        });
        Schema::create('salary_arrear_source_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('salary_arrear_periods')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('field', 48);
            $table->decimal('amount', 14, 2);
            $table->boolean('certified')->default(false);
            $table->string('evidence_reference', 500);
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['period_id', 'user_id', 'year', 'month', 'field'], 'salary_arrear_source_override_unique');
        });
        Schema::create('salary_arrear_override_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('salary_arrear_periods');
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('field', 48);
            $table->json('before_value')->nullable();
            $table->json('after_value');
            $table->foreignId('actor_user_id')->nullable()->constrained('users');
            $table->timestamps();
        });
        Schema::create('salary_arrear_manual_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('salary_arrear_periods')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('title', 120);
            $table->string('direction', 16);
            $table->decimal('amount', 14, 2);
            $table->boolean('certified')->default(false);
            $table->string('evidence_reference', 500);
            $table->text('reason');
            $table->foreignId('reverses_item_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });
        Schema::create('salary_arrear_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('salary_arrear_periods');
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedInteger('version');
            $table->string('status', 32)->default('draft');
            $table->char('source_hash', 64);
            $table->json('calculation');
            $table->decimal('net_arrear', 14, 2);
            $table->foreignId('prepared_by')->nullable()->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['period_id', 'user_id', 'version'], 'salary_arrear_snapshot_version_unique');
        });
        Schema::create('salary_arrear_monthly_breakdowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('salary_arrear_snapshots')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->json('source_data');
            $table->json('calculated_data');
            $table->decimal('net_difference', 14, 2);
            $table->timestamps();
            $table->unique(['snapshot_id', 'year', 'month'], 'salary_arrear_snapshot_month_unique');
        });
        Schema::create('salary_arrear_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('salary_arrear_snapshots')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('code', 60);
            $table->string('title', 120);
            $table->string('direction', 16);
            $table->decimal('amount', 14, 2);
            $table->string('source_reference', 500)->nullable();
            $table->timestamps();
        });
        Schema::create('salary_arrear_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('salary_arrear_snapshots')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users');
            $table->string('action', 32);
            $table->text('note')->nullable();
            $table->timestamps();
        });
        Schema::create('salary_arrear_disbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('salary_arrear_periods');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('snapshot_id')->constrained('salary_arrear_snapshots');
            $table->foreignId('salary_generate_id')->nullable()->constrained('salary_generates');
            $table->decimal('net_amount', 14, 2);
            $table->string('status', 24)->default('pending');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['period_id', 'user_id'], 'salary_arrear_single_disbursement');
        });
    }

    public function down(): void
    {
        // Arrear financial/audit records must not be destroyed by an accidental rollback.
        throw new \RuntimeException('Arrear tables contain auditable financial data. Use a separately reviewed reversal migration.');
    }
};
