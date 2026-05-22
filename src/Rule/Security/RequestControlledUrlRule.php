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
     * @return RuleDefinition Rule metadata and defaults.
     */
    public function definition(): RuleDefinition
    {
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
     * @param AnalysisUnit $analysisUnit Parsed unit to inspect.
     * @param RuleContext  $ruleContext  Rule context for this analysis pass.
     *
     * @return list<Finding> Findings for request-controlled URL access.
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
     * @return list<Finding>
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
     * @return list<Finding>
     */
    private function functionFindings(AnalysisUnit $analysisUnit, Expr\FuncCall $call): array
    {
        $name = SecurityNodeHelper::globalFunctionName($call);
        if ($name === null) {
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
     * Detect curl_init() with a request-controlled URL.
     *
     * @return bool True when the first argument reads request data.
     */
    private function hasCurlInitRequestUrl(Expr\FuncCall $call): bool
    {
        $urlArg = SecurityNodeHelper::argumentValue($call->args, 0);

        return $urlArg !== null && SecurityNodeHelper::containsUserInput($urlArg);
    }

    /**
     * Detect curl_setopt(CURLOPT_URL, ...) with a request-controlled URL.
     *
     * @return bool True when the URL option value reads request data.
     */
    private function hasCurlSetoptRequestUrl(Expr\FuncCall $call): bool
    {
        $optionArg = SecurityNodeHelper::argumentValue($call->args, 1);
        $valueArg  = SecurityNodeHelper::argumentValue($call->args, 2);

        return $optionArg !== null
            && $valueArg !== null
            && SecurityNodeHelper::constantName($optionArg) === 'CURLOPT_URL'
            && SecurityNodeHelper::containsUserInput($valueArg);
    }

    /**
     * Detect CURLOPT_URL entries in curl_setopt_array().
     *
     * @return bool True when the option map contains a request-controlled URL.
     */
    private function hasCurlSetoptArrayRequestUrl(Expr\FuncCall $call): bool
    {
        $optionsArg = SecurityNodeHelper::argumentValue($call->args, 1);
        if (!$optionsArg instanceof Expr\Array_) {
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
     * Detect stream-wrapper URL fetches with request-controlled URL pieces.
     *
     * @return bool True when a stream wrapper call uses request-controlled URL construction.
     */
    private function hasStreamWrapperRequestUrl(Expr\FuncCall $call): bool
    {
        $urlArg = SecurityNodeHelper::argumentValue($call->args, 0);

        return $urlArg !== null
            && SecurityNodeHelper::containsUrlLiteral($urlArg)
            && SecurityNodeHelper::containsUserInput($urlArg);
    }

    /**
     * Build the request-controlled URL finding.
     *
     * @return Finding Security finding.
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
