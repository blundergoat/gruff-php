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
 * Detects extract and compact calls on request-shaped input data.
 */
final class ExtractCompactUserInputRule implements RuleInterface
{
    /**
     * Stable rule identifier for request variable-table findings.
     */
    public const ID = 'security.extract-compact-user-input';

    /**
     * Describe the extract or compact user input security rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Medium confidence: a request-fed extract/compact is a likely variable-injection sink, not certain.
        return new RuleDefinition(
            id:              self::ID,
            name:            'extract or compact on request data',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find extract and compact calls that operate on user-controlled input.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per extract/compact call reached by request data.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($name === null || !in_array($name, ['compact', 'extract'], true)) {
                continue;
            }

            $firstArg = SecurityNodeHelper::argumentValue($call->args, 0);
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($firstArg === null || !SecurityNodeHelper::containsUserInput($firstArg)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('Heuristic %s() call on request-controlled data detected.', $name),
                filePath:    $analysisUnit->file->displayPath,
                line:        $call->getStartLine(),
                severity:    Severity::Warning,
                pillar:      Pillar::Security,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                remediation: 'Map request fields explicitly instead of mass-importing user input into local variables.',
                metadata:    [
                    'function' => $name,
                ],
            );
        }

        return $findings;
    }
}
