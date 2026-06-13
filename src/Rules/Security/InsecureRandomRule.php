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
 * Detects random sources that are unsuitable for security-sensitive decisions.
 */
final class InsecureRandomRule implements RuleInterface
{
    /**
     * Stable rule identifier for insecure random findings.
     */
    public const ID = 'security.insecure-random';

    /**
     * @var list<string>
     */
    private const INSECURE_RANDOM_FUNCTIONS = ['lcg_value', 'mt_rand', 'rand'];

    /**
     * Describe the insecure random security rule.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // High confidence: the flagged function names are unambiguous, so the gate can trust this warning.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Insecure random source',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find random APIs that are unsuitable for security-sensitive values.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per non-cryptographic random call in the unit.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            if ($name === null || !in_array($name, self::INSECURE_RANDOM_FUNCTIONS, true)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     sprintf('Insecure random source detected: %s().', $name),
                filePath:    $analysisUnit->file->displayPath,
                line:        $call->getStartLine(),
                severity:    Severity::Warning,
                pillar:      Pillar::Security,
                tier:        RuleTier::V01,
                confidence:  Confidence::High,
                remediation: 'Use random_int() or random_bytes() for security-sensitive randomness.',
                metadata:    [
                    'function' => $name,
                ],
            );
        }

        return $findings;
    }
}
