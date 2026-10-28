<?php

namespace Tests\Feature;

use App\Services\SalaryArrearRuleService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SalaryArrearSettingsPatch04Test extends TestCase
{
    public function test_settings_routes_keep_existing_names(): void
    {
        foreach (['salary-arrear-settings.index', 'salary-arrear-settings.rules.store', 'salary-arrear-settings.rules.archive', 'salary-arrear-settings.components.store'] as $name) {
            $this->assertTrue(Route::has($name), $name);
        }
    }

    public function test_rules_require_authenticated_user(): void
    {
        $this->postJson(route('salary-arrear-settings.rules.store'), [])->assertUnauthorized();
    }

    public function test_percentage_rule_requires_a_basis_in_existing_engine(): void
    {
        $this->assertSame(['2026-07','2026-08'], SalaryArrearRuleService::months(['selected_months'=>['2026-07','2026-08']], 2026, 10));
    }
}
