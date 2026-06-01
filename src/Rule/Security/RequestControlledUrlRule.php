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
use PhpParser\Node;
use PhpParser\Node\Expr;

/**
 * Detects URL fetches assembled from request-controlled data.
 */
final class RequestControlledUrlRule implements RuleInterface
{
    /**
     * Stable rule identifier for request-controlled URL sinks.
     */
    public const ID = 'security.request-controlled-url';

    /**
     * @var list<string>
     */
    private const HTTP_METHODS = ['delete', 'get', 'head', 'patch', 'post', 'put', 'request', 'send'];

    /**
     * Describe the request-controlled URL rule.
     *
     * @return RuleDefinition - Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
        // Warning at medium confidence: a request-built URL is a probable SSRF sink, but a host allow-list defuses it.
        return new RuleDefinition(
            id:              self::ID,
            name:            'Request-controlled URL',
            pillar:          Pillar::Security,
            tier:            RuleTier::V01,
            defaultSeverity: Severity::Warning,
            confidence:      Confidence::Medium,
        );
    }

    /**
     * Find URL sinks that receive request-controlled expressions.
     *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for request-controlled URL access.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            array_push($findings, ...$this->functionFindings($analysisUnit, $call));
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\MethodCall::class) as $call) {
            array_push($findings, ...$this->httpClientFindings($analysisUnit, $call));
        }

        foreach (NodeIndex::nodesOf($analysisUnit, Expr\StaticCall::class) as $call) {
            array_push($findings, ...$this->httpClientFindings($analysisUnit, $call));
        }

        return $findings;
    }

    /**
     * Build http client findings for the security rule.
     *
     * @param AnalysisUnit $analysisUnit - Unit being scanned; supplies the display path for any finding.
     * @param Expr\MethodCall|Expr\StaticCall $call - Client call whose URL argument is checked for request taint.
     *
     * @return list<Finding> - One finding when an HTTP-client URL argument is request-controlled, otherwise empty.
     */
    private function httpClientFindings(AnalysisUnit $analysisUnit, Expr\MethodCall|Expr\StaticCall $call): array
    {
        $findings = [];
        $method   = SecurityNodeHelper::methodName($call);
        if ($method === null || !in_array($method, self::HTTP_METHODS, true)) {
            // Not a recognised HTTP verb, so this call cannot be a URL sink we model.
            return [];
        }

        $argumentIndex = $method === 'request' ? 1 : 0;
        $urlArg        = SecurityNodeHelper::argumentValue($call->args, $argumentIndex);
        if ($urlArg !== null && SecurityNodeHelper::containsUrlLiteral($urlArg) && SecurityNodeHelper::containsUserInput($urlArg)) {
            $findings[] = $this->finding($analysisUnit, $call, $method);
        }

        return $findings;
    }

    /**
     * Build function findings for the security rule.
     *
     * @param AnalysisUnit  $analysisUnit - Unit being scanned; supplies the display path for any finding.
     * @param Expr\FuncCall $call - Global-function call routed by name to the matching curl/stream-wrapper check.
     *
     * @return list<Finding> - A single finding when the matched function reads a request-controlled URL, otherwise empty.
     */
    private function functionFindings(AnalysisUnit $analysisUnit, Expr\FuncCall $call): array
    {
        $name = SecurityNodeHelper::globalFunctionName($call);
        if ($name === null) {
            // A dynamic or namespaced callee we cannot resolve to a global function name is out of scope.
            return [];
        }

        if ($name === 'curl_init' && $this->hasCurlInitRequestUrl($call)) {
            // curl_init() took a request-controlled URL straight into the handle.
            return [$this->finding($analysisUnit, $call, $name)];
        }

        if ($name === 'curl_setopt' && $this->hasCurlSetoptRequestUrl($call)) {
            // CURLOPT_URL was set from request data via a single-option curl_setopt() call.
            return [$this->finding($analysisUnit, $call, $name)];
        }

        if ($name === 'curl_setopt_array' && $this->hasCurlSetoptArrayRequestUrl($call)) {
            // The bulk option array carried a request-controlled CURLOPT_URL entry.
            return [$this->finding($analysisUnit, $call, $name)];
        }

        if (in_array($name, ['file_get_contents', 'fopen', 'readfile'], true) && $this->hasStreamWrapperRequestUrl($call)) {
            // A stream-wrapper reader received a request-controlled remote URL.
            return [$this->finding($analysisUnit, $call, $name)];
        }

        // The function name matched none of the modelled URL sinks, so report nothing.
        return [];
    }

    /**
     * Detect curl_init() with a request-controlled URL.
     *
     * @param Expr\FuncCall $call - curl_init() call whose first (URL) argument is inspected for request taint.
     *
     * @return bool - True when the first argument reads request data.
     */
    private function hasCurlInitRequestUrl(Expr\FuncCall $call): bool
    {
        $urlArg = SecurityNodeHelper::argumentValue($call->args, 0);

        // Sink fires only when the URL argument is present and traces back to request input.
        return $urlArg !== null && SecurityNodeHelper::containsUserInput($urlArg);
    }

    /**
     * Detect curl_setopt(CURLOPT_URL, ...) with a request-controlled URL.
     *
     * @param Expr\FuncCall $call - curl_setopt() call whose option (arg 1) and value (arg 2) are checked for URL taint.
     *
     * @return bool - True when the URL option value reads request data.
     */
    private function hasCurlSetoptRequestUrl(Expr\FuncCall $call): bool
    {
        $optionArg = SecurityNodeHelper::argumentValue($call->args, 1);
        $valueArg  = SecurityNodeHelper::argumentValue($call->args, 2);

        // Sink fires only for the CURLOPT_URL option when its value traces back to request input.
        return $optionArg !== null
            && $valueArg !== null
            && SecurityNodeHelper::constantName($optionArg) === 'CURLOPT_URL'
            && SecurityNodeHelper::containsUserInput($valueArg);
    }

    /**
     * Detect CURLOPT_URL entries in curl_setopt_array().
     *
     * @param Expr\FuncCall $call - curl_setopt_array() call whose option-map (arg 1) is scanned for a tainted URL.
     *
     * @return bool - True when the option map contains a request-controlled URL.
     */
    private function hasCurlSetoptArrayRequestUrl(Expr\FuncCall $call): bool
    {
        $optionsArg = SecurityNodeHelper::argumentValue($call->args, 1);
        if (!$optionsArg instanceof Expr\Array_) {
            // No literal option array to inspect, so no CURLOPT_URL entry can be proven tainted.
            return false;
        }

        foreach ($optionsArg->items as $arrayItem) {
            if (!$arrayItem->key instanceof Node || SecurityNodeHelper::constantName($arrayItem->key) !== 'CURLOPT_URL') {
                continue;
            }

            if (SecurityNodeHelper::containsUserInput($arrayItem->value)) {
                // A CURLOPT_URL entry whose value reads request input is enough to flag the call.
                return true;
            }
        }

        // No CURLOPT_URL entry was request-controlled, so the call is clean.
        return false;
    }

    /**
     * Detect stream-wrapper URL fetches with request-controlled URL pieces.
     *
     * @param Expr\FuncCall $call - file_get_contents()/fopen()/readfile() call whose path (arg 0) is taint-checked.
     *
     * @return bool - True when a stream wrapper call uses request-controlled URL construction.
     */
    private function hasStreamWrapperRequestUrl(Expr\FuncCall $call): bool
    {
        $urlArg = SecurityNodeHelper::argumentValue($call->args, 0);

        // Require a literal URL scheme plus request taint so plain local file reads do not trip this remote-fetch sink.
        return $urlArg !== null
            && SecurityNodeHelper::containsUrlLiteral($urlArg)
            && SecurityNodeHelper::containsUserInput($urlArg);
    }

    /**
     * Build the request-controlled URL finding.
     *
     * @param AnalysisUnit $analysisUnit - Unit being scanned; supplies the display path reported to the reviewer.
     * @param Node         $node - Tainted sink node whose start line anchors the finding for the reviewer.
     * @param string       $sink - Sink discriminator (the curl/stream function name or HTTP verb) echoed into the
     *                                   message and metadata so callers can tell which construct fired.
     *
     * @return Finding - Security finding.
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node, string $sink): Finding
    {
        // Emit a fixed warning: every caller already confirmed the URL sink carries request-controlled data.
        return new Finding(
            ruleId:      self::ID,
            message:     sprintf('URL access with request-controlled data detected: %s.', $sink),
            filePath:    $analysisUnit->file->displayPath,
            line:        $node->getStartLine(),
            severity:    Severity::Warning,
            pillar:      Pillar::Security,
            tier:        RuleTier::V01,
            confidence:  Confidence::Medium,
            remediation: 'Restrict outbound URLs to allow-listed hosts and reject request-controlled schemes, hosts, and redirects.',
            metadata:    [
                'sink' => $sink,
            ],
        );
    }
}
