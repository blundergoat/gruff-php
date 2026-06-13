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
 * Detects empty method and function bodies that do not communicate useful intent.
 */
final readonly class EmptyMethodRule implements RuleInterface
{
    /**
     * Stable rule identifier for empty method findings.
     */
    public const ID = 'waste.empty-method';

    /**
     * Describe the empty method rule.
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
     * Find function-like declarations with empty bodies.
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

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node Finder predicate restricts results to function-like nodes. */
            if ($node instanceof ClassMethod && $node->isAbstract()) {
                continue;
            }

            if ($node->stmts === null || $node->stmts !== []) {
                continue;
            }

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
     * Allow empty constructors that only define promoted properties.
     *
     * @param ClassMethod $classMethod - Method to test; only `__construct` with promoted params earns the exemption.
     *
     * @return bool - True when the constructor exists solely for property promotion.
     */
    private function isPromotedConstructor(ClassMethod $classMethod): bool
    {
        if ($classMethod->name->toString() !== '__construct') {
            // Non-constructors gain nothing from an empty body, so they stay reportable.
            return false;
        }

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
