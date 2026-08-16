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
 * Flags an `extract()` or `compact()` call whose data comes from the request, the shape that lets an
 * attacker spawn arbitrary local variables - so the user maps request fields explicitly instead.
 *
 * Runs per file, matching global extract/compact calls whose first argument reaches user input. Warning,
 * medium confidence - a request-fed call is a likely variable-injection sink, not a certain one.
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
     * Reports each `extract()` or `compact()` call reached by request data.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per extract/compact call reached by request data.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Check every function call in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            // Only extract() and compact() import a whole variable table.
            if ($name === null || !in_array($name, ['compact', 'extract'], true)) {
                continue;
            }

            $firstArg = SecurityNodeHelper::sinkArgumentValue($call, 0);
            // A call with no request-controlled input is safe, so skip it.
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
