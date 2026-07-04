<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Waste;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

/**
 * Flags a method or function with a genuinely empty body, catching stubs that were never filled in -
 * while exempting promoted constructors, whose empty body is doing real assignment work.
 *
 * Runs per file over every callable, skipping abstract methods. A non-abstract empty body is reported at
 * advisory; a constructor that exists only to promote properties is left alone.
 */
final readonly class EmptyMethodRule implements RuleInterface
{
    /**
     * Stable rule identifier for empty method findings.
     */
    public const ID = 'waste.empty-method';

    /**
     * Describes the empty-method rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // High confidence because an empty body is unambiguous; advisory keeps the finding opt-in.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Empty method',
            pillar:          Pillar::DeadCode,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::High,
        );
    }

    /**
     * Reports each non-abstract empty callable body, excluding promoted constructors.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per non-abstract empty body, excluding promoted constructors.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodes      = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];

        // Check each function and method in the file.
        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            // An abstract method has no body by design.
            if ($node instanceof ClassMethod && $node->isAbstract()) {
                continue;
            }

            // Only a present-but-empty body counts: null means bodyless, a non-empty list means real code.
            if ($node->stmts === null || $node->stmts !== []) {
                continue;
            }

            // A promoted constructor's empty body still does assignment work, so skip it.
            if ($node instanceof ClassMethod && $this->isPromotedConstructor($node)) {
                continue;
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('%s has an empty body.', $symbol),
                filePath:    $analysisUnit->file->displayPath,
                line:        $node->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                endLine:     $node->getEndLine() > 0 ? $node->getEndLine() : null,
                symbol:      $symbol,
                remediation: 'Implement the method or remove it if unneeded.',
            );
        }

        return $findings;
    }

    /**
     * Reports whether an empty constructor exists only to promote properties, so its empty body is fine.
     *
     * @param ClassMethod $classMethod - Method to test; only `__construct` with promoted params earns the exemption.
     *
     * @return bool - True when the constructor exists solely for property promotion.
     */
    private function isPromotedConstructor(ClassMethod $classMethod): bool
    {
        // Non-constructors gain nothing from an empty body, so they stay reportable.
        if ($classMethod->name->toString() !== '__construct') {
            return false;
        }

        // A single promoted param means the empty body is really doing work.
        foreach ($classMethod->params as $param) {
            if ($param->isPromoted()) {
                // A promoted param means the empty body is doing real work (assigning the property); exempt it.
                return true;
            }
        }

        // A parameterless or non-promoting empty constructor carries no behaviour, so it remains a finding.
        return false;
    }
}
