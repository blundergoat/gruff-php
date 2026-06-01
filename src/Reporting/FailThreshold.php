<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

use GruffPhp\Finding\Severity;

/**
 * Defines the lowest finding severity that should fail a run.
 */
enum FailThreshold: string
{
    /**
     * Never fail from finding severities.
     */
    case None = 'none';

    /**
     * Fail on any finding, including advisory findings.
     */
    case Advisory = 'advisory';

    /**
     * Fail on warning and error findings.
     */
    case Warning = 'warning';

    /**
     * Fail only on error findings.
     */
    case Error = 'error';

    /**
     * Convert a CLI fail threshold string into the matching enum case.
     *
     * @param string $rawInput CLI fail-on value to parse.
     * @return self|null Matching threshold, or null for unsupported input.
     */
    public static function fromInput(string $rawInput): ?self
    {
        // Exact-match the CLI string to a case; anything else is an unsupported value the caller reports, hence null.
        return match ($rawInput) {
            self::None->value => self::None,
            self::Advisory->value => self::Advisory,
            self::Warning->value => self::Warning,
            self::Error->value => self::Error,
            default => null,
        };
    }

    /**
     * Decide whether a finding severity should fail for this threshold.
     *
     * @param Severity $severity Finding severity to compare with this threshold.
     * @return bool True when the severity meets or exceeds the threshold.
     */
    public function isTriggeredBy(Severity $severity): bool
    {
        // Each case names the floor: a severity trips the gate only when it is at least as severe as the threshold.
        return match ($this) {
            self::None => false,
            self::Advisory => true,
            self::Warning => $severity === Severity::Warning || $severity === Severity::Error,
            self::Error => $severity === Severity::Error,
        };
    }
}
