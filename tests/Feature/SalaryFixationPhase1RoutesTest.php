<?php
namespace Tests\Feature;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Read-only feature verification; does not create or approve employee records. */
final class SalaryFixationPhase1RoutesTest extends TestCase
{
    public function test_direct_and_bulk_routes_are_registered(): void
    {
        foreach (['salary-fixation.index', 'salary-fixation.approve-now',
            'salary-fixation.approve', 'salary-fixation.reject',
            'salary-fixation.bulk-decision', 'salary-fixation.admin-print'] as $name) {
            self::assertTrue(Route::has($name), "Missing route: $name");
        }
    }

    public function test_workflow_event_history_schema_exists(): void
    {
        self::assertTrue(Schema::hasTable('salary_fixation_workflow_events'));
        foreach (['user_id','salary_fixation_id','actor_id','action','previous_status','details','created_at'] as $column) {
            self::assertTrue(Schema::hasColumn('salary_fixation_workflow_events', $column), $column);
        }
    }
}
