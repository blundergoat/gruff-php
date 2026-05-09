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
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

final readonly class UnusedParameterRule implements RuleInterface
{
    public const ID = 'waste.unused-parameter';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Unused parameter',
            pillar: Pillar::DeadCode,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $definition = $this->definition();
        $finder = new NodeFinder();
        $nodes = $finder->find($unit->statements, static function (Node $node): bool {
            if ($node instanceof Function_) {
                return true;
            }

            return $node instanceof ClassMethod && $node->isPrivate();
        });

        $findings = [];

        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node */
            if ($node instanceof ClassMethod && $node->isAbstract()) {
                continue;
            }

            if ($node->stmts === null || $node->params === []) {
                continue;
            }

            $paramNames = [];

            foreach ($node->params as $param) {
                if ($param->var instanceof Variable && is_string($param->var->name)) {
                    $paramNames[$param->var->name] = $param;
                }
            }

            $usedVars = $finder->find($node->stmts, static function (Node $child): bool {
                return $child instanceof Variable && is_string($child->name);
            });

            $usedNames = [];

            foreach ($usedVars as $var) {
                /** @var Variable $var */
                if (is_string($var->name)) {
                    $usedNames[$var->name] = true;
                }
            }

            $symbol = CyclomaticComplexityRule::resolveSymbol($node);

            foreach ($paramNames as $name => $param) {
                if (isset($usedNames[$name])) {
                    continue;
                }

                $findings[] = new Finding(
                    ruleId: $definition->id,
                    message: sprintf('Parameter $%s in %s is never used.', $name, $symbol),
                    filePath: $unit->file->displayPath,
                    line: $param->getStartLine(),
                    severity: $definition->defaultSeverity,
                    pillar: $definition->pillar,
                    tier: $definition->tier,
                    confidence: $definition->confidence,
                    symbol: $symbol,
                    remediation: 'Remove the parameter or use it in the method body.',
                    metadata: ['parameter' => $name],
                );
            }
        }

        return $findings;
    }
}
