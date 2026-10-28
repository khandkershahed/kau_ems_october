<?php
namespace Tests\Feature;
use Tests\TestCase;
final class SalaryOctoberGpfEvidencePatch16Test extends TestCase {
    public function test_guest_cannot_submit_gpf_evidence(): void {
        $this->postJson('/dashboard/salary/arrear-settings/october-gpf-evidence',[])->assertUnauthorized();
    }
    public function test_route_is_registered(): void {
        $route=app('router')->getRoutes()->getByName('salary-arrear-settings.october-gpf-evidence.store');
        $this->assertNotNull($route);
        $this->assertContains('POST',$route->methods());
    }
}
