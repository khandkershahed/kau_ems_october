<?php

namespace Tests\Unit;

use App\Support\SalaryWorkflowTransitions as State;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SalaryWorkflowTransitionsTest extends TestCase
{
    public function test_exact_locked_transitions(): void
    {
        foreach ([
            [State::BLOCKED, 'save_draft', State::BLOCKED],
            [State::BLOCKED, 'approve_calculation', State::CALCULATED],
            [State::CALCULATED, 'save_draft', State::CALCULATED],
            [State::CALCULATED, 'approve_for_generation', State::READY],
            [State::READY, 'return_for_revision', State::CALCULATED],
            [State::READY, 'posting_failed', State::READY],
            [State::READY, 'posting_succeeded', State::GENERATED],
            [State::GENERATED, 'correct_generated', State::GENERATED],
        ] as [$from, $action, $expected]) {
            self::assertSame($expected, State::target($from, $action));
        }
    }

    public function test_approval_stages_cannot_be_skipped(): void
    {
        foreach ([[State::BLOCKED, 'approve_for_generation'], [State::CALCULATED, 'posting_succeeded'], [State::GENERATED, 'save_draft']] as [$from, $action]) {
            try {
                State::target($from, $action);
                self::fail("Unexpected transition accepted: {$from} / {$action}");
            } catch (DomainException $exception) {
                self::assertStringContainsString('Invalid salary workflow transition', $exception->getMessage());
            }
        }
    }
}
