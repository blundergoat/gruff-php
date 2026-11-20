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
 * Flags empty bodies on named functions and non-abstract methods, which can indicate unfinished stubs.
 *
 * A promoted parameter supplies property-assignment work even when a constructor body is empty.
 * Closures are outside this rule's scan.
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
     * Reports empty named functions and non-abstract methods, excluding constructors recognized by the promotion check.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - One finding per eligible empty function or method; empty when none qualify.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodes      = NodeIndex::nodesOfAny($analysisUnit, [ClassMethod::class, Function_::class]);

        $findings = [];


        foreach ($nodes as $node) {
            /** @var ClassMethod|Function_ $node - NodeIndex selects only class methods and named functions. */
            // An abstract method has no body by design.
            if ($node instanceof ClassMethod && $node->isAbstract()) {
                continue;
            }

            // Bodyless declarations are exempt; even a Nop makes a present body nonempty for this check.
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
     * Reports whether an empty constructor has a promoted parameter, so its body still performs promotion work.
     *
     * @param ClassMethod $classMethod - Method to test; only `__construct` with promoted params earns the exemption.
     *
     * @return bool - True when the method is __construct and at least one parameter is promoted.
     */
    private function isPromotedConstructor(ClassMethod $classMethod): bool
    {

        if ($classMethod->name->toString() !== '__construct') {
            return false;
        }

        // A single promoted param means the empty body is really doing work.
        foreach ($classMethod->params as $param) {
            if ($param->isPromoted()) {

                return true;
            }
        }


        return false;
    }
}
