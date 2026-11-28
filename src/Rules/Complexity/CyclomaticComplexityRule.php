<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Complexity;

use GruffPhp\Engine\Config\SeverityThreshold;
use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Rules\Size\LimitBand;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Flags a function or method whose control-flow decision count climbs above the configured threshold.
 *
 * Runs per file over every function-like node with a body, counting ifs, loops, catches and short-circuit
 * operators; a switch or match with non-default choices contributes one dispatch decision.
 *
 * Findings below one and a half times the active limit are advisory; the upper band keeps the configured
 * severity, except that a flat guard-clause method stays advisory.
 */
final readonly class CyclomaticComplexityRule implements RuleInterface
{
    /**
     * Stable rule identifier for cyclomatic complexity findings.
     */
    public const ID = 'complexity.cyclomatic';

    /**
     * Describes the cyclomatic-complexity rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and thresholds.
     */
    public function definition(): RuleDefinition
    {
        // Default threshold of 20 is the CCN above which a function-like is flagged; .gruff-php.yaml can override it.
        return new RuleDefinition(
            id:                self::ID,
            name:              'Cyclomatic complexity',
            pillar:            Pillar::Complexity,
            tier:              RuleTier::V01,
            defaultSeverity:   Severity::Warning,
            confidence:        Confidence::Medium,
            severityThreshold: new SeverityThreshold(20, Severity::Warning),
            falsePositiveShapes: [
                [
                    'shape'      => 'A sequence of independent `if` checks for options, fallbacks, validation, or cleanup can score high because each check and any enclosing loop adds a decision.',
                    'mitigation' => 'Keep each check beside the action or diagnostic it controls so each path can be verified in execution order.',
                ],
                [
                    'shape'      => 'A validator can exceed the cyclomatic complexity threshold through a series of independent `if` checks, each with a clear diagnostic.',
                    'mitigation' => 'For adjacent checks that use the same lower-bound comparison, put each value, minimum, and diagnostic in an ordered array and apply the comparison in one `foreach` loop.',
                ],
            ],
        );
    }

    /**
     * Reports each function-like node whose cyclomatic complexity exceeds the configured threshold.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context carrying thresholds.
     *
     * @return list<Finding> - Findings for complex function-like declarations.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $settings   = $ruleContext->settingsFor($definition);

        $nodes = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];


        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node - NodeIndex query is constrained to function-like classes. */
            // An abstract or bodyless declaration has no branches to count.
            if (!FunctionComplexity::hasExecutableBody($node)) {
                continue;
            }

            $ccn            = self::dispatchOnceComplexity($node);
            $thresholdMatch = $settings->highValueThresholdMatch($ccn);


            if ($thresholdMatch === null) {
                continue;
            }

            $symbol = FunctionComplexity::resolveSymbol($node);
            $isFlatGuardFlow = ComplexityShapeClassifier::isFlatGuardClauseFlow($node);
            $band = LimitBand::of($ccn, $settings->lowestHighValueThreshold());
            $severity = LimitBand::severity($band, $isFlatGuardFlow ? Severity::Advisory : $thresholdMatch->severity);

            $findings[] = new Finding(
                ruleId:  $definition->id,
                message: $isFlatGuardFlow
                    ? sprintf(
                        '%s has a cyclomatic complexity of %d from flat guard clauses, above the %s threshold of %s.',
                        $symbol,
                        $ccn,
                        $thresholdMatch->severity->value,
                        self::formatNumber($thresholdMatch->threshold),
                    )
                    : sprintf(
                        '%s has a cyclomatic complexity of %d, above the %s threshold of %s.',
                        $symbol,
                        $ccn,
                        $thresholdMatch->severity->value,
                        self::formatNumber($thresholdMatch->threshold),
                    ),
                filePath:         $analysisUnit->file->displayPath,
                line:             $node->getStartLine(),
                severity:         $severity,
                pillar:           $definition->pillar,
                tier:             $definition->tier,
                confidence:       $definition->confidence,
                endLine:          $node->getEndLine() > 0 ? $node->getEndLine() : null,
                symbol:           $symbol,
                remediation:      LimitBand::advice($band, LimitBand::LOWER_METHOD, LimitBand::SIMPLIFY_PATH),
                secondaryPillars: $definition->secondaryPillars,
                metadata:         [
                    'complexity' => $ccn,
                    'threshold' => $thresholdMatch->threshold,
                    'thresholdType' => $severity->value,
                    'rawThresholdType' => $thresholdMatch->severity->value,
                    'complexityShape' => $isFlatGuardFlow ? ComplexityShapeClassifier::SHAPE_FLAT_GUARD_CLAUSES : 'branching',
                    LimitBand::KEY => $band,
                ],
            );
        }

        return $findings;
    }

    /**
     * Counts cyclomatic decisions with one point per switch or match that has non-default choices.
     * Flat dispatches contribute one decision; the maintainability index uses FunctionComplexity::computeCyclomaticComplexity
     * to retain one point per non-default case or match-arm condition.
     *
     * @param ClassMethod|Function_ $node - Function-like node whose decisions are counted.
     *
     * @return int - Complexity score; default-only dispatches add no points.
     */
    private static function dispatchOnceComplexity(Node $node): int
    {
        $ccn = FunctionComplexity::computeCyclomaticComplexity($node);

        // Collapse multiple non-default choices to one decision; default-only dispatches contribute none.
        foreach (NodeIndex::bodyDescendants($node) as $child) {
            // A switch's non-default cases were each counted once.
            if ($child instanceof Stmt\Switch_) {
                $cases = count(array_filter($child->cases, static fn (Stmt\Case_ $case): bool => $case->cond !== null));
                $ccn  -= max($cases - 1, 0);
            }

            // A match's arm conditions were each counted once.
            if ($child instanceof Expr\Match_) {
                $conditions = 0;
                // The default arm has no conditions.
                foreach ($child->arms as $arm) {
                    $conditions += $arm->conds === null ? 0 : count($arm->conds);
                }

                $ccn -= max($conditions - 1, 0);
            }
        }

        return $ccn;
    }

    /**
     * Formats a threshold number for the message, dropping a whole number's ".0" tail.
     *
     * @param int|float $number - Configured threshold to render; whole floats are shown without a trailing decimal.
     *
     * @return string - Human-readable threshold value.
     */
    private static function formatNumber(int|float $number): string
    {

        if (is_float($number) && floor($number) !== $number) {
            return (string) $number;
        }

        return (string) (int) $number;
    }
}
