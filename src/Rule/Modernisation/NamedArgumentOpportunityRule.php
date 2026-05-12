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
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

final readonly class NamedArgumentOpportunityRule implements RuleInterface
{
    public const ID = 'modernisation.named-argument-opportunity';

    /**
     * Describe the named argument opportunity rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Named argument opportunity',
            pillar: Pillar::Modernisation,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Low,
            defaultThresholds: ['minPositionalArguments' => 5],
        );
    }

    /**
     * Find calls with many positional arguments that would read better named.
     *
     * @return list<Finding> Findings for named argument opportunities.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        if (!ModernisationNodeHelper::supportsPhp($context, 8.0)) {
            return [];
        }

        $definition = $this->definition();
        $minPositionalArguments = (int) $context->settingsFor($definition)->numericThreshold('minPositionalArguments');
        $finder = new NodeFinder();
        $findings = [];

        foreach ($finder->find($unit->statements, static fn (Node $node): bool => $node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) as $call) {
            if (!$call instanceof Expr\MethodCall && !$call instanceof Expr\StaticCall) {
                continue;
            }

            $reason = $this->reason($call->args, $minPositionalArguments);
            if ($reason === null) {
                continue;
            }

            $findings[] = new Finding(
                ruleId: self::ID,
                message: sprintf('Call with %s may be clearer with PHP 8 named arguments.', $reason),
                filePath: $unit->file->displayPath,
                line: $call->getStartLine(),
                severity: Severity::Advisory,
                pillar: Pillar::Modernisation,
                tier: RuleTier::V01,
                confidence: Confidence::Low,
                remediation: 'Consider named arguments only for stable APIs where parameter names are part of the intended contract; gruff-php reports only.',
                metadata: [
                    'requiresPhp' => 8.0,
                    'reason' => $reason,
                ],
            );
        }

        return $findings;
    }

    /**
     * @param array<int|string, Node\Arg|Node\VariadicPlaceholder> $args
     * @return string|null Explanation when the call should use named arguments.
     */
    private function reason(array $args, int $minPositionalArguments): ?string
    {
        $positionalCount = 0;

        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg || $arg->name !== null) {
                continue;
            }

            $positionalCount++;

        }

        return $positionalCount >= $minPositionalArguments ? sprintf('%d positional arguments', $positionalCount) : null;
    }
}
