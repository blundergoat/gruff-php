<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

enum OutputFormat: string
{
    case Text = 'text';
    case Json = 'json';
    case Html = 'html';
    case Markdown = 'markdown';
    case Github = 'github';
    case Hotspot = 'hotspot';

    public static function fromInput(string $value): ?self
    {
        return match ($value) {
            self::Text->value => self::Text,
            self::Json->value => self::Json,
            self::Html->value => self::Html,
            self::Markdown->value => self::Markdown,
            self::Github->value => self::Github,
            self::Hotspot->value => self::Hotspot,
            default => null,
        };
    }
}
