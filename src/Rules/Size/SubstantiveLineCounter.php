<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Size;

use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use PhpParser\Node\AttributeGroup;
use WeakMap;

/**
 * Shared code-line counting for the file, class and test length rules: blank lines,
 * comment-only lines and attribute-only lines are free (FAMILY-CONTRACT section 12, search `Code lines in
 * every line count`), so required documentation can never push a file, class or test over a budget.
 *
 * Comment tokens and parsed attribute groups are masked out of the source (newlines preserved) before
 * counting non-blank lines, so a line holding both code and a trailing comment or an attribute still counts.
 * Units without parser tokens fall back to counting non-blank raw lines; syntax-error units can retain
 * tokens and receive the same comment masking, but have no parsed attributes to mask.
 */
final class SubstantiveLineCounter
{
    /**
     * Cumulative substantive-line counts keyed by analysis-unit identity.
     *
     * @var WeakMap<AnalysisUnit, list<int>>|null
     */
    private static ?WeakMap $prefixCache = null;

    /**
     * Counts the substantive lines of the whole unit.
     *
     * @param AnalysisUnit $analysisUnit - Unit whose source and comment tokens are inspected.
     *
     * @return int - Number of non-blank lines after comment masking.
     */
    public static function countAll(AnalysisUnit $analysisUnit): int
    {
        $prefix = self::substantiveLinePrefix($analysisUnit);

        return $prefix[count($prefix) - 1];
    }

    /**
     * Counts the substantive lines inside one inclusive 1-based line range.
     *
     * @param AnalysisUnit $analysisUnit - Unit whose source and comment tokens are inspected.
     * @param int          $startLine    - First line of the range, 1-based inclusive.
     * @param int          $endLine      - Last line of the range, 1-based inclusive.
     *
     * @return int - Number of non-blank lines after comment masking within the range.
     */
    public static function countRange(AnalysisUnit $analysisUnit, int $startLine, int $endLine): int
    {
        $prefix          = self::substantiveLinePrefix($analysisUnit);
        $lineCount       = count($prefix) - 1;
        $startIndex      = max(0, $startLine - 1);
        $requestedLength = max(0, $endLine - $startLine + 1);

        // Preserve the original array-slice bounds: an empty or out-of-source range contributes nothing.
        if ($requestedLength === 0 || $startIndex >= $lineCount) {
            return 0;
        }

        $endIndex = min($lineCount, $startIndex + $requestedLength);

        return $prefix[$endIndex] - $prefix[$startIndex];
    }

    /**
     * Drops the cached prefix for a unit whose source is about to be released.
     *
     * @param AnalysisUnit $analysisUnit - Unit to remove from the substantive-line cache.
     *
     * @return void
     */
    public static function evictUnit(AnalysisUnit $analysisUnit): void
    {
        // The lightweight unit shell can outlive its source, so remove the source-derived prefix explicitly.
        if (self::$prefixCache !== null) {
            unset(self::$prefixCache[$analysisUnit]);
        }
    }

    /**
     * Builds and memoises cumulative substantive-line counts for one unit.
     *
     * Prefix index zero is always zero; index N stores the substantive count through source line N.
     *
     * @param AnalysisUnit $analysisUnit - Unit whose comment-masked source is counted.
     *
     * @return list<int> - Cumulative substantive-line counts with a leading zero.
     */
    private static function substantiveLinePrefix(AnalysisUnit $analysisUnit): array
    {
        self::$prefixCache ??= new WeakMap();
        $cached = self::$prefixCache[$analysisUnit] ?? null;
        if ($cached !== null) {
            // A hit means this unit's mask and every range count are already represented by the prefix.
            return $cached;
        }

        $count  = 0;
        $prefix = [0];

        foreach (self::maskedLines($analysisUnit) as $line) {
            // A line is substantive when anything beyond whitespace survives the comment mask.
            if (trim($line) !== '') {
                ++$count;
            }
            $prefix[] = $count;
        }

        self::$prefixCache[$analysisUnit] = $prefix;

        return $prefix;
    }

    /**
     * Splits the masked source into lines; a line that is blank after masking holds no code.
     *
     * Shared by both counting entry points and by rules that add their own per-line exclusions.
     *
     * @param AnalysisUnit $analysisUnit - Unit whose comment tokens and attribute groups are blanked.
     *
     * @return list<string> - Source lines with comment and attribute text replaced by spaces.
     */
    public static function maskedLines(AnalysisUnit $analysisUnit): array
    {
        $masked       = '';
        $sourceOffset = 0;

        // Copy source and masked spans in order so each source byte is visited at most once.
        foreach (self::freeSpans($analysisUnit) as [$start, $end]) {
            $masked .= substr($analysisUnit->source, $sourceOffset, $start - $sourceOffset);
            $masked .= preg_replace('/[^\n]/', ' ', substr($analysisUnit->source, $start, $end - $start)) ?? '';
            $sourceOffset = $end;
        }

        // Preserve the untouched tail after the final masked span.
        $masked .= substr($analysisUnit->source, $sourceOffset);

        return explode("\n", $masked);
    }

    /**
     * Lists the byte spans that never count as code, merged and in source order: every comment token and
     * every parsed attribute group from its `#[` to its closing `]`.
     *
     * @param AnalysisUnit $analysisUnit - Unit whose comment tokens and attribute groups are read.
     *
     * @return list<array{int, int}> - Non-overlapping spans as start-inclusive, end-exclusive byte offsets.
     */
    private static function freeSpans(AnalysisUnit $analysisUnit): array
    {
        $spans = [];

        // A syntax-error unit keeps every token, so only comment tokens are taken from the stream.
        foreach ($analysisUnit->tokens as $token) {
            if ($token->is([\T_COMMENT, \T_DOC_COMMENT])) {
                $spans[] = [$token->pos, $token->pos + strlen($token->text)];
            }
        }

        // An attribute group without parser offsets cannot be located, so it stays countable.
        foreach (NodeIndex::nodesOf($analysisUnit, AttributeGroup::class) as $attributeGroup) {
            $start = $attributeGroup->getStartFilePos();
            $end   = $attributeGroup->getEndFilePos() + 1;
            if ($start >= 0 && $end > $start) {
                $spans[] = [$start, $end];
            }
        }

        usort($spans, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        $merged = [];
        foreach ($spans as [$start, $end]) {
            $last = count($merged) - 1;
            // A comment written inside an attribute is already part of that attribute's span.
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
                continue;
            }
            $merged[] = [$start, $end];
        }

        return $merged;
    }
}
