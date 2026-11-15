<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Analysis;

use GruffPhp\Engine\Source\SourceFile;
use GruffPhp\Results\Finding\Finding;

/**
 * Applies FAMILY-CONTRACT.md section 13c after the sensitive exclusions: another tool's suppression comment, and a
 * second rule repeating one claim on one call.
 *
 * - A phpcs or psalm comment naming a check that makes the finding's claim suppresses it where it was written, when
 *   the comment is on the reported line or alone on the line above, e.g. `// phpcs:ignore WordPress.DB.PreparedSQL`
 *   above a `$wpdb->query()` hides security.sql-concatenation there and nothing else.
 * - An exec-family call reported by both security.dangerous-function-call and security.process-command-construction
 *   reports once, as security.process-command-construction, the narrower claim that the command is built at run time.
 * - Each removal is counted in one audit row per rule and file, after the built-in rows, and kept for SARIF.
 */
final readonly class InlineSuppressionFilter
{
    /**
     * Reason on each inline audit row; every port publishes these exact words (FAMILY-CONTRACT.md section 13c).
     */
    public const INLINE_REASON = "Another tool's suppression comment names this check, so the finding is suppressed where it was written.";

    /**
     * Reason on each repeated-claim audit row; every port publishes these exact words (FAMILY-CONTRACT.md section 13c).
     */
    public const DUPLICATE_REASON = 'Another rule reports the same claim on this line, so the line reports it once.';

    /**
     * phpcs sniffs section 13c names for gruff-php, each with the rule making the same claim.
     *
     * @var array<string, string> - Gruff rule ids keyed by phpcs sniff name.
     */
    private const PHPCS_SNIFF_RULES = [
        'WordPress.PHP.NoSilencedErrors' => 'security.error-suppression',
        'WordPress.DB.PreparedSQL' => 'security.sql-concatenation',
    ];

    /**
     * psalm issues section 13c names for gruff-php, each with the rules making the same claim.
     *
     * @var array<string, list<string>> - Gruff rule ids grouped by psalm issue name.
     */
    private const PSALM_ISSUE_RULES = [
        'ForbiddenCode' => ['security.dangerous-function-call', 'security.process-command-construction'],
    ];

    /**
     * Built-in calls on which security.dangerous-function-call repeats security.process-command-construction's claim.
     *
     * @var list<string> - Function names eligible for repeated-claim merging.
     */
    private const EXEC_FAMILY = ['exec', 'passthru', 'popen', 'proc_open', 'shell_exec', 'system'];

    /**
     * Holds where each reported file lives, so only files with a finding a comment could cover are read.
     *
     * @param array<string, string> $absolutePathByDisplayPath - Absolute path of each discovered file, keyed by the display path a finding carries.
     */
    public function __construct(private array $absolutePathByDisplayPath)
    {
    }

    /**
     * Builds the filter for one discovery result.
     *
     * @param list<SourceFile> $files - Files the run discovered.
     *
     * @return self - A filter that can read any of those files by its display path.
     */
    public static function forFiles(array $files): self
    {
        $paths = [];
        // A finding names its file by display path, so the lookup is keyed the same way.
        foreach ($files as $file) {
            $paths[$file->displayPath] = $file->absolutePath;
        }

        return new self($paths);
    }

    /**
     * Removes findings matched by supported suppression-directive text, then repeated claims, and counts both.
     *
     * @param SensitiveExclusionResult $result - Findings and audit rows left after the sensitive exclusions and the test-path skip.
     *
     * @return SensitiveExclusionResult - Survivors; prior, inline, then duplicate audit rows; prior and newly suppressed findings.
     */
    public function apply(SensitiveExclusionResult $result): SensitiveExclusionResult
    {
        $survivors  = [];
        $suppressed = [];
        $lines      = [];
        // A finding leaves only when supported directive text maps to its rule on its line or a preceding comment-marker line.
        foreach ($result->findings as $finding) {
            if ($this->isCoveredByComment($finding, $lines)) {
                $suppressed[] = ['finding' => $finding, 'justification' => self::INLINE_REASON];
                continue;
            }
            $survivors[] = $finding;
        }

        [$survivors, $merged] = $this->mergeRepeatedClaims($survivors);
        $summaries            = $result->summaries;
        // Built-in rows number among themselves; section 13c rows continue that sequence, apart from configured rows.
        $nextIndex = count(array_filter($summaries, static fn (SensitiveExclusionSummary $summary): bool => $summary->source === 'built-in'));
        $summaries = [...$summaries, ...self::rows($suppressed, 'inline', self::INLINE_REASON, $nextIndex)];
        $summaries = [...$summaries, ...self::rows($merged, 'duplicate', self::DUPLICATE_REASON, $nextIndex + count($summaries) - count($result->summaries))];

        return new SensitiveExclusionResult($survivors, $summaries, [...$result->suppressedFindings, ...$suppressed, ...$merged]);
    }

    /**
     * Reports whether supported directive text maps to the finding on its line or on a preceding comment-marker line.
     *
     * @param Finding                     $finding - Finding whose path, line, and rule are checked for a covering directive.
     * @param array<string, list<string>> $lines   - Lines of each file read so far, keyed by display path; filled on demand.
     *
     * @return bool - True when recognized directive text maps to the finding's rule.
     */
    private function isCoveredByComment(Finding $finding, array &$lines): bool
    {
        $line = $finding->line;
        // Only mapped rules can be suppressed, and a file-level finding has no line that a directive can cover.
        if ($line === null || $line < 1 || !self::isMappedRule($finding->ruleId)) {
            return false;
        }
        $lines[$finding->filePath] ??= $this->readLines($finding->filePath);
        $fileLines = $lines[$finding->filePath];
        if (self::hasCoveringComment($fileLines[$line - 1] ?? '', $finding->ruleId)) {
            return true;
        }
        $above = trim($fileLines[$line - 2] ?? '');

        return self::isCommentOnly($above) && self::hasCoveringComment($above, $finding->ruleId);
    }

    /**
     * Checks whether a rule has a supported PHPCS or Psalm inline-suppression mapping.
     *
     * @param string $ruleId - Rule id of a finding.
     *
     * @return bool - True when a configured phpcs or Psalm directive can suppress the rule.
     */
    private static function isMappedRule(string $ruleId): bool
    {
        return in_array($ruleId, self::PHPCS_SNIFF_RULES, true) || in_array($ruleId, self::PSALM_ISSUE_RULES['ForbiddenCode'], true);
    }

    /**
     * Reads one discovered file's lines; an unreadable file has none, so nothing in it is suppressed.
     *
     * @param string $displayPath - Display path the finding carries.
     *
     * @return list<string> - The file's lines without their line endings.
     */
    private function readLines(string $displayPath): array
    {
        $absolutePath = $this->absolutePathByDisplayPath[$displayPath] ?? null;
        $contents     = $absolutePath !== null && is_readable($absolutePath) ? file_get_contents($absolutePath) : false;

        // Splits on any line ending, so a CRLF file numbers its lines the way the parser does.
        return $contents === false ? [] : (preg_split('/\R/', $contents) ?: []);
    }

    /**
     * Reports whether a trimmed line starts with a supported comment marker, allowing a directive there to cover the line below.
     *
     * @param string $trimmed - The line with surrounding whitespace removed.
     *
     * @return bool - True when the line starts with `//`, `#`, `/*` or `*`.
     */
    private static function isCommentOnly(string $trimmed): bool
    {
        return str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '*');
    }

    /**
     * Reports whether a source line contains supported phpcs or psalm directive text that maps to the rule.
     *
     * @param string $line   - Source line to read.
     * @param string $ruleId - Rule id of the finding.
     *
     * @return bool - True when the line's parsed directive text maps to the rule.
     */
    private static function hasCoveringComment(string $line, string $ruleId): bool
    {
        return self::isIgnoredByPhpcs($line, $ruleId) || self::isSuppressedByPsalm($line, $ruleId);
    }

    /**
     * Reports whether a line contains `phpcs:ignore` text that the configured sniff mapping treats as covering the rule.
     *
     * @param string $line   - Source line to read.
     * @param string $ruleId - Rule id of the finding.
     *
     * @return bool - True when the rule has a configured sniff and the parsed text names it or names no sniff.
     */
    private static function isIgnoredByPhpcs(string $line, string $ruleId): bool
    {
        // Keep the directive tail so the sniff list can be separated from an optional reason or block-comment close.
        if (preg_match('/phpcs:ignore\b(.*)$/', $line, $match) !== 1) {
            return false;
        }
        $sniff = array_search($ruleId, self::PHPCS_SNIFF_RULES, true);
        // The sniff list ends at phpcs's ` --` reason separator or a block comment's close.
        $listed = preg_split('/\s--|\*\//', $match[1])[0] ?? '';
        // A reference can name a whole standard, a category, a sniff or one error code.
        $matched = preg_match_all('/[A-Za-z][\w]*(?:\.\w+)*/', $listed, $references);
        if ($sniff === false) {
            return false;
        }
        // With no parsed sniff reference, the directive text is treated as covering every configured phpcs sniff.
        if ($matched === 0) {
            return true;
        }
        foreach ($references[0] as $reference) {
            if ($reference === $sniff || str_starts_with($reference, $sniff . '.') || str_starts_with($sniff, $reference . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether a line contains `@psalm-suppress` text whose parsed issue names map to the rule.
     *
     * @param string $line   - Source line to read.
     * @param string $ruleId - Rule id of the finding.
     *
     * @return bool - True when one parsed issue name maps to the rule.
     */
    private static function isSuppressedByPsalm(string $line, string $ruleId): bool
    {
        // Limit candidates to directive tokens so only configured Psalm issue names can suppress the rule.
        if (preg_match('/@psalm-suppress\s+([\w\s,]+)/', $line, $match) !== 1) {
            return false;
        }
        // Whitespace and commas delimit Psalm issue names; only names mapped to this rule suppress it.
        foreach (preg_split('/[\s,]+/', trim($match[1])) ?: [] as $issue) {
            if (in_array($ruleId, self::PSALM_ISSUE_RULES[$issue] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merges a dangerous-function finding when its file, line, and exec-family function match a command-construction finding.
     *
     * @param list<Finding> $findings - Findings left after the inline comments.
     *
     * @return array{0: list<Finding>, 1: list<array{finding: Finding, justification: string}>} - Survivors, then each merged finding.
     */
    private function mergeRepeatedClaims(array $findings): array
    {
        $commandCalls = [];
        // Count command-construction findings by file, line, and sink so each count removes at most one repeat.
        foreach ($findings as $finding) {
            if ($finding->ruleId === 'security.process-command-construction') {
                $key                = self::callKey($finding, $finding->metadata['sink'] ?? null);
                $commandCalls[$key] = ($commandCalls[$key] ?? 0) + 1;
            }
        }
        $survivors = [];
        $merged    = [];
        foreach ($findings as $finding) {
            $function = $finding->metadata['function'] ?? null;
            $key      = self::callKey($finding, $function);
            // Merge only when an exec-family function has the same file, line, and sink key as a command-construction finding.
            if ($finding->ruleId === 'security.dangerous-function-call' && in_array($function, self::EXEC_FAMILY, true) && ($commandCalls[$key] ?? 0) > 0) {
                $commandCalls[$key]--;
                $merged[] = ['finding' => $finding, 'justification' => self::DUPLICATE_REASON];
                continue;
            }
            $survivors[] = $finding;
        }

        return [$survivors, $merged];
    }

    /**
     * Builds a grouping key from the finding's file, line, and called-function metadata.
     *
     * @param Finding $finding  - Finding that supplies the file and line key components.
     * @param mixed   $function - Called-function metadata; a non-string value uses an empty key component.
     *
     * @return string - File, line, and called-function key used to group potentially repeated findings.
     */
    private static function callKey(Finding $finding, mixed $function): string
    {
        return $finding->filePath . "\0" . (string)$finding->line . "\0" . (is_string($function) ? $function : '');
    }

    /**
     * Folds removed findings into one audit row per file and rule, in byte order of path and then rule.
     *
     * @param list<array{finding: Finding, justification: string}> $removed    - Findings one section 13c class removed.
     * @param string                                               $source     - `inline` or `duplicate`.
     * @param string                                               $reason     - The class's exact audit reason.
     * @param int                                                  $firstIndex - Index of the first row this class adds.
     *
     * @return list<SensitiveExclusionSummary> - One row per file and rule.
     */
    private static function rows(array $removed, string $source, string $reason, int $firstIndex): array
    {
        $counts = [];
        foreach ($removed as $item) {
            $fileAndRule          = $item['finding']->filePath . "\0" . $item['finding']->ruleId;
            $counts[$fileAndRule] = ($counts[$fileAndRule] ?? 0) + 1;
        }
        uksort($counts, strcmp(...));
        $rows = [];
        foreach ($counts as $fileAndRule => $count) {
            [$filePath, $ruleId] = explode("\0", (string)$fileAndRule, 2);
            $rows[]              = new SensitiveExclusionSummary(
                index:      $firstIndex + count($rows),
                rule:       $ruleId,
                path:       $filePath,
                symbol:     null,
                reason:     $reason,
                suppressed: $count,
                source:     $source,
            );
        }

        return $rows;
    }
}
