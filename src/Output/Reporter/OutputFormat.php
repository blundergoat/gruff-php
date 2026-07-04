<?php

declare(strict_types=1);

namespace GruffPhp\Output\Reporter;

/**
 * The closed set of report shapes the `analyse` command can emit - every value a user may pass to
 * `--format`, from the plain terminal report a person reads to machine outputs like JSON, SARIF,
 * and the GitHub annotation stream other tools consume. Reach for this enum wherever a raw
 * `--format` string must be validated into a real choice, or wherever the pipeline decides between
 * addressing a human and handing structured data to a script. Anything outside this list - a bad
 * flag like `--format=xml` - is unsupported and gets rejected at the CLI boundary rather than guessed.
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
     * Reports whether this format hands structured output to a tool rather than to a person at a
     * terminal. The pipeline reads it to stay silent in machine modes - suppressing chatter like the
     * missing-config init offer - so nothing corrupts a JSON or SARIF payload a script is about to parse.
     *
     * @return bool - True for every format except the human-oriented text report; false only for the plain text case.
     */
    public function isMachineReadable(): bool
    {
        // Only the plain text report speaks to a human at a terminal; every other format feeds a tool, so it counts as machine-readable.
        return $this !== self::Text;
    }

    /**
     * Turns the raw `--format` string a user typed into the matching enum case, or reports that it
     * matched nothing. Called at the CLI boundary so an unsupported flag fails fast with a usage
     * error instead of silently defaulting to a format the user never asked for.
     *
     * @param string $rawInput - The raw `--format` value the user typed; an empty or unknown string matches no case and returns null.
     *
     * @return self|null - The matching format; null when the string names no supported format, so the caller rejects the flag rather than guessing.
     */
    public static function fromInput(string $rawInput): ?self
    {
        // A typo or unsupported flag like `--format=xml` matches no arm and falls to `null`, so the caller can reject it instead of guessing a default.
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
