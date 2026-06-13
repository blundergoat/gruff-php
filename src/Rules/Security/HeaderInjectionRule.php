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
 * Detects dynamic header values that may allow response splitting.
 */
final class HeaderInjectionRule implements RuleInterface
{
    /**
     * Stable rule identifier for header injection findings.
     */
    public const ID = 'security.header-injection';

    /**
     * Describe the header injection security rule.
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
     * Find header calls that may receive unsanitized user input.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per header() call reached by request data.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            if (SecurityNodeHelper::globalFunctionName($call) !== 'header') {
                continue;
            }

            $firstArg = SecurityNodeHelper::argumentValue($call->args, 0);
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
