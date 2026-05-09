<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

final class HeaderInjectionRule implements RuleInterface
{
    public const ID = 'security.header-injection';

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Header injection risk',
            pillar: Pillar::Security,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::Medium,
        );
    }

    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $finder = new NodeFinder();
        $findings = [];

        foreach ($finder->findInstanceOf($unit->statements, Expr\FuncCall::class) as $call) {
            if (SecurityNodeHelper::globalFunctionName($call) !== 'header') {
                continue;
            }

            $firstArg = SecurityNodeHelper::argumentValue($call->args, 0);
            if ($firstArg === null || !SecurityNodeHelper::containsUserInput($firstArg)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId: self::ID,
                message: 'Heuristic header() call with request-controlled data detected.',
                filePath: $unit->file->displayPath,
                line: $call->getStartLine(),
                severity: Severity::Warning,
                pillar: Pillar::Security,
                tier: RuleTier::V01,
                confidence: Confidence::Medium,
                remediation: 'Validate and normalize redirect/header values, and reject CR/LF characters before calling header().',
            );
        }

        return $findings;
    }
}
