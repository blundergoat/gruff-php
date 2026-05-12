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

/**
 * Detects include and require paths built from variables.
 */
final class VariableIncludeRule implements RuleInterface
{
    /**
     * Stable rule identifier for variable include findings.
     */
    public const ID = 'security.variable-include';

    /**
     * Describe the variable include security rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Variable include or require path',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find include and require expressions using dynamic paths.
     *
     * @param AnalysisUnit $unit    Parsed unit to inspect.
     * @param RuleContext  $context Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for variable include paths.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $finder   = new NodeFinder();
        $findings = [];

        foreach ($finder->findInstanceOf($unit->statements, Expr\Include_::class) as $include) {
            if (SecurityNodeHelper::isStringLiteral($include->expr)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     'Variable include/require path detected.',
                filePath:    $unit->file->displayPath,
                line:        $include->getStartLine(),
                severity:    Severity::Warning,
                pillar:      Pillar::Security,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                remediation: 'Use fixed include paths or map request values through an allow-list before loading files.',
            );
        }

        return $findings;
    }
}
