<?php

declare(strict_types=1);

namespace GruffPhp\Engine\Analysis;

use GruffPhp\Engine\Source\SourceFile;
use GruffPhp\Results\Finding\Finding;

/**
 * Applies PHPCS and Psalm directives plus duplicate-call suppression under FAMILY-CONTRACT.md section 13c.
 * The analysis uses this after sensitive exclusions so the report can account for each removal.
 *
 * A PHPCS directive naming PreparedSQL can suppress the corresponding SQL finding on its line or alone above it.
 *
 * One exec-family call retains the more specific command-construction finding when both security rules report it.
 * The result includes audit rows by file and rule, with the per-finding reasons available to SARIF.
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
        $comments   = [];
        // A finding leaves only when supported directive text maps to its rule on its line or a preceding comment-marker line.
        foreach ($result->findings as $finding) {
            // A matching directive removes the finding from the visible report and leaves its audit reason.
            if ($this->isCoveredByComment($finding, $comments)) {
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
     * Checks comment tokens on the finding line and standalone comments immediately above it.
     *
     * @param Finding                                                                $finding  - Finding examined for an inline suppression.
     * @param array<string, array<int, list<array{text: string, standalone: bool}>>> $comments - Comment tokens indexed on demand by file and line.
     *
     * @return bool - True when recognized directive text maps to the finding's rule.
     */
    private function isCoveredByComment(Finding $finding, array &$comments): bool
    {
        $line = $finding->line;
        // Only mapped rules can be suppressed, and a file-level finding has no line that a directive can cover.
        if ($line === null || $line < 1 || !self::isMappedRule($finding->ruleId)) {
            return false;
        }
        $comments[$finding->filePath] ??= $this->readComments($finding->filePath);
        $fileComments = $comments[$finding->filePath];
        // Same-line directives can cover a finding where the caller wrote the suppression beside it.
        foreach ($fileComments[$line] ?? [] as $comment) {
            // Ignore the finding only when the directive actually names a check that covers it.
            if (self::hasCoveringComment($comment['text'], $finding->ruleId)) {
                return true;
            }
        }
        // A standalone comment just above the finding can express intent when the source line has no inline directive.
        foreach ($fileComments[$line - 1] ?? [] as $comment) {
            // A preceding comment beside other code belongs to that line and does not cover the next finding.
            if ($comment['standalone'] && self::hasCoveringComment($comment['text'], $finding->ruleId)) {
                return true;
            }
        }

        return false;
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
     * Indexes actual PHP comment fragments so directive-like string data has no effect.
     *
     * @param string $displayPath - Display path the finding carries.
     *
     * @return array<int, list<array{text: string, standalone: bool}>> - Comment fragments keyed by one-based source line.
     */
    private function readComments(string $displayPath): array
    {
        $absolutePath = $this->absolutePathByDisplayPath[$displayPath] ?? null;
        $contents     = $absolutePath !== null && is_readable($absolutePath) ? file_get_contents($absolutePath) : false;

        // Keep findings visible if the source cannot be read to verify its suppression comment.
        if ($contents === false) {
            return [];
        }

        $lines    = explode("\n", $contents);
        $comments = [];
        // Read PHP tokens so examples in strings and heredocs cannot suppress findings.
        foreach (token_get_all($contents) as $token) {
            // Only real PHP comments count as evidence of the caller's suppression.
            if (!is_array($token) || !in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            // Preserve the exact line of a directive in a block comment.
            foreach (explode("\n", $token[1]) as $offset => $text) {
                $line              = $token[2] + $offset;
                $comments[$line][] = ['text' => $text, 'standalone' => trim($lines[$line - 1] ?? '') === trim($text)];
            }
        }

        return $comments;
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
        // A rule with no mapped PHPCS sniff cannot use a PHPCS directive.
        if ($sniff === false) {
            return false;
        }
        // With no parsed sniff reference, the directive text is treated as covering every configured phpcs sniff.
        if ($matched === 0) {
            return true;
        }
        // Accept a sniff, an individual error code, or the documented enclosing category in the directive.
        foreach ($references[0] as $reference) {
            // Match only the configured sniff or its standard/category family so unrelated checks stay visible.
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
            // A Psalm directive suppresses only the matching issue family for the reported rule.
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
            // Keep the more specific command-construction finding available when the broader rule reports the same call.
            if ($finding->ruleId === 'security.process-command-construction') {
                $key                = self::callKey($finding, $finding->metadata['sink'] ?? null);
                $commandCalls[$key] = ($commandCalls[$key] ?? 0) + 1;
            }
        }
        $survivors = [];
        $merged    = [];
        // Compare each broader finding with the specific command report before counting a duplicate.
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
        // Combine removed findings by file and rule so callers can see what was suppressed without one row per hit.
        foreach ($removed as $item) {
            $fileAndRule          = $item['finding']->filePath . "\0" . $item['finding']->ruleId;
            $counts[$fileAndRule] = ($counts[$fileAndRule] ?? 0) + 1;
        }
        uksort($counts, strcmp(...));
        $rows = [];
        // Add one audit row for each affected file and rule after the configured exclusions.
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
