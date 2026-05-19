<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Naming;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;

/**
 * Detects standalone class names that hide responsibility.
 */
final readonly class ConfusingNameRule implements RuleInterface
{
    /**
     * Stable identifier for the confusing name rule.
     */
    public const ID = 'naming.confusing-name';

    /**
     * Class names that are too vague when used alone.
     */
    private const CONFUSING_STANDALONE = [
        'Data', 'Info', 'Manager', 'Handler', 'Helper', 'Util', 'Utils',
        'Service', 'Processor', 'Base', 'Common', 'Misc',
    ];

    /**
     * Describe the confusing name rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Confusing standalone class name',
            pillar:          Pillar::Naming,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Advisory,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find identifiers whose names are ambiguous or visually confusing.
     *
     * @param AnalysisUnit $analysisUnit    Parsed unit to inspect.
     * @param RuleContext  $ruleContext Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for confusing identifiers.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $definition = $this->definition();
        $nodeFinder = new NodeFinder();
        $classes    = $nodeFinder->findInstanceOf($analysisUnit->statements, Class_::class);

        $findings = [];

        foreach ($classes as $class) {
            /** @var Class_ $class Finder predicate restricts results to class declarations. */
            $name = $class->name?->toString();

            if ($name === null) {
                continue;
            }

            if (!in_array($name, self::CONFUSING_STANDALONE, true)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      $definition->id,
                message:     sprintf('Class %s is a vague standalone name that does not communicate responsibility.', $name),
                filePath:    $analysisUnit->file->displayPath,
                line:        $class->getStartLine(),
                severity:    $definition->defaultSeverity,
                pillar:      $definition->pillar,
                tier:        $definition->tier,
                confidence:  $definition->confidence,
                symbol:      $name,
                remediation: 'Use a domain-specific name, e.g. UserManager → UserRegistrar, Helper → InvoiceFormatter.',
            );
        }

        return $findings;
    }
}
