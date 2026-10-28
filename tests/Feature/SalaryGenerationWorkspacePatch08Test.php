<?php
namespace Tests\Feature;

use Tests\TestCase;

final class SalaryGenerationWorkspacePatch08Test extends TestCase
{
    public function test_workspace_and_run_creation_require_login(): void
    {
        $this->get('/dashboard/salary/generation-workspace?year=2026&month=10')->assertRedirect();
        $this->postJson('/dashboard/salary/generation-workspace/runs', [
            'year'=>2026, 'month'=>10, 'user_ids'=>[20057],
        ])->assertUnauthorized();
    }

    public function test_generation_routes_registered(): void
    {
        $this->assertNotNull(\Illuminate\Support\Facades\Route::getRoutes()->getByName('salary-generation-workspace.index'));
        $this->assertNotNull(\Illuminate\Support\Facades\Route::getRoutes()->getByName('salary-generation-workspace.start'));
    }

    public function test_existing_posting_service_remains_authoritative(): void
    {
        $posting = file_get_contents(app_path('Services/SalaryBillPostingService.php'));
        $this->assertStringContainsString('->assertReady($userId, $year, $month)', $posting);
        $this->assertStringContainsString('->recordGenerated($workflow, (int)$bill->id, $actorId)', $posting);
    }
}
