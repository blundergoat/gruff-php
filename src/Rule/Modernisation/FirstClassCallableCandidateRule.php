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
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Detects callable arrays that can use first-class callable syntax.
 */
final readonly class FirstClassCallableCandidateRule implements RuleInterface
{
    /**
     * Stable rule identifier for first-class callable candidate findings.
     */
    public const ID = 'modernisation.first-class-callable-candidate';

    /**
     * Describe the first-class callable candidate rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'First-class callable candidate',
            pillar: Pillar::Modernisation,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence: Confidence::Medium,
        );
    }

    /**
     * Find array-callable expressions that may use first-class callable syntax.
     *
     * @return list<Finding> Findings for PHP 8.1 callable syntax candidates.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        if (!ModernisationNodeHelper::supportsPhp($context, 8.1)) {
            return [];
        }

        $finder = new NodeFinder();
        $findings = [];

        foreach ($finder->findInstanceOf($unit->statements, Expr\Array_::class) as $array) {
            if (!$this->isCallableArray($array) || !$this->isCallableContext($array)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId: self::ID,
                message: 'Array callable syntax may be replaceable with PHP 8.1 first-class callable syntax.',
                filePath: $unit->file->displayPath,
                line: $array->getStartLine(),
                severity: Severity::Advisory,
                pillar: Pillar::Modernisation,
                tier: RuleTier::V01,
                confidence: Confidence::Medium,
                remediation: 'Consider first-class callable syntax only when callable binding semantics remain equivalent; gruff-php reports only.',
                metadata: [
                    'requiresPhp' => 8.1,
                ],
            );
        }

        return $findings;
    }

    /**
     * Check whether an array expression has the two-part callable shape.
     *
     * @return bool True when the array looks like a callable pair.
     */
    private function isCallableArray(Expr\Array_ $array): bool
    {
        if (count($array->items) !== 2) {
            return false;
        }

        $target = $array->items[0]->value;
        $method = $array->items[1]->value;

        if (!$method instanceof Scalar\String_) {
            return false;
        }

        return $target instanceof Expr\Variable
            || $target instanceof Expr\PropertyFetch
            || $target instanceof Expr\ClassConstFetch;
    }

    /**
     * Check whether the array callable appears in a callable-friendly context.
     *
     * @return bool True when the parent context can accept a callable.
     */
    private function isCallableContext(Expr\Array_ $array): bool
    {
        $parent = ModernisationNodeHelper::parent($array);

        return $parent instanceof Node\Arg
            || $parent instanceof Expr\Assign
            || $parent instanceof Stmt\Return_;
    }
}
