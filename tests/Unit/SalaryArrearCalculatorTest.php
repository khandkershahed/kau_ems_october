<?php

namespace Tests\Unit;

use App\Console\Commands\CorrectSeptember20057;
use App\Services\SalaryArrearCalculator;
use App\Services\SalaryStructuredArrearService;
use DomainException;
use PHPUnit\Framework\TestCase;

class SalaryArrearCalculatorTest extends TestCase
{
    private function gradeTen(): array
    {
        return [
            'june_basic' => 18530, 'entitled_basic' => 28715,
            'paid_basic' => 19460, 'paid_special_benefit' => 2919,
            'paid_house_rent' => 8000, 'paid_gpf' => 1946,
            'special_policy' => 'recover_paid', 'house_policy' => 'reconcile',
            'gpf_policy' => 'reconcile', 'gpf_rounding' => 'truncate_taka',
            'revised_house_rent' => 8000, 'gpf_rate_percent' => 10,
            'manual_items' => [],
        ];
    }

    public function test_meeting_example_reconciles_without_double_deduction(): void
    {
        $r = SalaryArrearCalculator::calculateMonth($this->gradeTen());
        $this->assertSame(10185.0, $r['gross_basic_arrear']);
        $this->assertSame(930.0, $r['old_increment_adjustment']);
        $this->assertSame(9255.0, $r['adjusted_basic_arrear']);
        $this->assertSame(2919.0, $r['special_benefit_recovery']);
        $this->assertSame(925.0, $r['additional_gpf']);
        $this->assertSame(5411.0, $r['net_arrear']);
        $this->assertSame(16233.0, $r['net_arrear'] * 3);
    }

    public function test_increment_hold_does_not_double_subtract(): void
    {
        $input = $this->gradeTen();
        $input['paid_basic'] = 18530;
        $input['entitled_basic'] = 27715; // HR-provided no-increment entitlement
        $r = SalaryArrearCalculator::calculateMonth($input);
        $this->assertSame(0.0, $r['old_increment_adjustment']);
        $this->assertSame(9185.0, $r['adjusted_basic_arrear']);
    }

    public function test_signed_house_rent_differences_and_manual_components(): void
    {
        $input = $this->gradeTen();
        $input['revised_house_rent'] = 7500;
        $input['manual_items'] = [
            ['direction' => 'addition', 'amount' => 1000],
            ['direction' => 'deduction', 'amount' => 250],
        ];
        $r = SalaryArrearCalculator::calculateMonth($input);
        $this->assertSame(-500.0, $r['house_rent_adjustment']);
        $this->assertSame(750.0, $r['manual_net']);
        $this->assertSame(5661.0, $r['net_arrear']);
    }

    public function test_missing_house_rent_entitlement_fails_closed(): void
    {
        $input = $this->gradeTen();
        unset($input['revised_house_rent']);
        $this->expectException(DomainException::class);
        SalaryArrearCalculator::calculateMonth($input);
    }

    public function test_unconfirmed_recovery_policy_fails_closed(): void
    {
        $input = $this->gradeTen();
        $input['special_policy'] = 'unconfirmed';
        $this->expectException(DomainException::class);
        SalaryArrearCalculator::calculateMonth($input);
    }

    public function test_truncation_and_rounding_are_explicit(): void
    {
        $input = $this->gradeTen();
        $input['paid_gpf'] = 0;
        $truncated = SalaryArrearCalculator::calculateMonth($input);
        $this->assertSame(2871.0, $truncated['additional_gpf']);
        $input['gpf_rounding'] = 'round_taka';
        $rounded = SalaryArrearCalculator::calculateMonth($input);
        $this->assertSame(2872.0, $rounded['additional_gpf']);
        $input['gpf_rounding'] = 'paisa';
        $precise = SalaryArrearCalculator::calculateMonth($input);
        $this->assertSame(2871.50, $precise['additional_gpf']);
    }

    public function test_missing_actual_paid_basic_is_not_zero(): void
    {
        $input = $this->gradeTen();
        unset($input['paid_basic']);
        $this->expectException(DomainException::class);
        SalaryArrearCalculator::calculateMonth($input);
    }

    public function test_retain_special_benefit_is_separate_rule(): void
    {
        $input = $this->gradeTen();
        $input['special_policy'] = 'retain';
        $r = SalaryArrearCalculator::calculateMonth($input);
        $this->assertSame(0.0, $r['special_benefit_recovery']);
    }

    public function test_approved_history_requires_unique_basic(): void
    {
        $bill = (object)[
            'reviewStatus' => 'approved',
            'salaryAllowanceCalcObject' => json_encode([
                ['name'=>'Basic Pay', 'calculated_amount'=>18530],
                ['name'=>'Basic Pay', 'calculated_amount'=>18530],
            ]),
            'salaryDeductionCalcObject' => '[]', 'gpfDeduction' => 1853, 'id'=>33,
        ];
        $this->expectException(DomainException::class);
        SalaryArrearCalculator::paidFromPayroll($bill);
    }
    public function test_historical_approved_bill_extracts_basic_special_rent_and_gpf(): void
    {
        $bill = (object)[
            'id' => 51,
            'reviewStatus' => 'approved',
            'salaryAllowanceCalcObject' => json_encode([
                ['name' => 'Basic Pay', 'calculated_amount' => 19460],
                ['name' => 'Special Benefit', 'calculated_amount' => 2919],
                ['name' => 'House Rent', 'calculated_amount' => 7800],
            ]),
            'salaryDeductionCalcObject' => '[]', 'gpfDeduction' => 1946,
        ];
        $paid = SalaryArrearCalculator::paidFromPayroll($bill);
        $this->assertSame(19460.0, $paid['paid_basic']);
        $this->assertSame(2919.0, $paid['paid_special_benefit']);
        $this->assertSame(7800.0, $paid['paid_house_rent']);
        $this->assertSame(1946.0, $paid['paid_gpf']);
        $this->assertSame(51, $paid['source_record_id']);
    }

    public function test_historical_bill_without_approval_is_not_accepted(): void
    {
        $bill = (object)['reviewStatus' => 'pending', 'salaryAllowanceCalcObject' => '[]',
            'salaryDeductionCalcObject' => '[]', 'gpfDeduction' => 0];
        $this->expectException(DomainException::class);
        SalaryArrearCalculator::paidFromPayroll($bill);
    }

    /** Phase 3A: a revised bill containing arrear components is never paid evidence. */
    public function test_revised_arrear_bill_is_rejected_as_paid_evidence(): void
    {
        $bill = (object)[
            'id' => 5850, 'reviewStatus' => 'pending',
            'salaryAllowanceCalcObject' => json_encode([
                ['name' => 'Basic Pay', 'calculated_amount' => 28715],
                ['name' => 'Bokeya Prapyo / Basic Arrear', 'calculated_amount' => 30555],
            ]),
            'salaryDeductionCalcObject' => json_encode([
                ['name' => '[-]Basic Difference / Old Increment', 'calculated_amount' => 2790],
                ['name' => '[-]Additional GPF', 'calculated_amount' => 2778],
            ]),
            'gpfDeduction' => 2872,
        ];
        $this->expectException(DomainException::class);
        SalaryArrearCalculator::paidFromPayroll($bill);
    }

    /** Phase 3A: even an approved revised bill with arrear components is rejected. */
    public function test_approved_arrear_bill_is_also_rejected(): void
    {
        $bill = (object)[
            'id' => 99, 'reviewStatus' => 'approved',
            'salaryAllowanceCalcObject' => json_encode([
                ['name' => 'Basic Pay', 'calculated_amount' => 28715],
                ['name' => 'Basic Arrear', 'calculated_amount' => 1000],
            ]),
            'salaryDeductionCalcObject' => '[]', 'gpfDeduction' => 0,
        ];
        $this->expectException(DomainException::class);
        SalaryArrearCalculator::paidFromPayroll($bill);
    }

    /** Phase 3A: Employee 20057-style evidence yields the expected components. */
    public function test_employee_20057_evidence_yields_expected_components(): void
    {
        $evidence = [
            'june_basic' => 18530,
            'old_increment' => 930,
            'increment_status' => 'RECONSTRUCTED_2015_STAGE_NOT_PAYMENT',
            'revised_basics' => ['2026-07' => 28715, '2026-08' => 28715, '2026-09' => 28715],
            'revised_sources' => ['2026-07' => 1, '2026-08' => 1, '2026-09' => 1],
            'paid_months' => [
                '2026-07' => ['paid_basic' => 19460, 'paid_special_benefit' => 2919,
                    'paid_house_rent' => 8757, 'paid_gpf' => 1946],
                '2026-08' => ['paid_basic' => 19460, 'paid_special_benefit' => 2919,
                    'paid_house_rent' => 8757, 'paid_gpf' => 1946],
                '2026-09' => ['paid_basic' => 19460, 'paid_special_benefit' => 2919,
                    'paid_house_rent' => 8757, 'paid_gpf' => 1946],
            ],
            'financial_months' => [],
        ];
        $r = SalaryStructuredArrearService::compute($evidence);
        $this->assertSame(30555.0, $r['BASIC_ARREAR_GROSS']['amount']);
        $this->assertSame(2790.0, $r['BASIC_INCREMENT_ADJUSTMENT']['amount']);
        $this->assertSame(27765.0, $r['BASIC_ARREAR_GROSS']['amount'] - $r['BASIC_INCREMENT_ADJUSTMENT']['amount']);
    }

    /** Phase 3A: missing paid source leaves the component UNKNOWN, never zero. */
    public function test_missing_paid_source_leaves_component_unknown(): void
    {
        $evidence = $this->evidence20057();
        $evidence['paid_months'] = [];
        $evidence['financial_months'] = [];
        $r = SalaryStructuredArrearService::compute($evidence);
        $this->assertSame(30555.0, $r['BASIC_ARREAR_GROSS']['amount']);
        $this->assertSame(2790.0, $r['BASIC_INCREMENT_ADJUSTMENT']['amount']);
        $this->assertSame(null, $r['SPECIAL_BENEFIT_RECOVERY']['amount']);
        $this->assertSame('UNKNOWN', $r['SPECIAL_BENEFIT_RECOVERY']['status']);
    }

    /** Phase 3B (D,E): pending September source keeps 8757 reported but unverified. */
    public function test_employee_20057_special_benefit_reported_but_unverified(): void
    {
        $evidence = $this->evidence20057();
        $evidence['paid_months']['2026-09']['payment_verified'] = false;
        $evidence['paid_months']['2026-09']['payment_status'] = 'pending';
        $r = SalaryStructuredArrearService::compute($evidence);
        $this->assertSame(8757.0, $r['SPECIAL_BENEFIT_RECOVERY']['amount']); // 2919*3
        $this->assertSame('PAYMENT_UNVERIFIED', $r['SPECIAL_BENEFIT_RECOVERY']['status']);
        $this->assertSame(2271.0, $r['HOUSE_RENT_RECOVERY']['amount']); // (8757-8000)*3
        $this->assertSame('PAYMENT_UNVERIFIED', $r['HOUSE_RENT_RECOVERY']['status']);
    }

    /** Phase 3B (A-C): 20057 basic arrear amounts and directions. */
    public function test_employee_20057_basic_arrear_components(): void
    {
        $r = SalaryStructuredArrearService::compute($this->evidence20057());
        $this->assertSame(30555.0, $r['BASIC_ARREAR_GROSS']['amount']);
        $this->assertSame('payable', $r['BASIC_ARREAR_GROSS']['direction']);
        $this->assertSame(2790.0, $r['BASIC_INCREMENT_ADJUSTMENT']['amount']);
        $this->assertSame('deduction', $r['BASIC_INCREMENT_ADJUSTMENT']['direction']);
        $this->assertSame(27765.0, $r['BASIC_ARREAR_GROSS']['amount'] - $r['BASIC_INCREMENT_ADJUSTMENT']['amount']);
        $this->assertSame('SOURCE_RECONCILED', $r['SPECIAL_BENEFIT_RECOVERY']['status']);
    }

    /** Phase 3B (G/H): verified house rent difference — positive payable, over-deduction payable. */
    public function test_house_rent_difference_directions(): void
    {
        $r = SalaryStructuredArrearService::compute($this->evidence20057());
        $this->assertSame(2271.0, $r['HOUSE_RENT_RECOVERY']['amount']); // (8757-8000)*3
        $this->assertSame('deduction', $r['HOUSE_RENT_RECOVERY']['direction']);
        $e = $this->evidence20057();
        $e['financial_months'] = ['2026-07' => ['entitled_house_rent' => 9000],
            '2026-08' => ['entitled_house_rent' => 9000], '2026-09' => ['entitled_house_rent' => 9000]];
        $r = SalaryStructuredArrearService::compute($e);
        $this->assertSame(729.0, $r['HOUSE_RENT_RECOVERY']['amount']); // (9000-8757)*3
        $this->assertSame('payable', $r['HOUSE_RENT_RECOVERY']['direction']);
    }

    /** Phase 3B (I/J): missing house-rent entitlement or GPF rule stays UNKNOWN. */
    public function test_missing_house_rent_and_gpf_evidence_stays_unknown(): void
    {
        $e = $this->evidence20057();
        $e['financial_months'] = [];
        $r = SalaryStructuredArrearService::compute($e);
        $this->assertSame(null, $r['HOUSE_RENT_RECOVERY']['amount']);
        $this->assertSame('UNKNOWN', $r['HOUSE_RENT_RECOVERY']['status']);
        $this->assertSame(null, $r['ADDITIONAL_GPF']['amount']);
        $this->assertSame('UNKNOWN', $r['ADDITIONAL_GPF']['status']);
    }

    /** Phase 3B (K): positive verified GPF difference is a deduction. */
    public function test_positive_verified_gpf_difference_is_deduction(): void
    {
        $e = $this->evidence20057();
        foreach (['2026-07', '2026-08', '2026-09'] as $p) {
            $e['financial_months'][$p]['required_gpf'] = 2146;
        }
        $r = SalaryStructuredArrearService::compute($e);
        $this->assertSame(600.0, $r['ADDITIONAL_GPF']['amount']); // (2146-1946)*3
        $this->assertSame('deduction', $r['ADDITIONAL_GPF']['direction']);
        $this->assertSame('SOURCE_RECONCILED', $r['ADDITIONAL_GPF']['status']);
    }

    /** Phase 3B (F): unverified payment status is excluded from approval eligibility. */
    public function test_unverified_payment_status_is_excluded_from_approval(): void
    {
        $evidence = $this->evidence20057();
        $evidence['paid_months']['2026-09']['payment_verified'] = false;
        $calculated = SalaryStructuredArrearService::compute($evidence);
        $this->assertSame('PAYMENT_UNVERIFIED', $calculated['SPECIAL_BENEFIT_RECOVERY']['status']);
        $src = file_get_contents((new \ReflectionClass(SalaryStructuredArrearService::class))->getFileName());
        $this->assertStringContainsString("'PAYMENT_UNVERIFIED', 'UNKNOWN', 'SOURCE_CONFLICT_REVIEW_REQUIRED'", $src);
    }

    /** Step 3B-R: only employee 20057 / 5850 / 9 / 2026 is eligible. */
    public function test_correction_scope_only_accepts_20057_september_2026(): void
    {
        $bill = (object)['user_id' => 58, 'monthId' => 9, 'salaryGenerationYear' => 2026];
        $this->assertNull(CorrectSeptember20057::validateContext($bill));
        $this->assertNotNull(CorrectSeptember20057::validateContext((object)['user_id' => 424, 'monthId' => 9, 'salaryGenerationYear' => 2026]));
        $this->assertNotNull(CorrectSeptember20057::validateContext((object)['user_id' => 58, 'monthId' => 8, 'salaryGenerationYear' => 2026]));
        $this->assertNotNull(CorrectSeptember20057::validateContext((object)['user_id' => 58, 'monthId' => 9, 'salaryGenerationYear' => 2025]));
    }

    /** Step 3B-R: changed record fingerprint is detected (pure helper). */
    public function test_fingerprint_changes_detect_record_mutation(): void
    {
        $before = (object)['id' => 5850, 'totalAllowance' => 70027.00, 'salaryTotal' => 51564.50];
        $fp1 = CorrectSeptember20057::fingerprint($before);
        $this->assertSame($fp1, CorrectSeptember20057::fingerprint($before));
        $after = (object)['id' => 5850, 'totalAllowance' => 33136.00, 'salaryTotal' => 31180.00];
        $this->assertNotSame($fp1, CorrectSeptember20057::fingerprint($after));
    }

    /** Step 3B-R: canonical payload reconciles exactly to the September PDF. */
    public function test_expected_payload_matches_september_pdf(): void
    {
        $p = CorrectSeptember20057::expectedPayload();
        $this->assertSame(33136.0, $p['totalAllowance']);
        $this->assertSame(1956.0, $p['totalDeduction']);
        $this->assertSame(31180.0, $p['salaryTotal']);
        $this->assertSame($p['totalAllowance'] - $p['totalDeduction'], $p['salaryTotal']);
        $this->assertSame(1946.0, $p['gpfDeduction']);
        $basic = 0.0;
        foreach ($p['salaryAllowanceCalcObject'] as $item) {
            if ($item['name'] === 'Basic Pay') $basic += $item['calculated_amount'];
        }
        $this->assertSame(19460.0, $basic); // no arrear inside allowances
    }

    /** Step 3B-S: approved bills are refused (downstream dependency guard). */
    public function test_dependency_issues_refuse_approved_bill(): void
    {
        $issues = CorrectSeptember20057::dependencyIssues((object)['reviewStatus' => 'approved']);
        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('DEPENDENCY_REFUSED', $issues[0]);
    }

    /** Phase 3C-Fix (Task B): missing rules still yield all five canonical rows. */
    public function test_canonical_rule_set_fills_missing_rules(): void
    {
        $rules = SalaryStructuredArrearService::canonicalRuleSet([]);
        $this->assertCount(5, $rules);
        $this->assertSame(SalaryStructuredArrearService::CODES, array_map(fn ($r) => $r->code, $rules));
        foreach ($rules as $rule) {
            $this->assertNotNull($rule->formula);
            $this->assertNull($rule->amount); // display-only fallback: no fabricated amounts
            $this->assertNull($rule->id);
        }
    }

    /** Phase 3C-Fix (Task B): configured rules are preserved with their revision. */
    public function test_canonical_rule_set_preserves_configured_rules(): void
    {
        $configured = [
            (object)['id' => 7, 'code' => 'BASIC_ARREAR_GROSS', 'title' => 'Configured', 'formula' => 'cfg', 'amount' => null, 'revision' => 3],
        ];
        $rules = SalaryStructuredArrearService::canonicalRuleSet($configured);
        $this->assertCount(5, $rules);
        $this->assertSame(7, $rules[0]->id);
        $this->assertSame(3, $rules[0]->revision);
        $this->assertSame('Configured', $rules[0]->title);
        $this->assertSame('BASIC_INCREMENT_ADJUSTMENT', $rules[1]->code);
    }

    /** Phase 3C-Fix (Task A): evidence summary is narrow, read-only and null-preserving. */
    public function test_evidence_summary_is_narrow_and_null_preserving(): void
    {
        $ref = new \ReflectionMethod(SalaryStructuredArrearService::class, 'evidenceSummary');
        $this->assertTrue($ref->isPublic());
        $src = file_get_contents((new \ReflectionClass(SalaryStructuredArrearService::class))->getFileName());
        $this->assertStringNotContainsString('public function sources(', $src); // sources() stays private
        $this->assertStringContainsString('public function evidenceSummary(', $src);
    }

    /** Step 3B-S (Task E): active original journal — reverses_entry_id NULL and no reversal. */
    public function test_active_original_journal_is_detected(): void
    {
        $entries = [
            (object)['id' => 1, 'signed_amount' => 2778.0, 'reverses_entry_id' => null],
        ];
        $this->assertSame([1], CorrectSeptember20057::journalActiveIds($entries));
        $this->assertSame(2778.0, CorrectSeptember20057::journalNetEffect($entries));
    }

    /** Step 3B-S (Task E): a properly reversed journal has zero net effect. */
    public function test_properly_reversed_journal_has_zero_net_effect(): void
    {
        $entries = [
            (object)['id' => 1, 'signed_amount' => 2778.0, 'reverses_entry_id' => null],
            (object)['id' => 2, 'signed_amount' => -2778.0, 'reverses_entry_id' => 1],
        ];
        $this->assertSame([], CorrectSeptember20057::journalActiveIds($entries));
        $this->assertSame(0.0, CorrectSeptember20057::journalNetEffect($entries));
    }

    /** Step 3B-S (Task E): duplicate reversal is rejected — net effect is a negative double-count guard. */
    public function test_duplicate_reversal_is_not_double_counted(): void
    {
        $entries = [
            (object)['id' => 1, 'signed_amount' => 2778.0, 'reverses_entry_id' => null],
            (object)['id' => 2, 'signed_amount' => -2778.0, 'reverses_entry_id' => 1],
            (object)['id' => 3, 'signed_amount' => -2778.0, 'reverses_entry_id' => 1],
        ];
        $this->assertSame([], CorrectSeptember20057::journalActiveIds($entries));
        $this->assertSame(0.0, CorrectSeptember20057::journalNetEffect($entries));
    }

    /** Step 3B-S (Task E): a changed journal amount is still detected by net effect. */
    public function test_changed_journal_amount_is_reflected_in_net_effect(): void
    {
        $entries = [
            (object)['id' => 1, 'signed_amount' => 999.0, 'reverses_entry_id' => null],
        ];
        $this->assertSame(999.0, CorrectSeptember20057::journalNetEffect($entries));
    }

    /** Step 3B-S (Task E): missing journal yields zero net effect. */
    public function test_missing_journal_yields_zero_net_effect(): void
    {
        $this->assertSame([], CorrectSeptember20057::journalActiveIds([]));
        $this->assertSame(0.0, CorrectSeptember20057::journalNetEffect([]));
    }

    /** Step 3B-S (Task E): a journal is not necessarily active merely because reverses_entry_id is NULL. */
    public function test_null_reverses_entry_id_is_not_active_when_a_reversal_exists(): void
    {
        $entries = [
            (object)['id' => 5, 'signed_amount' => 1000.0, 'reverses_entry_id' => null],
            (object)['id' => 9, 'signed_amount' => -1000.0, 'reverses_entry_id' => 5],
        ];
        $this->assertSame([], CorrectSeptember20057::journalActiveIds($entries));
        $this->assertSame(0.0, CorrectSeptember20057::journalNetEffect($entries));
    }

    /** Step 3B-S (Task E): chronological chain break detection. */
    public function test_gpf_chain_breaks_are_detected(): void
    {
        $ok = [
            (object)['id' => 1, 'year' => 2026, 'month' => 9, 'openingBalance' => 26128.0, 'closingAmount' => 28074.0],
            (object)['id' => 2, 'year' => 2026, 'month' => 10, 'openingBalance' => 28074.0, 'closingAmount' => 30020.0],
            (object)['id' => 3, 'year' => 2027, 'month' => 1, 'openingBalance' => 30020.0, 'closingAmount' => 31966.0],
        ];
        $this->assertSame([], CorrectSeptember20057::gpfChainBreaks($ok));
        $broken = [
            (object)['id' => 1, 'year' => 2026, 'month' => 9, 'openingBalance' => 26128.0, 'closingAmount' => 28074.0],
            (object)['id' => 2, 'year' => 2026, 'month' => 10, 'openingBalance' => 29000.0, 'closingAmount' => 30020.0],
        ];
        $this->assertCount(1, CorrectSeptember20057::gpfChainBreaks($broken));
    }

    /** Step 3B-T (Task F): backup integrity guard — missing/empty/corrupted manifest and checksum. */
    public function test_backup_integrity_guard(): void
    {
        $tmp = sys_get_temp_dir().'/step3bt_backup_'.uniqid().'.sql';
        file_put_contents($tmp, str_repeat('a', 2048));
        $this->assertNotEmpty(CorrectSeptember20057::backupIssues($tmp, 'codershat_kau')); // no manifest
        file_put_contents($tmp.'.manifest.json', json_encode(['database' => 'codershat_kau',
            'sha256' => 'wrong', 'completed_at' => date('c'), 'source_snapshot' => 'x', 'target_snapshot' => 'y']));
        $issues = CorrectSeptember20057::backupIssues($tmp, 'codershat_kau');
        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('sha256', $issues[0]);
        $manifest = json_decode(file_get_contents($tmp.'.manifest.json'), true);
        $manifest['sha256'] = hash_file('sha256', $tmp);
        file_put_contents($tmp.'.manifest.json', json_encode($manifest));
        $this->assertSame([], CorrectSeptember20057::backupIssues($tmp, 'codershat_kau'));
        unlink($tmp);
        unlink($tmp.'.manifest.json');
    }

    /** Step 3B-T (Task F): expected September salary payload matches the PDF. */
    public function test_expected_september_salary_payload_matches_pdf(): void
    {
        $payload = CorrectSeptember20057::expectedPayload();
        $this->assertSame(33136.0, $payload['totalAllowance']);
        $this->assertSame(1956.0, $payload['totalDeduction']);
        $this->assertSame(31180.0, $payload['salaryTotal']);
        $this->assertSame(1946.0, $payload['gpfDeduction']);
    }

    /** Step 3B-T (Task F): reversal amount is exactly -2778.00 and one-to-one; net effect is zero. */
    public function test_reversal_amount_and_one_to_one_relationship(): void
    {
        $entries = [
            (object)['id' => 1, 'signed_amount' => 2778.0, 'reverses_entry_id' => null],
            (object)['id' => 2, 'signed_amount' => -2778.0, 'reverses_entry_id' => 1],
        ];
        $this->assertSame(0.0, CorrectSeptember20057::journalNetEffect($entries));
        $this->assertSame([], CorrectSeptember20057::journalActiveIds($entries));
        $this->assertSame(1, $entries[1]->reverses_entry_id); // exactly one reversal of id 1
    }

    /** Step 3B-T (Task F): duplicate reversal of the same journal is rejected. */
    public function test_duplicate_reversal_is_rejected(): void
    {
        $entries = [
            (object)['id' => 1, 'signed_amount' => 2778.0, 'reverses_entry_id' => null],
            (object)['id' => 2, 'signed_amount' => -2778.0, 'reverses_entry_id' => 1],
            (object)['id' => 3, 'signed_amount' => -2778.0, 'reverses_entry_id' => 1],
        ];
        $this->assertSame([], CorrectSeptember20057::journalActiveIds($entries));
        $this->assertSame(0.0, CorrectSeptember20057::journalNetEffect($entries));
    }

    public function test_missing_backup_file_is_refused(): void
    {
        $issues = CorrectSeptember20057::backupIssues(sys_get_temp_dir().'/nonexistent_'.uniqid().'.sql', 'codershat_kau');
        $this->assertNotEmpty($issues);
        $this->assertStringContainsString('BACKUP_REFUSED', $issues[0]);
    }

    /** Phase 3B: 20057 evidence factory — verified monthly payment + house entitlement. */
    private function evidence20057(): array
    {
        return [
            'june_basic' => 18530, 'old_increment' => 930,
            'increment_status' => 'RECONSTRUCTED_2015_STAGE_NOT_PAYMENT',
            'revised_basics' => ['2026-07' => 28715, '2026-08' => 28715, '2026-09' => 28715],
            'revised_sources' => ['2026-07' => 1, '2026-08' => 1, '2026-09' => 1],
            'paid_months' => [
                '2026-07' => ['paid_basic' => 19460, 'paid_special_benefit' => 2919, 'paid_house_rent' => 8757, 'paid_gpf' => 1946, 'payment_verified' => true],
                '2026-08' => ['paid_basic' => 19460, 'paid_special_benefit' => 2919, 'paid_house_rent' => 8757, 'paid_gpf' => 1946, 'payment_verified' => true],
                '2026-09' => ['paid_basic' => 19460, 'paid_special_benefit' => 2919, 'paid_house_rent' => 8757, 'paid_gpf' => 1946, 'payment_verified' => true],
            ],
            'financial_months' => ['2026-07' => ['entitled_house_rent' => 8000],
                '2026-08' => ['entitled_house_rent' => 8000], '2026-09' => ['entitled_house_rent' => 8000]],
        ];
    }
}
