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
 * Flags the `@` error-suppression operator, which silences whatever failure the expression hits and leaves
 * the user staring at a blank result with no clue why an operation quietly did nothing.
 *
 * Runs per file over every suppression node. Warning, high confidence - the operator is an unambiguous AST
 * node, and modernisation is a secondary pillar since explicit error handling is the modern replacement.
 */
final class ErrorSuppressionRule implements RuleInterface
{
    /**
     * Stable rule identifier for error suppression findings.
     */
    public const ID = 'security.error-suppression';

    /**
     * Describes the error-suppression rule for the registry and reports.
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
     * Reports each `@`-suppressed expression that can hide a runtime failure.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per `@`-suppressed expression in the unit.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Flag every `@`-suppressed expression in the file.
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
