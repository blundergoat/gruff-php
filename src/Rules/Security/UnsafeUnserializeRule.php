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
use PhpParser\Node\Scalar;

/**
 * Flags an `unserialize()` call whose payload is not a fixed literal, the shape that can hydrate an
 * attacker-controlled object and trigger a gadget chain - so the user gates it before it reaches the sink.
 *
 * Runs per file over global unserialize() calls, skipping literal payloads and calls that disable object
 * hydration with `allowed_classes => false`. Warning, medium confidence.
 */
final class UnsafeUnserializeRule implements RuleInterface
{
    /**
     * Stable rule identifier for unsafe unserialize findings.
     */
    public const ID = 'security.unsafe-unserialize';

    /**
     * Describes the unsafe-unserialize rule for the registry and reports.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Medium confidence: a non-literal first argument is heuristic, not proof of attacker control; warn not error.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Unsafe unserialize usage',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
            // Off unless a project sets enabled: true: precision-floor M02 found it right on 2 of 8 judged findings, too few
            // to establish its precision floor, so it is off by default rather than deleted (ADR-034).
            isEnabledByDefault: false,
            falsePositiveShapes: [
                [
                    'shape'      => 'An unserialize() of trusted internal data, such as a value the application wrote to its own cache or database.',
                    'mitigation' => 'Any non-literal payload is reported regardless of provenance, so pass [\'allowed_classes\' => false], which is recognised as safe, or store the value as JSON.',
                ],
            ],
        );
    }

    /**
     * Reports unserialize() calls whose resolved payload is not a literal string.
     * Calls with a recognised options guard are skipped.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - One finding per matched call; empty when no call meets the heuristic.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];


        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {

            if (SecurityNodeHelper::globalFunctionName($call) !== 'unserialize') {
                continue;
            }

            $firstArg = SecurityNodeHelper::sinkArgumentValue($call, 0);
            // A literal-string payload is fixed, not attacker-controlled.
            if ($firstArg === null || SecurityNodeHelper::isStringLiteral($firstArg)) {
                continue;
            }

            // For this rule, a literal allowed_classes => false option suppresses the object-hydration finding.
            if ($this->hasAllowedClassesFalse($call)) {
                continue;
            }

            $findings[] = new Finding(
                ruleId:      self::ID,
                message:     'Heuristic unsafe unserialize() input detected.',
                filePath:    $analysisUnit->file->displayPath,
                line:        $call->getStartLine(),
                severity:    Severity::Warning,
                pillar:      Pillar::Security,
                tier:        RuleTier::V01,
                confidence:  Confidence::Medium,
                remediation: 'Avoid unserialize() on untrusted data, or pass allowed_classes with strict input provenance.',
            );
        }

        return $findings;
    }

    /**
     * Reports whether a literal options array contains allowed_classes => false.
     *
     * @param Expr\FuncCall $call - unserialize() call whose second argument is checked for an options array.
     *
     * @return bool - True when the literal option disables application-class hydration for this rule.
     */
    private function hasAllowedClassesFalse(Expr\FuncCall $call): bool
    {
        $options = SecurityNodeHelper::sinkArgumentValue($call, 1);
        if (!$options instanceof Expr\Array_) {
            // No literal options array means we cannot prove the guardrail is set, so treat the call as unguarded.
            return false;
        }


        foreach ($options->items as $item) {

            if (!$item->key instanceof Scalar\String_) {
                continue;
            }

            if ($item->key->value === 'allowed_classes' && SecurityNodeHelper::isFalseLike($item->value)) {
                // For this rule, allowed_classes => false suppresses the gadget-chain finding.
                return true;
            }
        }

        // No literal disabling option was recognised; retain the call as a heuristic candidate.
        return false;
    }
}
