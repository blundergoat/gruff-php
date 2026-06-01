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
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * Detects unserialize calls that can hydrate attacker-controlled payloads.
 */
final class UnsafeUnserializeRule implements RuleInterface
{
    /**
     * Stable rule identifier for unsafe unserialize findings.
     */
    public const ID = 'security.unsafe-unserialize';

    /**
     * Describe the unsafe unserialize security rule.
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
        );
    }

    /**
     * Find unserialize calls that can deserialize untrusted data.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for unsafe unserialize calls; empty when none take untrusted input.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            if (SecurityNodeHelper::globalFunctionName($call) !== 'unserialize') {
                continue;
            }

            $firstArg = SecurityNodeHelper::argumentValue($call->args, 0);
            if ($firstArg === null || SecurityNodeHelper::isStringLiteral($firstArg)) {
                continue;
            }

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
     * Detect `unserialize($payload, ['allowed_classes' => false])` object-hydration guardrails.
     *
     * @param Expr\FuncCall $call - unserialize() call whose second argument is checked for an options array.
     *
     * @return bool - True when object deserialization has been disabled by options.
     */
    private function hasAllowedClassesFalse(Expr\FuncCall $call): bool
    {
        $options = SecurityNodeHelper::argumentValue($call->args, 1);
        if (!$options instanceof Expr\Array_) {
            // No literal options array means we cannot prove the guardrail is set, so treat the call as unguarded.
            return false;
        }

        foreach ($options->items as $item) {
            if (!$item->key instanceof Scalar\String_) {
                continue;
            }

            if ($item->key->value === 'allowed_classes' && SecurityNodeHelper::isFalseLike($item->value)) {
                // allowed_classes => false disables object hydration entirely, which neutralises the gadget-chain risk.
                return true;
            }
        }

        // The options array exists but never sets allowed_classes to false, so object hydration is still possible.
        return false;
    }
}
