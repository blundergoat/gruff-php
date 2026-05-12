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
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

final class DisabledSslVerificationRule implements RuleInterface
{
    public const ID = 'security.disabled-ssl-verification';

    /**
     * Describe the disabled SSL verification rule.
     *
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            id: self::ID,
            name: 'Disabled SSL verification',
            pillar: Pillar::Security,
            tier: RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence: Confidence::High,
        );
    }

    /**
     * Find cURL calls that disable peer or hostname verification.
     *
     * @return list<Finding> Findings for disabled SSL verification.
     */
    public function analyse(AnalysisUnit $unit, RuleContext $context): array
    {
        $finder = new NodeFinder();
        $findings = [];

        foreach ($finder->findInstanceOf($unit->statements, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            if ($name === 'curl_setopt' && $this->isDisabledCurlSetopt($call)) {
                $findings[] = $this->finding($unit, $call);
            }

            if ($name === 'curl_setopt_array' && $this->isDisabledCurlSetoptArray($call)) {
                $findings[] = $this->finding($unit, $call);
            }
        }

        return $findings;
    }

    /**
     * Detect disabled verification in a `curl_setopt` call.
     *
     * @return bool True when SSL verification is disabled.
     */
    private function isDisabledCurlSetopt(Expr\FuncCall $call): bool
    {
        $optionArg = SecurityNodeHelper::argumentValue($call->args, 1);
        $valueArg = SecurityNodeHelper::argumentValue($call->args, 2);

        if ($optionArg === null || $valueArg === null) {
            return false;
        }

        $option = SecurityNodeHelper::constantName($optionArg);
        if ($option === 'CURLOPT_SSL_VERIFYPEER') {
            return SecurityNodeHelper::isFalseLike($valueArg);
        }

        if ($option === 'CURLOPT_SSL_VERIFYHOST') {
            return SecurityNodeHelper::isFalseLike($valueArg);
        }

        return false;
    }

    /**
     * Detect disabled verification in a `curl_setopt_array` option map.
     *
     * @return bool True when the option array disables SSL verification.
     */
    private function isDisabledCurlSetoptArray(Expr\FuncCall $call): bool
    {
        $optionsArg = SecurityNodeHelper::argumentValue($call->args, 1);
        if (!$optionsArg instanceof Expr\Array_) {
            return false;
        }

        foreach ($optionsArg->items as $item) {
            if (!$item->key instanceof Node) {
                continue;
            }

            $option = SecurityNodeHelper::constantName($item->key);
            if ($option === null) {
                continue;
            }

            if (!in_array($option, ['CURLOPT_SSL_VERIFYHOST', 'CURLOPT_SSL_VERIFYPEER'], true)) {
                continue;
            }

            if (SecurityNodeHelper::isFalseLike($item->value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the SSL verification finding for a cURL call.
     *
     * @return Finding Security finding.
     */
    private function finding(AnalysisUnit $unit, Node $node): Finding
    {
        return new Finding(
            ruleId: self::ID,
            message: 'cURL SSL verification is disabled.',
            filePath: $unit->file->displayPath,
            line: $node->getStartLine(),
            severity: Severity::Warning,
            pillar: Pillar::Security,
            tier: RuleTier::V01,
            confidence: Confidence::High,
            remediation: 'Keep CURLOPT_SSL_VERIFYPEER enabled and require hostname verification.',
        );
    }
}
