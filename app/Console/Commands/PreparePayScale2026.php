<?php

namespace App\Console\Commands;

use App\Models\Grade;
use App\Models\PayScale;
use App\Models\PayrollComponent;
use App\Models\PayrollGroup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Prepare 2026 configuration without activating payroll or copying 2015 rates.
 * Safe to re-run: existing groups/components are never changed.
 */
class PreparePayScale2026 extends Command
{
    protected $signature = 'kau:prepare-pay-scale-2026
        {--apply : Create inactive designation mappings and zero-value, inactive component templates}
        {--groups-only : With --apply, create only designation mappings}
        {--report= : Optional CSV report destination; relative paths are inside storage/app}';

    protected $description = 'Preview or prepare draft 2026 payroll configuration without modifying 2015 payroll';

    public static function isSpecialBenefit(string $name, string $description = ''): bool
    {
        $normalized = strtolower(trim($name.' '.$description));

        return str_contains($normalized, 'special benefit')
            || str_contains($normalized, 'special_benefit')
            || str_contains($normalized, 'special bonus')
            || str_contains($normalized, 'special_bonus');
    }

    public function handle(): int
    {
        $old = PayScale::where('scale_name', 'Govt. Pay Scale 2015')->first();
        $new = PayScale::where('scale_name', 'Govt. Pay Scale 2026')->first();
        if (!$old || !$new) {
            $this->error('Both Govt. Pay Scale 2015 and Govt. Pay Scale 2026 must exist. Nothing changed.');
            return self::FAILURE;
        }
        if ((bool) $new->isActive && $this->option('apply')) {
            $this->error('Govt. Pay Scale 2026 is already active. Preparation requires an inactive target scale. Nothing changed.');
            return self::FAILURE;
        }

        $oldGrades = Grade::where('pay_scale_id', $old->id)->get()->keyBy('grade_name');
        $newGrades = Grade::where('pay_scale_id', $new->id)->get()->keyBy('grade_name');
        $expected = array_map(fn ($n) => 'Grade '.$n, range(1, 20));
        $missing = array_values(array_filter($expected, fn ($name) => !$oldGrades->has($name) || !$newGrades->has($name)));
        if ($missing) {
            $this->error('Missing grade mapping: '.implode(', ', $missing).'. Nothing changed.');
            return self::FAILURE;
        }

        // The old 2015 government group assignments are the structural source only.
        $oldGroups = PayrollGroup::with(['grade', 'payrollGroupType', 'payrollComponents.payrollComponentType'])
            ->whereIn('grade_id', $oldGrades->pluck('id')->all())
            ->orderBy('id')
            ->get();
        if ($oldGroups->isEmpty()) {
            $this->error('No 2015 government designation mappings were found. Nothing changed.');
            return self::FAILURE;
        }

        $plan = [];
        foreach ($oldGroups as $group) {
            $newGrade = $newGrades->get($group->grade->grade_name);
            $plan[] = [$group, $newGrade];
        }

        $apply = (bool) $this->option('apply');
        $groupsOnly = (bool) $this->option('groups-only');
        $defaultName = 'kau_pay_scale_2026_step1_'.($apply ? 'applied' : 'preview').'.csv';
        $pathOption = trim((string) ($this->option('report') ?: $defaultName));
        if ($pathOption === '' || str_contains($pathOption, '..') || str_starts_with($pathOption, '/')
            || str_contains($pathOption, ':') || str_contains($pathOption, '\\')) {
            $this->error('Report filename must be relative to storage/app and must not contain parent traversals. Nothing changed.');
            return self::FAILURE;
        }
        // Ensure a report can be written BEFORE starting any database changes.
        $path = storage_path('app/'.$pathOption);
        $dir = dirname($path);
        if ((!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) || !is_writable($dir)) {
            $this->error('Cannot write report directory: '.$dir.'. Nothing changed.');
            return self::FAILURE;
        }
        $rows = [];
        $counts = ['group_created' => 0, 'group_existing' => 0, 'component_created' => 0, 'component_existing' => 0, 'special_skipped' => 0];
        $execute = function () use ($plan, $apply, $groupsOnly, &$rows, &$counts): void {
            foreach ($plan as [$source, $newGrade]) {
                $identifier = [
                    'grade_id' => $newGrade->id,
                    'payroll_group_type_id' => $source->payroll_group_type_id,
                ];
                $target = PayrollGroup::where($identifier)->first();
                $groupAction = $target ? 'existing' : ($apply ? 'created_inactive' : 'would_create_inactive');
                if (!$target && $apply) {
                    $target = PayrollGroup::firstOrCreate($identifier, ['isActive' => false]);
                    $counts['group_created']++;
                } else {
                    $counts['group_existing'] += (int) (bool) $target;
                }
                $eligible = 0;
                foreach ($source->payrollComponents as $component) {
                    $type = $component->payrollComponentType;
                    if (!$type) {
                        throw new RuntimeException('Component ID '.$component->id.' has no component type; no changes committed.');
                    }
                    if (self::isSpecialBenefit($type->name, (string) $type->component_description)) {
                        $counts['special_skipped']++;
                        $rows[] = [$source->grade->grade_name, $source->payrollGroupType->group_name, $type->name, 'excluded_special_benefit', $component->amount, '', ''];
                        continue;
                    }
                    $eligible++;
                    $existing = $target
                        ? PayrollComponent::where('payroll_group_id', $target->id)
                            ->where('payroll_component_type_id', $component->payroll_component_type_id)->first()
                        : null;
                    $action = $existing ? 'existing_untouched' : ($apply && !$groupsOnly ? 'draft_created' : 'draft_needed');
                    if ($existing) {
                        $counts['component_existing']++;
                    } elseif ($target && $apply && !$groupsOnly) {
                        PayrollComponent::firstOrCreate(
                            ['payroll_group_id' => $target->id, 'payroll_component_type_id' => $component->payroll_component_type_id],
                            [
                                'component_type' => $component->component_type,
                                'calcType' => $component->calcType,
                                'is_taxable' => $component->is_taxable,
                                'isActive' => false,
                                'amount' => 0,
                            ]
                        );
                        $counts['component_created']++;
                    }
                    $rows[] = [
                        $source->grade->grade_name,
                        $source->payrollGroupType->group_name,
                        $type->name,
                        $action,
                        $component->amount,
                        $existing ? $existing->amount : '0.00',
                        $groupAction,
                    ];
                }
                if ($eligible === 0) {
                    $rows[] = [$source->grade->grade_name, $source->payrollGroupType->group_name, '', 'no_eligible_component', '', '', $groupAction];
                }
            }
        };

        try {
            if ($apply) {
                DB::transaction($execute, 3);
            } else {
                $execute();
            }
        } catch (\Throwable $e) {
            $this->error('Preparation cancelled: '.$e->getMessage());
            return self::FAILURE;
        }

        $fp = fopen($path, 'wb');
        if ($fp === false) {
            $this->error('Cannot create report at '.$path);
            return self::FAILURE;
        }
        fputcsv($fp, ['grade', 'designation', 'component', 'action', '2015_amount_reference_only', '2026_draft_amount', 'group_action']);
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);

        $this->info($apply ? 'APPLIED: Inactive 2026 configuration prepared.' : 'DRY RUN ONLY: Database was not modified.');
        $this->line('Source government groups: '.count($plan));
        foreach ($counts as $key => $count) {
            $this->line($key.': '.$count);
        }
        $this->warn('2026 scale, grades, groups and new component drafts remain inactive. No employee or existing monetary amount changed.');
        $this->line('Audit CSV: '.$path);

        return self::SUCCESS;
    }
}
