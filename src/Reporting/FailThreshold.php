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
