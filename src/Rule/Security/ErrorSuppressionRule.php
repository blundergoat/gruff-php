<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node\Expr;

/**
 * Detects error suppression operators that hide runtime failures.
 */
final class ErrorSuppressionRule implements RuleInterface
{
    /**
     * Stable rule identifier for error suppression findings.
     */
    public const ID = 'security.error-suppression';

    /**
     * Describe the error suppression security rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // High confidence: the `@` operator is an unambiguous AST node, so the gate can rely on this warning.
        return new RuleDefinition(
            id:               self::ID,
            name:             'Error suppression operator',
            pillar:           Pillar::Security,
            tier:             RuleTier::V01,
            defaultSeverity:  Severity::Warning,
            confidence:       Confidence::High,
            secondaryPillars: [Pillar::Modernisation],
        );
    }

    /**
     * Find uses of PHP error suppression that can hide failures.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for suppressed expressions.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\ErrorSuppress::class) as $node) {
            $findings[] = new Finding(
                ruleId:           self::ID,
                message:          'Error suppression operator hides failures.',
                filePath:         $analysisUnit->file->displayPath,
                line:             $node->getStartLine(),
                severity:         Severity::Warning,
                pillar:           Pillar::Security,
                tier:             RuleTier::V01,
                confidence:       Confidence::High,
                remediation:      'Handle the specific failure mode explicitly instead of suppressing errors with @.',
                secondaryPillars: [Pillar::Modernisation],
            );
        }

        // One finding per `@`-suppressed expression in this unit; empty when the file uses none.
        return $findings;
    }
}
