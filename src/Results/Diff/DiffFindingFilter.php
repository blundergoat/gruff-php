<?php

declare(strict_types=1);

namespace GruffPhp\Results\Diff;

use GruffPhp\Results\Finding\Finding;
use GruffPhp\Engine\Parser\AnalysisUnit;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Restricts findings to the files and ranges in an active diff for changed-region analysis.
 * Symbol scope can widen ordinary findings to enclosing declarations when parsed units are supplied.
 * Hunk scope uses finding spans; file scope also retains designated whole-file aggregates.
 *
 * In changed files, findings without a line or without changed ranges remain in scope.
 * An inactive diff preserves every finding; membership does not establish when an issue was introduced.
 */
final readonly class DiffFindingFilter
{
    /**
     * `symbol` can widen ordinary findings to the smallest enclosing declaration recovered from parsed units.
     * In changed files with ranges, anchor-only aggregates with a reported line must overlap at that line.
     */
    public const SCOPE_SYMBOL = 'symbol';

    /**
     * In changed files, `hunk` keeps findings whose spans overlap changed ranges; anchor-only aggregates use their reported line.
     * Findings without a line or without changed ranges also pass within those files.
     */
    public const SCOPE_HUNK   = 'hunk';

    /**
     * `--changed-scope file`: keeps whole-file aggregate verdicts (file length, TODO density) for any file
     * the diff touched, on top of the plain hunk-overlap test - but it drops symbol widening, so a finding
     * inside an edited method yet off the changed lines is kept under `symbol` and hidden here.
     */
    public const SCOPE_FILE   = 'file';

    /**
     * Rules that judge a whole file at once, so under `file` scope their verdict is kept for any
     * changed file even though it has no single edited line to pin it to.
     */
    private const FILE_AGGREGATE_RULE_IDS = [
        'docs.todo-density' => true,
        'size.file-length'  => true,
    ];

    /**
     * Rules whose finding is parked on one representative line (a class or file aggregate) rather than a
     * real reviewable span, so `symbol`/`hunk` scope only keeps them when the edit lands on that line.
     */
    private const ANCHOR_ONLY_AGGREGATE_RULE_IDS = [
        'docs.todo-density'           => true,
        'size.class-length'           => true,
        'size.file-length'            => true,
        'size.property-count'         => true,
        'size.public-method-count'    => true,
    ];

    /**
     * Returns the findings kept by the default scope without collecting declaration spans.
     * With no analysis units, filtering cannot widen a finding to its enclosing symbol.
     *
     * @param list<Finding> $findings - Findings to filter against the diff scope; an empty list yields an empty result.
     * @param DiffResult    $diff - Diff result used to retain changed-file findings; an inactive diff keeps every one.
     *
     * @return list<Finding> - The kept findings in input order; empty when every finding fell outside the changed region.
     */
    public function filter(array $findings, DiffResult $diff): array
    {

        $result = $this->apply($findings, $diff);

        return $result->findings;
    }

    /**
     * Filters findings using the requested changed-region scope and counts those excluded.
     * Diff membership restricts review coverage; it does not determine whether an issue is new.
     *
     * @param list<Finding> $findings - Findings to filter; an empty list produces no findings and zero suppressions.
     * @param DiffResult $diff - An inactive diff keeps every finding and suppresses none.
     * @param list<AnalysisUnit> $analysisUnits - Parsed units for symbol widening; empty input disables widening.
     * @param string $scope - `symbol` widens ordinary findings; `hunk` uses overlaps; `file` also retains file aggregates.
     *
     * @return DiffFilterResult - Kept findings in input order paired with the count dropped as outside the diff scope.
     */
    public function apply(array $findings, DiffResult $diff, array $analysisUnits = [], string $scope = self::SCOPE_SYMBOL): DiffFilterResult
    {
        // No diff was requested, so there is nothing to scope against: return every finding with a zero
        // suppressed count, leaving a full-project scan exactly as it was.
        if (!$diff->active) {
            return new DiffFilterResult($findings, 0);
        }

        // Recovering declaration spans is only worth it under `symbol` scope; the other scopes never widen
        // to an enclosing method, so skip the parse-tree walk and leave the lookup map empty.
        $declarationRanges = $scope === self::SCOPE_SYMBOL
            ? $this->declarationRangesByFile($analysisUnits)
            : [];
        $kept              = [];
        $suppressedCount   = 0;


        foreach ($findings as $finding) {

            if ($this->isFindingInScope($finding, $diff, $declarationRanges, $scope)) {
                $kept[] = $finding;
                continue;
            }


            $suppressedCount++;
        }

        return new DiffFilterResult($kept, $suppressedCount);
    }

    /**
     * Tests a finding against changed-file membership and the scope's range rules.
     *
     * @param Finding $finding - Finding whose location is tested for diff membership.
     * @param DiffResult $diff - Source of changed paths and line ranges.
     * @param array<string, list<ChangedLineRange>> $declarationRanges - Per-file declaration spans; empty disables widening.
     * @param string $scope - Requested changed-region scope.
     *
     * @return bool - True when the finding is retained, including locationless findings in changed files.
     */
    private function isFindingInScope(Finding $finding, DiffResult $diff, array $declarationRanges, string $scope): bool
    {

        if (!in_array($finding->filePath, $diff->changedFiles, true)) {
            return false;
        }

        $line = $finding->line;
        // A null line marks a whole-file finding with nowhere to intersect, so keep it rather than silently lose a verdict.
        if ($line === null) {
            return true;
        }

        $changedRanges = $diff->rangesFor($finding->filePath);
        // Without ranges, every finding in this changed file stays in scope.

        if ($changedRanges === []) {
            return true;
        }

        // Under `file` scope a whole-file verdict (like file length) is exactly what the reviewer wants to
        // see for a changed file, so keep it without demanding it line up with an edited line.
        if ($scope === self::SCOPE_FILE && $this->isFileAggregateFinding($finding)) {
            return true;
        }

        // Aggregate rules park their finding on one representative line. Under `symbol`/`hunk` review keep
        // such a finding only if the edit lands on that exact anchor, never widening it to the whole class.
        if ($scope !== self::SCOPE_FILE && $this->isAnchorOnlyAggregateFinding($finding)) {
            return $this->hasRangeOverlap($changedRanges, $line, $line);
        }

        $endLine = $finding->endLine ?? $line;

        if ($this->hasRangeOverlap($changedRanges, $line, $endLine)) {
            return true;
        }

        $enclosingRange = $this->enclosingRange($declarationRanges[$finding->filePath] ?? [], $line, $endLine);


        if (!$enclosingRange instanceof ChangedLineRange) {
            return false;
        }



        return $this->hasRangeOverlap($changedRanges, $enclosingRange->startLine, $enclosingRange->endLine);
    }

    /**
     * Flags the "anchored" aggregate rules - the ones whose finding sits on a stand-in line rather than a
     * real span - so `symbol` and `hunk` review can hold them to the stricter exact-line test.
     *
     * @param Finding $finding - Finding to classify before symbol/file span widening.
     *
     * @return bool - True when the rule reports a file or class aggregate anchored at a representative line.
     */
    private function isAnchorOnlyAggregateFinding(Finding $finding): bool
    {
        return isset(self::ANCHOR_ONLY_AGGREGATE_RULE_IDS[$finding->ruleId]);
    }

    /**
     * Flags the whole-file aggregate rules (file length, TODO density) so `file` scope can keep their
     * verdict for a changed file even though it maps to no single edited line.
     *
     * @param Finding $finding - Finding to classify for changed-file aggregate review.
     *
     * @return bool - True when the finding's rule ID is in the whole-file aggregate allowlist.
     */
    private function isFileAggregateFinding(Finding $finding): bool
    {
        return isset(self::FILE_AGGREGATE_RULE_IDS[$finding->ruleId]);
    }

    /**
     * Checks whether any changed hunk overlaps the supplied inclusive line span.
     *
     * @param list<ChangedLineRange> $ranges - Changed-line ranges to test for any overlap; empty means no change touches the span.
     * @param int                    $startLine - First line of the inclusive span being matched.
     * @param int                    $endLine - Last line of the inclusive span being matched.
     *
     * @return bool - True as soon as one changed range overlaps the inclusive span; false when none do.
     */
    private function hasRangeOverlap(array $ranges, int $startLine, int $endLine): bool
    {

        foreach ($ranges as $range) {

            if ($range->touches($startLine, $endLine)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Finds the smallest declaration span containing the entire supplied finding span.
     *
     * @param list<ChangedLineRange> $ranges - Candidate declaration spans to search for an enclosing one; empty yields null.
     * @param int                    $startLine - First line of the inclusive span that must be contained.
     * @param int                    $endLine - Last line of the inclusive span that must be contained.
     *
     * @return ChangedLineRange|null - Smallest enclosing span, or null when no declaration contains the entire finding.
     */
    private function enclosingRange(array $ranges, int $startLine, int $endLine): ?ChangedLineRange
    {
        $bestRange = null;
        $bestSize  = PHP_INT_MAX;


        foreach ($ranges as $range) {

            if ($range->startLine > $startLine || $range->endLine < $endLine) {
                continue;
            }

            $size = $range->endLine - $range->startLine;
            // Prefer the smallest container seen so far, so we land on the method rather than its whole class.
            if ($size < $bestSize) {
                $bestRange = $range;
                $bestSize  = $size;
            }
        }



        return $bestRange;
    }

    /**
     * Builds the per-file map of editable declaration spans that symbol scope relies on, walking each
     * parsed file once up front so widening a finding to its enclosing method is a cheap lookup later.
     *
     * @param list<AnalysisUnit> $analysisUnits - Parsed analysis units whose statement spans define declaration-level diff filtering.
     *
     * @return array<string, list<ChangedLineRange>> - Smallest-first spans by display path; units without statements are omitted.
     */
    private function declarationRangesByFile(array $analysisUnits): array
    {
        $rangesByFile = [];


        foreach ($analysisUnits as $analysisUnit) {

            if ($analysisUnit->statements === []) {
                continue;
            }

            $ranges = [];

            foreach ($analysisUnit->statements as $statement) {
                $this->collectDeclarationRanges($statement, $ranges);
            }

            // Sort smallest span first so the later enclosing-range search meets the tightest method before its class.
            usort(
                $ranges,
                static fn(ChangedLineRange $left, ChangedLineRange $right): int => [
                                                                                       $left->endLine - $left->startLine,
                                                                                       $left->startLine,
                                                                                   ] <=> [
                                                                                       $right->endLine - $right->startLine,
                                                                                       $right->startLine,
                                                                                   ],
            );

            $rangesByFile[$analysisUnit->file->displayPath] = $ranges;
        }

        return $rangesByFile;
    }

    /**
     * Recursively walks a parse subtree and records the line span of every declaration that counts as a
     * reviewable symbol, feeding the per-file map that lets `symbol` scope widen a finding to its method.
     *
     * @param Node                   $node - Subtree root walked recursively for scope-defining declarations.
     * @param list<ChangedLineRange> $ranges - Accumulator appended to in place as scope spans are discovered.
     *
     * @return void - Appends valid declaration spans to the caller's range list.
     */
    private function collectDeclarationRanges(Node $node, array &$ranges): void
    {

        if ($this->isScopeNode($node)) {
            $startLine = $node->getStartLine();
            $endLine   = $node->getEndLine();

            // Only keep spans the parser could actually place; a missing or inverted range is unusable.
            if ($startLine > 0 && $endLine >= $startLine) {
                $ranges[] = new ChangedLineRange($startLine, $endLine);
            }
        }


        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNodeValue = $node->{$subNodeName};

            if ($subNodeValue instanceof Node) {
                $this->collectDeclarationRanges($subNodeValue, $ranges);
                continue;
            }


            if (!is_array($subNodeValue)) {
                continue;
            }


            foreach ($subNodeValue as $item) {

                if ($item instanceof Node) {
                    $this->collectDeclarationRanges($item, $ranges);
                }
            }
        }
    }

    /**
     * Identifies class-like declarations, methods, functions, closures, and arrow functions for symbol widening.
     *
     * @param Node $node - Parser node being classified for diff-scope widening.
     *
     * @return bool - True when the node is a declaration or callable whose span `symbol` scope can widen to.
     */
    private function isScopeNode(Node $node): bool
    {
        // Declarations and callables are whole reviewable units; nested control-flow blocks (an `if`, a
        // `foreach`) are too fine-grained to treat as an enclosing symbol, so they return false here.
        return $node instanceof Stmt\ClassLike
               || $node instanceof Stmt\ClassMethod
               || $node instanceof Stmt\Function_
               || $node instanceof Expr\Closure
               || $node instanceof Expr\ArrowFunction;
    }
}
