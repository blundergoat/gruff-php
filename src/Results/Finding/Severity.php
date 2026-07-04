<?php

declare(strict_types=1);

namespace GruffPhp\Results\Finding;

/**
 * How loudly a finding speaks - and whether it is allowed to fail the run the user is gating on.
 *
 * Every finding carries one of these levels, and the user's `minimumSeverity` / `--fail-on` choice
 * compares against it: a finding at or above the chosen level counts toward a non-zero exit, while
 * anything below is still shown for context but never trips the gate. Advisory nudges cleanup,
 * Warning is worth stopping a warning-level gate for, and Error is severe enough to fail even the
 * strictest run - so this is the dial that decides which issues merely inform and which block a merge.
 */
enum Severity: string
{
    /**
     * Informational only - worth cleaning up, but never fails a gate on its own; the user sees it as guidance.
     */
    case Advisory = 'advisory';

    /**
     * Serious enough to fail a warning-level gate (the bar a user sets with `--fail-on warning`).
     */
    case Warning = 'warning';

    /**
     * Severe enough to fail even an error-level gate - the highest bar a run can trip.
     */
    case Error = 'error';
}
