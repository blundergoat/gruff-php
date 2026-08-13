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
 * Flags a cURL call that turns off certificate verification - `CURLOPT_SSL_VERIFYPEER` or
 * `CURLOPT_SSL_VERIFYHOST` set false - the toggle that lets a man-in-the-middle impersonate the remote host.
 *
 * Runs per file over `curl_setopt` calls and `curl_setopt_array` option maps. Warning, high confidence -
 * a literal verify-off toggle is unambiguous.
 */
final class DisabledSslVerificationRule implements RuleInterface
{
    /**
     * Stable rule identifier for disabled SSL verification findings.
     */
    public const ID = 'security.disabled-ssl-verification';

    /**
     * Describes the disabled-SSL-verification rule for the registry and reports.
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
     * Reports each cURL call that turns peer or hostname verification off.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - One finding per cURL call that turns peer or hostname verification off.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // Check every function call in the file.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);
            // A single curl_setopt disabling a verify option is a finding.
            if ($name === 'curl_setopt' && $this->isDisabledCurlSetopt($call)) {
                $findings[] = $this->finding($analysisUnit, $call);
            }

            // An options map disabling a verify option is a finding too.
            if ($name === 'curl_setopt_array' && $this->isDisabledCurlSetoptArray($call)) {
                $findings[] = $this->finding($analysisUnit, $call);
            }
        }

        return $findings;
    }

    /**
     * Reports whether a `curl_setopt` call disables SSL verification.
     *
     * @param Expr\FuncCall $call - Parsed `curl_setopt($handle, $option, $value)` call whose option/value pair is read.
     *
     * @return bool - True when SSL verification is disabled.
     */
    private function isDisabledCurlSetopt(Expr\FuncCall $call): bool
    {
        $optionArg = SecurityNodeHelper::sinkArgumentValue($call, 1);
        $valueArg  = SecurityNodeHelper::sinkArgumentValue($call, 2);

        if ($optionArg === null || $valueArg === null) {
            // Without both an option and a value argument the call cannot be the two-arg form this rule inspects.
            return false;
        }

        $option = SecurityNodeHelper::constantName($optionArg);
        if ($option === 'CURLOPT_SSL_VERIFYPEER') {
            // Verification is disabled exactly when the peer-verify option is set to a false-like value.
            return SecurityNodeHelper::isFalseLike($valueArg);
        }

        if ($option === 'CURLOPT_SSL_VERIFYHOST') {
            // Verification is disabled exactly when the hostname-verify option is set to a false-like value.
            return SecurityNodeHelper::isFalseLike($valueArg);
        }

        // Any other cURL option is irrelevant to certificate verification.
        return false;
    }

    /**
     * Reports whether a `curl_setopt_array` option map disables SSL verification.
     *
     * @param Expr\FuncCall $call - Parsed `curl_setopt_array($handle, $options)` call; its options array is scanned.
     *
     * @return bool - True when the option array disables SSL verification.
     */
    private function isDisabledCurlSetoptArray(Expr\FuncCall $call): bool
    {
        $optionsArg = SecurityNodeHelper::sinkArgumentValue($call, 1);
        if (!$optionsArg instanceof Expr\Array_) {
            // A non-literal options argument (variable, spread) is opaque to static inspection; assume nothing is off.
            return false;
        }

        // Weigh each option in the map.
        foreach ($optionsArg->items as $arrayItem) {
            // A spread entry has no key to read.
            if (!$arrayItem->key instanceof Node) {
                continue;
            }

            $option = SecurityNodeHelper::constantName($arrayItem->key);
            // Skip an entry whose key is not a named constant.
            if ($option === null) {
                continue;
            }

            // Only the two SSL verification options matter here.
            if (!in_array($option, ['CURLOPT_SSL_VERIFYHOST', 'CURLOPT_SSL_VERIFYPEER'], true)) {
                continue;
            }

            if (SecurityNodeHelper::isFalseLike($arrayItem->value)) {
                // A single verify-peer/verify-host entry set to a false-like value is enough to disable verification.
                return true;
            }
        }

        // No verification-related option in the map was turned off.
        return false;
    }

    /**
     * Builds the SSL-verification finding for a cURL call.
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
