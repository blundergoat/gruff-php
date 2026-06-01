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
    public const SCOPE_SYMBOL = 'symbol';
    public const SCOPE_HUNK   = 'hunk';

    /**
     * @param list<Finding> $findings Findings to filter against the diff scope.
     * @param DiffResult    $diff     Diff result used to retain changed-file findings.
     * @return list<Finding>
     */
    public function filter(array $findings, DiffResult $diff): array
    {
        // Convenience façade: callers that only want the kept list, not the suppressed count, get the
        // default symbol scope without passing analysis units; the count is dropped on the floor here.
        return $this->apply($findings, $diff)->findings;
    }

    /**
     * @param list<Finding> $findings Findings to filter against the diff scope.
     * @param DiffResult $diff Diff result used to retain changed-file findings.
     * @param list<AnalysisUnit> $analysisUnits Parsed units used to recover enclosing declarations.
     * @param string $scope SCOPE_SYMBOL widens a hit to its enclosing declaration; SCOPE_HUNK keeps only hunk hits.
     * @return DiffFilterResult Retained findings and the number suppressed as out of scope.
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
        $kept            = [];
        $suppressedCount = 0;

        foreach ($findings as $finding) {
            if ($this->isFindingInScope($finding, $diff, $declarationRanges)) {
                $kept[] = $finding;
                continue;
            }

            $suppressedCount++;
        }

        // Return the partition: kept findings plus the tally of those dropped, so the caller can report
        // how many were hidden by diff scoping rather than guessing from the size difference.
        return new DiffFilterResult($kept, $suppressedCount);
    }

    /**
     * @param Finding $finding Single finding whose location is tested for diff membership.
     * @param DiffResult $diff Source of changed files and changed-line ranges to test against.
     * @param array<string, list<ChangedLineRange>> $declarationRanges Per-file declaration spans for symbol widening.
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
        if ($this->rangesTouch($changedRanges, $line, $endLine)) {
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
        return $this->rangesTouch($changedRanges, $enclosingRange->startLine, $enclosingRange->endLine);
    }

    /**
     * @param list<ChangedLineRange> $ranges    Changed-line ranges to test for any overlap.
     * @param int                    $startLine First line of the inclusive span being matched.
     * @param int                    $endLine   Last line of the inclusive span being matched.
     */
    private function rangesTouch(array $ranges, int $startLine, int $endLine): bool
    {
        foreach ($ranges as $range) {
            if ($range->touches($startLine, $endLine)) {
                // One overlap is sufficient; short-circuit so a long range list does not keep scanning.
                return true;
            }
        }

        // Checked every range with no overlap, so the span lies entirely outside the changed lines.
        return false;
    }

    /**
     * @param list<ChangedLineRange> $ranges    Candidate declaration spans to search for an enclosing one.
     * @param int                    $startLine First line of the inclusive span that must be contained.
     * @param int                    $endLine   Last line of the inclusive span that must be contained.
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
     * @param list<AnalysisUnit> $analysisUnits
     * @return array<string, list<ChangedLineRange>>
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
                static fn (ChangedLineRange $left, ChangedLineRange $right): int => [
                    $left->endLine - $left->startLine,
                    $left->startLine,
                ] <=> [
                    $right->endLine - $right->startLine,
                    $right->startLine,
                ],
            );

            $rangesByFile[$analysisUnit->file->displayPath] = $ranges;
        }

        // Keyed by display path and pre-sorted smallest-span-first so enclosingRange() can take the first
        // containing match as the tightest one without re-sorting on every lookup.
        return $rangesByFile;
    }

    /**
     * @param Node                   $node   Subtree root walked recursively for scope-defining declarations.
     * @param list<ChangedLineRange> $ranges Accumulator appended to in place as scope spans are discovered.
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

    private function isScopeNode(Node $node): bool
    {
        // Defines what counts as an enclosing "symbol" for diff scoping: control-flow blocks are listed
        // alongside callables so a finding inside an edited if/loop/try is attributed to that edited block.
        return $node instanceof Stmt\ClassLike
            || $node instanceof Stmt\ClassMethod
            || $node instanceof Stmt\Function_
            || $node instanceof Expr\Closure
            || $node instanceof Expr\ArrowFunction
            || $node instanceof Stmt\If_
            || $node instanceof Stmt\ElseIf_
            || $node instanceof Stmt\Else_
            || $node instanceof Stmt\For_
            || $node instanceof Stmt\Foreach_
            || $node instanceof Stmt\While_
            || $node instanceof Stmt\Do_
            || $node instanceof Stmt\Switch_
            || $node instanceof Stmt\Case_
            || $node instanceof Stmt\TryCatch
            || $node instanceof Stmt\Catch_
            || $node instanceof Stmt\Finally_;
    }
}
