<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

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
     * Report whether this format feeds a parser or artifact store rather than a human terminal.
     *
     * Machine-readable output suppresses interactive chatter such as the
     * missing-config init offer so the payload stays parseable.
     *
     * @return bool - True for every format except the human-oriented text report.
     */
    public function isMachineReadable(): bool
    {
        // Only the plain text report targets a human at a terminal; json/sarif/github/hotspot/markdown/html feed tooling.
        return $this !== self::Text;
    }

    /**
     * Convert a CLI format string into the matching output format.
     *
     * @param string $rawInput - CLI format value to parse.
     *
     * @return self|null - Matching format, or null for unsupported input.
     */
    public static function fromInput(string $rawInput): ?self
    {
        // Unrecognised format strings yield null so the caller can reject CLI input rather than guess a default.
        return match ($rawInput) {
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
