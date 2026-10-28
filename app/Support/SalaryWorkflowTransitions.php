<?php

namespace App\Support;

use DomainException;

final class SalaryWorkflowTransitions
{
    public const BLOCKED = 'BLOCKED';
    public const CALCULATED = 'CALCULATED';
    public const READY = 'READY_FOR_GENERATION';
    public const GENERATED = 'GENERATED';

    private const TRANSITIONS = [
        'save_draft' => [self::BLOCKED => self::BLOCKED, self::CALCULATED => self::CALCULATED],
        'approve_calculation' => [self::BLOCKED => self::CALCULATED],
        'approve_for_generation' => [self::CALCULATED => self::READY],
        'return_for_revision' => [self::READY => self::CALCULATED],
        'posting_failed' => [self::READY => self::READY],
        'posting_succeeded' => [self::READY => self::GENERATED],
        'correct_generated' => [self::GENERATED => self::GENERATED],
    ];

    public static function target(string $current, string $action): string
    {
        if (!isset(self::TRANSITIONS[$action][$current])) {
            throw new DomainException("Invalid salary workflow transition: {$current} / {$action}.");
        }
        return self::TRANSITIONS[$action][$current];
    }
}
