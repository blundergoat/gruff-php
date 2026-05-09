<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

enum OutputFormat: string
{
    case Text = 'text';
    case Json = 'json';

    public static function fromInput(string $value): ?self
    {
        return match ($value) {
            self::Text->value => self::Text,
            self::Json->value => self::Json,
            default => null,
        };
    }
}
