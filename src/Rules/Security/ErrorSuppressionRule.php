<?php

declare(strict_types=1);

namespace GruffPhp\Rules\Security;

use GruffPhp\Results\Finding\Confidence;
use GruffPhp\Results\Finding\Finding;
use GruffPhp\Results\Finding\Pillar;
use GruffPhp\Results\Finding\RuleTier;
use GruffPhp\Results\Finding\Severity;
use GruffPhp\Engine\Parser\AnalysisUnit;
use GruffPhp\Rules\Shared\NodeIndex;
use GruffPhp\Rules\Contracts\RuleContext;
use GruffPhp\Rules\Contracts\RuleDefinition;
use GruffPhp\Rules\Contracts\RuleInterface;
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per `@`-suppressed expression in the unit.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // User view: add each item that can appear in findings list.
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

        return $findings;
    }
}
