<?php

namespace Tests\Unit;

use App\Support\SalaryArrearTemplateCatalog;
use PHPUnit\Framework\TestCase;

final class SalaryArrearTemplateCatalogPhase6Test extends TestCase
{
    public function test_five_templates_are_unpublished_by_design_and_unique(): void
    {
        $definitions=SalaryArrearTemplateCatalog::definitions();
        $this->assertCount(5,$definitions);
        $this->assertCount(5,array_unique(array_column($definitions,'code')));
        $this->assertSame('payable',$definitions[0]['direction']);
        $this->assertSame(['deduction','deduction','deduction','deduction'],array_column(array_slice($definitions,1),'direction'));
    }

    public function test_existing_templates_are_preserved_and_missing_codes_are_inserted(): void
    {
        $codes=['BASIC_ARREAR_GROSS','BASIC_INCREMENT_ADJUSTMENT'];
        $plan=SalaryArrearTemplateCatalog::plan($codes);
        $this->assertSame('PRESERVE_EXISTING',$plan[0]['action']);
        $this->assertSame('PRESERVE_EXISTING',$plan[1]['action']);
        $this->assertSame('INSERT_UNPUBLISHED',$plan[2]['action']);
        $this->assertSame('INSERT_UNPUBLISHED',$plan[4]['action']);
    }

    public function test_repeat_seed_is_a_noop(): void
    {
        $codes=array_column(SalaryArrearTemplateCatalog::definitions(),'code');
        $plan=SalaryArrearTemplateCatalog::plan($codes);
        $this->assertSame(0,count(array_filter($plan,fn($r)=>$r['action']==='INSERT_UNPUBLISHED')));
    }

    public function test_duplicate_business_code_is_never_silently_merged(): void
    {
        $this->expectException(\DomainException::class);
        SalaryArrearTemplateCatalog::plan(['ADDITIONAL_GPF','ADDITIONAL_GPF']);
    }
}
