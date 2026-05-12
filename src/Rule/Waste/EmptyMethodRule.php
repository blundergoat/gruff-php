<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Waste;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\Complexity\CyclomaticComplexityRule;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

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
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Empty method',
            pillar: Pillar::DeadCode,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::High,
        );
    }

    /**
     * Find function-like declarations with empty bodies.
     *
     * @param AnalysisUnit $unit Parsed unit to inspect.
     * @param RuleContext $context Rule context for this analysis pass.
     * @return list<Finding> Findings for empty methods or functions.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder = new NodeFinder();
        $nodes = $finder->find($unit->statements, static function (Node $node): bool {
            return $node instanceof ClassMethod || $node instanceof Function_;
        });

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
                ruleId: $definition->id,
                message: sprintf('%s has an empty body.', $symbol),
                filePath: $unit->file->displayPath,
                line: $node->getStartLine(),
                severity: $definition->defaultSeverity,
                pillar: $definition->pillar,
                tier: $definition->tier,
                confidence: $definition->confidence,
                endLine: $node->getEndLine() > 0 ? $node->getEndLine() : null,
                symbol: $symbol,
                remediation: 'Implement the method or remove it if unneeded.',
            );
        }

        return $findings;
    }

    /**
     * Allow empty constructors that only define promoted properties.
     *
     * @return bool True when the constructor exists solely for property promotion.
     */
    private function isPromotedConstructor(ClassMethod $method): bool
    {
        if ($method->name->toString() !== '__construct') {
            return false;
        }

        foreach ($method->params as $param) {
            if ($param->isPromoted()) {
                return true;
            }
        }

        return false;
    }
}
