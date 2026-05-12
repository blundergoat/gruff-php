<?php

declare(strict_types=1);

namespace GruffPhp\Reporting;

/**
 * Represents the report formats supported by the analyse command.
 */
enum OutputFormat: string
{
    /**
     * Plain terminal report for local runs.
     */
    case Text = 'text';

    /**
     * Machine-readable JSON report with the full analysis payload.
     */
    case Json = 'json';

    /**
     * Browser-friendly HTML report.
     */
    case Html = 'html';

    /**
     * Markdown report for issue and pull request comments.
     */
    case Markdown = 'markdown';

    /**
     * GitHub workflow annotation output.
     */
    case Github = 'github';

    /**
     * JSON hotspot map for file offender ranking.
     */
    case Hotspot = 'hotspot';

    /**
     * SARIF report for code scanning consumers.
     */
    case Sarif = 'sarif';

    /**
     * Convert a CLI format string into the matching output format.
     *
     * @param string $value CLI format value to parse.
     * @return self|null Matching format, or null for unsupported input.
     */
    public static function fromInput(string $value): ?self
    {
        return match ($value) {
            self::Text->value => self::Text,
            self::Json->value => self::Json,
            self::Html->value => self::Html,
            self::Markdown->value => self::Markdown,
            self::Github->value => self::Github,
            self::Hotspot->value => self::Hotspot,
            self::Sarif->value => self::Sarif,
            default => null,
        };
    }
}
