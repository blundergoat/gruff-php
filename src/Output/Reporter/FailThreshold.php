<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

use GruffPhp\Results\Finding\Severity;

/**
 * Selects the finding severity that triggers an analyse failure gate.
 * Warning includes warnings and errors; none disables severity-based failure without suppressing findings.
 * fromInput() parses exact values, and isTriggeredBy() compares one finding's severity.
 */
enum FailThreshold: string
{
    /**
     * Disables severity-based failure; other command errors can still produce a non-zero exit status.
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
     * @return self|null - Exact matching threshold; null for empty, unknown, or differently cased input.
     */
    public static function fromInput(string $rawInput): ?self
    {


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
     * @return bool - True when this gate rejects the severity; false for none or a severity below its floor.
     */
    public function isTriggeredBy(Severity $severity): bool
    {

        return match ($this) {
            self::None => false,
            self::Advisory => true,
            self::Warning => $severity === Severity::Warning || $severity === Severity::Error,
            self::Error => $severity === Severity::Error,
        };
    }
}
