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
 * Flags each `@` error-suppression node with one warning; confidence is medium because a suppression whose failure the code then checks is often deliberate.
 * Does not establish whether the expression fails or which diagnostics are suppressed.
 * Recommends explicit error handling, with modernisation as a secondary pillar.
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
        // Medium confidence: the operator is an unambiguous AST node, but a suppression whose failed result is checked nearby is often deliberate.
        return new RuleDefinition(
            id:               self::ID,
            name:             'Error suppression operator',
            pillar:           Pillar::Security,
            tier:             RuleTier::V01,
            defaultSeverity:  Severity::Warning,
            confidence:       Confidence::Medium,
            secondaryPillars: [Pillar::Modernisation],
            falsePositiveShapes: [
                [
                    'shape'      => 'A connection or read call prefixed with `@` is reported even when nearby code checks and handles the failed result.',
                    'mitigation' => 'Keep the result check beside the call and trace the failure to its diagnostic, fallback, or loop exit.',
                ],
                [
                    'shape'      => 'The rule flags `@` on a deprecation replay or a best-effort `chmod` even when the surrounding code documents why suppression is intentional.',
                    'mitigation' => 'Keep the reason beside the call so readers can verify that the replay preserves the original suppression or that execution can continue if `chmod` fails.',
                ],
            ],
        );
    }

    /**
     * Reports each `@`-suppressed expression that can hide a runtime failure.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - One finding per `@`-suppressed expression in the unit.
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
                confidence:       $this->definition()->confidence,
                remediation:      'Handle the specific failure mode explicitly instead of suppressing errors with @.',
                secondaryPillars: [Pillar::Modernisation],
            );
        }

        return $findings;
    }
}
