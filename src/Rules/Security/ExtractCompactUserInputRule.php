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
 * Flags `extract()` and `compact()` call shapes whose first argument matches the request-data heuristic.
 * `extract()` imports locals; `compact()` exports existing locals named by its arguments.
 * Emits medium-confidence warnings without proving variable injection or inspecting later `compact()` arguments.
 */
final class ExtractCompactUserInputRule implements RuleInterface
{
    /**
     * Stable rule identifier for request variable-table findings.
     */
    public const ID = 'security.extract-compact-user-input';

    /**
     * Describes the extract/compact-on-request-data rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Medium confidence: request-data reachability does not prove that these calls introduce a vulnerability.
        return new RuleDefinition(
            id:              self::ID,
            name:            'extract or compact on request data',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
            falsePositiveShapes: [
                [
                    'shape'      => 'A compact() call naming fixed local variables, one of which was assigned from request data earlier in the same scope.',
                    'mitigation' => 'compact() and extract() share one taint check although compact() exports names the code chose; keep the explicit name list and accept the finding.',
                ],
            ],
        );
    }

    /**
     * Reports `extract()` or `compact()` calls whose first argument is traced to request data.
     *
     * @param AnalysisUnit $analysisUnit - Source unit supplying call nodes and their assignment context.
     * @param RuleContext $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - One finding per matching call; empty when no first argument is recognized as request-fed.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];


        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);

            if ($name === null || !in_array($name, ['compact', 'extract'], true)) {
                continue;
            }

            $firstArg = SecurityNodeHelper::sinkArgumentValue($call, 0);

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
