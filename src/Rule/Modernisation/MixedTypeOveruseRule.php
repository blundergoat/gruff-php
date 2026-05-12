<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Modernisation;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

final readonly class MixedTypeOveruseRule implements RuleInterface
{
    public const ID = 'modernisation.mixed-type-overuse';

    /**
     * Describe the mixed type overuse rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Mixed type overuse',
            pillar: Pillar::Modernisation,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Medium,
        );
    }

    /**
     * Find parameters and returns that overuse explicit mixed types.
     *
     * @return list<Finding> Findings for mixed type overuse.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        if (!ModernisationNodeHelper::supportsPhp($context, 8.0)) {
            return [];
        }

        $finder = new NodeFinder();
        $findings = [];

        foreach ($finder->find($unit->statements, static fn (Node $node): bool => $node instanceof Stmt\ClassMethod || $node instanceof Stmt\Function_) as $functionLike) {
            if ($functionLike instanceof Stmt\ClassMethod && !$functionLike->isPublic()) {
                continue;
            }

            if (!$functionLike instanceof Stmt\ClassMethod && !$functionLike instanceof Stmt\Function_) {
                continue;
            }

            $locations = $this->mixedLocations($functionLike);
            if ($locations === []) {
                continue;
            }

            $findings[] = new Finding(
                ruleId: self::ID,
                message: sprintf('Public API uses mixed type in %s.', implode(', ', $locations)),
                filePath: $unit->file->displayPath,
                line: $functionLike->getStartLine(),
                severity: Severity::Advisory,
                pillar: Pillar::Modernisation,
                tier: RuleTier::V01,
                confidence: Confidence::Medium,
                symbol: $functionLike->name->toString() . '()',
                remediation: 'Prefer narrower value objects, unions, or documented generics when the accepted shape is known; gruff-php reports only.',
                metadata: [
                    'requiresPhp' => 8.0,
                    'locations' => $locations,
                ],
            );
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function mixedLocations(Stmt\ClassMethod|Stmt\Function_ $functionLike): array
    {
        $locations = [];

        foreach ($functionLike->params as $parameter) {
            if (ModernisationNodeHelper::typeName($parameter->type) === 'mixed') {
                $name = $parameter->var instanceof \PhpParser\Node\Expr\Variable && is_string($parameter->var->name)
                    ? '$' . $parameter->var->name
                    : 'parameter';
                $locations[] = $name;
            }
        }

        if (ModernisationNodeHelper::typeName($functionLike->returnType) === 'mixed') {
            $locations[] = 'return type';
        }

        return $locations;
    }
}
