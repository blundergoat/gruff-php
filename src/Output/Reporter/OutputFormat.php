<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

/**
 * Defines the report formats accepted by `analyse --format`.
 * fromInput() validates a requested value and returns null when no case matches.
 * Non-text formats also suppress the missing-config offer through isMachineReadable().
 */
enum OutputFormat: string
{
    /**
     * The default `--format text` report a person reads straight in their terminal after a local run.
     */
    case Text = 'text';

    /**
     * `--format json` - the full analysis payload as machine-readable JSON for CI gates and editors.
     */
    case Json = 'json';

    /**
     * `--format html` - a self-contained report a user opens in a browser to share or browse findings.
     */
    case Html = 'html';

    /**
     * `--format markdown` - report text a user pastes into an issue or pull-request comment.
     */
    case Markdown = 'markdown';

    /**
     * `--format github` - workflow annotations so findings surface inline on a pull request's diff.
     */
    case Github = 'github';

    /**
     * `--format hotspot` - a JSON map ranking the files that most need attention, worst offenders first.
     */
    case Hotspot = 'hotspot';

    /**
     * `--format sarif` - the standard SARIF document a code-scanning dashboard ingests.
     */
    case Sarif = 'sarif';

    /**
     * Marks formats that must suppress the interactive missing-config offer.
     * HTML and Markdown return true along with machine-oriented formats; only Text returns false.
     *
     * @return bool - True for every format except the human-oriented text report; false only for the plain text case.
     */
    public function isMachineReadable(): bool
    {

        return $this !== self::Text;
    }

    /**
     * Turns the raw --format string a user typed into the matching enum case, or reports that it
     * matched nothing. Called at the CLI boundary so an unsupported flag fails fast with a usage
     * error instead of silently defaulting to a format the user never asked for.
     *
     * @param string $rawInput - The raw --format value the user typed; an empty or unknown string matches no case and returns null.
     *
     * @return self|null - The matching format; null when the string names no supported format, so the caller rejects the flag rather than guessing.
     */
    public static function fromInput(string $rawInput): ?self
    {

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
