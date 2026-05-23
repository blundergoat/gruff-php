<?php

declare(strict_types=1);

namespace GruffPhp\Rule\Security;

use GruffPhp\Finding\Confidence;
use GruffPhp\Finding\Finding;
use GruffPhp\Finding\Pillar;
use GruffPhp\Finding\RuleTier;
use GruffPhp\Finding\Severity;
use GruffPhp\Parser\AnalysisUnit;
use GruffPhp\Rule\NodeIndex;
use GruffPhp\Rule\RuleContext;
use GruffPhp\Rule\RuleDefinition;
use GruffPhp\Rule\RuleInterface;
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Detects archive extraction to request-controlled destinations.
 */
final class UnsafeArchiveExtractionRule implements RuleInterface
{
    /**
     * Stable rule identifier for unsafe archive extraction.
     */
    public const ID = 'security.unsafe-archive-extraction';

    /**
     * Describe the unsafe archive extraction rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id:              self::ID,
            name:            'Unsafe archive extraction',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find archive extraction calls with request-controlled destinations or entries.
     *
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for unsafe archive extraction.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\MethodCall::class) as $call) {
            if (SecurityNodeHelper::methodName($call) !== 'extractto') {
                continue;
            }

            if ($this->hasRequestControlledExtractionArgument($call)) {
                $findings[] = $this->finding($analysisUnit, $call);
            }
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\StaticCall::class) as $call) {
            if (
                SecurityNodeHelper::methodName($call) !== 'extractto'
                || !SecurityNodeHelper::hasMatchingClassName($call->class, ['PharData', 'ZipArchive'])
            ) {
                continue;
            }

            if ($this->hasRequestControlledExtractionArgument($call)) {
                $findings[] = $this->finding($analysisUnit, $call);
            }
        }

        return $findings;
    }

    /**
     * @return bool True when destination or selected entries come from request data.
     */
    private function hasRequestControlledExtractionArgument(Expr\MethodCall|Expr\StaticCall $call): bool
    {
        foreach ([0, 1] as $argumentIndex) {
            $argument = SecurityNodeHelper::argumentValue($call->args, $argumentIndex);
            if ($argument !== null && SecurityNodeHelper::containsUserInput($argument)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the unsafe archive extraction finding.
     *
     * @return Finding Security finding.
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node): Finding
    {
        return new Finding(
            ruleId:      self::ID,
            message:     'Archive extraction with request-controlled destination or entries detected.',
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::Security,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Extract only to a controlled temporary directory, validate archive entries, and reject traversal paths.',
            metadata:    [
                'sink' => 'extractTo',
            ],
        );
    }
}
