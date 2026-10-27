<?php

namespace Tests\Feature;

use App\Models\Grade;
use App\Models\PayScale;
use App\Models\PayrollComponent;
use App\Models\PayrollComponentType;
use App\Models\PayrollGroup;
use App\Models\PayrollGroupType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PayScale2026ConfigurationTest extends TestCase
{
    /**
     * Tables created by this test itself (in-memory SQLite). On a persistent
     * MySQL test DB the migrations already own some tables; only the tables
     * this test created are dropped in tearDown — never migration-owned tables.
     */
    private array $createdTables = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Create only the tables used by the preparation command that do not exist
        // yet (default in-memory SQLite). On a persistent MySQL test DB the project
        // migrations have already created some of them; those are reused, never
        // dropped in tearDown, and only their rows are cleared below.
        $createIfMissing = function (string $table, \Closure $build): void {
            if (Schema::hasTable($table)) {
                return;
            }
            $this->createdTables[] = $table;
            Schema::create($table, $build);
        };

        $createIfMissing('pay_scales', function (Blueprint $t) {
            $t->id(); $t->string('scale_name'); $t->text('scale_description'); $t->boolean('isActive'); $t->timestamps();
        });
        $createIfMissing('grades', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('pay_scale_id'); $t->string('grade_name');
            $t->text('grade_description'); $t->boolean('isActive'); $t->double('min'); $t->double('max'); $t->timestamps();
        });
        $createIfMissing('payroll_group_types', function (Blueprint $t) {
            $t->id(); $t->string('group_name'); $t->text('group_description'); $t->timestamps();
        });
        $createIfMissing('payroll_groups', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('grade_id'); $t->unsignedBigInteger('payroll_group_type_id'); $t->boolean('isActive'); $t->timestamps();
        });
        $createIfMissing('payroll_component_types', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->text('component_description'); $t->timestamps();
        });
        $createIfMissing('payroll_components', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('payroll_group_id'); $t->unsignedBigInteger('payroll_component_type_id');
            $t->string('component_type'); $t->string('calcType'); $t->boolean('is_taxable');
            $t->boolean('isActive'); $t->decimal('amount', 8, 2); $t->timestamps();
        });

        // When running against a persistent database (e.g. MySQL test DB) the tables
        // already exist and may contain rows from a previous run — clear them so the
        // seed below is deterministic and satisfies unique constraints.
        foreach (['payroll_components', 'payroll_component_types', 'payroll_groups', 'payroll_group_types', 'grades', 'pay_scales'] as $table) {
            if (Schema::hasTable($table)) {
                Schema::disableForeignKeyConstraints();
                DB::table($table)->delete();
                Schema::enableForeignKeyConstraints();
            }
        }

        // Reset identity/auto-increment so tests that assume id=1 (e.g. pay_scale_id 1)
        // remain deterministic on persistent databases too (MySQL only; SQLite has no
        // AUTO_INCREMENT and in-memory tables are created fresh anyway).
        foreach (['pay_scales', 'grades', 'payroll_group_types', 'payroll_groups', 'payroll_component_types', 'payroll_components'] as $table) {
            if (Schema::hasTable($table) && ! in_array($table, $this->createdTables, true)) {
                Schema::disableForeignKeyConstraints();
                DB::statement("ALTER TABLE {$table} AUTO_INCREMENT = 1");
                Schema::enableForeignKeyConstraints();
            }
        }

        $old = PayScale::create(['scale_name' => 'Govt. Pay Scale 2015', 'scale_description' => 'Existing', 'isActive' => true]);
        $new = PayScale::create(['scale_name' => 'Govt. Pay Scale 2026', 'scale_description' => 'Pending', 'isActive' => false]);
        for ($i = 1; $i <= 20; $i++) {
            Grade::create(['pay_scale_id' => $old->id, 'grade_name' => 'Grade '.$i,
                'grade_description' => '2015', 'min' => 100, 'max' => 1000, 'isActive' => true]);
            Grade::create(['pay_scale_id' => $new->id, 'grade_name' => 'Grade '.$i,
                'grade_description' => '2026', 'min' => 200, 'max' => 2000, 'isActive' => false]);
        }
        $type = PayrollGroupType::create(['group_name' => 'Example Designation', 'group_description' => 'Example']);
        $sourceGrade = Grade::where('pay_scale_id', $old->id)->where('grade_name', 'Grade 9')->firstOrFail();
        $group = PayrollGroup::create(['grade_id' => $sourceGrade->id, 'payroll_group_type_id' => $type->id, 'isActive' => true]);
        foreach ([['Basic Pay', 'basic_pay', 400], ['House Rent Allowance', 'house_rent_allowance', 100], ['Special benefit', 'special_benefit', 50]] as [$name, $description, $amount]) {
            $componentType = PayrollComponentType::create(['name' => $name, 'component_description' => $description]);
            PayrollComponent::create(['payroll_group_id' => $group->id,
                'payroll_component_type_id' => $componentType->id,
                'component_type' => 'allowance', 'calcType' => 'fixed',
                'is_taxable' => false, 'isActive' => true, 'amount' => $amount]);
        }
    }

    protected function tearDown(): void
    {
        // Drop only the schema tables this test created itself (in-memory SQLite).
        // Never drop tables that belong to the project migrations (e.g. a MySQL test DB).
        foreach ($this->createdTables as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['kau_step1_feature_test_preview.csv', 'kau_step1_feature_test_apply.csv'] as $name) {
            @unlink(storage_path('app/'.$name));
        }
        parent::tearDown();
    }

    public function test_preview_never_changes_the_database(): void
    {
        $this->assertSame(0, Artisan::call('kau:prepare-pay-scale-2026', ['--report' => 'kau_step1_feature_test_preview.csv']));
        $this->assertSame(1, PayrollGroup::count());
        $this->assertSame(3, PayrollComponent::count());
    }

    public function test_apply_creates_inactive_zero_value_templates_and_is_idempotent(): void
    {
        $args = ['--apply' => true, '--report' => 'kau_step1_feature_test_apply.csv'];
        $this->assertSame(0, Artisan::call('kau:prepare-pay-scale-2026', $args));
        $this->assertSame(2, PayrollGroup::count());
        $this->assertSame(5, PayrollComponent::count());
        $new = PayScale::where('scale_name', 'Govt. Pay Scale 2026')->firstOrFail();
        $group = PayrollGroup::whereHas('grade', fn ($q) => $q->where('pay_scale_id', $new->id))->firstOrFail();
        $this->assertFalse((bool) $group->isActive);
        $this->assertSame(2, $group->payrollComponents()->count());
        foreach ($group->payrollComponents as $component) {
            $this->assertFalse((bool) $component->isActive);
            $this->assertEquals(0, (float) $component->amount);
            $this->assertNotEquals('Special benefit', $component->payrollComponentType->name);
        }
        $this->assertSame(0, Artisan::call('kau:prepare-pay-scale-2026', $args));
        $this->assertSame(2, PayrollGroup::count());
        $this->assertSame(5, PayrollComponent::count());
        $this->assertEquals(400, (float) PayrollComponent::whereHas('payrollGroup.grade', fn ($q) => $q->where('pay_scale_id', 1))
            ->whereHas('payrollComponentType', fn ($q) => $q->where('name', 'Basic Pay'))->firstOrFail()->amount);
    }
}
