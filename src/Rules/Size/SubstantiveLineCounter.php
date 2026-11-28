<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Size;

use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use PhpParser\Node\AttributeGroup;
use WeakMap;

/**
 * Counts substantive lines for file, class, and test length rules under FAMILY-CONTRACT section 12: Code lines in every line count.
 * Masks comment tokens and located attribute groups with spaces, preserving newlines; blank masked lines do not count.
 * With no spans, raw non-blank lines count; syntax-error units can retain comment tokens but have no parsed attributes.
 */
final class SubstantiveLineCounter
{
    /**
     * Cumulative substantive-line counts keyed by analysis-unit identity.
     *
     * @var WeakMap<AnalysisUnit, list<int>>|null - Prefix counts with a leading zero, or null before cache initialization.
     */
    private static ?WeakMap $prefixCache = null;

    /**
     * Counts the substantive lines of the whole unit.
     *
     * @param AnalysisUnit $analysisUnit - Unit whose source, comment tokens, and parsed attributes are inspected.
     *
     * @return int - Non-blank line count after comment and attribute masking; zero when no code remains.
     */
    public static function countAll(AnalysisUnit $analysisUnit): int
    {
        $prefix = self::substantiveLinePrefix($analysisUnit);

        return $prefix[count($prefix) - 1];
    }

    /**
     * Counts substantive lines in an inclusive 1-based range, stopping at the source end.
     *
     * @param AnalysisUnit $analysisUnit - Unit whose source, comment tokens, and parsed attributes are inspected.
     * @param int          $startLine    - First line of the range, 1-based inclusive.
     * @param int          $endLine      - Last line of the range, 1-based inclusive.
     *
     * @return int - Masked non-blank line count; zero for reversed ranges or starts past the source.
     */
    public static function countRange(AnalysisUnit $analysisUnit, int $startLine, int $endLine): int
    {
        $prefix          = self::substantiveLinePrefix($analysisUnit);
        $lineCount       = count($prefix) - 1;
        $startIndex      = max(0, $startLine - 1);
        $requestedLength = max(0, $endLine - $startLine + 1);


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
     * @return void - Removes the unit's cached counts, leaving an uninitialized cache untouched.
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
     * @param AnalysisUnit $analysisUnit - Unit whose source is counted after comment and attribute masking.
     *
     * @return list<int> - Cumulative substantive-line counts with a leading zero.
     */
    private static function substantiveLinePrefix(AnalysisUnit $analysisUnit): array
    {
        self::$prefixCache ??= new WeakMap();
        $cached = self::$prefixCache[$analysisUnit] ?? null;
        if ($cached !== null) {

            return $cached;
        }

        $count  = 0;
        $prefix = [0];

        foreach (self::maskedLines($analysisUnit) as $line) {

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
     * @param AnalysisUnit $analysisUnit - Unit whose comment tokens and attribute groups with usable offsets are blanked.
     *
     * @return list<string> - Source lines with each located comment or attribute span replaced by spaces.
     */
    public static function maskedLines(AnalysisUnit $analysisUnit): array
    {
        $masked       = '';
        $sourceOffset = 0;

        // Copy source and masked spans in order so each source byte is visited at most once.
        foreach (self::freeSpans($analysisUnit) as [$start, $end]) {
            $masked .= substr($analysisUnit->source, $sourceOffset, $start - $sourceOffset);
            // Blank every character except newlines, so the masked span keeps its line count.
            $masked .= preg_replace('/[^\n]/', ' ', substr($analysisUnit->source, $start, $end - $start)) ?? '';
            $sourceOffset = $end;
        }


        $masked .= substr($analysisUnit->source, $sourceOffset);

        return explode("\n", $masked);
    }

    /**
     * Lists merged, source-ordered spans for comment tokens and parsed attribute groups with usable offsets.
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
