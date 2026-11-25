<?php

namespace Tests\Feature;

use App\Exceptions\HistoricalSalaryImmutableException;
use App\Models\SalaryGenerate;
use App\Services\SalaryBillCompositionService;
use App\Services\SalaryCalculationService;
use App\Services\SalaryGenerationGuards;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * STEP 02 safety tests. Runs on the in-memory SQLite database from phpunit.xml only:
 * it never touches the live/local MySQL database.
 */
final class PayrollStep02SafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
    }

    private function makeBillsTable(): void
    {
        Schema::create('salary_generates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedInteger('monthId');
            $t->unsignedInteger('salaryGenerationYear');
            $t->string('reviewStatus')->default('approved');
            $t->decimal('salaryTotal', 14, 2)->default(0);
            $t->timestamps();
        });
        DB::table('salary_generates')->insert([
            ['user_id' => 1, 'monthId' => 10, 'salaryGenerationYear' => 2026, 'salaryTotal' => 50000.00],
            ['user_id' => 1, 'monthId' => 11, 'salaryGenerationYear' => 2026, 'salaryTotal' => 50000.00],
        ]);
    }

    public function test_october_2026_salary_cannot_be_updated_or_deleted(): void
    {
        $this->makeBillsTable();
        $oct = SalaryGenerate::where('monthId', 10)->firstOrFail();

        try {
            $oct->update(['salaryTotal' => 50000.01]);
            $this->fail('October update must be blocked.');
        } catch (HistoricalSalaryImmutableException $e) {
            $this->assertStringContainsString('HISTORICAL_SALARY_IMMUTABLE', $e->getMessage());
        }
        try {
            $oct->delete();
            $this->fail('October delete must be blocked.');
        } catch (HistoricalSalaryImmutableException) {
            $this->addToAssertionCount(1);
        }
        $this->assertEquals(50000.00, DB::table('salary_generates')->where('monthId', 10)->value('salaryTotal'));
        $this->assertSame(1, DB::table('salary_generates')->where('monthId', 10)->count());

        // Only the review workflow status may change on a locked bill.
        $oct = SalaryGenerate::where('monthId', 10)->firstOrFail(); // fresh instance, no dirty state
        $oct->update(['reviewStatus' => 'review']);
        $this->assertSame('review', DB::table('salary_generates')->where('monthId', 10)->value('reviewStatus'));

        // November 2026 stays editable.
        SalaryGenerate::where('monthId', 11)->firstOrFail()->update(['salaryTotal' => 51000]);
        $this->assertSame(1, DB::table('salary_generates')->where('salaryTotal', 51000)->count());
    }

    public function test_guard_boundaries_and_422_rendering(): void
    {
        foreach ([[2026, 10], [2026, 9], [2025, 12]] as [$y, $m]) {
            $this->assertTrue(SalaryGenerationGuards::isMonthLocked($y, $m));
        }
        foreach ([[2026, 11], [2027, 1]] as [$y, $m]) {
            $this->assertFalse(SalaryGenerationGuards::isMonthLocked($y, $m));
            SalaryGenerationGuards::assertMonthMutable($y, $m);
        }
        $this->expectException(HistoricalSalaryImmutableException::class);
        try {
            SalaryGenerationGuards::assertMonthMutable(2026, 10, 'recalculated');
        } catch (HistoricalSalaryImmutableException $e) {
            $response = $e->render(request());
            $this->assertSame(422, $response->getStatusCode());
            throw $e;
        }
    }

    public function test_gpf_keeps_two_decimals_from_november_but_october_stays_whole_taka(): void
    {
        $basic = [['name' => 'Basic Pay', 'calcType' => 'fixed', 'amount' => 18155.00]];
        $svc = new SalaryCalculationService();

        $nov = $svc->calculate($basic, [], null, null, 2026, 11);
        $this->assertSame(1815.50, $nov['gpf_deduction']);
        $this->assertSame(18155.00 - 1815.50, $nov['net_salary']);

        $undated = $svc->calculate($basic, []);
        $this->assertSame(1815.50, $undated['gpf_deduction']);

        // Finalized October reproduction keeps its legacy whole-taka rule.
        $oct = $svc->calculate($basic, [], null, null, 2026, 10);
        $this->assertSame(1816.0, $oct['gpf_deduction']);
    }

    public function test_employee_20083_joined_2026_09_01_is_excluded_from_july_preview(): void
    {
        $this->assertTrue(SalaryGenerationGuards::joinedAfterPeriodEnd('2026-09-01', 2026, 7));
        $this->assertTrue(SalaryGenerationGuards::joinedAfterPeriodEnd('2026-09-01', 2026, 6));
        $this->assertTrue(SalaryGenerationGuards::joinedAfterPeriodEnd('2026-09-01', 2026, 8));
        $this->assertFalse(SalaryGenerationGuards::joinedAfterPeriodEnd('2026-09-01', 2026, 9));
        $this->assertFalse(SalaryGenerationGuards::joinedAfterPeriodEnd(null, 2026, 7));

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->boolean('isActive')->default(true);
            $t->timestamps();
        });
        Schema::create('joining_dates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->date('joining_date');
            $t->timestamps();
        });
        Schema::create('salary_calculation_versions', fn (Blueprint $t) => $t->id());
        Schema::create('salary_arrear_rules', fn (Blueprint $t) => $t->id());
        DB::table('users')->insert(['id' => 20083, 'name' => 'Joined Sept', 'isActive' => 1]);
        DB::table('joining_dates')->insert(['user_id' => 20083, 'joining_date' => '2026-09-01']);

        $out = app(SalaryBillCompositionService::class)->composeRegularOnly(20083, 2026, 7);
        $this->assertSame('BLOCKED', $out['status']);
        $this->assertContains('EMPLOYEE_NOT_YET_JOINED_IN_SELECTED_PAYROLL_PERIOD', $out['blocking_reasons']);
    }
}
