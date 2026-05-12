<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

use GruffPhp\Finding\Severity;

enum FailThreshold: string
{
    case None = 'none';
    case Advisory = 'advisory';
    case Warning = 'warning';
    case Error = 'error';

    /**
     * Convert a CLI fail threshold string into the matching enum case.
     *
     * @return self|null Matching threshold, or null for unsupported input.
     */
    public static function fromInput(string $value): ?self
    {
        return match ($value) {
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
     * @return bool True when the severity meets or exceeds the threshold.
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
