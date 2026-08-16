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
        );
    }

    /**
     * Reports each `unserialize()` call that can hydrate untrusted data.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for unsafe unserialize calls; empty when none take untrusted input.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Check every function call in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            // Only a global unserialize() is an object-hydration sink.
            if (SecurityNodeHelper::globalFunctionName($call) !== 'unserialize') {
                continue;
            }

            $firstArg = SecurityNodeHelper::sinkArgumentValue($call, 0);
            // A literal-string payload is fixed, not attacker-controlled.
            if ($firstArg === null || SecurityNodeHelper::isStringLiteral($firstArg)) {
                continue;
            }

            // Object hydration disabled by allowed_classes => false is already safe.
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
     * Reports whether the call disables object hydration via `allowed_classes => false`.
     *
     * @param Expr\FuncCall $call - unserialize() call whose second argument is checked for an options array.
     *
     * @return bool - True when object deserialization has been disabled by options.
     */
    private function hasAllowedClassesFalse(Expr\FuncCall $call): bool
    {
        $options = SecurityNodeHelper::sinkArgumentValue($call, 1);
        if (!$options instanceof Expr\Array_) {
            // No literal options array means we cannot prove the guardrail is set, so treat the call as unguarded.
            return false;
        }

        // Weigh each key in the options array.
        foreach ($options->items as $item) {
            // Only a string key can be the allowed_classes option.
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
