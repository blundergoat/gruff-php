<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Results\Finding\Severity;

/**
 * The severity gate behind `gruff-php analyse --fail-on <level>`: it names the lowest finding
 * severity allowed to fail a run. A user reaches for this when wiring gruff into CI or a
 * pre-commit hook and choosing how strict the gate should be - `--fail-on warning` lets advisory
 * notes slide but fails on warnings and errors, while `--fail-on none` still reports every finding
 * yet never breaks the build. `fromInput()` turns the raw flag value the user typed into one of
 * these cases, and `isTriggeredBy()` answers, finding by finding, whether that gate has tripped.
 */
enum FailThreshold: string
{
    /**
     * The `--fail-on none` choice: every finding is still reported, but the run always exits
     * clean - for a user who wants the scores without ever letting the gate fail CI.
     */
    case None = 'none';

    /**
     * The strictest gate (`--fail-on advisory`): any finding at all, down to the gentlest
     * advisory nudge, is enough to fail the run.
     */
    case Advisory = 'advisory';

    /**
     * The middle gate (`--fail-on warning`): advisory notes are allowed through, but warnings
     * and errors fail the run.
     */
    case Warning = 'warning';

    /**
     * The most lenient failing gate (`--fail-on error`): only genuine error-severity findings
     * fail the run; advisory and warning findings pass.
     */
    case Error = 'error';

    /**
     * Turns the raw `--fail-on` value the user typed on the command line into the matching gate,
     * so the rest of the run can reason about a case instead of a free-form string.
     *
     * @param string $rawInput - Raw `--fail-on` text the user typed; an empty or unknown value matches no case.
     *
     * @return self|null - Matching threshold; null when the text is none of the four names
     *                     (e.g. `--fail-on loud`), which the caller reports as a usage error.
     */
    public static function fromInput(string $rawInput): ?self
    {
        // Match the exact text the user passed to `--fail-on` against the four known names;
        // anything else (say `--fail-on loud`) falls through to null for the caller to reject.
        return match ($rawInput) {
            self::None->value => self::None,
            self::Advisory->value => self::Advisory,
            self::Warning->value => self::Warning,
            self::Error->value => self::Error,
            default => null,
        };
    }

    /**
     * Answers, for one finding, whether its severity is serious enough to trip this gate - the
     * check that ultimately turns a scan's findings into a green or red exit code for the user.
     *
     * @param Severity $severity - Severity of the finding being weighed against this threshold.
     *
     * @return bool - True when the finding is at or above the threshold and should fail the run;
     *                false when it is allowed through and the run may still pass.
     */
    public function isTriggeredBy(Severity $severity): bool
    {
        // Each gate names a severity floor: this finding fails only when it is at least as severe as the threshold.
        return match ($this) {
            self::None => false,
            self::Advisory => true,
            self::Warning => $severity === Severity::Warning || $severity === Severity::Error,
            self::Error => $severity === Severity::Error,
        };
    }
}
