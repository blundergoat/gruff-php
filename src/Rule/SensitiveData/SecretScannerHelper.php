<?php

declare(strict_types=1);

namespace GruffPhp\Rule\SensitiveData;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;

/**
 * Provides shared string and finding helpers for sensitive-data scanners.
 */
final class SecretScannerHelper
{
    /**
     * Cache of comment-range tuples keyed by analysis-unit display path. Computed once per unit.
     *
     * @var array<string, list<array{0:int,1:int}>>
     */
    private static array $commentRangeCache = [];

    /**
     * List key-name fragments that mark literals as secret-like.
     *
     * @return list<string> - upper-cased key-name fragments shared by every scanner as secret context
     */
    public static function sensitiveKeyFragments(): array
    {
        // The shared allowlist of key-name fragments every scanner treats as secret-context; edit here to widen all.
        return ['API_KEY', 'KEY', 'PASSWORD', 'PASS', 'SECRET', 'TOKEN', 'PRIVATE'];
    }

    /**
     * Build the list of comment byte ranges for an analysis unit so pattern rules can skip in-comment matches.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit whose tokens describe comment spans.
     *
     * @return list<array{0:int,1:int}> - ordered [startOffset, endOffsetExclusive] half-open spans callers test offsets against
     */
    public static function commentRanges(AnalysisUnit $analysisUnit): array
    {
        $cacheKey = $analysisUnit->file->displayPath;

        if (isset(self::$commentRangeCache[$cacheKey])) {
            // Reuse the per-unit scan; ranges are immutable for a given source, so recomputing would only cost time.
            return self::$commentRangeCache[$cacheKey];
        }

        $ranges = [];

        foreach ($analysisUnit->tokens as $token) {
            if ($token->id === T_COMMENT || $token->id === T_DOC_COMMENT) {
                $ranges[] = [$token->pos, $token->getEndPos()];
            }
        }

        // Comment token spans never change once a source is parsed, so memoise the computed ranges in
        // $commentRangeCache keyed by $cacheKey; a repeat commentRanges() call for the same unit returns the
        // cached list instead of re-walking the whole token stream.
        return self::$commentRangeCache[$cacheKey] = $ranges;
    }

    /**
     * Check whether a source-text byte offset falls inside one of the given comment ranges.
     *
     * @param int                      $offset - Zero-based byte offset of a pattern match.
     * @param list<array{0:int,1:int}> $ranges - Comment ranges produced by commentRanges().
     *
     * @return bool - true when the offset falls inside a comment span, signalling the caller to skip the match
     */
    public static function isInsideComment(int $offset, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($offset >= $start && $offset < $end) {
                // Half-open interval: the match starts inside this comment span, so the caller must skip it.
                return true;
            }
        }

        // Offset sits in no comment span, so it is live source the caller should keep scanning.
        return false;
    }

    /**
     * Compute the 1-based line number for a byte offset within a source string.
     *
     * @param string $source - Source text being scanned.
     * @param int    $offset - Zero-based byte offset inside the source text.
     *
     * @return int - 1-based line number containing the offset, as findings and editors expect
     */
    public static function lineNumberForOffset(string $source, int $offset): int
    {
        // Newlines before the offset, plus one, give the 1-based line findings and editors expect.
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    /**
     * Build a redacted preview of a sensitive value (first 4 + last 4 chars for values longer than 8 chars).
     *
     * @param string $secretValue - Sensitive value to redact for reporting.
     *
     * @return string - redacted preview (length marker, or first/last 4 chars) safe to embed in findings and reports
     */
    public static function redactedPreview(string $secretValue): string
    {
        $length = strlen($secretValue);
        if ($length <= 8) {
            // Too short to show any edge without leaking most of it, so disclose only the length.
            return sprintf('<redacted:%d chars>', $length);
        }

        // Show only the first and last 4 chars: enough to recognise the value, never enough to reconstruct it.
        return sprintf('%s...%s (redacted, %d chars)', substr($secretValue, 0, 4), substr($secretValue, -4), $length);
    }

    /**
     * Build a `KEY=<redacted:N chars>` string for env-style secret findings.
     *
     * @param string $key - Environment-style key name.
     * @param string $secretValue - Sensitive value associated with the key.
     *
     * @return string - `KEY=<redacted:N chars>` with the key kept visible and only the value's byte length disclosed
     */
    public static function redactedKeyValue(string $key, string $secretValue): string
    {
        // Keep the key visible for context but disclose only the value's length, never any of its bytes.
        return sprintf('%s=<redacted:%d chars>', $key, strlen($secretValue));
    }

    /**
     * Detect whether the value looks like a placeholder rather than a real secret (changeme / dummy / example / etc.).
     *
     * @param string $secretValue - Candidate sensitive value.
     *
     * @return bool - true when the value is empty, low-cardinality, or a placeholder, so the caller suppresses it
     */
    public static function isLikelyDummyValue(string $secretValue): bool
    {
        $normalized = strtolower(trim($secretValue, "\"' \t\r\n"));
        if ($normalized === '') {
            // An empty or quote-only literal carries no secret, so treat it as a placeholder and suppress.
            return true;
        }

        foreach (['changeme', 'dummy', 'example', 'fake', 'placeholder', 'redacted', 'sample', 'test'] as $marker) {
            if (str_contains($normalized, $marker)) {
                // A known placeholder word anywhere in the value marks it as documentation, not a live credential.
                return true;
            }
        }

        // One or two distinct characters (e.g. "aaaa", "xxxxxxxx") cannot be a real secret; treat as a placeholder.
        return count(array_unique(str_split($normalized))) <= 2;
    }

    /**
     * Detect whether the file's basename is `.env` or `.env.*`.
     *
     * @param string $displayPath - Project-relative path being scanned.
     *
     * @return bool - true when the basename is `.env` or a `.env.*` variant; callers relax dummy-value filtering
     */
    public static function isEnvFile(string $displayPath): bool
    {
        $basename = basename($displayPath);

        // Match `.env` and variants like `.env.local`; callers relax dummy-value filtering for these files.
        return $basename === '.env' || str_starts_with($basename, '.env.');
    }

    /**
     * Detect whether the path lives under a test or fixtures directory (test, tests, fixture, fixtures).
     *
     * @param string $displayPath - Project-relative path being scanned.
     *
     * @return bool - true when any path segment is a test or fixtures directory; callers downgrade secrets found there
     */
    public static function isTestPath(string $displayPath): bool
    {
        $normalized = '/' . str_replace('\\', '/', $displayPath);

        // True when any path segment is a test or fixtures directory; callers downgrade secrets found under these.
        return str_contains($normalized, '/test/')
               || str_contains($normalized, '/tests/')
               || str_contains($normalized, '/fixture/')
               || str_contains($normalized, '/fixtures/');
    }

    /**
     * Detect whether the line contains an upper-cased secret-context fragment (API_KEY, PASSWORD, etc.).
     *
     * @param string $line - Source line being scanned.
     *
     * @return bool - true when the line contains a secret-context fragment that raises detector confidence
     */
    public static function hasSensitiveContext(string $line): bool
    {
        $upperLine = strtoupper($line);

        foreach (self::sensitiveKeyFragments() as $fragment) {
            if (str_contains($upperLine, $fragment)) {
                // A secret-context fragment on the line raises confidence that a nearby literal is a credential.
                return true;
            }
        }

        // No secret-context fragment present, so the line gives the detector no reason to escalate.
        return false;
    }

    /**
     * Compute the Shannon entropy of a string in bits-per-character.
     *
     * @param string $secretValue - Candidate secret value.
     *
     * @return float - bits-per-character Shannon entropy; callers compare it against a threshold to flag secret-shaped literals
     */
    public static function entropy(string $secretValue): float
    {
        $length = strlen($secretValue);
        if ($length === 0) {
            // An empty string carries zero Shannon entropy by definition; returning here also keeps the
            // per-character probability quotient $count / $length well-defined, since $length is its divisor.
            return 0.0;
        }

        $counts  = count_chars($secretValue, 1);
        $entropy = 0.0;

        foreach ($counts as $count) {
            $probability = $count / $length;
            $entropy     -= $probability * log($probability, 2);
        }

        // Bits per character: callers compare this against an entropy threshold to flag secret-shaped literals.
        return $entropy;
    }

    /**
     * Build a sensitive-data Finding with redacted preview / detector metadata.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit that owns the finding.
     * @param string       $ruleId - Sensitive-data rule identifier.
     * @param string       $message - Human-readable finding message.
     * @param int          $line - Source line for the detected secret.
     * @param Confidence   $confidence - Confidence level assigned by the detector.
     * @param string       $detector - Detector name written to finding metadata.
     * @param string       $preview - Redacted preview written to finding metadata.
     * @param string       $remediation - Suggested remediation text for the finding.
     *
     * @return Finding - a SensitiveData/Warning finding carrying the detector name and redacted preview in metadata
     */
    public static function finding(
        AnalysisUnit $analysisUnit,
        string       $ruleId,
        string       $message,
        int          $line,
        Confidence   $confidence,
        string       $detector,
        string       $preview,
        string       $remediation,
    ): Finding {
        // Every sensitive-data hit is a SensitiveData/Warning finding; detector and redacted preview ride in metadata.
        return new Finding(
            ruleId:      $ruleId,
            message:     $message,
            filePath:    $analysisUnit->file->displayPath,
            line:        $line,
            severity:    Severity::Warning,
            pillar:      Pillar::SensitiveData,
            tier:        RuleTier::V01,
            confidence:  $confidence,
            remediation: $remediation,
            metadata:    [
                             'detector' => $detector,
                             'preview'  => $preview,
                         ],
        );
    }
}
