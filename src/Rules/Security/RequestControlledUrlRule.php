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
      * User flow: Decides whether this rule adds a finding to the user report.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit $analysisUnit - Parsed unit to inspect.
     * @param RuleContext  $ruleContext - Rule context for this analysis pass.
     *
     * @return list<Finding> - Findings for request-controlled URL access.
     */
    public function analyse(AnalysisUnit $analysisUnit, RuleContext $ruleContext): array
    {
        $findings = [];

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\FuncCall::class) as $call) {
            array_push($findings, ...$this->functionFindings($analysisUnit, $call));
        }

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\MethodCall::class) as $call) {
            array_push($findings, ...$this->httpClientFindings($analysisUnit, $call));
        }

        // User view: add each item that can appear in findings list.
        foreach (NodeIndex::nodesOf($analysisUnit, Expr\StaticCall::class) as $call) {
            array_push($findings, ...$this->httpClientFindings($analysisUnit, $call));
        }

        return $findings;
    }

    /**
     * Build http client findings for the security rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($method === null || !in_array($method, self::HTTP_METHODS, true)) {
            // Not a recognised HTTP verb, so this call cannot be a URL sink we model.
            return [];
        }

        $argumentIndex = $method === 'request' ? 1 : 0;
        $urlArg        = SecurityNodeHelper::argumentValue($call->args, $argumentIndex);
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($urlArg !== null && SecurityNodeHelper::containsUrlLiteral($urlArg) && SecurityNodeHelper::containsUserInput($urlArg)) {
            $findings[] = $this->finding($analysisUnit, $call, $method);
        }

        return $findings;
    }

    /**
     * Build function findings for the security rule.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param AnalysisUnit  $analysisUnit - Unit being scanned; supplies the display path for any finding.
     * @param Expr\FuncCall $call - Global-function call routed by name to the matching curl/stream-wrapper check.
     *
     * @return list<Finding> - A single finding when the matched function reads a request-controlled URL, otherwise empty.
     */
    private function functionFindings(AnalysisUnit $analysisUnit, Expr\FuncCall $call): array
    {
        $name = SecurityNodeHelper::globalFunctionName($call);
        // User view: choose the findings list branch for this case.
        // User view: missing data becomes the expected findings list state.
        if ($name === null) {
            // A dynamic or namespaced callee we cannot resolve to a global function name is out of scope.
            return [];
        }

        // User view: choose the findings list branch for this case.
        if ($name === 'curl_init' && $this->hasCurlInitRequestUrl($call)) {
            // curl_init() took a request-controlled URL straight into the handle.
            return [$this->finding($analysisUnit, $call, $name)];
        }

        // User view: choose the findings list branch for this case.
        if ($name === 'curl_setopt' && $this->hasCurlSetoptRequestUrl($call)) {
            // CURLOPT_URL was set from request data via a single-option curl_setopt() call.
            return [$this->finding($analysisUnit, $call, $name)];
        }

        // User view: choose the findings list branch for this case.
        if ($name === 'curl_setopt_array' && $this->hasCurlSetoptArrayRequestUrl($call)) {
            // The bulk option array carried a request-controlled CURLOPT_URL entry.
            return [$this->finding($analysisUnit, $call, $name)];
        }

        // User view: choose the findings list branch for this case.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall $call - curl_init() call whose first (URL) argument is inspected for request taint.
     *
     * @return bool - True when the first argument reads request data.
     */
    private function hasCurlInitRequestUrl(Expr\FuncCall $call): bool
    {
        $urlArg = SecurityNodeHelper::argumentValue($call->args, 0);

        // Sink fires only when the URL argument is present and traces back to request input.
        // User view: missing data becomes the expected findings list state.
        return $urlArg !== null && SecurityNodeHelper::containsUserInput($urlArg);
    }

    /**
     * Detect curl_setopt(CURLOPT_URL, ...) with a request-controlled URL.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
        // User view: missing data becomes the expected findings list state.
        return $optionArg !== null
            // User view: missing data becomes the expected findings list state.
            && $valueArg !== null
            && SecurityNodeHelper::constantName($optionArg) === 'CURLOPT_URL'
            && SecurityNodeHelper::containsUserInput($valueArg);
    }

    /**
     * Detect CURLOPT_URL entries in curl_setopt_array().
     *
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall $call - curl_setopt_array() call whose option-map (arg 1) is scanned for a tainted URL.
     *
     * @return bool - True when the option map contains a request-controlled URL.
     */
    private function hasCurlSetoptArrayRequestUrl(Expr\FuncCall $call): bool
    {
        $optionsArg = SecurityNodeHelper::argumentValue($call->args, 1);
        // User view: choose the findings list branch for this case.
        if (!$optionsArg instanceof Expr\Array_) {
            // No literal option array to inspect, so no CURLOPT_URL entry can be proven tainted.
            return false;
        }

        // User view: add each item that can appear in findings list.
        foreach ($optionsArg->items as $arrayItem) {
            // User view: choose the findings list branch for this case.
            if (!$arrayItem->key instanceof Node || SecurityNodeHelper::constantName($arrayItem->key) !== 'CURLOPT_URL') {
                continue;
            }

            // User view: choose the findings list branch for this case.
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
      * User flow: Decides whether this rule adds a finding to the user report.
      *
     * @param Expr\FuncCall $call - file_get_contents()/fopen()/readfile() call whose path (arg 0) is taint-checked.
     *
     * @return bool - True when a stream wrapper call uses request-controlled URL construction.
     */
    private function hasStreamWrapperRequestUrl(Expr\FuncCall $call): bool
    {
        $urlArg = SecurityNodeHelper::argumentValue($call->args, 0);

        // Require a literal URL scheme plus request taint so plain local file reads do not trip this remote-fetch sink.
        // User view: missing data becomes the expected findings list state.
        return $urlArg !== null
            && SecurityNodeHelper::containsUrlLiteral($urlArg)
            && SecurityNodeHelper::containsUserInput($urlArg);
    }

    /**
     * Build the request-controlled URL finding.
     *
      * User flow: Decides whether this rule adds a finding to the user report.
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
