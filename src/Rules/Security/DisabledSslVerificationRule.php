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
 * Detects HTTP client options that disable SSL certificate verification.
 */
final class DisabledSslVerificationRule implements RuleInterface
{
    /**
     * Stable rule identifier for disabled SSL verification findings.
     */
    public const ID = 'security.disabled-ssl-verification';

    /**
     * Describe the disabled SSL verification rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // High confidence: a literal CURLOPT_SSL_VERIFY* off toggle is unambiguous, so the gate can trust it.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Disabled SSL verification',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::High,
        );
    }

    /**
     * Find cURL calls that disable peer or hostname verification.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per cURL call that turns peer or hostname verification off.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            // User view: choose the findings list branch for this case.
            if ($name === 'curl_setopt' && $this->isDisabledCurlSetopt($call)) {
                $findings[] = $this->finding($analysisUnit, $call);
            }

            // User view: choose the findings list branch for this case.
            if ($name === 'curl_setopt_array' && $this->isDisabledCurlSetoptArray($call)) {
                $findings[] = $this->finding($analysisUnit, $call);
            }
        }

        return $findings;
    }

    /**
     * Detect disabled verification in a `curl_setopt` call.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall $call - Parsed `curl_setopt($handle, $option, $value)` call whose option/value pair is read.
     *
     * @return bool - True when SSL verification is disabled.
     */
    private function isDisabledCurlSetopt(Expr\FuncCall $call): bool
    {
        $optionArg = SecurityNodeHelper::argumentValue($call->args, 1);
        $valueArg  = SecurityNodeHelper::argumentValue($call->args, 2);

        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($optionArg === null || $valueArg === null) {
            // Without both an option and a value argument the call cannot be the two-arg form this rule inspects.
            return false;
        }

        $option = SecurityNodeHelper::constantName($optionArg);
        // User view: choose the findings list branch for this case.
        if ($option === 'CURLOPT_SSL_VERIFYPEER') {
            // Verification is disabled exactly when the peer-verify option is set to a false-like value.
            return SecurityNodeHelper::isFalseLike($valueArg);
        }

        // User view: choose the findings list branch for this case.
        if ($option === 'CURLOPT_SSL_VERIFYHOST') {
            // Verification is disabled exactly when the hostname-verify option is set to a false-like value.
            return SecurityNodeHelper::isFalseLike($valueArg);
        }

        // Any other cURL option is irrelevant to certificate verification.
        return false;
    }

    /**
     * Detect disabled verification in a `curl_setopt_array` option map.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall $call - Parsed `curl_setopt_array($handle, $options)` call; its options array is scanned.
     *
     * @return bool - True when the option array disables SSL verification.
     */
    private function isDisabledCurlSetoptArray(Expr\FuncCall $call): bool
    {
        $optionsArg = SecurityNodeHelper::argumentValue($call->args, 1);
        // User view: choose the findings list branch for this case.
        if (!$optionsArg instanceof Expr\Array_) {
            // A non-literal options argument (variable, spread) is opaque to static inspection; assume nothing is off.
            return false;
        }

        // User view: add each item that can appear in findings list.
        foreach ($optionsArg->items as $arrayItem) {
            // User view: choose the findings list branch for this case.
            if (!$arrayItem->key instanceof Node) {
                continue;
            }

            $option = SecurityNodeHelper::constantName($arrayItem->key);
            // User view: choose the findings list branch for this case.
            // User view: missing data becomes the expected findings list state.
            if ($option === null) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if (!in_array($option, ['CURLOPT_SSL_VERIFYHOST', 'CURLOPT_SSL_VERIFYPEER'], true)) {
                continue;
            }

            // User view: choose the findings list branch for this case.
            if (SecurityNodeHelper::isFalseLike($arrayItem->value)) {
                // A single verify-peer/verify-host entry set to a false-like value is enough to disable verification.
                return true;
            }
        }

        // No verification-related option in the map was turned off.
        return false;
    }

    /**
     * Build the SSL verification finding for a cURL call.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit supplying the display path recorded on the finding.
     * @param Node         $node - cURL call node whose start line localises the finding for the reviewer.
     *
     * @return Finding - Security finding.
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node): Finding
    {
        // Report against the cURL call's own line so the reviewer lands on the disabled-verification toggle.
        return new Finding(
            ruleId:      self::ID,
            message:     'cURL SSL verification is disabled.',
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::Security,
            tier:        RuleTier::V01,
            confidence:  Confidence::High,
            remediation: 'Keep CURLOPT_SSL_VERIFYPEER enabled and require hostname verification.',
        );
    }
}
