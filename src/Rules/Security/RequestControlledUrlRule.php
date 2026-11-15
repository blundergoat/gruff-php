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
 * Flags request data in curl URL settings, stream-wrapper paths and HTTP-style method arguments.
 * Method names are matched without receiver-type checks; HTTP-style and stream calls also need an HTTP(S) literal.
 *
 * Emits warning-level, medium-confidence findings for possible SSRF; upstream host allow-lists are not verified.
 */
final class RequestControlledUrlRule implements RuleInterface
{
    /**
     * Stable rule identifier for request-controlled URL sinks.
     */
    public const ID = 'security.request-controlled-url';

    /**
     * Method names inspected as possible HTTP-client URL sinks, without receiver-type checks.
     *
     * @var list<string> - Lower-case names matched against instance and static calls.
     */
    private const HTTP_METHODS = ['delete', 'get', 'head', 'patch', 'post', 'put', 'request', 'send'];

    /**
     * Describes the request-controlled-URL rule for the registry and reports.
     *
     * @return RuleDefinition - Warning-level, medium-confidence security metadata and documented false-positive shapes.
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
            falsePositiveShapes: [
                [
                    'shape'      => 'An HTTP-verb method on something that is not an HTTP client, such as a repository\'s get() or a queue\'s send(), whose argument joins a literal URL with request data.',
                    'mitigation' => 'Client calls are matched by method name with no receiver-type check, so accept the finding or rename the local method away from the HTTP verbs.',
                ],
                [
                    'shape'      => 'A fetch whose host is fixed and where request data reaches only the path or query, so the target host cannot be redirected.',
                    'mitigation' => 'A literal URL plus reachable request data is enough to report, so confirm the host is fixed and accept the finding.',
                ],
                [
                    'shape'      => 'A request-built URL constrained by an upstream host allow-list can still look controlled at the sink.',
                    'mitigation' => 'Confirm and document the upstream host allow-list when reviewing the warning.',
                ],
            ],
        );
    }

    /**
     * Reports URL sink calls with detected request-controlled data in the modeled URL argument.
     *
     * @param AnalysisUnit $analysisUnit - Parsed file whose function and method calls are scanned for URL sinks.
     * @param RuleContext  $ruleContext - Unused; the rule interface requires it, and this rule reads no options or project root.
     *
     * @return list<Finding> - At most one finding per matched call; empty when no modeled sink receives detected request data.
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
     * Builds a finding for a recognized HTTP-style method name, without checking the receiver type.
     *
     * @param AnalysisUnit $analysisUnit - Unit being scanned; supplies the display path for any finding.
     * @param Expr\MethodCall|Expr\StaticCall $call - Call checked at positional URL index 1 for request(), otherwise 0.
     *
     * @return list<Finding> - One finding for a recognized call with an HTTP(S) literal and request data in its URL argument; otherwise empty.
     */
    private function httpClientFindings(AnalysisUnit $analysisUnit, Expr\MethodCall|Expr\StaticCall $call): array
    {
        $findings = [];
        $method   = SecurityNodeHelper::methodName($call);
        if ($method === null || !in_array($method, self::HTTP_METHODS, true)) {

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
     * Checks global curl setup and stream-read calls for request-controlled URLs.
     *
     * @param AnalysisUnit  $analysisUnit - Unit being scanned; supplies the display path for any finding.
     * @param Expr\FuncCall $call - Candidate call routed by its global name when resolvable.
     *
     * @return list<Finding> - One finding for a matched request-controlled curl URL or HTTP(S) stream path; otherwise empty.
     */
    private function functionFindings(AnalysisUnit $analysisUnit, Expr\FuncCall $call): array
    {
        $name = SecurityNodeHelper::globalFunctionName($call);
        if ($name === null) {
            // A dynamic or namespaced callee we cannot resolve to a global function name is out of scope.
            return [];
        }

        if ($name === 'curl_init' && $this->hasCurlInitRequestUrl($call)) {

            return [$this->finding($analysisUnit, $call, $name)];
        }

        if ($name === 'curl_setopt' && $this->hasCurlSetoptRequestUrl($call)) {

            return [$this->finding($analysisUnit, $call, $name)];
        }

        if ($name === 'curl_setopt_array' && $this->hasCurlSetoptArrayRequestUrl($call)) {

            return [$this->finding($analysisUnit, $call, $name)];
        }

        if (in_array($name, ['file_get_contents', 'fopen', 'readfile'], true) && $this->hasStreamWrapperRequestUrl($call)) {

            return [$this->finding($analysisUnit, $call, $name)];
        }


        return [];
    }

    /**
     * Reports whether a `curl_init()` call takes a request-controlled URL.
     *
     * @param Expr\FuncCall $call - curl_init() call whose first (URL) argument is inspected for request taint.
     *
     * @return bool - True when the first argument reads request data.
     */
    private function hasCurlInitRequestUrl(Expr\FuncCall $call): bool
    {
        $urlArg = SecurityNodeHelper::sinkArgumentValue($call, 0);


        return $urlArg !== null && SecurityNodeHelper::containsUserInput($urlArg);
    }

    /**
     * Reports whether a curl_setopt() call assigns request data to CURLOPT_URL.
     *
     * @param Expr\FuncCall $call - curl_setopt() call whose option (arg 1) and value (arg 2) are checked for URL taint.
     *
     * @return bool - True when the URL option value reads request data.
     */
    private function hasCurlSetoptRequestUrl(Expr\FuncCall $call): bool
    {
        $optionArg = SecurityNodeHelper::sinkArgumentValue($call, 1);
        $valueArg  = SecurityNodeHelper::sinkArgumentValue($call, 2);


        return $optionArg !== null
            && $valueArg !== null
            && SecurityNodeHelper::constantName($optionArg) === 'CURLOPT_URL'
            && SecurityNodeHelper::containsUserInput($valueArg);
    }

    /**
     * Reports whether a `curl_setopt_array()` option map sets a request-controlled URL.
     *
     * @param Expr\FuncCall $call - curl_setopt_array() call whose option-map (arg 1) is scanned for a tainted URL.
     *
     * @return bool - True when a literal option array has a CURLOPT_URL constant key whose value reads request data.
     */
    private function hasCurlSetoptArrayRequestUrl(Expr\FuncCall $call): bool
    {
        $optionsArg = SecurityNodeHelper::sinkArgumentValue($call, 1);
        if (!$optionsArg instanceof Expr\Array_) {
            // Non-literal option maps are outside this rule's static inspection.
            return false;
        }


        foreach ($optionsArg->items as $arrayItem) {

            if (!$arrayItem->key instanceof Node || SecurityNodeHelper::constantName($arrayItem->key) !== 'CURLOPT_URL') {
                continue;
            }

            if (SecurityNodeHelper::containsUserInput($arrayItem->value)) {

                return true;
            }
        }


        return false;
    }

    /**
     * Checks a stream-read path for both an HTTP(S) literal and request input.
     *
     * @param Expr\FuncCall $call - file_get_contents()/fopen()/readfile() call whose path (arg 0) is taint-checked.
     *
     * @return bool - True when the path expression contains both an HTTP(S) literal and detected request data; false otherwise.
     */
    private function hasStreamWrapperRequestUrl(Expr\FuncCall $call): bool
    {
        $urlArg = SecurityNodeHelper::sinkArgumentValue($call, 0);

        // Require a literal URL scheme plus request taint so plain local file reads do not trip this remote-fetch sink.
        return $urlArg !== null
            && SecurityNodeHelper::containsUrlLiteral($urlArg)
            && SecurityNodeHelper::containsUserInput($urlArg);
    }

    /**
     * Builds the request-controlled-URL finding.
     *
     * @param AnalysisUnit $analysisUnit - Unit being scanned; supplies the display path reported to the reviewer.
     * @param Node         $node - Tainted sink node whose start line anchors the finding for the reviewer.
     * @param string $sink - Curl/stream function or HTTP-style method name included in the message and metadata.
     *
     * @return Finding - Warning-level, medium-confidence security finding with the sink name in its message and metadata.
     */
    private function finding(AnalysisUnit $analysisUnit, Node $node, string $sink): Finding
    {

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
