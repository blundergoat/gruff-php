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
        return $this->apply($findings, $diff)->findings;
    }

    /**
     * @param list<Finding>      $findings      Findings to filter against the diff scope.
     * @param DiffResult         $diff          Diff result used to retain changed-file findings.
     * @param list<AnalysisUnit> $analysisUnits Parsed units used to recover enclosing declarations.
     * @return DiffFilterResult Retained findings and the number suppressed as out of scope.
     */
    public function apply(array $findings, DiffResult $diff, array $analysisUnits = [], string $scope = self::SCOPE_SYMBOL): DiffFilterResult
    {
        if (!$diff->active) {
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

        return new DiffFilterResult($kept, $suppressedCount);
    }

    /**
     * @param array<string, list<ChangedLineRange>> $declarationRanges
     */
    private function isFindingInScope(Finding $finding, DiffResult $diff, array $declarationRanges): bool
    {
        if (!in_array($finding->filePath, $diff->changedFiles, true)) {
            return false;
        }

        $line = $finding->line;
        if ($line === null) {
            return true;
        }

        $changedRanges = $diff->rangesFor($finding->filePath);
        if ($changedRanges === []) {
            return true;
        }

        $endLine = $finding->endLine ?? $line;
        if ($this->rangesTouch($changedRanges, $line, $endLine)) {
            return true;
        }

        $enclosingRange = $this->enclosingRange($declarationRanges[$finding->filePath] ?? [], $line, $endLine);
        if (!$enclosingRange instanceof ChangedLineRange) {
            return false;
        }

        return $this->rangesTouch($changedRanges, $enclosingRange->startLine, $enclosingRange->endLine);
    }

    /**
     * @param list<ChangedLineRange> $ranges
     */
    private function rangesTouch(array $ranges, int $startLine, int $endLine): bool
    {
        foreach ($ranges as $range) {
            if ($range->touches($startLine, $endLine)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<ChangedLineRange> $ranges
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

        return $rangesByFile;
    }

    /**
     * @param list<ChangedLineRange> $ranges
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
