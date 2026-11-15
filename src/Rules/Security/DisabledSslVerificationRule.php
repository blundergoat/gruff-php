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
            // Off unless a project sets `enabled: true`: precision-floor M02 found it right on its 1 judged finding, with the rest unresolved, too few
            // to establish its precision floor, so it is off by default rather than deleted (ADR-034).
            isEnabledByDefault: false,
        );
    }

    /**
     * Reports cURL calls whose option and value match the rule's disabled-verification patterns.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - One finding per call matched by a disabled-verification pattern.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];


        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            $name = SecurityNodeHelper::globalFunctionName($call);

            if ($name === 'curl_setopt' && $this->isDisabledCurlSetopt($call)) {
                $findings[] = $this->finding($analysisUnit, $call);
            }


            if ($name === 'curl_setopt_array' && $this->isDisabledCurlSetoptArray($call)) {
                $findings[] = $this->finding($analysisUnit, $call);
            }
        }

        return $findings;
    }

    /**
     * Reports whether a curl_setopt call's option and value match a disabled-verification pattern.
     *
     * @param Expr\FuncCall $call - Parsed `curl_setopt($handle, $option, $value)` call whose option/value pair is read.
     *
     * @return bool - True when the option and value match; false when either is absent or unrecognized.
     */
    private function isDisabledCurlSetopt(Expr\FuncCall $call): bool
    {
        $optionArg = SecurityNodeHelper::sinkArgumentValue($call, 1);
        $valueArg  = SecurityNodeHelper::sinkArgumentValue($call, 2);

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
     * Reports whether a curl_setopt_array option map matches a disabled-verification pattern.
     *
     * @param Expr\FuncCall $call - Parsed `curl_setopt_array($handle, $options)` call; its options array is scanned.
     *
     * @return bool - True when one option/value entry matches; false when the argument or its entries do not match.
     */
    private function isDisabledCurlSetoptArray(Expr\FuncCall $call): bool
    {
        $optionsArg = SecurityNodeHelper::sinkArgumentValue($call, 1);
        if (!$optionsArg instanceof Expr\Array_) {

            return false;
        }


        foreach ($optionsArg->items as $arrayItem) {

            if (!$arrayItem->key instanceof Node) {
                continue;
            }

            $option = SecurityNodeHelper::constantName($arrayItem->key);

            if ($option === null) {
                continue;
            }


            if (!in_array($option, ['CURLOPT_SSL_VERIFYHOST', 'CURLOPT_SSL_VERIFYPEER'], true)) {
                continue;
            }

            if (SecurityNodeHelper::isFalseLike($arrayItem->value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds the SSL-verification finding for a cURL call.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit supplying the display path recorded on the finding.
     * @param Node         $node - cURL call node whose start line localises the finding for the reviewer.
     *
     * @return Finding - High-confidence finding anchored to the cURL call.
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node): Finding
    {
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
