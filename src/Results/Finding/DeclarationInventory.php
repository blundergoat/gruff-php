<?php

declare(strict_types=1);

namespace GruffPhp\Results\Finding;

use GruffPhp\Support\PathHelper;

/**
 * The places in a file where a variable symbol such as `$value` can be reported, so its ordinal counts those places
 * rather than only the ones that warned this run.
 *
 * PHP forbids declaring a class, method, function, property, or constant twice in one file, so those symbols always take
 * ordinal 1 and need no inventory. A variable symbol is different: `docs.var-annotation-description` reports each inline
 * `@var` assertion, and `naming.identifier-quality` and `naming.abbreviation-allowlist` each declared property, so one
 * file can hold several occurrences.
 * Ranked only among the occurrences that warned, a clean earlier `@var $value` would take no slot, and the day it started
 * warning it would take the later occurrence's ordinal and stored review.
 *
 * The inventory is read from source text, because a streamed or cached run no longer holds each file's syntax tree. It
 * lists every docblock naming the variable in an `@var` tag, except one that documents a declaration, and every line
 * declaring a property or promoted parameter of that name. Listing a few more places than the rules can report keeps the
 * ordinal independent of which findings fired.
 */
final readonly class DeclarationInventory
{
    /**
     * An `@var` tag naming a variable, as `docs.var-annotation-description` reads it from each docblock line.
     */
    private const VAR_TAG_PATTERN = '/^@var\b.*?\$(?<variable>[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)/u';

    /**
     * The start of a line declaring properties or a promoted constructor parameter: optional attribute groups such as
     * `#[Column]`, then visibility or `var` keywords; the declared names follow, up to the statement's `;`.
     */
    private const PROPERTY_LINE_PATTERN = '/^\s*(?:#\[[^\]]*\]\s*)*(?:(?:public|protected|private|var|static|readonly)(?:\(set\))?\s+)+(?<declared>[^;]*)/u';

    /**
     * One declared variable name, as `docs.var-annotation-description` reads it.
     */
    private const VARIABLE_NAME_PATTERN = '/\$(?<name>[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)/u';

    /**
     * Code that opens a property, function, class-like or constant declaration once attribute groups are passed; the
     * `@var` rule exempts a docblock on any of these.
     */
    private const DECLARATION_START_PATTERN = '/^\s*(?:#\[[^\]]*\]\s*)*(?:public|protected|private|var|abstract|final|function|class|interface|trait|enum|const)\b/u';

    /**
     * Keeps the scanned positions of every variable symbol in each file.
     *
     * @param array<string, array<string, list<int>>> $positions - Display path, then `$name` symbol, then ascending 1-based lines.
     */
    public function __construct(private array $positions = [])
    {
    }

    /**
     * Scans every file that carries a variable-symbol finding, reading each from the project root.
     *
     * A file that cannot be read contributes no positions, which leaves its variables ranked among their findings alone,
     * exactly as before this inventory existed.
     *
     * @param string        $projectRoot - Root the findings' display paths resolve against.
     * @param list<Finding> $findings - The run's findings; only those naming a variable symbol select a file to scan.
     *
     * @return self - Positions for every scanned file, keyed by the findings' own display paths.
     */
    public static function forFindings(string $projectRoot, array $findings): self
    {
        $positions = [];

        // Only a variable symbol has more than one possible occurrence per file, so only its file is read.
        foreach ($findings as $finding) {
            $symbol = (string)$finding->symbol;

            if (!str_starts_with($symbol, '$') || isset($positions[$finding->filePath])) {
                continue;
            }

            $path   = PathHelper::isAbsolute($finding->filePath) ? $finding->filePath : rtrim($projectRoot, '/') . '/' . $finding->filePath;
            $source = is_file($path) ? file_get_contents($path) : false;

            $positions[$finding->filePath] = $source === false ? [] : self::scan($source);
        }

        return new self($positions);
    }

    /**
     * Lists, for each variable symbol, the start line of every docblock with an `@var` tag naming it, unless the docblock
     * documents a declaration, and every line declaring a property or promoted parameter of that name.
     *
     * @param string $source - One file's PHP source.
     *
     * @return array<string, list<int>> - `$name` symbol to ascending, distinct 1-based lines.
     */
    public static function scan(string $source): array
    {
        $positions = [];

        // Find every `/** ... */` docblock with its offset; its start line is where `docs.var-annotation-description` reports it.
        if (preg_match_all('/\/\*\*.*?\*\//s', $source, $docblocks, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($docblocks[0] as [$docText, $offset]) {
                // A docblock on a property, function, class-like or constant documents it, and the `@var` rule never reports it.
                if (preg_match(self::DECLARATION_START_PATTERN, substr($source, $offset + strlen($docText))) === 1) {
                    continue;
                }

                $line = substr_count($source, "\n", 0, $offset) + 1;

                foreach (preg_split('/\R/', $docText) ?: [] as $docLine) {
                    // Match an `@var` tag line and capture the variable it names.
                    if (preg_match(self::VAR_TAG_PATTERN, trim($docLine, " \t\n\r\0\x0B/*"), $matches) === 1) {
                        $positions['$' . $matches['variable']][$line] = true;
                    }
                }
            }
        }

        // A property's own line is where `naming.identifier-quality` reports it.
        foreach (preg_split('/\R/', $source) ?: [] as $index => $sourceLine) {
            // Match a visibility-led property or promoted-parameter line; a function signature is skipped.
            if (preg_match('/\bfunction\b/', $sourceLine) !== 1 && preg_match(self::PROPERTY_LINE_PATTERN, $sourceLine, $matches) === 1) {
                // Capture every name the line declares, so the second property of `private int $a, $b;` is listed too.
                preg_match_all(self::VARIABLE_NAME_PATTERN, $matches['declared'], $names);

                foreach ($names['name'] as $name) {
                    $positions['$' . $name][$index + 1] = true;
                }
            }
        }

        $sorted = [];

        foreach ($positions as $symbol => $lines) {
            $ordered = array_keys($lines);
            sort($ordered);
            $sorted[$symbol] = $ordered;
        }

        return $sorted;
    }

    /**
     * Lists the scanned lines for one variable symbol in one file.
     *
     * @param string $filePath - Display path exactly as the finding carries it.
     * @param string $symbol - Variable symbol such as `$value`.
     *
     * @return list<int> - Ascending lines; empty when the file was not scanned or never names the variable.
     */
    public function positionsFor(string $filePath, string $symbol): array
    {
        return $this->positions[$filePath][$symbol] ?? [];
    }
}
