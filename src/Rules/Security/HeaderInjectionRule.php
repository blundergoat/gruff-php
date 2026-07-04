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
 * Flags a `header()` call whose value is built from request data, the shape that lets an attacker inject
 * CR/LF and split the response - so the user reviews it before a redirect or header leaks extra content.
 *
 * Runs per file, matching global header() calls whose first argument reaches user input. Warning, medium
 * confidence - a request-fed header is a likely response-splitting sink, not a certain one.
 */
final class HeaderInjectionRule implements RuleInterface
{
    /**
     * Stable rule identifier for header injection findings.
     */
    public const ID = 'security.header-injection';

    /**
     * Describes the header-injection rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Medium confidence by default: a request-fed header() is a likely response-splitting sink, not certain.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Header injection risk',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Reports each `header()` call reached by request-controlled data.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per header() call reached by request data.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Check every function call in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            // Only a call to the global header() can split the response.
            if (SecurityNodeHelper::globalFunctionName($call) !== 'header') {
                continue;
            }

            $firstArg = SecurityNodeHelper::argumentValue($call->args, 0);
            // A header value with no request-controlled data is safe, so skip it.
            if ($firstArg === null || !SecurityNodeHelper::containsUserInput($firstArg)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     'Heuristic header() call with request-controlled data detected.',
                filePath:    $analysisUnit->file->displayPath,
                line:        $call->getStartLine(),
                severity:    Severity::Warning,
                pillar:      Pillar::Security,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                remediation: 'Validate and normalize redirect/header values, and reject CR/LF characters before calling header().',
            );
        }

        return $findings;
    }
}
