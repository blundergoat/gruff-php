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
     * @var array<string, string>
     */
    private const PHPCS_SNIFF_RULES = [
        'WordPress.PHP.NoSilencedErrors' => 'security.error-suppression',
        'WordPress.DB.PreparedSQL' => 'security.sql-concatenation',
    ];

    /**
     * psalm issues section 13c names for gruff-php, each with the rules making the same claim.
     *
     * @var array<string, list<string>>
     */
    private const PSALM_ISSUE_RULES = [
        'ForbiddenCode' => ['security.dangerous-function-call', 'security.process-command-construction'],
    ];

    /**
     * Built-in calls on which security.dangerous-function-call repeats security.process-command-construction's claim.
     *
     * @var list<string>
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
     * Removes the findings another tool's comment covers, then the repeated claims, and counts both.
     *
     * @param SensitiveExclusionResult $result - Findings and audit rows left after the sensitive exclusions and the test-path skip.
     *
     * @return SensitiveExclusionResult - Survivors, the earlier rows followed by the inline and then the repeated-claim rows, and every removed finding.
     */
    public function apply(SensitiveExclusionResult $result): SensitiveExclusionResult
    {
        $survivors  = [];
        $suppressed = [];
        $lines      = [];
        // A finding leaves only when a comment on its own line, or alone on the line above, names a check making its claim.
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
     * Reports whether a comment on the finding's line, or alone on the line above, names a check making its claim.
     *
     * @param Finding                     $finding - Finding to judge.
     * @param array<string, list<string>> $lines   - Lines of each file read so far, keyed by display path; filled on demand.
     *
     * @return bool - true when the finding is suppressed where it was written
     */
    private function isCoveredByComment(Finding $finding, array &$lines): bool
    {
        $line = $finding->line;
        // Only the four rules section 13c maps can be covered, and a file-level finding has no line to carry a comment.
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
     * Reports whether a rule appears in section 13c's table for gruff-php.
     *
     * @param string $ruleId - Rule id of a finding.
     *
     * @return bool - true for the four rules a phpcs or psalm comment can cover
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
     * Reports whether a line holds only a comment, so a directive on it speaks for the line below.
     *
     * @param string $trimmed - The line with surrounding whitespace removed.
     *
     * @return bool - true for a `//`, `#`, `/*` or docblock `*` line
     */
    private static function isCommentOnly(string $trimmed): bool
    {
        return str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '*');
    }

    /**
     * Reports whether one line's phpcs or psalm comment names a check making the rule's claim.
     *
     * @param string $line   - Source line to read.
     * @param string $ruleId - Rule id of the finding.
     *
     * @return bool - true when the comment covers the rule
     */
    private static function hasCoveringComment(string $line, string $ruleId): bool
    {
        return self::isIgnoredByPhpcs($line, $ruleId) || self::isSuppressedByPsalm($line, $ruleId);
    }

    /**
     * Reports whether a `phpcs:ignore` on the line covers the rule: naming no sniff it covers every phpcs row, and a
     * named sniff, category or error code covers the row whose sniff it contains or is contained in.
     *
     * @param string $line   - Source line to read.
     * @param string $ruleId - Rule id of the finding.
     *
     * @return bool - true when phpcs would ignore the sniff section 13c maps to this rule
     */
    private static function isIgnoredByPhpcs(string $line, string $ruleId): bool
    {
        // Captures whatever follows `phpcs:ignore` on the line: its sniff list, reason and comment close.
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
        // A phpcs:ignore naming no sniff ignores every sniff on the line, as phpcs reads it.
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
     * Reports whether a `@psalm-suppress` on the line names an issue section 13c maps to the rule.
     *
     * @param string $line   - Source line to read.
     * @param string $ruleId - Rule id of the finding.
     *
     * @return bool - true when a named psalm issue makes the rule's claim
     */
    private static function isSuppressedByPsalm(string $line, string $ruleId): bool
    {
        // Captures the issue names after `@psalm-suppress`, separated by spaces or commas.
        if (preg_match('/@psalm-suppress\s+([\w\s,]+)/', $line, $match) !== 1) {
            return false;
        }
        // Each issue name stands alone between spaces or commas.
        foreach (preg_split('/[\s,]+/', trim($match[1])) ?: [] as $issue) {
            if (in_array($ruleId, self::PSALM_ISSUE_RULES[$issue] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merges security.dangerous-function-call into security.process-command-construction on one exec-family call.
     *
     * @param list<Finding> $findings - Findings left after the inline comments.
     *
     * @return array{0: list<Finding>, 1: list<array{finding: Finding, justification: string}>} - Survivors, then each merged finding.
     */
    private function mergeRepeatedClaims(array $findings): array
    {
        $commandCalls = [];
        // Count the command-construction findings per file, line and call, so each merges at most one repeat.
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
            // The dangerous-call finding repeats the claim only on an exec-family call the other rule reports.
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
     * Keys one call by file, line and called function.
     *
     * @param Finding $finding  - Finding on the call.
     * @param mixed   $function - Called function name from the finding's metadata.
     *
     * @return string - A key two findings on the same call share.
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
