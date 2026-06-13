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
     * @return RuleDefinition - Rule metadata and defaults.
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
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for unsafe archive extraction.
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
     * Check whether an extractTo() call draws its destination or entry list from request input.
     *
     * @param Expr\MethodCall|Expr\StaticCall $call - extractTo() call whose first two arguments (destination, entries)
     *                                              are taint-checked against request data.
     *
     * @return bool - True when destination or selected entries come from request data.
     */
    private function hasRequestControlledExtractionArgument(Expr\MethodCall|Expr\StaticCall $call): bool
    {
        foreach ([0, 1] as $argumentIndex) {
            $argument = SecurityNodeHelper::argumentValue($call->args, $argumentIndex);
            if ($argument !== null && SecurityNodeHelper::containsUserInput($argument)) {
                // A request-tainted destination or entry list is enough to flag the extraction.
                return true;
            }
        }

        // Neither modelled argument carried request taint, so the extraction target is trusted.
        return false;
    }

    /**
     * Build the unsafe archive extraction finding.
     *
     * @param AnalysisUnit $analysisUnit - Unit being scanned; supplies the display path recorded on the finding.
     * @param Node         $node - extractTo() call flagged as unsafe; its start line locates the finding.
     *
     * @return Finding - Security finding.
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node): Finding
    {
        // Request-controlled extraction enables path traversal, so flag it as a warning with remediation guidance.
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
