<?php

declare(strict_types=1);

namespace GruffPhp\Diff;

use GruffPhp\Finding\Finding;
use GruffPhp\Parser\AnalysisUnit;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Filters findings to those attributable to changed hunks or enclosing symbols.
 */
final readonly class DiffFindingFilter
{
    /**
     * Include findings anywhere inside the changed declaration or block that encloses the diff.
     */
    public const SCOPE_SYMBOL = 'symbol';

    /**
     * Include only findings whose own line span overlaps a changed diff hunk.
     */
    public const SCOPE_HUNK   = 'hunk';

    /**
     * @param list<Finding> $findings - Findings to filter against the diff scope.
     * @param DiffResult    $diff - Diff result used to retain changed-file findings.
     *
     * @return list<Finding> - the kept findings in input order; empty when every finding was out of diff scope
     */
    public function filter(array $findings, DiffResult $diff): array
    {
        // Convenience façade: callers that only want the kept list, not the suppressed count, get the
        // default symbol scope without passing analysis units; the count is dropped on the floor here.
        $result = $this->apply($findings, $diff);

        return $result->findings;
    }

    /**
     * @param list<Finding>      $findings - Findings to filter against the diff scope.
     * @param DiffResult         $diff - Diff result used to retain changed-file findings.
     * @param list<AnalysisUnit> $analysisUnits - Parsed units used to recover enclosing declarations.
     * @param string             $scope - SCOPE_SYMBOL widens a hit to its enclosing declaration; SCOPE_HUNK keeps only hunk hits.
     *
     * @return DiffFilterResult - kept findings in input order paired with the count dropped as out of diff scope
     */
    public function apply(array $findings, DiffResult $diff, array $analysisUnits = [], string $scope = self::SCOPE_SYMBOL): DiffFilterResult
    {
        if (!$diff->active) {
            // No diff in play means no scoping: pass every finding through untouched with a zero
            // suppression count, so a full-project run is never silently narrowed.
            return new DiffFilterResult($findings, 0);
        }

        $declarationRanges = $scope === self::SCOPE_SYMBOL
            ? $this->declarationRangesByFile($analysisUnits)
            : [];
        $kept              = [];
        $suppressedCount   = 0;

        foreach ($findings as $finding) {
            if ($this->isFindingInScope($finding, $diff, $declarationRanges)) {
                $kept[] = $finding;
                continue;
            }

            $suppressedCount++;
        }

        return new DiffFilterResult($kept, $suppressedCount);
    }

    /**
     * @param Finding                               $finding - Single finding whose location is tested for diff membership.
     * @param DiffResult                            $diff - Source of changed files and changed-line ranges to test against.
     * @param array<string, list<ChangedLineRange>> $declarationRanges - Per-file declaration spans for symbol widening.
     *
     * @return bool - true when the finding belongs to a changed file, hunk, or enclosing changed declaration
     */
    private function isFindingInScope(Finding $finding, DiffResult $diff, array $declarationRanges): bool
    {
        if (!in_array($finding->filePath, $diff->changedFiles, true)) {
            // The diff never touched this file, so nothing in it can be attributable to the change.
            return false;
        }

        $line = $finding->line;
        if ($line === null) {
            // File-level findings carry no line to intersect; keep them rather than drop diagnostics we can't place.
            return true;
        }

        $changedRanges = $diff->rangesFor($finding->filePath);
        if ($changedRanges === []) {
            // File is changed but ships no line ranges (e.g. rename/mode-only): treat the whole file as in scope,
            // retaining every finding in it because we cannot localise the edit to specific lines.
            return true;
        }

        $endLine = $finding->endLine ?? $line;
        if ($this->hasRangeOverlap($changedRanges, $line, $endLine)) {
            // The finding's own span lands on edited lines, so it is a direct consequence of the diff.
            return true;
        }

        $enclosingRange = $this->enclosingRange($declarationRanges[$finding->filePath] ?? [], $line, $endLine);
        if (!$enclosingRange instanceof ChangedLineRange) {
            // Outside every edited hunk and inside no recovered declaration: pre-existing, not from this change.
            return false;
        }

        // Last chance under symbol scope: the finding sits in a method/closure whose body was edited,
        // so editing any part of that symbol pulls the whole symbol's findings back into review.
        return $this->hasRangeOverlap($changedRanges, $enclosingRange->startLine, $enclosingRange->endLine);
    }

    /**
     * @param list<ChangedLineRange> $ranges - Changed-line ranges to test for any overlap.
     * @param int                    $startLine - First line of the inclusive span being matched.
     * @param int                    $endLine - Last line of the inclusive span being matched.
     *
     * @return bool - true when any changed range overlaps the inclusive span
     */
    private function hasRangeOverlap(array $ranges, int $startLine, int $endLine): bool
    {
        foreach ($ranges as $range) {
            if ($range->touches($startLine, $endLine)) {
                // One overlap is sufficient; short-circuit so a long range list does not keep scanning.
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<ChangedLineRange> $ranges - Candidate declaration spans to search for an enclosing one.
     * @param int                    $startLine - First line of the inclusive span that must be contained.
     * @param int                    $endLine - Last line of the inclusive span that must be contained.
     *
     * @return ChangedLineRange|null - the tightest declaration span containing the input span, or null when none exists
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
            if ($size < $bestSize) {
                $bestRange = $range;
                $bestSize  = $size;
            }
        }

        // Tightest enclosing declaration wins so a finding maps to its own method, not the whole class;
        // null means no declaration contained the span, leaving the caller to fall back to hunk overlap.
        return $bestRange;
    }

    /**
     * @param list<AnalysisUnit> $analysisUnits - Parsed analysis units whose statement spans define declaration-level diff filtering.
     *
     * @return array<string, list<ChangedLineRange>> - declaration spans keyed by display path, each list pre-sorted smallest-span-first; files with
     *                       no statements are absent
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
     * @param Node                   $node - Subtree root walked recursively for scope-defining declarations.
     * @param list<ChangedLineRange> $ranges - Accumulator appended to in place as scope spans are discovered.
     *
     * @return void
     */
    private function collectDeclarationRanges(Node $node, array &$ranges): void
    {
        if ($this->isScopeNode($node)) {
            $startLine = $node->getStartLine();
            $endLine   = $node->getEndLine();

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
     * Decide whether a parser node should widen hunk scope to an enclosing reviewable block.
     *
     * @param Node $node - Parser node being classified for diff-scope widening.
     *
     * @return bool - true when the node has a meaningful source span for symbol or block-level diff review
     */
    private function isScopeNode(Node $node): bool
    {
        // Defines what counts as an enclosing "symbol" for diff scoping: declarations and callables
        // are reviewable units, while nested control-flow blocks are too narrow for symbol scope.
        return $node instanceof Stmt\ClassLike
               || $node instanceof Stmt\ClassMethod
               || $node instanceof Stmt\Function_
               || $node instanceof Expr\Closure
               || $node instanceof Expr\ArrowFunction;
    }
}
