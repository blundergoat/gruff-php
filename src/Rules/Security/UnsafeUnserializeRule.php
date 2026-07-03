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
      * User flow: Decides whether this rule adds a finding to the user report.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for unsafe unserialize calls; empty when none take untrusted input.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            // User view: choose the findings list branch for this case.
            if (SecurityNodeHelper::globalFunctionName($call) !== 'unserialize') {
                continue;
            }

            $firstArg = SecurityNodeHelper::argumentValue($call->args, 0);
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($firstArg === null || SecurityNodeHelper::isStringLiteral($firstArg)) {
                continue;
            }

            // User view: choose the findings list branch for this case.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall $call - unserialize() call whose second argument is checked for an options array.
     *
     * @return bool - True when object deserialization has been disabled by options.
     */
    private function hasAllowedClassesFalse(Expr\FuncCall $call): bool
    {
        $options = SecurityNodeHelper::argumentValue($call->args, 1);
        // User view: choose the findings list branch for this case.
        if (!$options instanceof Expr\Array_) {
            // No literal options array means we cannot prove the guardrail is set, so treat the call as unguarded.
            return false;
        }

        // User view: add each item that can appear in findings list.
        foreach ($options->items as $item) {
            // User view: choose the findings list branch for this case.
            if (!$item->key instanceof Scalar\String_) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if ($item->key->value === 'allowed_classes' && SecurityNodeHelper::isFalseLike($item->value)) {
                // allowed_classes => false disables object hydration entirely, which neutralises the gadget-chain risk.
                return true;
            }
        }

        // The options array exists but never sets allowed_classes to false, so object hydration is still possible.
        return false;
    }
}
