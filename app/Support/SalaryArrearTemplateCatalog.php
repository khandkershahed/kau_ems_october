<?php

namespace App\Support;

/** Arrear formula templates are NOT HR entitlement or payment approvals. */
final class SalaryArrearTemplateCatalog
{
    public static function definitions(): array
    {
        return [
            ['code'=>'BASIC_ARREAR_GROSS','title'=>'Bokeya Prapyo / Basic Arrear','direction'=>'payable','formula'=>'SUM_MONTHS(REVISED_BASIC - JUNE_BASIC)'],
            ['code'=>'BASIC_INCREMENT_ADJUSTMENT','title'=>'Basic Difference / Old Increment','direction'=>'deduction','formula'=>'SUM_MONTHS(OLD_SCALE_INCREMENT)'],
            ['code'=>'SPECIAL_BENEFIT_RECOVERY','title'=>'Special Benefit Recovery','direction'=>'deduction','formula'=>'SUM_MONTHS(PAID_SPECIAL_BENEFIT)'],
            ['code'=>'HOUSE_RENT_RECOVERY','title'=>'House Rent Difference','direction'=>'deduction','formula'=>'SUM_MONTHS(OCTOBER_HOUSE_RENT - JUNE_HOUSE_RENT)'],
            ['code'=>'ADDITIONAL_GPF','title'=>'Additional GPF','direction'=>'deduction','formula'=>'SUM_MONTHS(OCTOBER_GPF - JUNE_GPF)'],
        ];
    }

    /** Pure read-only planner; never overwrite employee/HR edits. */
    public static function plan(array $existingCodes): array
    {
        $counts = array_count_values($existingCodes);
        $actions=[];
        foreach(self::definitions() as $definition){
            $count=$counts[$definition['code']]??0;
            if($count>1) throw new \DomainException('Duplicate Arrear Rule code '.$definition['code'].' requires manual reconciliation.');
            $actions[]=['code'=>$definition['code'],'title'=>$definition['title'],
                'action'=>$count===1?'PRESERVE_EXISTING':'INSERT_UNPUBLISHED'];
        }
        return $actions;
    }
}
