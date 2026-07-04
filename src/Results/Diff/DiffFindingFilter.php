<?php

declare(strict_types=1);

namespace GruffPhp\Results\Diff;

use GruffPhp\Results\Finding\Finding;
use GruffPhp\Engine\Parser\AnalysisUnit;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Narrows a full finding set down to only what the current change is responsible for, so a diff or
 * branch review shows the issues a user just introduced rather than the whole project's backlog -
 * for example after `git diff | gruff-php analyse --diff=-`. A scan scoped with `--diff`, `--since`,
 * or `--changed-ranges` runs this, and `--changed-scope` decides how wide the net is: `symbol` keeps
 * every finding in an edited method or closure, `hunk` keeps only findings on the edited lines, and
 * `file` also retains whole-file aggregate verdicts for changed files. With no diff active it is a
 * no-op that lets every finding through, so an ordinary full-project scan is never quietly trimmed.
 */
final readonly class DiffFindingFilter
{
    /**
     * Default `--changed-scope symbol`: keep every finding inside an edited method, closure, or class,
     * so touching a single line pulls that whole symbol's issues into the review.
     */
    public const SCOPE_SYMBOL = 'symbol';

    /**
     * `--changed-scope hunk`: the strictest view - keep only findings whose own lines sit on an edited
     * hunk (whole-file findings with no line still pass), so the user sees issues on the lines they changed.
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
        'size.average-method-length'  => true,
        'size.class-length'           => true,
        'size.file-length'            => true,
        'size.property-count'         => true,
        'size.public-method-count'    => true,
    ];

    /**
     * The simple entry point for callers that only want the surviving findings and don't care how many
     * were hidden - it runs the default `symbol` scope and hands back just the kept list.
     *
     * @param list<Finding> $findings - Findings to filter against the diff scope; an empty list yields an empty result.
     * @param DiffResult    $diff - Diff result used to retain changed-file findings; an inactive diff keeps every one.
     *
     * @return list<Finding> - The kept findings in input order; empty when every finding fell outside the changed region.
     */
    public function filter(array $findings, DiffResult $diff): array
    {
        // Delegate to `apply()` with `symbol` scope and no analysis units, then return only its `findings`.
        $result = $this->apply($findings, $diff);

        return $result->findings;
    }

    /**
     * The full filter behind a diff-scoped `analyse` run (say `gruff-php analyse --since=main`): keeps a
     * finding only when the changed region accounts for it, and reports how many were hidden - surfaced
     * as the report's `suppressedCount`. `--changed-scope` sets how wide that is.
     *
     * @param list<Finding>      $findings - Findings to filter against the diff scope; an empty list yields an empty result and a zero count.
     * @param DiffResult         $diff - Diff result used to retain changed-file findings; an inactive diff keeps every finding and suppresses none.
     * @param list<AnalysisUnit> $analysisUnits - Parsed units used to recover enclosing declarations; empty (or a non-symbol scope) disables symbol widening.
     * @param string             $scope - `symbol` widens ordinary hits to their enclosing declaration; `hunk` keeps only hunk hits;
     *                                    `file` keeps file aggregates and aggregate span hits for changed-file review workflows.
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

        // Sort every finding into "keep" or "hide" so the review shows only what this change is answerable for.
        foreach ($findings as $finding) {
            // The changed region accounts for this finding, so it stays in the user-visible list.
            if ($this->isFindingInScope($finding, $diff, $declarationRanges, $scope)) {
                $kept[] = $finding;
                continue;
            }

            // Pre-existing and untouched by the diff: hide it, but count it as suppressed (the report's `suppressedCount`).
            $suppressedCount++;
        }

        return new DiffFilterResult($kept, $suppressedCount);
    }

    /**
     * Decides a single finding's fate: does the current change actually account for it? Checks the file,
     * then the exact edited lines, then - under `symbol` scope - the enclosing method or class, so a user
     * only ever sees issues their own edit is responsible for.
     *
     * @param Finding                               $finding - Single finding whose location is tested for diff membership.
     * @param DiffResult                            $diff - Source of changed files and changed-line ranges to test against.
     * @param array<string, list<ChangedLineRange>> $declarationRanges - Per-file declaration spans for symbol widening; empty means no widening.
     * @param string                                $scope - Changed-region scope requested by the `--changed-scope` flag.
     *
     * @return bool - True when the finding belongs to a changed file, hunk, or enclosing changed declaration; false hides it.
     */
    private function isFindingInScope(Finding $finding, DiffResult $diff, array $declarationRanges, string $scope): bool
    {
        // The diff never touched this file, so nothing in it could be a consequence of the change; drop it.
        if (!in_array($finding->filePath, $diff->changedFiles, true)) {
            return false;
        }

        $line = $finding->line;
        // A null line marks a whole-file finding with nowhere to intersect, so keep it rather than silently lose a verdict.
        if ($line === null) {
            return true;
        }

        $changedRanges = $diff->rangesFor($finding->filePath);
        // The file changed but carries no line ranges (a rename or mode-only edit), so we can't localise the
        // change: treat the whole file as in scope and keep every finding rather than guess which lines moved.
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
        // The finding's own span sits on lines the user actually edited, so it is a direct result of the change.
        if ($this->hasRangeOverlap($changedRanges, $line, $endLine)) {
            return true;
        }

        $enclosingRange = $this->enclosingRange($declarationRanges[$finding->filePath] ?? [], $line, $endLine);
        // It sits outside every edited hunk and inside no method we could recover, so it is pre-existing debt
        // the user didn't touch on this change; hide it.
        if (!$enclosingRange instanceof ChangedLineRange) {
            return false;
        }

        // Final `symbol`-scope check: the finding lives in a method or closure the user edited elsewhere, so
        // editing any part of that symbol pulls its remaining findings back into review too.
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
     * @return bool - True when the rule reports one aggregate finding for the whole file.
     */
    private function isFileAggregateFinding(Finding $finding): bool
    {
        return isset(self::FILE_AGGREGATE_RULE_IDS[$finding->ruleId]);
    }

    /**
     * Answers the core question behind every scope decision: does any changed hunk touch this line span?
     * Used again and again while deciding whether a finding or an enclosing symbol counts as edited.
     *
     * @param list<ChangedLineRange> $ranges - Changed-line ranges to test for any overlap; empty means no change touches the span.
     * @param int                    $startLine - First line of the inclusive span being matched.
     * @param int                    $endLine - Last line of the inclusive span being matched.
     *
     * @return bool - True as soon as one changed range overlaps the inclusive span; false when none do.
     */
    private function hasRangeOverlap(array $ranges, int $startLine, int $endLine): bool
    {
        // Walk the changed hunks looking for any that overlaps the span the caller is asking about.
        foreach ($ranges as $range) {
            // A single overlapping hunk already proves the span was edited, so stop at the first hit.
            if ($range->touches($startLine, $endLine)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Finds the tightest edited declaration that wraps a finding, so a hit can be attributed to its own
     * method or closure rather than the entire class it happens to live in.
     *
     * @param list<ChangedLineRange> $ranges - Candidate declaration spans to search for an enclosing one; empty yields null.
     * @param int                    $startLine - First line of the inclusive span that must be contained.
     * @param int                    $endLine - Last line of the inclusive span that must be contained.
     *
     * @return ChangedLineRange|null - The smallest declaration span containing the input; null when none contains it, so the caller falls back to hunk overlap.
     */
    private function enclosingRange(array $ranges, int $startLine, int $endLine): ?ChangedLineRange
    {
        $bestRange = null;
        $bestSize  = PHP_INT_MAX;

        // Compare the finding's span against every declaration span recovered for the file.
        foreach ($ranges as $range) {
            // This declaration doesn't fully contain the finding, so it can't be the enclosing symbol; skip it.
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

        // Return the tightest match so a finding maps to its own method, not the whole class; null means no
        // declaration contained it, leaving the caller to fall back to plain hunk overlap.
        return $bestRange;
    }

    /**
     * Builds the per-file map of editable declaration spans that `symbol` scope relies on, walking each
     * parsed file once up front so widening a finding to its enclosing method is a cheap lookup later.
     *
     * @param list<AnalysisUnit> $analysisUnits - Parsed analysis units whose statement spans define declaration-level diff filtering.
     *
     * @return array<string, list<ChangedLineRange>> - Declaration spans keyed by display path, each list sorted smallest-span-first; a file with no
     *                       statements is absent, and an empty map means nothing can be widened by symbol.
     */
    private function declarationRangesByFile(array $analysisUnits): array
    {
        $rangesByFile = [];

        // Walk every parsed file so `symbol` scope has a ready map to look declarations up in.
        foreach ($analysisUnits as $analysisUnit) {
            // A file the parser produced no statements for has no declarations to widen to, so skip it.
            if ($analysisUnit->statements === []) {
                continue;
            }

            $ranges = [];
            // Descend each top-level statement to gather the span of every method, closure, and class within.
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
     * @return void
     */
    private function collectDeclarationRanges(Node $node, array &$ranges): void
    {
        // When the node is itself a reviewable symbol, record its line span as one the diff can widen to.
        if ($this->isScopeNode($node)) {
            $startLine = $node->getStartLine();
            $endLine   = $node->getEndLine();

            // Only keep spans the parser could actually place; a missing or inverted range is unusable.
            if ($startLine > 0 && $endLine >= $startLine) {
                $ranges[] = new ChangedLineRange($startLine, $endLine);
            }
        }

        // Recurse into every child of the node so nested methods and closures are found too.
        foreach ($node->getSubNodeNames() as $subNodeName) {
            $subNodeValue = $node->{$subNodeName};
            // A single child node: descend straight into it.
            if ($subNodeValue instanceof Node) {
                $this->collectDeclarationRanges($subNodeValue, $ranges);
                continue;
            }

            // Anything that is neither a node nor a list of nodes (a scalar, a name) holds no declarations.
            if (!is_array($subNodeValue)) {
                continue;
            }

            // A list of child nodes (statements, params): walk each entry looking for more declarations.
            foreach ($subNodeValue as $item) {
                // Only real parse nodes can contain a declaration, so skip any non-node list entry.
                if ($item instanceof Node) {
                    $this->collectDeclarationRanges($item, $ranges);
                }
            }
        }
    }

    /**
     * Defines what counts as a reviewable "symbol" for diff scoping: a class, method, function, or
     * closure is a unit a user reviews as a whole, so `symbol` scope may widen a finding out to it.
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
